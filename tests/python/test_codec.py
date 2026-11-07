"""Value conversion rules between Python and the JSON wire format."""

from __future__ import annotations

import math
from collections import OrderedDict

import pytest

from pyhp_worker import (
    INT64_MAX,
    INT64_MIN,
    ConversionError,
    Registry,
    StaleReferenceError,
    decode,
    encode,
    encode_value,
)


@pytest.fixture
def registry() -> Registry:
    return Registry()


class Opaque:
    pass


def test_scalars_are_sent_by_value(registry: Registry) -> None:
    assert encode(None, registry) is None
    assert encode(True, registry) is True
    assert encode(42, registry) == 42
    assert encode(1.5, registry) == 1.5
    assert encode("héllo ✓", registry) == "héllo ✓"


def test_non_finite_floats_are_tagged(registry: Registry) -> None:
    assert encode(math.inf, registry) == {"$t": "float", "v": "inf"}
    assert encode(-math.inf, registry) == {"$t": "float", "v": "-inf"}
    assert encode(math.nan, registry) == {"$t": "float", "v": "nan"}


def test_bytes_are_base64_tagged(registry: Registry) -> None:
    assert encode(b"\x00\xff", registry) == {"$t": "bytes", "v": "AP8="}


def test_ints_outside_int64_stay_in_python(registry: Registry) -> None:
    assert encode(INT64_MAX, registry) == INT64_MAX
    assert encode(INT64_MIN, registry) == INT64_MIN
    big = encode(INT64_MAX + 1, registry)
    assert isinstance(big, dict)
    assert big["$t"] == "ref"
    assert big["type"] == "int"


def test_lists_and_tuples_become_arrays(registry: Registry) -> None:
    assert encode([1, (2, 3), []], registry) == [1, [2, 3], []]


def test_dicts_are_sent_as_key_value_pairs(registry: Registry) -> None:
    # Plain JSON objects: PHP's json_decode turns them straight into arrays.
    assert encode({"a": 1, 2: "b"}, registry) == {"a": 1, "2": "b"}


def test_dict_with_a_tag_key_is_wrapped_so_it_is_not_mistaken_for_a_tag(registry: Registry) -> None:
    assert encode({"$t": "x", "a": 1}, registry) == {"$t": "dict", "o": {"$t": "x", "a": 1}}


def test_encode_value_reports_whether_any_tag_was_needed(registry: Registry) -> None:
    assert encode_value({"a": [1, 2.5, "x", None]}, registry) == ({"a": [1, 2.5, "x", None]}, False)
    assert encode_value([b"x"], registry)[1] is True
    assert encode_value({"n": math.nan}, registry)[1] is True
    assert encode_value([Opaque()], registry)[1] is True
    assert encode_value({"$t": 1}, registry)[1] is True


