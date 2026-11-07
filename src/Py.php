<?php

declare(strict_types=1);

namespace PyHP;

use PyHP\Exception\ProtocolException;
use PyHP\Internal\Codec;
use PyHP\Value\Dict;
use PyHP\Value\Slice;
use PyHP\Value\Tuple;

/**
 * Static entry point.
 *
 * Two ways to work:
 *
 *     // 1. A session object you hold (recommended for anything non-trivial)
 *     $py = Py::session();
 *     $py->vars->limit = 10;
 *
 *     // 2. The shared default session, for quick scripts
 *     Py::config(['python' => 'C:/venvs/data/Scripts/python.exe']);
 *     Py::exec('print("hi")');
 *
 * Helpers for values PHP has no syntax for: Py::tuple(), Py::slice(), Py::dict().
 * Helpers that inspect a proxy without risking a name clash with Python
 * attributes: Py::len(), Py::repr(), Py::type(), Py::toPhp().
 */
final class Py
{
    private static ?Config $config = null;

    private static ?Session $default = null;

    /**
     * Change the configuration used by sessions created from now on, including
     * the default session after it is restarted (see reset()).
     *
     * @param  array<string, mixed>  $options
     */
    public static function config(array $options): void
    {
        self::$config = self::configuration()->with($options);
    }

    public static function configuration(): Config
    {
        return self::$config ??= new Config;
    }

    /**
     * A new, independent session.
     *
     * @param  array<string, mixed>  $overrides  Options for this session only.
     */
    public static function session(array $overrides = []): Session
    {
        return new Session(self::configuration()->with($overrides));
    }

    /** The shared default session, started on first use and restarted if it was closed. */
    public static function default(): Session
    {
        if (self::$default === null || ! self::$default->isOpen()) {
            self::$default = self::session();
        }

        return self::$default;
    }

    /** Close the default session. With $forgetConfig, also go back to the default configuration. */
    public static function reset(bool $forgetConfig = false): void
    {
        self::$default?->close();
        self::$default = null;
        if ($forgetConfig) {
            self::$config = null;
        }
    }

    public static function exec(string $code): void
    {
        self::default()->exec($code);
    }

    public static function execFile(string $path): void
    {
        self::default()->execFile($path);
    }

    public static function eval(string $expression): mixed
    {
        return self::default()->eval($expression);
    }

    public static function import(string $module, ?string $as = null, ?string $from = null): mixed
    {
        return self::default()->import($module, $as, $from);
    }

    public static function modules(): Modules
    {
        return self::default()->modules;
    }

    public static function classes(): Classes
    {
        return self::default()->classes;
    }

    public static function vars(): Vars
    {
        return self::default()->vars;
    }

    public static function functions(): Functions
    {
        return self::default()->functions;
    }

    /** A Python tuple, e.g. for $df->loc[Py::tuple($rows, 'col')] or $array[Py::tuple(0, 1)]. */
    public static function tuple(mixed ...$items): Tuple
    {
        return new Tuple(...$items);
    }

    /** A Python slice: Py::slice(1, 3) is 1:3, Py::slice(null, null, -1) is ::-1. */
    public static function slice(?int $start, ?int $stop = null, ?int $step = null): Slice
    {
        return new Slice($start, $stop, $step);
    }

    /**
     * Force a Python dict, even for an empty or list-shaped PHP array.
     *
     * @param  array<array-key, mixed>  $items
     */
    public static function dict(array $items): Dict
    {
        return new Dict($items);
    }

    /** Python len(obj). */
    public static function len(PyObject $object): int
    {
        $length = self::call($object, 'len');
        if (! is_int($length)) {
            throw new ProtocolException('len() returned a non-integer.');
        }

        return $length;
    }

    /** Python repr(obj). */
    public static function repr(PyObject $object): string
    {
        $text = self::call($object, 'repr');

        return is_string($text) ? $text : '';
    }

    /** The Python type name, e.g. "pandas.DataFrame" or "int". */
    public static function type(PyObject $object): string
    {
        $text = self::call($object, 'type');

        return is_string($text) ? $text : '';
    }

    /**
     * A live proxy to an attribute, even when its value is plain data that reading
     * `$object->attribute` would copy into PHP (dicts, lists, numbers, ...).
     * Use it to change such an attribute in place:
     *
     *     Py::ref($doc, 'user_data')['checked_by'] = 'PHP';
     */
    public static function ref(PyObject $object, string $attribute): PyObject
    {
        [$session, $id] = Codec::unwrap($object);
        $proxy = $session->request('getattr_ref', ['ref' => $id, 'name' => $attribute]);
        if (! $proxy instanceof PyObject) {
            throw new ProtocolException('getattr_ref returned a value instead of a proxy.');
        }

        return $proxy;
    }

    /**
     * Copy the Python object into a PHP value, also accepting sets and subclasses
     * of dict/list/str/int/float. Throws ConversionException if any part has no
     * PHP equivalent, instead of returning nested proxies.
     */
    public static function toPhp(PyObject $object): mixed
    {
        return self::call($object, 'to_php');
    }

    /**
     * A pandas DataFrame as a list of rows: [['col' => value, ...], ...].
     *
     * Faster than $df->to_dict('records') and ready for json_encode(): missing
     * values (NaN, None, NaT, pd.NA) become null, dates and durations become
     * ISO 8601 strings, and the index is left out (call ->reset_index() first
     * to keep it). Columns holding values PHP can't represent (Decimal, date
     * objects, ...) throw ConversionException; convert them first.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(PyObject $frame): array
    {
        [$names, $data] = self::table($frame);
        $rows = [];
        $count = $data === [] ? 0 : count($data[0]);
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($names as $position => $name) {
                $row[$name] = $data[$position][$i];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A pandas DataFrame as columns: ['col' => [values...], ...]. Same value
     * rules as rows(), and the most compact way to copy a table into PHP.
     *
     * @return array<array-key, list<mixed>>
     */
    public static function columns(PyObject $frame): array
    {
        [$names, $data] = self::table($frame);

        return array_combine($names, $data);
    }

    /**
     * @return array{0: list<int|string>, 1: list<list<mixed>>}
     */
    private static function table(PyObject $frame): array
    {
        $table = self::call($frame, 'table');
        $names = is_array($table) ? ($table['columns'] ?? null) : null;
        $data = is_array($table) ? ($table['data'] ?? null) : null;
        if (! is_array($names) || ! is_array($data) || count($names) !== count($data)) {
            throw new ProtocolException('table returned an unexpected value.');
        }
        $checkedNames = [];
        foreach ($names as $name) {
            if (! is_int($name) && ! is_string($name)) {
                throw new ProtocolException('table returned an invalid column name.');
            }
            $checkedNames[] = $name;
        }
        $checkedData = [];
        foreach ($data as $column) {
            if (! is_array($column) || ! array_is_list($column)) {
                throw new ProtocolException('table returned an invalid column.');
            }
            $checkedData[] = $column;
        }

        return [$checkedNames, $checkedData];
    }

    private static function call(PyObject $object, string $op): mixed
    {
        [$session, $id] = Codec::unwrap($object);

        return $session->request($op, ['ref' => $id]);
    }
}
