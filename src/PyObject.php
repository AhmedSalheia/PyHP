<?php

declare(strict_types=1);

namespace PyHP;

use ArrayAccess;
use Generator;
use IteratorAggregate;
use LogicException;
use PyHP\Exception\ProtocolException;
use PyHP\Exception\StaleProxyException;
use Stringable;

/**
 * A PHP handle to an object that lives in the Python worker.
 *
 * The object itself never leaves Python. Every operation on the proxy is one
 * round trip to the worker:
 *
 *     $df->shape                 getattr(df, "shape")
 *     $df->shape = ...           setattr(...)
 *     isset($df->x) / unset(...) hasattr / delattr
 *     $df->head(5, n: 3)         df.head(5, n=3)
 *     $func(1, 2)                func(1, 2)
 *     $df['col']                 df["col"]        ($df['col'] = v, unset, isset too)
 *     $list[] = 4                list.append(4)
 *     foreach ($obj as $item)    iteration, fetched in batches
 *     (string) $obj              str(obj)
 *
 * Results that are plain data (numbers, strings, lists, dicts, ...) come back
 * as PHP values; anything else comes back as another proxy.
 *
 * When the last PHP reference to a proxy goes away, the Python object is
 * released (with the next request to the worker).
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<int, mixed>
 */
final class PyObject implements ArrayAccess, IteratorAggregate, Stringable
{
    /**
     * @internal Proxies are created by PyHP when Python returns an object.
     */
    /** The worker generation this object lives in; see Session::restart(). */
    private readonly int $generation;

    public function __construct(
        private readonly Session $session,
        private readonly int $id,
        private readonly string $type,
    ) {
        $this->generation = $session->generation();
    }

    public function __destruct()
    {
        $this->session->release($this->id, $this->generation);
    }

    public function __get(string $name): mixed
    {
        return $this->session->request('getattr', ['ref' => $this->ref(), 'name' => $name]);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->session->request('setattr', [
            'ref' => $this->ref(),
            'name' => $name,
            'value' => $this->session->codec()->encode($value),
        ]);
    }

    public function __isset(string $name): bool
    {
        return $this->session->request('hasattr', ['ref' => $this->ref(), 'name' => $name]) === true;
    }

    public function __unset(string $name): void
    {
        $this->session->request('delattr', ['ref' => $this->ref(), 'name' => $name]);
    }

    /**
     * Calls the Python method $name. Also usable directly, e.g.
     * $obj->__call('offsetGet', [...]), to reach a Python method whose name is
     * taken by one of this class's PHP interface methods.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->session->request(
            'call_method',
            ['ref' => $this->ref(), 'name' => $name] + $this->session->codec()->encodeArguments($arguments),
        );
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return $this->session->request(
            'call',
            ['ref' => $this->ref()] + $this->session->codec()->encodeArguments($arguments),
        );
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->session->request('getitem', [
            'ref' => $this->ref(),
            'key' => $this->session->codec()->encode($offset),
        ]);
    }

    /** $obj[] = $value calls $obj.append(value). */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->__call('append', [$value]);

            return;
        }
        $this->session->request('setitem', [
            'ref' => $this->ref(),
            'key' => $this->session->codec()->encode($offset),
            'value' => $this->session->codec()->encode($value),
        ]);
    }

    /** PHP isset() semantics: the key exists and its value is not None. */
    public function offsetExists(mixed $offset): bool
    {
        return $this->session->request('hasitem', [
            'ref' => $this->ref(),
            'key' => $this->session->codec()->encode($offset),
        ]) === true;
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->session->request('delitem', [
            'ref' => $this->ref(),
            'key' => $this->session->codec()->encode($offset),
        ]);
    }

    /**
     * Iterates like Python's for-loop, fetching iter_batch_size items per round trip.
     * Keys are 0, 1, 2, ... (iterating a dict yields its keys, as in Python).
     *
     * @return Generator<int, mixed>
     */
    public function getIterator(): Generator
    {
        $iterator = $this->session->request('iter', ['ref' => $this->ref()]);
        if (! $iterator instanceof self) {
            throw new ProtocolException('iter did not return an iterator reference.');
        }
        $index = 0;
        do {
            $batch = $this->session->request('next_batch', [
                'ref' => $iterator->id,
                'size' => $this->session->configuration()->iterBatchSize,
            ]);
            if (! is_array($batch) || ! is_array($batch['items'] ?? null)) {
                throw new ProtocolException('next_batch returned an unexpected value.');
            }
            foreach ($batch['items'] as $item) {
                yield $index++ => $item;
            }
        } while (($batch['done'] ?? true) !== true);
    }

    /** The object's id in the worker, after checking that worker is still the one that created it. */
    private function ref(): int
    {
        if ($this->generation !== $this->session->generation()) {
            throw StaleProxyException::restarted();
        }

        return $this->id;
    }

    public function __toString(): string
    {
        $text = $this->session->request('str', ['ref' => $this->ref()]);

        return is_string($text) ? $text : '';
    }

    /**
     * What var_dump()/print_r() show. Does not talk to Python.
     *
     * @return array{type: string, id: int, session_open: bool}
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'session_open' => $this->session->isOpen()];
    }

    public function __clone(): void
    {
        throw new LogicException(
            'Python object proxies cannot be cloned. To copy the Python object, use '
            .'$py->import("copy")->copy($object) or deepcopy().'
        );
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Python object proxies cannot be serialized: the object only exists in its Python session.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Python object proxies cannot be unserialized.');
    }
}
