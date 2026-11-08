<?php

declare(strict_types=1);

namespace PyHP;

use Closure;
use PyHP\Exception\ConversionException;
use PyHP\Exception\MessageTooLargeException;
use PyHP\Exception\ProtocolException;
use PyHP\Exception\PyHPException;
use PyHP\Exception\PythonException;
use PyHP\Exception\SessionClosedException;
use PyHP\Internal\Codec;
use PyHP\Internal\Worker;
use Throwable;

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

    /** Modules bound in the session namespace: $py->modules->pd. */
    public readonly Modules $modules;

    /** Python classes (yours, imported ones and builtins): $py->classes->Invoice(...). */
    public readonly Classes $classes;

    private ?Worker $worker;

    private readonly Codec $codec;

    private string $output = '';

    /** @var list<int> */
    private array $pendingReleases = [];

    /**
     * PHP closures Python currently holds, by callback id.
     *
     * @var array<int, Closure>
     */
    private array $callbacks = [];

    /**
     * Callback id of each registered closure, by spl_object_id(). Stable because
     * $callbacks keeps the closures alive.
     *
     * @var array<int, int>
     */
    private array $callbackIds = [];

    private int $nextCallbackId = 1;

    /**
     * Exceptions thrown by callbacks during the current call, by callback call
     * number, so the original object can be rethrown when it surfaces.
     *
     * @var array<int, Throwable>
     */
    private array $callbackErrors = [];

    /** How many requests are in progress (more than one while callbacks call Python). */
    private int $requestDepth = 0;

    /** Counts workers: proxies remember theirs, so ones from before a restart are refused. */
    private int $generation = 1;

    /** True after close(): an explicitly closed session is never restarted. */
    private bool $closed = false;

    /** @var list<Closure(Session): mixed> run after every worker start; see onStart() */
    private array $startHooks = [];

    public function __construct(private readonly Config $config = new Config)
    {
        $this->codec = new Codec($this);
        $this->vars = new Vars($this);
        $this->functions = new Functions($this);
        $this->modules = new Modules($this);
        $this->classes = new Classes($this);
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
     * Import like Python does, binding the name in the session namespace:
     *
     *     import('pandas')                    import pandas           (binds pandas)
     *     import('pandas', as: 'pd')          import pandas as pd     (binds pd)
     *     import('os.path')                   import os.path          (binds os, returns os.path)
     *     import('time', from: 'datetime')    from datetime import time
     *     import('*', from: 'math')           from math import *      (returns null)
     *
     * Returns what was imported: a module or class proxy, or a plain PHP value
     * when the imported name is plain data (e.g. import('pi', from: 'math')).
     */
    public function import(string $module, ?string $as = null, ?string $from = null): mixed
    {
        return $this->request('import', ['module' => $module, 'as' => $as, 'from' => $from]);
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
     * @return array{protocol: int, python: string, executable: string, objects: int, callbacks: int, pid: int}
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
            'callbacks' => count($this->callbacks),
            'pid' => $pid,
        ];
    }

    /**
     * Registers a hook that prepares the session (imports, models, variables): it runs
     * now, and again whenever the worker is restarted (see the "restart" option).
     *
     * @param  Closure(Session): mixed  $hook
     */
    public function onStart(Closure $hook): self
    {
        $this->startHooks[] = $hook;
        if ($this->isOpen()) {
            $hook($this);
        }

        return $this;
    }

    /**
     * Replaces the worker with a fresh one and runs the onStart hooks. Everything in
     * the old worker is gone; proxies to it throw StaleProxyException from now on.
     */
    public function restart(): void
    {
        if ($this->requestDepth > 0) {
            throw new PyHPException('A session can\'t restart while a call is running (for example from a PHP callback).');
        }
        $this->worker?->stop();
        $this->worker = null;
        $this->pendingReleases = [];
        $this->callbacks = [];
        $this->callbackIds = [];
        $this->closed = false;
        $this->generation++;
        $this->worker = Worker::start($this->config);
        foreach ($this->startHooks as $hook) {
            $hook($this);
        }
    }

    /** True when the worker answers a ping; restarts it first if the "restart" option allows. */
    public function ping(): bool
    {
        try {
            $this->request('ping');

            return true;
        } catch (PyHPException) {
            return false;
        }
    }

    /**
     * The worker generation proxies belong to.
     *
     * @internal Used by PyObject and Codec.
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /** Stop the Python process. All proxies from this session become unusable. */
    public function close(): void
    {
        $this->closed = true;
        $this->worker?->stop();
        $this->worker = null;
        $this->pendingReleases = [];
        $this->callbacks = [];
        $this->callbackIds = [];
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
        if ($this->config->restart && ! $this->closed && $this->requestDepth === 0
            && ($this->worker === null || ! $this->worker->isRunning())) {
            $this->restart();
        }
        $worker = $this->runningWorker();

        $releases = $this->pendingReleases;
        $message = ['op' => $op] + $fields;
        if ($releases !== []) {
            $message['release'] = $releases;
        }
        $json = Codec::toJson($message);
        $this->pendingReleases = [];
        $timeout = $this->config->timeout;
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        $this->requestDepth++;
        try {
            try {
                $worker->send($json, $deadline, $timeout);
            } catch (MessageTooLargeException $error) {
                // Nothing was sent, so the releases are still owed to the worker.
                $this->pendingReleases = $releases;
                throw $error;
            }
            $response = $this->awaitResponse($deadline, $timeout);

            $this->handleOutput($response['out'] ?? '');

            if (($response['ok'] ?? false) !== true) {
                throw $this->errorFrom($response['error'] ?? null);
            }

            $value = $response['value'] ?? null;

            // Plain results (no references, bytes or NaN/Inf anywhere) are already
            // the final PHP value: skip the decode pass and its full copy.
            return ($response['tagged'] ?? true) === false ? $value : $this->codec->decode($value);
        } finally {
            $this->requestDepth--;
            if ($this->requestDepth === 0) {
                $this->callbackErrors = [];
            }
            if ($this->worker !== null && ! $this->worker->isRunning()) {
                $this->worker = null;
            }
        }
    }

    /**
     * The id under which Python knows this closure; registers it on first use.
     *
     * @internal Used by Codec.
     */
    public function callbackId(Closure $closure): int
    {
        $objectId = spl_object_id($closure);
        if (isset($this->callbackIds[$objectId])) {
            return $this->callbackIds[$objectId];
        }
        $id = $this->nextCallbackId++;
        $this->callbacks[$id] = $closure;
        $this->callbackIds[$objectId] = $id;

        return $id;
    }

    /**
     * @internal Used by Codec.
     */
    public function callback(int $id): Closure
    {
        return $this->callbacks[$id]
            ?? throw new ProtocolException("The worker referred to unknown PHP callback #{$id}.");
    }

    /**
     * Read frames until the response arrives, running PHP callbacks Python asks for.
     *
     * @return array<array-key, mixed>
     */
    private function awaitResponse(?float $deadline, ?float $timeout): array
    {
        while (true) {
            $frame = Codec::fromJson($this->runningWorker()->receive($deadline, $timeout));
            $this->forgetCallbacks($frame['release_callbacks'] ?? null);
            if (($frame['op'] ?? null) !== 'php_call') {
                return $frame;
            }

            $started = microtime(true);
            [$reply, $thrown] = $this->runCallback($frame);
            if ($deadline !== null) {
                // The timeout limits Python; time spent running PHP doesn't count.
                $deadline += microtime(true) - $started;
            }
            $this->sendReply($reply, $thrown, $deadline, $timeout);
        }
    }

    /**
     * @param  array<array-key, mixed>  $frame
     * @return array{0: array<string, mixed>, 1: Throwable|null}
     */
    private function runCallback(array $frame): array
    {
        $seq = $frame['seq'] ?? null;
        $id = $frame['cb'] ?? null;
        if (! is_int($seq) || ! is_int($id)) {
            throw new ProtocolException('The worker sent an invalid callback call.');
        }

        try {
            $closure = $this->callback($id);
            $tagged = ($frame['tagged'] ?? true) !== false;
            $args = $tagged ? $this->codec->decode($frame['args'] ?? []) : ($frame['args'] ?? []);
            $kwargs = $tagged ? $this->codec->decode($frame['kwargs'] ?? []) : ($frame['kwargs'] ?? []);
            if (! is_array($args) || ! array_is_list($args) || ! is_array($kwargs)) {
                throw new ProtocolException('The worker sent invalid callback arguments.');
            }
            $value = $this->codec->encode($closure(...$args, ...$kwargs));

            return [$this->withReleases(['op' => 'php_return', 'seq' => $seq, 'ok' => true, 'value' => $value]), null];
        } catch (Throwable $error) {
            // Not swallowed: it travels to Python as PhpError, and the original
            // object is rethrown here if Python doesn't catch it.
            return [$this->withReleases($this->callbackFailure($seq, $error)), $error];
        }
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function sendReply(array $reply, ?Throwable $thrown, ?float $deadline, ?float $timeout): void
    {
        $worker = $this->worker;
        if ($worker === null || ! $worker->isRunning()) {
            // The callback closed the session (or a nested call killed the worker).
            throw $thrown ?? new SessionClosedException('The session was closed inside a PHP callback.');
        }
        try {
            $worker->send(Codec::toJson($reply), $deadline, $timeout);
        } catch (MessageTooLargeException|ConversionException $error) {
            $seq = $reply['seq'] ?? 0;
            $worker->send(Codec::toJson($this->callbackFailure(is_int($seq) ? $seq : 0, $error)), $deadline, $timeout);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackFailure(int $seq, Throwable $error): array
    {
        $this->callbackErrors[$seq] = $error;

        return [
            'op' => 'php_return',
            'seq' => $seq,
            'ok' => false,
            'error' => [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'id' => $seq,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function withReleases(array $message): array
    {
        if ($this->pendingReleases !== []) {
            $message['release'] = $this->pendingReleases;
            $this->pendingReleases = [];
        }

        return $message;
    }

    private function forgetCallbacks(mixed $ids): void
    {
        if (! is_array($ids)) {
            return;
        }
        foreach ($ids as $id) {
            if (is_int($id) && isset($this->callbacks[$id])) {
                unset($this->callbackIds[spl_object_id($this->callbacks[$id])], $this->callbacks[$id]);
            }
        }
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
    public function release(int $id, int $generation): void
    {
        if ($this->worker !== null && $generation === $this->generation) {
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

    private function errorFrom(mixed $error): Throwable
    {
        if (! is_array($error)) {
            return new ProtocolException('The worker reported a failure without details.');
        }
        $phpError = $error['php_error'] ?? null;
        if (is_int($phpError) && isset($this->callbackErrors[$phpError])) {
            // A PHP callback threw and Python didn't catch it: rethrow the original.
            return $this->callbackErrors[$phpError];
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
