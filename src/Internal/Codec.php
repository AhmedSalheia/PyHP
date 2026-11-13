<?php

declare(strict_types=1);

namespace PyHP\Internal;

use Closure;
use PyHP\Exception\ConversionException;
use PyHP\Exception\ProtocolException;
use PyHP\Exception\StaleProxyException;
use PyHP\PyObject;
use PyHP\Session;
use PyHP\Value\Dict;
use PyHP\Value\NdArray;
use PyHP\Value\Slice;
use PyHP\Value\Tuple;
use PyHP\Value\Vectorized;
use stdClass;

/**
 * Converts values between PHP and the JSON wire format.
 *
 * Plain JSON carries null, bools, ints, floats, strings and lists. Everything
 * else is a "tagged" JSON object: {"$t": "<tag>", ...}. The Python side
 * (python/pyhp_worker.py) implements the mirror image of these rules.
 *
 * @internal
 */
final class Codec
{
    public const MAX_DEPTH = 256;

    // Values are checked for valid UTF-8 one by one (invalid strings travel as
    // bytes); the substitute flag only protects free text such as error messages.
    private const JSON_ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var (Closure(PyObject): array{0: Session, 1: int, 2: int})|null */
    private static ?Closure $unwrap = null;

    /**
     * Data of the arrays encoded since the last takeBuffers(), sent as binary
     * frames after the message; an "ndarray" tag refers to its buffer by index.
     *
     * @var list<string>
     */
    private array $buffers = [];

    public function __construct(private readonly Session $session) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public static function toJson(array $message): string
    {
        try {
            return json_encode($message, self::JSON_ENCODE_FLAGS);
        } catch (\JsonException $error) {
            throw new ConversionException('Could not encode the request: '.$error->getMessage(), 0, $error);
        }
    }

    /**
     * Decodes a worker message. JSON objects become PHP arrays directly, so a
     * plain result (no tagged values) needs no second pass and no extra copy.
     *
     * @return array<array-key, mixed>
     */
    public static function fromJson(string $json): array
    {
        try {
            $message = json_decode($json, true, self::MAX_DEPTH * 2 + 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            throw new ProtocolException('The worker sent invalid JSON: '.$error->getMessage(), 0, $error);
        }
        if (! is_array($message) || array_is_list($message)) {
            throw new ProtocolException('The worker sent a message that is not a JSON object.');
        }

        return $message;
    }

    /**
     * The id of a proxy, after checking it belongs to this session.
     */
    public function refId(PyObject $object): int
    {
        [$session, $id] = self::unwrap($object);
        if ($session !== $this->session) {
            throw new ConversionException('A Python object proxy from a different session cannot be used here.');
        }

        return $id;
    }

    /**
     * PHP value -> wire value.
     */
    public function encode(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ConversionException('The value is nested too deep (more than '.self::MAX_DEPTH.' levels) to send to Python.');
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => $this->encodeFloat($value),
            is_string($value) => $this->encodeString($value),
            is_array($value) => array_is_list($value)
                ? array_map(fn (mixed $item): mixed => $this->encode($item, $depth + 1), $value)
                : $this->encodeDict($value, $depth),
            $value instanceof PyObject => ['$t' => 'ref', 'id' => $this->refId($value)],
            $value instanceof Tuple => [
                '$t' => 'tuple',
                'v' => array_map(fn (mixed $item): mixed => $this->encode($item, $depth + 1), $value->items),
            ],
            $value instanceof Slice => ['$t' => 'slice', 'v' => [$value->start, $value->stop, $value->step]],
            $value instanceof NdArray => $this->encodeNdArray($value),
            $value instanceof Dict => $this->encodeDict($value->items, $depth),
            $value instanceof Closure => ['$t' => 'callback', 'id' => $this->session->callbackId($value)],
            $value instanceof Vectorized => [
                '$t' => 'vectorize',
                'fn' => ['$t' => 'callback', 'id' => $this->session->callbackId($value->callback)],
                'batch' => $value->batchSize,
            ],
            default => throw new ConversionException(sprintf(
                'A PHP %s cannot be sent to Python. Send scalars, arrays, Python object proxies, '
                .'or Py::tuple()/Py::slice()/Py::dict()/Py::ndarray() values.',
                get_debug_type($value),
            )),
        };
    }

    /**
     * Named and positional call arguments, split the way Python expects.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array{args: list<mixed>, kwargs: array<string, mixed>|stdClass}
     */
    public function encodeArguments(array $arguments): array
    {
        $args = [];
        $kwargs = [];
        foreach ($arguments as $key => $argument) {
            if (is_int($key)) {
                $args[] = $this->encode($argument);
            } else {
                $kwargs[$key] = $this->encode($argument);
            }
        }

        // An empty PHP array would be sent as a JSON list; kwargs must be a JSON object.
        return ['args' => $args, 'kwargs' => $kwargs === [] ? new stdClass : $kwargs];
    }

