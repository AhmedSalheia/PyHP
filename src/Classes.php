<?php

declare(strict_types=1);

namespace PyHP;

use LogicException;

/**
 * Python classes, called like PHP methods to create instances.
 *
 *     $py->classes->Invoice('Ahmed', currency: 'ILS');  // Invoice('Ahmed', currency='ILS')
 *     $py->classes->dict(a: 1);                          // builtins work too
 *     $cls = $py->classes->Invoice;                      // the class itself, as a proxy
 *     isset($py->classes->Invoice);                      // is "Invoice" a class?
 *     $py->classes->names();                             // your classes, e.g. ['Invoice']
 *
 * Unlike functions, only classes are accepted: calling a function through
 * classes is a TypeError. Define classes with exec()/execFile(), or import them.
 */
final class Classes
{
    /** @internal Create sessions with Py::session() or new Session(). */
    public function __construct(private readonly Session $session) {}

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->session->request(
            'call_global',
            ['name' => $name, 'kind' => 'class'] + $this->session->codec()->encodeArguments($arguments),
        );
    }

    public function __get(string $name): PyObject
    {
        $class = $this->session->request('get_global', ['name' => $name, 'kind' => 'class']);
        assert($class instanceof PyObject);

        return $class;
    }

    public function __isset(string $name): bool
    {
        return $this->session->request('has_global', ['name' => $name, 'kind' => 'class']) === true;
    }

    public function __set(string $name, mixed $value): never
    {
        throw new LogicException("Classes can't be assigned. Define \"{$name}\" with \$py->exec(...) or import it.");
    }

    /**
     * Names of the session's own classes (defined or imported), in order.
     * Python builtins such as dict are callable but not listed.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = $this->session->request('global_names', ['kind' => 'class']);

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }
}
