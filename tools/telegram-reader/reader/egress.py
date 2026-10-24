"""Provider-scoped MTProto egress. Internal VKPoster HTTP remains direct RU traffic."""

import asyncio
import ipaddress
import hashlib
import hmac
import os
from urllib.parse import unquote, urlsplit

from python_socks import ProxyError


def proxy_config(env=None):
    env = os.environ if env is None else env
    mode = env.get("EGRESS_TELEGRAM_MODE", "direct")
    if mode == "direct":
        return None
    if mode not in ("http", "socks5", "tunnel"):
        raise ValueError("egress_mode")
    try:
        raw = env.get("EGRESS_TELEGRAM_PROXY_URL", "")
        if any(ord(c) < 33 or ord(c) == 127 for c in raw):
            raise ValueError("egress_endpoint")
        value = urlsplit(raw)
        scheme = value.scheme
        allowed = {"http"} if mode == "http" else {"socks5", "socks5h"}
        if mode == "tunnel":
            allowed = {"http", "socks5", "socks5h"}
        if (
            scheme not in allowed
            or not value.hostname
            or value.query
            or value.fragment
            or value.path not in ("", "/")
        ):
            raise ValueError("egress_endpoint")
        try:
            private = ipaddress.ip_address(value.hostname).is_private
        except ValueError:
            private = value.hostname == "localhost"
        if mode == "tunnel" and not private:
            raise ValueError("egress_tunnel_endpoint")
        if (value.username or value.password) and not private:
            raise ValueError("egress_proxy_auth_requires_private_tunnel")
        username = unquote(value.username) if value.username else None
        password = unquote(value.password) if value.password else None
        for credential in (username, password):
            if credential and (
                len(credential.encode()) > 255 or any(ord(c) < 32 for c in credential)
            ):
                raise ValueError("egress_proxy_auth")
        if value.port is not None and value.port < 1:
            raise ValueError("egress_proxy_port")
        return {
            "proxy_type": "http" if scheme == "http" else "socks5",
            "addr": value.hostname,
            "port": value.port or (80 if scheme == "http" else 1080),
            "rdns": True,
            "username": username,
            "password": password,
        }
    except (ValueError, UnicodeError):
        # Never include a malformed URL/credential in exception text.
        raise ValueError("egress_configuration") from None


async def connect_with_backoff(client, store, sleep=asyncio.sleep, attempts=5):
    """Only retry pre-auth network connection. No send/authentication operation is replayed."""
    if not 1 <= attempts <= 10:
        raise ValueError("egress_attempt_limit")
    for attempt in range(attempts):
        remaining = store.get("egress_retry_after", 0) - store.clock()
        if remaining > 0:
            await sleep(min(60, remaining))
        try:
            await client.connect()
            store.set("egress_state", "ok")
            store.set("egress_last_success", store.clock())
            store.set("egress_failures", 0)
            store.set("egress_retry_after", 0)
            return
        except (OSError, ConnectionError, TimeoutError, ProxyError):
            failures = min(10, store.get("egress_failures", 0) + 1)
            store.set("egress_state", "fail")
            store.set("egress_failures", failures)
            store.set(
                "egress_retry_after", store.clock() + min(60, 2 ** (failures - 1))
            )
            await client.disconnect()
            if attempt + 1 == attempts:
                raise ConnectionError("egress_unavailable") from None


def route_identity(secret, env=None):
    """Authenticated config identity only; never log or expose it in public diagnostics."""
    env = os.environ if env is None else env
    mode = env.get("EGRESS_TELEGRAM_MODE", "direct")
    proxy = "" if mode == "direct" else env.get("EGRESS_TELEGRAM_PROXY_URL", "")
    return hmac.new(
        secret.encode(), ("telegram:" + mode + ":" + proxy).encode(), hashlib.sha256
    ).hexdigest()
