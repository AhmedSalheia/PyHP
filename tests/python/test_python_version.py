"""The worker refuses Pythons older than the minimum with a clear message."""

from __future__ import annotations

import sys

import pytest

import pyhp_worker
from pyhp_worker import MIN_PYTHON, unsupported_python


def test_the_minimum_is_python_3_11() -> None:
    assert MIN_PYTHON == (3, 11)


def test_older_pythons_get_a_message_naming_both_versions() -> None:
    message = unsupported_python((3, 10, 19))

    assert message is not None
    assert "3.11" in message
    assert "3.10.19" in message


@pytest.mark.parametrize("version", [(3, 11, 0), (3, 15, 0), (4, 0, 0)])
def test_supported_pythons_pass(version: tuple[int, int, int]) -> None:
    assert unsupported_python(version) is None


def test_main_stops_with_exit_code_4_before_anything_else(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setattr(sys, "version_info", (3, 10, 19, "final", 0))

    assert pyhp_worker.main() == 4
    assert "3.11" in capsys.readouterr().err
