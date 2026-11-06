<?php

declare(strict_types=1);

namespace PyHP\Internal;

use ArrayObject;
use PyHP\Config;
use PyHP\Exception\MessageTooLargeException;
use PyHP\Exception\ProtocolException;
use PyHP\Exception\TimeoutException;
use PyHP\Exception\WorkerCrashedException;
use PyHP\Exception\WorkerStartException;

/**
 * One running Python worker process and the socket connected to it.
 *
 * Why a loopback socket and not stdin/stdout pipes:
 *  - On Windows, PHP cannot wait on pipes with a timeout (stream_select() does
 *    not support them), but it can on sockets. Sockets give timeouts on every OS.
 *  - Nothing the Python code prints can corrupt the protocol, because printed
 *    output never travels on the socket.
 *
 * Frames are a 4-byte big-endian length followed by UTF-8 JSON.
 *
 * @internal
 */
final class Worker
{
    public const PROTOCOL_VERSION = 1;

    private const POLL_SECONDS = 0.05;

    private const STOP_GRACE_SECONDS = 2.0;

    /**
     * Exit codes of finished processes, keyed by process resource id.
     *
     * @var array<int, int>
     */
    private static array $exitCodes = [];

    private bool $running = true;

    /**
     * @param  resource  $process
     * @param  resource  $socket
     */
    private function __construct(
        private $process,
        private $socket,
        private readonly string $logPath,
        private readonly bool $ownsLog,
        private readonly int $maxMessageBytes,
        public readonly string $pythonVersion,
        public readonly int $pid,
    ) {}

    public static function start(Config $config): self
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($server === false) {
            throw new WorkerStartException("Could not open a local socket for the Python worker: {$errorMessage} ({$errorCode}).");
        }
        $address = stream_socket_get_name($server, false);
        $port = $address === false ? 0 : (int) substr($address, (int) strrpos($address, ':') + 1);

        $token = bin2hex(random_bytes(16));
        [$logPath, $ownsLog] = self::prepareLog($config);
        $process = self::launch($config, $port, $token, $logPath);

        try {
            $socket = self::acceptWorker($server, $process, $config, $logPath);
            $hello = self::readHello($socket, $config, $token, $logPath, $process);
        } catch (WorkerStartException|ProtocolException $error) {
            self::terminate($process);
            if ($ownsLog) {
                @unlink($logPath);
            }
            throw $error;
        } finally {
            fclose($server);
        }

        return new self(
            $process,
            $socket,
            $logPath,
            $ownsLog,
            $config->maxMessageBytes,
            $hello['python'],
            $hello['pid'],
        );
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Send one request and wait for its response, within one shared deadline.
     */
    public function roundTrip(string $request, ?float $timeout): string
    {
        $size = strlen($request);
        if ($size > $this->maxMessageBytes) {
            throw new MessageTooLargeException(
                "The request is {$size} bytes, above the {$this->maxMessageBytes}-byte max_message_bytes limit."
            );
        }
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        try {
            $this->write(pack('N', $size).$request, $deadline);
            $header = $this->read(4, $deadline);
            $length = unpack('Nlength', $header);
            $length = is_array($length) && is_int($length['length']) ? $length['length'] : -1;
            if ($length < 0 || $length > $this->maxMessageBytes) {
                throw new ProtocolException("The worker announced an invalid response size ({$length} bytes).");
            }

            return $this->read($length, $deadline);
        } catch (TimeoutException $error) {
            $this->kill();
            throw new TimeoutException(sprintf(
                'The Python call did not finish within the %s-second timeout. The worker was stopped and the session is closed.',
                (string) $timeout,
            ), 0, $error);
        } catch (ProtocolException $error) {
            $this->kill();
            throw $error;
        }
    }

    /** Polite stop: closing the socket makes the worker exit on its own. */
    public function stop(): void
    {
        if (! $this->running) {
            return;
        }
        $this->running = false;
        @fclose($this->socket);
        $deadline = microtime(true) + self::STOP_GRACE_SECONDS;
        while (microtime(true) < $deadline && self::isAlive($this->process)) {
            usleep(10_000);
        }
        self::terminate($this->process);
        $this->cleanUpLog();
    }

    /** Immediate stop, used after timeouts and protocol errors. */
    public function kill(): void
    {
        if (! $this->running) {
            return;
        }
        $this->running = false;
        @fclose($this->socket);
        self::terminate($this->process);
        $this->cleanUpLog();
    }

    public function log(): string
    {
        $contents = @file_get_contents($this->logPath);

        return $contents === false ? '' : $contents;
    }

