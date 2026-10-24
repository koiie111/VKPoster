# ADR 0019: Caddy на production host перед внутренним nginx

Дата: 2026-10-19. Статус: принято для подготовки Stage 19A; deploy не выполнялся.

## Решение

По запросу владельца первый production использует Caddy как system service хоста:
Internet → Caddy (80/443, автоматический HTTPS/HTTP2) → 127.0.0.1:8080 → nginx → PHP-FPM.
Шаблон: `docker/caddy/Caddyfile`, домен example.com. Реальный домен и сертификат — только 19.8.
Внутренний nginx и существующий publishing pipeline сохраняются.
Caddy устанавливает стандартные proxy headers; PHP доверяет только IP реального
reverse proxy/Docker bridge, а не заголовкам произвольного клиента. Нужен тест цепочки
на production-подобном стенде перед deploy. Публичный Caddy не доверяет входящему
X-Forwarded-For от внешних клиентов без отдельно согласованного upstream proxy.

## Причины и последствия

Автоматический TLS исключает отдельную certbot orchestration. Автоматический redirect
HTTP→HTTPS и HTTP/2 обеспечивает Caddy; compression zstd/gzip. Приложение сохраняет
свою CSP, включая nonce; proxy её не заменяет. Access log выключен ради URL capabilities.
Обновление/версию пакета Caddy и TLS/DNS нужно проверить в 19.8, хост сейчас не меняется.
Это решение не подтверждает работоспособность production TLS.

## Security maintenance, 2026-10-20

The upstream 2.11.7 image binary has reachable Go/HTTP2 HIGH findings. Do not
allowlist the vendor image. `docker/caddy/Dockerfile` retains Caddy 2.11.7 but
rebuilds the standard modules with pinned Go 1.27.3 and x/net >=0.60.0 using
committed go.mod/go.sum and the public module checksum database. Patched artifact
build/Trivy/HTTPS/ZAP validation is a release gate, not assumed complete merely
because this Dockerfile exists. Stage 19.8 must install the validated matching
artifact on the host (or run that image), verify HTTP/2/TLS/firewall and record
the digest. Installing the old package/binary would invalidate the local evidence.
