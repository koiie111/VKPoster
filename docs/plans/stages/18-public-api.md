# Этап 18 — Публичный REST API и исходящие вебхуки

**Зависимости:** 07, 10 · **Ветка:** `stage-18-public-api`

## Задачи
- [x] API-токены workspace: создание (показывается один раз, в БД хэш), права (scopes: `posts:read`, `posts:write`, `channels:read`, `media:write`, `stats:read`), срок жизни, отзыв, последняя активность.
- [x] `/api/v1`: JSON, аутентификация `Authorization: Bearer`, без cookies и CSRF; пагинация курсором; единый формат ошибок (`{"error":{"code","message","details"}}`); `Idempotency-Key` для POST.
- [x] Эндпоинты: каналы (список), медиа (загрузка, в том числе по URL через SsrfGuard), посты (CRUD, планирование, варианты), публикации (статус), статистика.
- [x] Rate limit по токену и тарифу, заголовки `X-RateLimit-*`.
- [x] OpenAPI 3.1 спецификация (`public/api/openapi.yaml`) + страница документации (Redoc из vendor) + тест, сверяющий маршруты и спецификацию.
- [x] Исходящие вебхуки: подписка на события (`post.published`, `post.failed`, `channel.error`, `approval.*`), подпись `X-Signature: t=…,v1=HMAC-SHA256`, повторы с backoff, журнал доставок, ручной повтор, автоотключение после N неудач. URL получателя проверяется SsrfGuard при создании **и** при каждой доставке.
- [x] Примеры интеграций в документации: n8n, Make, curl.

## Тесты
Токен без scope → 403; токен другого workspace → 404; идемпотентность POST; rate limit; подпись вебхука; вебхук на внутренний адрес → отказ.

## Проверка владельцем
Не требуется.

## DoD
OpenAPI-спецификация валидна и совпадает с маршрутами.


## Implementation verification — 2026-10-19

DONE. Full PHP 2484/43168; targeted API/webhook/migration/OpenAPI 30/406; PHPStan/style/audit/health and migration upgrade/rollback/replay passed. OpenAPI 3.1 schema validation + route parity passed. Self-hosted Redoc and workspace/staff UI passed responsive light/dark axe checks (5 screens). Architecture/limits: `docs/architecture/modules/public-api.md`, ADR 0018; curl/n8n/Make/HMAC examples: `resources/help/public-api.md`.

Owner verification is not required. PR is created without automatic merge as requested.