    public function logTail(int $bytes = 4000): string
    {
        $log = $this->log();

        return trim(strlen($log) > $bytes ? '...'.substr($log, -$bytes) : $log);
    }

    private function cleanUpLog(): void
    {
        if ($this->ownsLog) {
            @unlink($this->logPath);
        }
    }

    private function write(string $data, ?float $deadline): void
    {
        while ($data !== '') {
            $this->waitFor('write', $deadline);
            $written = @fwrite($this->socket, $data);
            if ($written === false) {
                throw $this->crashed();
            }
            $data = substr($data, $written);
        }
    }

    private function read(int $length, ?float $deadline): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $this->waitFor('read', $deadline);
            $chunk = @fread($this->socket, max(1, min($length - strlen($buffer), 1 << 20)));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw $this->crashed();
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param  'read'|'write'  $direction
     */
    private function waitFor(string $direction, ?float $deadline): void
    {
        while (true) {
            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0) {
                throw new TimeoutException('timeout');
            }
            $read = $direction === 'read' ? [$this->socket] : null;
            $write = $direction === 'write' ? [$this->socket] : null;
            $except = null;
            $wait = $remaining === null ? 1.0 : min($remaining, 1.0);
            $seconds = (int) $wait;
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1_000_000));
            if ($ready === false) {
                throw $this->crashed();
            }
            if ($ready > 0) {
                return;
            }
            if (! self::isAlive($this->process)) {
                throw $this->crashed();
            }
        }
    }

    private function crashed(): WorkerCrashedException
    {
        $exitCode = self::exitCode($this->process);
        $this->running = false;
        @fclose($this->socket);
        $log = $this->logTail();
        $this->terminateQuietly();

        return new WorkerCrashedException(trim(sprintf(
            "The Python worker stopped unexpectedly%s. The session is closed.\n%s",
            $exitCode === null ? '' : " (exit code {$exitCode})",
            $log === '' ? '' : "Worker log:\n{$log}",
        )));
    }

    private function terminateQuietly(): void
    {
        self::terminate($this->process);
        $this->cleanUpLog();
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private static function prepareLog(Config $config): array
    {
        if ($config->logFile !== null) {
            return [$config->logFile, false];
        }
        $path = tempnam(sys_get_temp_dir(), 'pyhp-');
        if ($path === false) {
            throw new WorkerStartException('Could not create a temporary log file for the Python worker.');
        }

        return [$path, true];
    }

    /**
     * @return resource
     */
    private static function launch(Config $config, int $port, string $token, string $logPath)
    {
        $script = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'python'.DIRECTORY_SEPARATOR.'pyhp_worker.py';
        $command = [$config->python, '-u', $script];
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];

        // On Windows a missing interpreter makes proc_open() itself raise a warning
        // ("CreateProcess failed"). Catch it here so it becomes part of our exception
        // instead of leaking to the application's (or test runner's) error handler.
        /** @var ArrayObject<int, string> $launchErrors */
        $launchErrors = new ArrayObject;
        set_error_handler(static function (int $level, string $message) use ($launchErrors): bool {
            $launchErrors[] = $message;

            return true;
        });
        try {
            $process = proc_open($command, $descriptors, $pipes, $config->cwd, self::environment($config, $port, $token));
        } finally {
            restore_error_handler();
        }

        if (! is_resource($process)) {
            throw new WorkerStartException(sprintf(
                'Could not start Python using "%s"%s. Set the "python" option to the full path of a Python 3.10+ interpreter.',
                $config->python,
                $launchErrors->count() === 0 ? '' : ': '.implode('; ', $launchErrors->getArrayCopy()),
            ));
        }

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private static function environment(Config $config, int $port, string $token): array
    {
        $base = [];
        if ($config->inheritEnv) {
            foreach (getenv() as $name => $value) {
                $base[$name] = $value;
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            // Python cannot initialise sockets or randomness on Windows without SYSTEMROOT.
            $systemRoot = getenv('SYSTEMROOT');
            if (is_string($systemRoot)) {
                $base['SYSTEMROOT'] = $systemRoot;
            }
        }
        $base += ['PYTHONIOENCODING' => 'utf-8'];

        return array_merge($base, $config->env, [
            'PYHP_PORT' => (string) $port,
            'PYHP_TOKEN' => $token,
            'PYHP_MAX_FRAME' => (string) $config->maxMessageBytes,
            'PYHP_PATHS' => implode(PATH_SEPARATOR, $config->paths),
        ]);
    }

    /**
     * @param  resource  $server
     * @param  resource  $process
     * @return resource
     */
    private static function acceptWorker($server, $process, Config $config, string $logPath)
    {
        $deadline = microtime(true) + $config->startupTimeout;
        while (microtime(true) < $deadline) {
            $read = [$server];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) (self::POLL_SECONDS * 1_000_000)) > 0) {
                $socket = @stream_socket_accept($server, 0);
                if (is_resource($socket)) {
                    stream_set_blocking($socket, false);

                    return $socket;
                }
            }
            if (! self::isAlive($process)) {
                throw self::startFailure($config, $process, $logPath, 'exited before connecting');
            }
        }

        throw self::startFailure($config, $process, $logPath, "did not connect within {$config->startupTimeout} seconds");
    }

    /**
     * @param  resource  $socket
     * @param  resource  $process
     * @return array{python: string, pid: int}
     */
    private static function readHello($socket, Config $config, string $token, string $logPath, $process): array
    {
        $deadline = microtime(true) + $config->startupTimeout;
        $buffer = '';
        $needed = 4;
        $length = null;
        while (strlen($buffer) < $needed) {
            if (microtime(true) > $deadline) {
                throw self::startFailure($config, $process, $logPath, 'did not complete the handshake');
            }
            $read = [$socket];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) (self::POLL_SECONDS * 1_000_000)) > 0) {
                $chunk = @fread($socket, max(1, $needed - strlen($buffer)));
                if ($chunk === false || ($chunk === '' && feof($socket))) {
                    throw self::startFailure($config, $process, $logPath, 'closed the connection during the handshake');
                }
                $buffer .= $chunk;
            }
            if ($length === null && strlen($buffer) === 4) {
                $unpacked = unpack('Nlength', $buffer);
                $length = is_array($unpacked) && is_int($unpacked['length']) ? $unpacked['length'] : 0;
                if ($length < 1 || $length > 65536) {
                    throw new ProtocolException('The process on the PyHP socket is not a PyHP worker.');
                }
                $buffer = '';
                $needed = $length;
            }
        }

        try {
            $hello = json_decode($buffer, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ProtocolException('The PyHP worker sent an unreadable handshake.', 0, $error);
        }
        if (! is_array($hello) || ($hello['op'] ?? null) !== 'hello'
            || ! is_string($hello['token'] ?? null) || ! hash_equals($token, $hello['token'])) {
            throw new ProtocolException('The process on the PyHP socket is not the worker PyHP started.');
        }
        if (($hello['protocol'] ?? null) !== self::PROTOCOL_VERSION) {
            throw new ProtocolException(sprintf(
                'The Python worker speaks protocol %s but this PHP code expects %d. Reinstall PyHP so both halves match.',
                json_encode($hello['protocol'] ?? null),
                self::PROTOCOL_VERSION,
            ));
        }
        $python = $hello['python'] ?? null;
        $pid = $hello['pid'] ?? null;

        return ['python' => is_string($python) ? $python : 'unknown', 'pid' => is_int($pid) ? $pid : 0];
    }

    /**
     * @param  resource  $process
     */
    private static function startFailure(Config $config, $process, string $logPath, string $what): WorkerStartException
    {
        $exitCode = self::exitCode($process);
        $log = @file_get_contents($logPath);
        $log = $log === false ? '' : trim($log);
        $hint = $exitCode === 127 || $exitCode === 9009
            ? ' The interpreter was not found; set the "python" option to its full path.'
            : '';

        return new WorkerStartException(trim(sprintf(
            "The Python worker started with \"%s\" %s%s.%s\n%s",
            $config->python,
            $what,
            $exitCode === null ? '' : " (exit code {$exitCode})",
            $hint,
            $log === '' ? '' : 'Worker log:'.PHP_EOL.substr($log, -4000),
        )));
    }

    /**
     * @param  resource  $process
     */
    private static function isAlive($process): bool
    {
        return is_resource($process) && self::poll($process) === null;
    }

    /**
     * @param  resource  $process
     */
    private static function exitCode($process): ?int
    {
        if (! is_resource($process)) {
            return null;
        }
        $code = self::poll($process);

        return $code === null || $code < 0 ? null : $code;
    }

    /**
     * Null while running, otherwise the exit code.
     *
     * proc_get_status() reports the real exit code only on the first call after
     * the process ends (later calls say -1), so the first value is remembered.
     *
     * @param  resource  $process
     */
    private static function poll($process): ?int
    {
        $key = (int) $process;
        if (array_key_exists($key, self::$exitCodes)) {
            return self::$exitCodes[$key];
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            return null;
        }
        self::$exitCodes[$key] = $status['exitcode'];

        return $status['exitcode'];
    }

    /**
     * @param  resource  $process
     */
    private static function terminate($process): void
    {
        if (! is_resource($process)) {
            return;
        }
        if (self::isAlive($process)) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
}
