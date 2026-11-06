<?php

declare(strict_types=1);

namespace PyHP;

/**
 * The session's Python global variables, as PHP properties.
 *
 *     $py->vars->limit = 10;          // limit = 10
 *     $py->vars->limit;               // read back (copied if plain data, proxied otherwise)
 *     isset($py->vars->limit);        // does the global exist?
 *     unset($py->vars->limit);        // del limit (no error if missing)
 *     $py->vars->names();             // names of your globals
 *     $py->vars->ref('items');        // always a live proxy, never a copy
 *
 * names() and ref() are PHP methods, so they never clash with Python
 * variables called "names" or "ref": $py->vars->names (no parentheses) still
 * reads the Python variable.
 */
final class Vars
{
    /** @internal Create sessions with Py::session() or new Session(). */
    public function __construct(private readonly Session $session) {}

    public function __get(string $name): mixed
    {
        return $this->session->request('get_var', ['name' => $name]);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->session->request('set_var', ['name' => $name, 'value' => $this->session->codec()->encode($value)]);
    }

    public function __isset(string $name): bool
    {
        return $this->session->request('has_var', ['name' => $name]) === true;
    }

    public function __unset(string $name): void
    {
        $this->session->request('del_var', ['name' => $name, 'missing_ok' => true]);
    }

    /**
     * Names of the session's globals, in definition order. Names starting with
     * "__" (Python internals) are left out.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = $this->session->request('var_names');

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }

    /**
     * A live proxy to the variable, even when its value is plain data that
     * reading it normally would copy (lists, dicts, numbers, ...).
     */
    public function ref(string $name): PyObject
    {
        $proxy = $this->session->request('get_var_ref', ['name' => $name]);
        assert($proxy instanceof PyObject);

        return $proxy;
    }
}
