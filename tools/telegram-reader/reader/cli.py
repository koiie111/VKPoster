"""Local interactive entry point; never logs credentials or post bodies."""

import argparse
import asyncio
import fcntl
import json
import logging
import os
import signal
import sys
import re
from pathlib import Path

from telethon import TelegramClient
from telethon.sessions import SQLiteSession, StringSession

from .auth import AuthInputError, authenticate
from .egress import connect_with_backoff, proxy_config
from .integration import InternalAPI, Outbox, run_sources
from .model import channel_username
from .qr import QRDisplay, authenticate_qr
from .service import Reader
from .smoke import smoke_test
from .store import Store
from .transport import TelegramTransport


async def live(args, state, store):
    api_id = int(os.environ.get("TELEGRAM_API_ID") or "0")
    api_hash = os.environ.get("TELEGRAM_API_HASH") or ""
    if (
        api_id <= 0
        or len(api_hash) != 32
        or any(c not in "0123456789abcdefABCDEF" for c in api_hash)
    ):
        raise ValueError("Invalid API credentials")
    reader = Reader(store, None, state)
    transport = TelegramTransport(
        state / "telegram",
        api_id,
        api_hash,
        store,
        reader.ingest,
        enable_updates=args.command in ("run", "login-qr"),
    )
    reader.transport = transport
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        loop.add_signal_handler(sig, stop.set)
    tasks = []
    try:
        if args.command == "login-qr":
            with QRDisplay(args.qr_port) as display:
                await authenticate_qr(
                    transport.client,
                    display,
                    allow_password=sys.stdin.isatty(),
                    connect=lambda: connect_with_backoff(transport.client, store),
                )
        else:
            await authenticate(
                transport.client,
                os.environ.get("TELEGRAM_PHONE", ""),
                interactive=args.command == "login",
                connect=lambda: connect_with_backoff(transport.client, store),
            )
        me = await transport.call(lambda: transport.client.get_me())
        if me.bot:
            raise ValueError("Use a test user account, not a bot")
        saved_account = store.get("account_id")
        if saved_account is not None and saved_account != str(me.id):
            raise ValueError("State belongs to a different test account")
        store.set("account_id", str(me.id))
        if args.command in ("login", "login-qr"):
            print("Persistent user session ready")
            return
        value = (
            args.channel
            or os.environ.get("TELEGRAM_TEST_CHANNEL")
            or input("Controlled public channel URL: ")
        )
        entity = await transport.resolve(
            channel_username(value), initialize_updates=args.command == "run"
        )
        reader.channel = str(entity.id)
        print(f"resolved channel_id={entity.id}")
        if args.command == "smoke":
            if not await smoke_test(reader, transport):
                raise AuthInputError(
                    "Smoke не завершён: нужны 10 сообщений с текстом и хотя бы одна фотография среди них, либо требуется проверить ошибку загрузки."
                )
            return
        await reader.startup()
        await transport.call(lambda: transport.client.catch_up())
        await transport.difference()
        if args.once:
            while await reader.download_once():
                pass
            print(json.dumps(store.summary()))
            return
        tasks = [
            asyncio.create_task(reader.photo_loop()),
            asyncio.create_task(reader.recovery_loop()),
            asyncio.create_task(reader.album_loop()),
            asyncio.create_task(stop.wait()),
        ]
        print("Read-only monitoring active; Ctrl+C to stop")
        done, _ = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in done:
            task.result()
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        await transport.client.disconnect()


async def enroll_qr(args, store):
    """QR-authorize in memory and deliver the session directly to the encrypted registry."""
    if not args.account_id or not re.fullmatch(r"[0-9A-HJKMNP-TV-Z]{26}", args.account_id):
        raise ValueError("account_id_required")
    api_id = int(os.environ.get("TELEGRAM_API_ID") or "0")
    api_hash = os.environ.get("TELEGRAM_API_HASH") or ""
    if api_id <= 0 or len(api_hash) != 32:
        raise ValueError("telegram_credentials")
    api = InternalAPI(
        os.environ.get("VKPOSTER_INTERNAL_URL", "http://host.docker.internal:8080"),
        os.environ.get("SOURCES_READER_SECRET", ""),
    )
    client = TelegramClient(
        StringSession(), api_id, api_hash, sequential_updates=True,
        flood_sleep_threshold=0, proxy=proxy_config(),
    )
    try:
        with QRDisplay(args.qr_port) as display:
            await authenticate_qr(
                client, display, allow_password=sys.stdin.isatty(),
                connect=lambda: connect_with_backoff(client, store),
            )
        me = await client.get_me()
        if me is None or me.bot:
            raise ValueError("reader_account_identity")
        session = StringSession.save(client.session)
        await api.enroll(args.account_id, int(me.id), session)
        print("Reader account connected; session stored in the application vault", flush=True)
    finally:
        await client.disconnect()