def test_dict_with_unsupported_keys_stays_in_python(registry: Registry) -> None:
    result = encode({(1, 2): "x"}, registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_dict_whose_keys_collide_in_php_stays_in_python(registry: Registry) -> None:
    # PHP turns the string key "1" into the integer key 1, so these would collide.
    result = encode({1: "a", "1": "b"}, registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_dict_with_bool_key_stays_in_python(registry: Registry) -> None:
    result = encode({True: "x"}, registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_unknown_objects_become_references_with_type_name(registry: Registry) -> None:
    obj = Opaque()
    result = encode(obj, registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"
    assert result["type"] == "test_codec.Opaque"
    ref_id = result["id"]
    assert isinstance(ref_id, int)
    assert registry.get(ref_id) is obj


def test_nested_unconvertible_values_become_nested_references(registry: Registry) -> None:
    result = encode({"plain": 1, "obj": Opaque()}, registry)
    assert isinstance(result, dict)
    assert result["plain"] == 1
    nested = result["obj"]
    assert isinstance(nested, dict)
    assert nested["$t"] == "ref"


def test_subclasses_of_builtins_stay_in_python_in_auto_mode(registry: Registry) -> None:
    result = encode(OrderedDict(a=1), registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_sets_stay_in_python_in_auto_mode(registry: Registry) -> None:
    result = encode({1, 2}, registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_cyclic_list_is_cut_with_a_reference(registry: Registry) -> None:
    cyclic: list[object] = [1]
    cyclic.append(cyclic)
    result = encode(cyclic, registry)
    assert isinstance(result, list)
    assert result[0] == 1
    assert isinstance(result[1], dict)
    assert result[1]["$t"] == "ref"


def test_strings_that_are_not_valid_utf8_stay_in_python(registry: Registry) -> None:
    result = encode("\ud800", registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_strict_mode_converts_subclasses_and_sets(registry: Registry) -> None:
    assert encode(OrderedDict(a=1), registry, strict=True) == {"a": 1}
    assert encode(frozenset([7]), registry, strict=True) == [7]


def test_strict_mode_rejects_unconvertible_values(registry: Registry) -> None:
    with pytest.raises(ConversionError, match="Opaque"):
        encode([Opaque()], registry, strict=True)


def test_strict_mode_rejects_cycles(registry: Registry) -> None:
    cyclic: list[object] = []
    cyclic.append(cyclic)
    with pytest.raises(ConversionError, match="cycle"):
        encode(cyclic, registry, strict=True)


def test_numpy_scalars_are_unwrapped_when_numpy_is_installed(registry: Registry) -> None:
    np = pytest.importorskip("numpy")
    assert encode(np.int64(5), registry) == 5
    assert encode(np.float64(2.5), registry) == 2.5
    assert encode(np.bool_(True), registry) is True


def test_decode_plain_values() -> None:
    registry = Registry()
    assert decode([None, True, 3, 1.25, "x"], registry) == [None, True, 3, 1.25, "x"]


def test_decode_tagged_values() -> None:
    registry = Registry()
    assert decode({"$t": "dict", "v": [["a", 1], [5, 2]]}, registry) == {"a": 1, 5: 2}
    assert decode({"$t": "bytes", "v": "AP8="}, registry) == b"\x00\xff"
    assert decode({"$t": "tuple", "v": [1, [2]]}, registry) == (1, [2])
    assert decode({"$t": "slice", "v": [1, None, 2]}, registry) == slice(1, None, 2)
    nan = decode({"$t": "float", "v": "nan"}, registry)
    assert isinstance(nan, float)
    assert math.isnan(nan)


def test_decode_reference_returns_the_original_object() -> None:
    registry = Registry()
    obj = Opaque()
    ref_id = registry.add(obj)
    assert decode({"$t": "ref", "id": ref_id}, registry) is obj


def test_decode_unknown_reference_raises_stale_error() -> None:
    with pytest.raises(StaleReferenceError):
        decode({"$t": "ref", "id": 999}, Registry())


def test_decode_rejects_unknown_tags() -> None:
    with pytest.raises(ConversionError, match="unknown"):
        decode({"$t": "nope"}, Registry())


def test_registry_release_forgets_objects() -> None:
    registry = Registry()
    ref_id = registry.add(Opaque())
    assert len(registry) == 1
    registry.release([ref_id, 12345])
    assert len(registry) == 0
    with pytest.raises(StaleReferenceError):
        registry.get(ref_id)


def test_strict_mode_converts_int_and_str_subclasses(registry: Registry) -> None:
    class Code(str):
        pass

    class Small(int):
        pass

    assert encode(Code("x"), registry, strict=True) == "x"
    assert encode(Small(3), registry, strict=True) == 3
    assert isinstance(encode(Small(3), registry), dict)


def test_strict_mode_rejects_huge_ints_and_bad_dict_keys(registry: Registry) -> None:
    with pytest.raises(ConversionError):
        encode(INT64_MAX + 1, registry, strict=True)
    with pytest.raises(ConversionError, match="key"):
        encode({(1,): 1}, registry, strict=True)


def test_deep_nesting_is_cut_with_a_reference(registry: Registry) -> None:
    deep: list[object] = []
    current = deep
    for _ in range(300):
        child: list[object] = []
        current.append(child)
        current = child
    assert isinstance(encode(deep, registry), list)
    with pytest.raises(ConversionError, match="deeper"):
        encode(deep, registry, strict=True)


def test_numpy_values_without_a_plain_equivalent_stay_in_python(registry: Registry) -> None:
    np = pytest.importorskip("numpy")
    result = encode(np.datetime64("2024-01-02T03:04:05.123456789"), registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"


def test_decode_rejects_malformed_tagged_values() -> None:
    registry = Registry()
    with pytest.raises(ConversionError):
        decode({"$t": "bytes", "v": 5}, registry)
    with pytest.raises(ConversionError):
        decode({"$t": "float", "v": "huge"}, registry)
    with pytest.raises(ConversionError):
        decode(object(), registry)


def test_dict_that_must_stay_in_python_does_not_leak_value_references() -> None:
    registry = Registry()
    result = encode({1: Opaque(), "1": "b"}, registry)
    assert isinstance(result, dict)
    assert result["$t"] == "ref"
    assert len(registry) == 1  # only the dict itself
