#!/usr/bin/env python3
"""Build private, validated reader env files from protected release secrets."""

import os
from pathlib import Path
import re
import sys
from urllib.parse import urlsplit


def required(name: str) -> str:
    value = os.environ.get(name, "")
    if not value or "\n" in value or "\r" in value or "\x00" in value:
        raise ValueError(f"Missing or multiline {name}")
    return value


def main() -> None:
    target = Path(sys.argv[1])
    api_id = required("TELEGRAM_API_ID")
    api_hash = required("TELEGRAM_API_HASH")
    secret = required("SOURCES_READER_SECRET")
    proxy = required("TELEGRAM_EGRESS_PROXY_URL")
    if not re.fullmatch(r"[1-9][0-9]*", api_id):
        raise ValueError("Invalid Telegram API ID")
    if not re.fullmatch(r"[0-9a-fA-F]{32}", api_hash):
        raise ValueError("Invalid Telegram API hash")
    if len(secret) < 32 or any(char.isspace() for char in secret):
        raise ValueError("Invalid reader secret")
    parsed = urlsplit(proxy)
    if parsed.scheme not in {"http", "https", "socks5"} or not parsed.hostname:
        raise ValueError("Invalid Telegram proxy URL")
    if parsed.hostname in {"localhost", "127.0.0.1", "::1"}:
        raise ValueError("Telegram proxy cannot use reader loopback")
    if any(char.isspace() or char in "#$'\"\\" for char in proxy):
        raise ValueError("Invalid Telegram proxy URL")
    target.mkdir(mode=0o700, parents=True, exist_ok=True)
    target.chmod(0o700)
    for name, contents in {
        "sources.env": f"SOURCES_ENABLED=true\nSOURCES_READER_SECRET={secret}\n",
        "reader.env": (
            f"TELEGRAM_API_ID={api_id}\nTELEGRAM_API_HASH={api_hash}\n"
            f"EGRESS_TELEGRAM_MODE=tunnel\nEGRESS_TELEGRAM_PROXY_URL={proxy}\n"
        ),
    }.items():
        path = target / name
        path.write_text(contents)
        path.chmod(0o600)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, IndexError) as exc:
        raise SystemExit(str(exc)) from None
