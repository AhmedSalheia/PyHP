<?php

declare(strict_types=1);

namespace PyHP;

use LogicException;

/**
 * The modules bound in the session namespace, by the names they are bound to.
 *
 *     $py->import('pandas', as: 'pd');
 *     $py->modules->pd->read_csv('sales.csv');   // the module bound as "pd"
 *     isset($py->modules->pd);                    // is "pd" bound to a module?
 *     $py->modules->names();                      // ['pd', ...]
 *
 * Modules imported inside exec() code ("import json") are included too.
 * Add modules with $py->import(), not by assignment.
 */
final class Modules
{
    /** @internal Create sessions with Py::session() or new Session(). */
    public function __construct(private readonly Session $session) {}

    public function __get(string $name): PyObject
    {
        $module = $this->session->request('get_global', ['name' => $name, 'kind' => 'module']);
        assert($module instanceof PyObject);

        return $module;
    }

    public function __isset(string $name): bool
    {
        return $this->session->request('has_global', ['name' => $name, 'kind' => 'module']) === true;
    }

    public function __set(string $name, mixed $value): never
    {
        throw new LogicException("Modules can't be assigned. Use \$py->import(...) to add \"{$name}\".");
    }

    /**
     * Names bound to modules, in the order they were bound.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = $this->session->request('global_names', ['kind' => 'module']);

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }
}
