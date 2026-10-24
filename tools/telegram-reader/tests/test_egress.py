"""No Telegram requests: config/SDK compatibility, direct Core routing and durable connect backoff."""

import unittest
from unittest.mock import patch

from python_socks import ProxyType
from telethon.network.connection.connection import Connection

from reader.egress import connect_with_backoff, proxy_config, route_identity
from reader.integration import InternalAPI


class State:
    def __init__(self):
        self.values = {}
        self.now = 100

    def get(self, key, default=None):
        return self.values.get(key, default)

    def set(self, key, value):
        self.values[key] = value

    def clock(self):
        return self.now


class Client:
    def __init__(self, failures):
        self.failures = failures
        self.calls = 0
        self.disconnects = 0

    async def connect(self):
        self.calls += 1
        if self.calls <= self.failures:
            raise OSError("fixture-user:fixture-password")

    async def disconnect(self):
        self.disconnects += 1


class EgressTests(unittest.IsolatedAsyncioTestCase):
    def test_telethon_proxy_backend_contract(self):
        env = {
            "EGRESS_TELEGRAM_MODE": "tunnel",
            "EGRESS_TELEGRAM_PROXY_URL": "socks5h://fixture-user:fixture-password@127.0.0.1:1080",
        }
        proxy = proxy_config(env)
        parsed = Connection._parse_proxy(**proxy)
        self.assertEqual(parsed[0], ProxyType.SOCKS5)
        self.assertEqual(parsed[1:3], ("127.0.0.1", 1080))
        self.assertEqual(parsed[3:5], ("fixture-user", "fixture-password"))
        self.assertIsNone(proxy_config({}))

    def test_invalid_configuration_never_echoes_credentials(self):
        for value in [
            "socks5://fixture-user:fixture-password@8.8.8.8:1080",
            "https://fixture-user:fixture-password@127.0.0.1:443",
            "not a url",
        ]:
            with self.assertRaises(ValueError) as error:
                proxy_config(
                    {
                        "EGRESS_TELEGRAM_MODE": "tunnel",
                        "EGRESS_TELEGRAM_PROXY_URL": value,
                    }
                )
            self.assertEqual(str(error.exception), "egress_configuration")

    async def test_recovery_uses_persisted_exponential_backoff_without_auth_or_send(
        self,
    ):
        state = State()
        client = Client(2)
        waits = []

        async def sleep(seconds):
            waits.append(seconds)
            state.now += seconds

        await connect_with_backoff(client, state, sleep=sleep)
        self.assertEqual(waits, [1, 2])
        self.assertEqual(client.calls, 3)
        self.assertEqual(client.disconnects, 2)
        self.assertEqual(state.get("egress_state"), "ok")
        self.assertEqual(state.get("egress_failures"), 0)
        self.assertNotIn("fixture-password", repr(state.values))

    async def test_exhaustion_stays_failed_and_restart_obeys_saved_delay(self):
        state = State()
        client = Client(100)
        with self.assertRaises(ConnectionError) as error:
            await connect_with_backoff(client, state, attempts=1)
        self.assertEqual(str(error.exception), "egress_unavailable")
        self.assertEqual(state.get("egress_state"), "fail")
        waits = []

        async def sleep(seconds):
            waits.append(seconds)
            state.now += seconds

        await connect_with_backoff(Client(0), state, sleep=sleep)
        self.assertEqual(waits, [1])
        self.assertEqual(state.get("egress_state"), "ok")

    def test_internal_core_http_ignores_ambient_proxy(self):
        with patch.dict("os.environ", {"HTTP_PROXY": "http://fixture.invalid:8080"}):
            api = InternalAPI("http://nginx", "x" * 32)
        self.assertTrue(all(not getattr(h, "proxies", {}) for h in api.opener.handlers))


class RouteIdentityTest(unittest.TestCase):
    def test_identity_is_authenticated_and_changes_with_proxy_credentials(self):
        direct = route_identity("synthetic-shared-secret", {})
        self.assertEqual(len(direct), 64)
        self.assertNotEqual(direct, route_identity("another-secret", {}))
        first = {
            "EGRESS_TELEGRAM_MODE": "tunnel",
            "EGRESS_TELEGRAM_PROXY_URL": "socks5h://user:first@127.0.0.1:1080",
        }
        second = dict(
            first, EGRESS_TELEGRAM_PROXY_URL="socks5h://user:second@127.0.0.1:1080"
        )
        self.assertNotEqual(
            route_identity("synthetic-shared-secret", first),
            route_identity("synthetic-shared-secret", second),
        )
        self.assertNotIn("first", route_identity("synthetic-shared-secret", first))