    /**
     * Wire value (as decoded by fromJson) -> PHP value.
     *
     * Plain JSON already is the PHP value. Tagged values are arrays with a "$t"
     * key; a Python dict that really has a "$t" key arrives wrapped in a "dict"
     * tag, so the two can't be confused.
     */
    /**
     * @param  list<string>  $buffers  the binary frames that came with the message
     */
    public function decode(mixed $data, array $buffers = []): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        if (! array_key_exists('$t', $data)) {
            return array_map(fn (mixed $item): mixed => $this->decode($item, $buffers), $data);
        }

        $tag = $data['$t'];
        $raw = $data['v'] ?? null;

        return match ($tag) {
            'ref' => $this->decodeRef($data),
            'callback' => $this->session->callback(is_int($data['id'] ?? null) ? $data['id'] : -1),
            'dict' => $this->decodeDict($data['o'] ?? null, $buffers),
            'ndarray' => $this->decodeNdArray($data, $buffers),
            'bytes' => $this->decodeBytes($raw),
            'float' => match ($raw) {
                'nan' => NAN,
                'inf' => INF,
                '-inf' => -INF,
                default => throw new ProtocolException('Invalid special float on the wire.'),
            },
            default => throw new ProtocolException('Unknown value tag on the wire: '.json_encode($tag)),
        };
    }

    /**
     * The buffers of the arrays encoded since the last call, in tag order.
     *
     * @return list<string>
     */
    public function takeBuffers(): array
    {
        $buffers = $this->buffers;
        $this->buffers = [];

        return $buffers;
    }

    /**
     * The session and id behind a proxy.
     *
     * @return array{0: Session, 1: int}
     */
    public static function unwrap(PyObject $object): array
    {
        // Read the proxy's private fields without giving PyObject any public
        // method that could hide a Python attribute of the same name.
        self::$unwrap ??= Closure::bind(
            static fn (PyObject $proxy): array => [$proxy->session, $proxy->id, $proxy->generation],
            null,
            PyObject::class,
        );
        [$session, $id, $generation] = (self::$unwrap)($object);
        if ($generation !== $session->generation()) {
            throw StaleProxyException::restarted();
        }

        return [$session, $id];
    }

    /**
     * @return float|array{'$t': string, v: string}
     */
    private function encodeFloat(float $value): float|array
    {
        if (is_nan($value)) {
            return ['$t' => 'float', 'v' => 'nan'];
        }
        if (is_infinite($value)) {
            return ['$t' => 'float', 'v' => $value > 0 ? 'inf' : '-inf'];
        }

        return $value;
    }

    /**
     * Valid UTF-8 becomes a Python str; anything else becomes bytes.
     *
     * @return string|array{'$t': string, v: string}
     */
    private function encodeString(string $value): string|array
    {
        return preg_match('//u', $value) === 1 ? $value : ['$t' => 'bytes', 'v' => base64_encode($value)];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{'$t': string, v: list<array{0: mixed, 1: mixed}>}
     */
    private function encodeDict(array $items, int $depth): array
    {
        $pairs = [];
        foreach ($items as $key => $item) {
            $pairs[] = [$this->encode($key, $depth + 1), $this->encode($item, $depth + 1)];
        }

        return ['$t' => 'dict', 'v' => $pairs];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function decodeRef(array $data): PyObject
    {
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        if (! is_int($id) || ! is_string($type)) {
            throw new ProtocolException('Invalid object reference on the wire.');
        }

        return new PyObject($this->session, $id, $type);
    }

    /**
     * @return array<array-key, mixed>
     */
    /**
     * @param  list<string>  $buffers
     * @return array<array-key, mixed>
     */
    private function decodeDict(mixed $object, array $buffers): array
    {
        if (! is_array($object)) {
            throw new ProtocolException('Invalid dict on the wire.');
        }

        return array_map(fn (mixed $item): mixed => $this->decode($item, $buffers), $object);
    }

    /**
     * @return array{'$t': 'ndarray', dtype: string, shape: list<int>, buf: int}
     */
    private function encodeNdArray(NdArray $array): array
    {
        [$dtype, $shape, $bytes] = ArrayPacker::pack($array);
        $this->buffers[] = $bytes;

        return ['$t' => 'ndarray', 'dtype' => $dtype, 'shape' => $shape, 'buf' => count($this->buffers) - 1];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $buffers
     */
    private function decodeNdArray(array $data, array $buffers): mixed
    {
        $dtype = $data['dtype'] ?? null;
        $shape = $data['shape'] ?? null;
        $index = $data['buf'] ?? null;
        if (! is_string($dtype) || ! is_array($shape) || ! is_int($index)) {
            throw new ProtocolException('Invalid array on the wire.');
        }
        $sizes = [];
        foreach ($shape as $size) {
            if (! is_int($size) || $size < 0) {
                throw new ProtocolException('Invalid array on the wire.');
            }
            $sizes[] = $size;
        }
        if (! isset($buffers[$index])) {
            throw new ProtocolException("The worker referred to array buffer #{$index}, which it did not send.");
        }

        return ArrayPacker::unpack($dtype, $sizes, $buffers[$index]);
    }

    private function decodeBytes(mixed $raw): string
    {
        $bytes = is_string($raw) ? base64_decode($raw, true) : false;
        if ($bytes === false) {
            throw new ProtocolException('Invalid bytes value on the wire.');
        }

        return $bytes;
    }
}