async def migrate_legacy_session(args, state, store):
    """Move an existing SQLite authorization into the encrypted account registry."""
    if not args.account_id or not re.fullmatch(r"[0-9A-HJKMNP-TV-Z]{26}", args.account_id):
        raise ValueError("account_id_required")
    legacy = state / "telegram.session"
    if not legacy.is_file() or legacy.is_symlink():
        raise ValueError("legacy_session_missing")
    api_id = int(os.environ.get("TELEGRAM_API_ID") or "0")
    api_hash = os.environ.get("TELEGRAM_API_HASH") or ""
    if api_id <= 0 or len(api_hash) != 32:
        raise ValueError("telegram_credentials")
    api = InternalAPI(
        os.environ.get("VKPOSTER_INTERNAL_URL", "http://host.docker.internal:8080"),
        os.environ.get("SOURCES_READER_SECRET", ""),
    )
    session = SQLiteSession(str(state / "telegram"))
    client = TelegramClient(
        session, api_id, api_hash, sequential_updates=True,
        flood_sleep_threshold=0, proxy=proxy_config(),
    )
    try:
        await connect_with_backoff(client, store)
        me = await client.get_me()
        if me is None or me.bot:
            raise ValueError("reader_account_identity")
        await api.enroll(args.account_id, int(me.id), StringSession.save(session))
    finally:
        await client.disconnect()
        session.close()
    for suffix in ("", "-wal", "-shm", "-journal"):
        path = state / ("telegram.session" + suffix)
        if path.exists() and not path.is_symlink():
            path.unlink()
    print("Legacy session migrated to the application vault and local session removed", flush=True)


def main():
    # SDK diagnostics can expose request parameters; only our redacted events log.
    sdk_logger = logging.getLogger("telethon")
    sdk_logger.handlers = [logging.NullHandler()]
    sdk_logger.propagate = False
    parser = argparse.ArgumentParser(
        description="Изолированный read-only Telegram spike"
    )
    parser.add_argument(
        "command",
        choices=(
            "login",
            "login-qr",
            "enroll-qr",
            "migrate-legacy-session",
            "smoke",
            "run",
            "status",
            "sources",
            "outbox-retry",
        ),
    )
    parser.add_argument(
        "--qr-port", type=int, default=8765, help="Local QR listener port inside Docker"
    )
    parser.add_argument("--channel")
    parser.add_argument(
        "--source-id", help="Restrict a controlled smoke run to one enabled Source"
    )
    parser.add_argument("--event-id")
    parser.add_argument("--account-id", help="Pending service account ID for enroll-qr")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--state", default="/state")
    args = parser.parse_args()
    os.umask(0o077)
    state = Path(args.state)
    state.mkdir(mode=0o700, parents=True, exist_ok=True)
    lock = (state / "reader.lock").open("a")
    store = None
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        store = Store(state / "reader.sqlite")
        if args.command == "status":
            print(
                json.dumps(
                    {
                        **store.summary(),
                        "outbox": Outbox(store).summary(),
                        "channel_id": store.get("channel_id"),
                        "recovery_gap": store.get("recovery_gap", False),
                        "egress": {
                            "mode": os.environ.get("EGRESS_TELEGRAM_MODE", "direct")
                            if os.environ.get("EGRESS_TELEGRAM_MODE", "direct")
                            in {"direct", "http", "socks5", "tunnel"}
                            else "invalid",
                            "state": store.get("egress_state", "unknown"),
                            "last_success": store.get("egress_last_success"),
                        },
                    }
                )
            )
        elif args.command == "outbox-retry":
            if (
                not args.event_id
                or len(args.event_id) != 64
                or any(c not in "0123456789abcdef" for c in args.event_id)
            ):
                raise ValueError("invalid_event_id")
            print(json.dumps({"requeued": Outbox(store).retry_failed(args.event_id)}))
        elif args.command == "sources":
            asyncio.run(run_sources(args, state, store))
        elif args.command == "enroll-qr":
            asyncio.run(enroll_qr(args, store))
        elif args.command == "migrate-legacy-session":
            asyncio.run(migrate_legacy_session(args, state, store))
        else:
            asyncio.run(live(args, state, store))
    except (KeyboardInterrupt, EOFError):
        print("Stopped")
    except AuthInputError as error:
        print(str(error))
        raise SystemExit(1) from None
    # Top-level boundary redacts SDK exceptions, including unexpected failures.
    except Exception as error:  # noqa: BLE001
        print(
            f"Reader stopped: {type(error).__name__}. Check local configuration/account; credentials are not logged."
        )
        raise SystemExit(1) from None
    finally:
        if store:
            store.close()
        lock.close()


if __name__ == "__main__":
    main()
