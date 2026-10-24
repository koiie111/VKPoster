# ADR 0022: RU Core with provider-scoped regional egress

Date: 2026-10-20. Status: implemented locally, validation in progress; no EU VPS/deploy.

Reuse `HttpClientInterface` / `GuzzleHttpClient`, current typed Telegram/VK/MAX clients,
Publisher error classification and Queue retry/backoff. Do not introduce a second
HTTP stack, SourcePublisher, job framework or global server VPN. Core DB/Redis,
browser requests and reader internal HTTP remain RU/direct. Every direct HTTP call
explicitly disables ambient HTTP(S)_PROXY; it is not an accidental fallback.

`EGRESS_<PROVIDER>_MODE=direct|http|socks5|tunnel` and matching `_PROXY_URL` select
route per platform/provider. Defaults direct. Typed platform clients mark their
provider, other known provider API hosts map in the same transport. Unknown/untrusted
media URLs still require SSRF validation. Routed `user_url` is refused because remote
proxy DNS cannot silently replace the IP pinned by SsrfGuard. No credentials in
logs/health/exception messages. Configuration is operator-controlled, never a browser
parameter. Reload app/workers/reader after env change; no code/session reset needed.

A tunnel is an existing operator-managed private HTTP/SOCKS5 endpoint, e.g. over
SSH or narrowly routed WireGuard to an EU node. No tunnel command, SSH key, EU server
or DNS mutation is created by the app. Credentials on a public plaintext proxy are
rejected. PHP supports HTTPS proxies; reader supports private HTTP CONNECT or SOCKS5.
For a shared reader/publisher profile use the common private/tunnel SOCKS5 route;
container loopback means that container, not the host. Restrict a host proxy listener
to its private bridge interface and approved app/reader networks, not public 0.0.0.0.

MTProto uses Telethon's existing proxy parameter and python-socks 3.1.1 (pinned
PyPI release hashes). This transport-specific dependency is needed for Telethon's
supported proxy backend; do not reimplement SOCKS/CONNECT handshakes. The Python
configuration contract matches PHP provider policy. It does not proxy the internal
VKPoster HTTP API. Session/outbox/source identity remain unchanged. Initial connect
and subsequent read RPC network failure backoff is bounded, exponential and durable;
auth/code/password/send operations are not replayed by the connection helper.

Guzzle 8 distinguishes ConnectException (not dispatched, safe retry) from network
response timeouts (uncertain). Preserve that distinction and strip proxy diagnostics;
never resend a paid/publication call inside the routing layer. Existing worker retry
and unknown-outcome guards remain authoritative. A successful external operation must
not become retryable merely because Redis telemetry failed.

Health stores provider/mode/state/time only. A keyed config fingerprint invalidates
old successful observations after credentials/endpoint rotation. Optional scheduled
GET probes (`EGRESS_PROBE_ENABLED=false` by default) use the same transport, no API
credentials and no paid operations. `/readyz` never performs external network calls;
`EGRESS_REQUIRED_PROVIDERS` explicitly opts into fresh successful observations,
otherwise provider outage is monitored degradation while the RU application remains
available. `telegram-reader` can be required separately; signed internal heartbeat
observations and existing reader heartbeat diagnose reader routing independently.
`ops:status` and existing operational alerts expose only safe route metadata.

Tests: Guzzle handler contracts for per-platform options, SSRF/redirect boundary,
no duplicate dispatch, safe retry/uncertain outcome; egress freshness/config rotation,
reader SDK proxy compatibility/backoff/internal-direct tests. All are isolated. Actual
EU exit IP, Telegram connection, proxy authentication/firewall/TLS/tunnel lifecycle,
DNS, failure/recovery and observed metrics must be verified by the owner in 19.8.
Local tests are not a claim that a real EU egress is available.

Official references:
- https://docs.telethon.dev/en/stable/basic/signing-in.html#signing-in-behind-a-proxy
- https://github.com/romis2012/python-socks
- https://github.com/guzzle/guzzle (installed 8.x exception hierarchy inspected)
