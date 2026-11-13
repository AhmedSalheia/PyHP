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
    public const PROTOCOL_VERSION = 4;

    /** Bytes handed to fwrite() at a time when writing a large frame. */
    private const WRITE_CHUNK = 1 << 20;

    private const POLL_SECONDS = 0.05;

    private const PORT_POLL_MICROSECONDS = 2_000;

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
        $token = bin2hex(random_bytes(16));
        $process = null;
        $socket = null;
        $logPath = null;
        $ownsLog = false;
        $portFile = null;

        // Python listens and PHP connects, never the other way round: on Windows,
        // PHP waits 500 ms whenever it closes a listening socket.
        //
        // Any failure must release everything acquired so far: the worker
        // process, the socket and the temporary files.
        try {
            [$logPath, $ownsLog] = self::prepareLog($config);
            $portFile = self::temporaryFile('pyhp-port-', 'port file');
            $process = self::launch($config, $portFile, $token, $logPath);
            $deadline = microtime(true) + $config->startupTimeout;
            $port = self::waitForPort($portFile, $process, $config, $logPath, $deadline);
            $socket = self::connect($port, $token, $process, $config, $logPath, $deadline);
            $hello = self::readHello($socket, $config, $token, $logPath, $process);
        } catch (WorkerStartException|ProtocolException $error) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            if (is_resource($process)) {
                self::terminate($process);
            }
            if ($ownsLog && $logPath !== null) {
                @unlink($logPath);
            }
            throw $error;
        } finally {
            if ($portFile !== null) {
                @unlink($portFile);
            }
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
     * Send one message: its JSON frame, then one binary frame per buffer (the
     * message's "bin" field says how many). The deadline is shared by everything
     * belonging to one call; $timeout is only used in the error message.
     *
     * @param  list<string>  $buffers
     */
    public function send(string $message, array $buffers, ?float $deadline, ?float $timeout): void
    {
        $size = max([strlen($message), ...array_map(strlen(...), $buffers)]);
        if ($size > $this->maxMessageBytes) {
            throw new MessageTooLargeException(
                "The message is {$size} bytes, above the {$this->maxMessageBytes}-byte max_message_bytes limit."
            );
        }

        try {
            $this->write(pack('N', strlen($message)).$message, $deadline);
            foreach ($buffers as $buffer) {
                $this->write(pack('N', strlen($buffer)), $deadline);
                $this->write($buffer, $deadline);
            }
        } catch (TimeoutException $error) {
            throw $this->timedOut($timeout, $error);
        }
    }

    /**
     * Wait for the next frame from the worker (a response or a callback call).
     */
    public function receive(?float $deadline, ?float $timeout): string
    {
        try {
            $header = $this->read(4, $deadline);
            $length = unpack('Nlength', $header);
            $length = is_array($length) && is_int($length['length']) ? $length['length'] : -1;
            if ($length < 0 || $length > $this->maxMessageBytes) {
                throw new ProtocolException("The worker announced an invalid message size ({$length} bytes).");
            }

            return $this->read($length, $deadline);
        } catch (TimeoutException $error) {
            throw $this->timedOut($timeout, $error);
        } catch (ProtocolException $error) {
            $this->kill();
            throw $error;
        }
    }

    private function timedOut(?float $timeout, TimeoutException $error): TimeoutException
    {
        $this->kill();

        return new TimeoutException(sprintf(
            'The Python call did not finish within the %s-second timeout. The worker was stopped and the session is closed.',
            (string) $timeout,
        ), 0, $error);
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
        // Track an offset instead of cutting the string after every partial write,
        // which would copy the rest of a large array's bytes again and again.
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $this->waitFor('write', $deadline);
            $chunk = $offset === 0 && $length <= self::WRITE_CHUNK ? $data : substr($data, $offset, self::WRITE_CHUNK);
            $written = @fwrite($this->socket, $chunk);
            if ($written === false) {
                throw $this->crashed();
            }
            $offset += $written;
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

        return [self::temporaryFile('pyhp-', 'log file'), true];
    }

    private static function temporaryFile(string $prefix, string $purpose): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new WorkerStartException("Could not create a temporary {$purpose} for the Python worker.");
        }

        return $path;
    }

    /**
     * @return resource
     */
    private static function launch(Config $config, string $portFile, string $token, string $logPath)
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
            $process = proc_open($command, $descriptors, $pipes, $config->cwd, self::environment($config, $portFile, $token));
        } finally {
            restore_error_handler();
        }

        if (! is_resource($process)) {
            throw new WorkerStartException(sprintf(
                'Could not start Python using "%s"%s. Set the "python" option to the full path of a Python 3.11+ interpreter.',
                $config->python,
                $launchErrors->count() === 0 ? '' : ': '.implode('; ', $launchErrors->getArrayCopy()),
            ));
        }

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private static function environment(Config $config, string $portFile, string $token): array
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
            'PYHP_PORT_FILE' => $portFile,
            'PYHP_TOKEN' => $token,
            'PYHP_STARTUP_TIMEOUT' => (string) $config->startupTimeout,
            'PYHP_MAX_FRAME' => (string) $config->maxMessageBytes,
            'PYHP_PATHS' => implode(PATH_SEPARATOR, $config->paths),
        ]);
    }

    /**
     * Wait until the worker has written the port it listens on (a number and a newline).
     *
     * @param  resource  $process
     */
    private static function waitForPort(string $portFile, $process, Config $config, string $logPath, float $deadline): int
    {
        while (true) {
            $contents = @file_get_contents($portFile);
            if (is_string($contents) && str_ends_with($contents, "\n") && ctype_digit(trim($contents))) {
                return (int) trim($contents);
            }
            if (! self::isAlive($process)) {
                throw self::startFailure($config, $process, $logPath, 'exited before it was ready');
            }
            if (microtime(true) > $deadline) {
                throw self::startFailure($config, $process, $logPath, "was not ready within {$config->startupTimeout} seconds");
            }
            usleep(self::PORT_POLL_MICROSECONDS);
        }
    }

    /**
     * Connect to the worker and prove we are the PHP process that started it.
     *
     * @param  resource  $process
     * @return resource
     */
    private static function connect(int $port, string $token, $process, Config $config, string $logPath, float $deadline)
    {
        $socket = @stream_socket_client(
            "tcp://127.0.0.1:{$port}",
            $errorCode,
            $errorMessage,
            max(0.1, $deadline - microtime(true)),
        );
        if ($socket === false) {
            throw self::startFailure($config, $process, $logPath, "could not be reached on port {$port}: {$errorMessage} ({$errorCode})");
        }
        $auth = Codec::toJson(['op' => 'auth', 'token' => $token]);
        if (@fwrite($socket, pack('N', strlen($auth)).$auth) === false) {
            fclose($socket);
            throw self::startFailure($config, $process, $logPath, 'closed the connection during the handshake');
        }
        stream_set_blocking($socket, false);

        return $socket;
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
