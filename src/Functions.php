<?php

declare(strict_types=1);

namespace PyHP;

/**
 * Call the session's global callables (functions, classes, builtins) as PHP methods.
 *
 *     $py->functions->greet('Ahmed', excited: true);  // greet('Ahmed', excited=True)
 *     $py->functions->Account('Ahmed');               // instantiate a Python class
 *     $py->functions->len([1, 2, 3]);                 // builtins work too
 *     $square = $py->functions->square;               // a proxy you can call later
 *     isset($py->functions->greet);                   // exists and is callable?
 *
 * This class deliberately has no ordinary methods, so every Python name is
 * reachable, whatever it is called.
 */
final class Functions
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
            ['name' => $name] + $this->session->codec()->encodeArguments($arguments),
        );
    }

    public function __get(string $name): PyObject
    {
        $proxy = $this->session->request('get_var_ref', ['name' => $name]);
        assert($proxy instanceof PyObject);

        return $proxy;
    }

    public function __isset(string $name): bool
    {
        return $this->session->request('is_callable_global', ['name' => $name]) === true;
    }
}
