<?php

declare(strict_types=1);

namespace PyHP;

use PyHP\Exception\ConversionException;
use PyHP\Exception\MessageTooLargeException;
use PyHP\Exception\ProtocolException;
use PyHP\Exception\PythonException;
use PyHP\Exception\SessionClosedException;
use PyHP\Internal\Codec;
use PyHP\Internal\Worker;

/**
 * One live Python process with its own global namespace.
 *
 * Everything you do through a session (exec, vars, functions, proxies) shares
 * the same Python state until the session is closed. Calls are sequential: a
 * session is not meant to be shared between threads or concurrent requests.
 */
final class Session
{
    /** Read and write Python global variables: $py->vars->name. */
    public readonly Vars $vars;

    /** Call Python global functions (and classes, and builtins): $py->functions->name(...). */
    public readonly Functions $functions;

    private ?Worker $worker;

    private readonly Codec $codec;

    private string $output = '';

    /** @var list<int> */
    private array $pendingReleases = [];

    public function __construct(private readonly Config $config = new Config)
    {
        $this->codec = new Codec($this);
        $this->vars = new Vars($this);
        $this->functions = new Functions($this);
        $this->worker = Worker::start($config);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function isOpen(): bool
    {
        return $this->worker !== null && $this->worker->isRunning();
    }

    /** Run Python statements. Definitions and variables stay in the session. */
    public function exec(string $code): void
    {
        $this->request('exec', ['code' => $code]);
    }

    /**
     * Run a Python file inside the session's namespace, so whatever it defines
     * becomes available through vars and functions.
     */
    public function execFile(string $path): void
    {
        $resolved = realpath($path);
        $this->request('exec_file', ['path' => $resolved === false ? $path : $resolved]);
    }

    /** Evaluate one Python expression and return its value. */
    public function eval(string $expression): mixed
    {
        return $this->request('eval', ['expr' => $expression]);
    }

    /**
     * Import a module and return a proxy to it.
     *
     * With $as, the module is also bound to that name in the session namespace,
     * like "import pandas as pd", so later exec() code can use it.
     */
    public function import(string $module, ?string $as = null): PyObject
    {
        $result = $this->request('import', ['module' => $module, 'as' => $as]);
        if (! $result instanceof PyObject) {
            throw new ProtocolException('import did not return a module reference.');
        }

        return $result;
    }

    /** Text printed by Python since the last call, in OutputMode::Capture. Clears the buffer. */
    public function takeOutput(): string
    {
        $output = $this->output;
        $this->output = '';

        return $output;
    }

    /** Everything the worker wrote to stderr (warnings, low-level prints, crash reports). */
    public function log(): string
    {
        return $this->worker?->log() ?? '';
    }

    /**
     * Details about the running worker.
     *
     * @return array{protocol: int, python: string, executable: string, objects: int, pid: int}
     */
    public function info(): array
    {
        $pid = $this->worker === null ? 0 : $this->worker->pid;
        $info = $this->request('ping');
        if (! is_array($info)) {
            throw new ProtocolException('ping returned an unexpected value.');
        }
        $protocol = $info['protocol'] ?? null;
        $python = $info['python'] ?? null;
        $executable = $info['executable'] ?? null;
        $objects = $info['objects'] ?? null;

        return [
            'protocol' => is_int($protocol) ? $protocol : 0,
            'python' => is_string($python) ? $python : '',
            'executable' => is_string($executable) ? $executable : '',
            'objects' => is_int($objects) ? $objects : 0,
            'pid' => $pid,
        ];
    }

    /** Stop the Python process. All proxies from this session become unusable. */
    public function close(): void
    {
        $this->worker?->stop();
        $this->worker = null;
        $this->pendingReleases = [];
    }

    /**
     * Send one operation to the worker and return its decoded result.
     *
     * @internal Used by Vars, Functions and PyObject.
     *
     * @param  array<string, mixed>  $fields  Already wire-encoded fields.
     */
    public function request(string $op, array $fields = []): mixed
    {
        // Fetched through a helper so static analysis doesn't assume the worker is still
        // running after roundTrip(), which can stop it (timeout, crash).
        $worker = $this->runningWorker();

        $releases = $this->pendingReleases;
        $message = ['op' => $op] + $fields;
        if ($releases !== []) {
            $message['release'] = $releases;
        }
        $json = Codec::toJson($message);
        $this->pendingReleases = [];

        try {
            $response = Codec::fromJson($worker->roundTrip($json, $this->config->timeout));
        } catch (MessageTooLargeException $error) {
            // Nothing was sent, so the releases are still owed to the worker.
            $this->pendingReleases = $releases;
            throw $error;
        } finally {
            if (! $worker->isRunning()) {
                $this->worker = null;
            }
        }

        $this->handleOutput($response['out'] ?? '');

        if (($response['ok'] ?? false) !== true) {
            throw $this->errorFrom($response['error'] ?? null);
        }

        $value = $response['value'] ?? null;

        // Plain results (no references, bytes or NaN/Inf anywhere) are already
        // the final PHP value: skip the decode pass and its full copy.
        return ($response['tagged'] ?? true) === false ? $value : $this->codec->decode($value);
    }

    /**
     * @internal
     */
    public function codec(): Codec
    {
        return $this->codec;
    }

    /**
     * Called by PyObject::__destruct(). The release is sent with the next
     * request instead of immediately, so destructors never block on I/O.
     *
     * @internal
     */
    public function release(int $id): void
    {
        if ($this->worker !== null) {
            $this->pendingReleases[] = $id;
        }
    }

    private function runningWorker(): Worker
    {
        if ($this->worker === null || ! $this->worker->isRunning()) {
            throw new SessionClosedException('This Python session is closed.');
        }

        return $this->worker;
    }

    private function handleOutput(mixed $output): void
    {
        if (! is_string($output) || $output === '') {
            return;
        }
        if ($this->config->output === OutputMode::Echo) {
            echo $output;
        } else {
            $this->output .= $output;
        }
    }

    private function errorFrom(mixed $error): \Throwable
    {
        if (! is_array($error)) {
            return new ProtocolException('The worker reported a failure without details.');
        }
        $kind = $error['kind'] ?? null;
        $type = is_string($error['type'] ?? null) ? $error['type'] : 'Exception';
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $traceback = is_string($error['traceback'] ?? null) ? $error['traceback'] : '';

        return match ($kind) {
            'python' => new PythonException($type, $message, $traceback),
            'conversion' => new ConversionException($message),
            'too_large' => new MessageTooLargeException($message),
            default => new ProtocolException("The worker rejected a request ({$type}): {$message}"),
        };
    }
}
