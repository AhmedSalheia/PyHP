"""The protocol reference in docs/15-internals.md must cover everything the worker speaks."""

from __future__ import annotations

import re
from pathlib import Path

from pyhp_worker import PROTOCOL_VERSION, Session

DOC = Path(__file__).resolve().parents[2] / "docs" / "15-internals.md"


def _reference() -> str:
    text = DOC.read_text(encoding="utf-8")
    start = text.index("## The wire protocol, frame by frame")
    return text[start : text.index("\n## ", start + 1)]


def _section(title: str) -> str:
    reference = _reference()
    start = reference.index(f"### {title}")
    end = reference.find("\n### ", start + 1)
    return reference[start : None if end == -1 else end]


def test_every_operation_the_worker_handles_is_documented() -> None:
    frame_sections = ("Start-up", "Requests (PHP → Python)", "Frames during a request")
    text = "".join(_section(title) for title in frame_sections)
    documented = set(re.findall(r"^\| `([a-z_]+)` \|", text, re.MULTILINE))
    handled = set(Session()._handlers)  # the handler table is the source of truth

    assert sorted(handled - documented) == []
    connection_frames = {"auth", "hello", "php_call", "php_return", "output"}
    assert sorted(documented - handled - connection_frames) == []


def test_the_documented_protocol_version_is_the_current_one() -> None:
    assert f"Protocol version: **{PROTOCOL_VERSION}**" in _reference()


def test_every_value_tag_is_documented() -> None:
    source = (Path(__file__).resolve().parents[2] / "python" / "pyhp_worker.py").read_text(
        encoding="utf-8"
    )
    tags = set(re.findall(r'"\$t": "([a-z]+)"', source)) | set(
        re.findall(r'tag == "([a-z]+)"', source)
    )
    documented = set(re.findall(r"`\{\"\$t\": \"([a-z]+)\"", _reference()))

    assert sorted(tags - documented) == []
