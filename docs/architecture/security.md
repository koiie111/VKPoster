# Безопасность каркаса

Сводка модели: `docs/plans/00-master-plan.md` §5. Чек-лист для каждого PR: `docs/plans/ENGINEERING_RULES.md` §4. Здесь описано, что уже реализовано в ядре (этап 01).

## Сессии
- Хранилище: Redis, ключ `sess:<sha256(id)>`; дамп Redis не раскрывает годные id. Данные в JSON (без `unserialize`).
- Id: 32 случайных байта в hex. Id из cookie принимается только если он существует в хранилище и соответствует формату, поэтому подсунуть свой id (session fixation) нельзя.
- Cookie `__Host-sid` (`Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, без `Domain`). Если `APP_URL` начинается с `http://` (local), имя `sid` без `Secure`.
- `Session::regenerate()` выдаёт новый id и сразу удаляет старый: вызывать при входе и при смене привилегий.
- Таймауты: idle 2 ч (`SESSION_IDLE_TTL`), absolute 30 дней (`SESSION_ABSOLUTE_TTL`). Любая активность продлевает idle, но не absolute.
- Flash живёт ровно один следующий запрос. `flashValidation()` не сохраняет поля с `password`, `token`, `secret` в имени.

## CSRF
- Токен (32 байта) лежит в сессии и создаётся при первом обращении. Форма отправляет `_token` (`{{ csrf_field() }}`), htmx и fetch — заголовок `X-CSRF-Token` (мета-тег `{{ csrf_meta() }}`, заголовок добавляет `public/assets/js/app.js`).
- Сравнение через `hash_equals`. Дополнительно: `Sec-Fetch-Site` должен быть `same-origin` или `none`, а `Origin` (если есть) — совпадать с хостом запроса; `Origin: null` отклоняется.
- Проверяются все методы, кроме GET/HEAD/OPTIONS. GET ничего не меняет. Отказ от проверки только через `->withoutCsrf()` у маршрута (вебхуки с подписью, API с токеном).
- Ответ при провале: 419.

## CSP и заголовки
`default-src 'self'; script-src 'self' 'nonce-<на запрос>'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`. Без `unsafe-inline` и `unsafe-eval`:
- Alpine подключён CSP-сборкой (`@alpinejs/csp`), выражения в атрибутах только простые, сложную логику выносим в `Alpine.data()`.
- htmx настроен мета-тегом `htmx-config`: `allowEval=false`, `includeIndicatorStyles=false`.
- Скрипты подключаются с `nonce="{{ csp_nonce() }}"`; inline `<script>` и обработчики `onclick` запрещены.

Остальное: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (камера, микрофон, геолокация закрыты), `Cross-Origin-Opener-Policy: same-origin`, HSTS (`max-age=31536000; includeSubDomains`) в production. Заголовки получают и страницы ошибок.

## Шифрование (`Crypto`)
- libsodium `crypto_secretbox` (XSalsa20-Poly1305), случайный nonce на каждое сообщение. Формат: `v1:<key_id>:<base64(nonce|cipher)>`.
- Подмена шифртекста, неверный ключ, неизвестный `key_id`, битый формат дают `CryptoException`.
- Ключи: `APP_KEY` (текущий, base64 от 32 байт, `bin/console key:generate`), `APP_KEY_ID` (по умолчанию `k1`), `APP_KEYS` (старые ключи `id:base64,…` только для расшифровки).

### Ротация ключа
1. `bin/console key:generate` → новый ключ.
2. В `.env`: старый `APP_KEY` переносим в `APP_KEYS=k1:<старый>`, новый ставим в `APP_KEY`, а `APP_KEY_ID=k2`. Деплой. Старые данные читаются, новые пишутся ключом `k2`.
3. Перешифровываем строки в БД: `Crypto::needsRotation()` находит записи со старым ключом, `Crypto::rotate()` перешифровывает (команда-обёртка появится вместе с таблицами секретов, этап 06).
4. Когда `needsRotation` ничего не находит, убираем старый ключ из `APP_KEYS`.

Подписи (`Signer`) используют ключ, выведенный через HKDF из текущего `APP_KEY`: после ротации ранее выданные подписанные ссылки перестают проверяться, это ожидаемо (у них и так короткий TTL).

## Подписанные ссылки (`Signer`)
HMAC-SHA256 по пути и отсортированным параметрам, параметры `expires` и `signature`. Проверка через `hash_equals`; просроченная, изменённая или неподписанная ссылка отклоняется.

## Пароли
`PasswordHasher`: Argon2id (память 64 МБ, 4 прохода, 1 поток; настраивается `ARGON_*`), `needsRehash()` для прозрачного обновления хэшей.

## Rate limit
`RateLimiter`: скользящее окно на Redis (sorted set, атомарно в Lua). Заблокированные попытки не продлевают бан. Middleware `RateLimit` ограничивает по IP (`bucket`, `max`, `seconds`), ответ 429 с `Retry-After`. Ключ учитывает только доверенные прокси (см. `TRUSTED_PROXIES`).

## SSRF (`SsrfGuard`, `GuzzleHttpClient`)
Любой запрос по URL, который пришёл от пользователя (RSS, медиа по ссылке), выполняется с опцией `user_url => true`:
- только `http`/`https`, порты 80, 443, 8080, 8443, без `user:pass@`;
- все адреса, в которые резолвится имя, должны быть публичными: запрещены loopback, `10/8`, `172.16/12`, `192.168/16`, link-local (`169.254.169.254`), CGNAT `100.64/10`, IPv6 `::1`, `fc00::/7`, `fe80::/10`, IPv4-mapped IPv6;
- соединение привязывается к проверенному IP (`CURLOPT_RESOLVE`), повторного DNS нет;
- редиректы идут вручную (максимум 5), каждый переход проверяется заново.

Таймауты по умолчанию: connect 5 с, всего 20 с. Журнал запросов не содержит заголовков и тел.

## Логи
JSON-строки в `storage/logs/app.log` и stderr. `SecretRedactor` маскирует значения по ключам `password`, `secret`, `token`, `authorization`, `cookie`, `api_key`, `private_key`, а также одноразовые коды (`code`, `oauth_code`, `otp_code`…) на любой глубине `context` и `extra`. Читаемыми остаются `error_code` и `status_code`.

## Шаблоны и вывод
Twig с автоэкранированием `html`; `strict_variables` включён вне production. `|raw` не используем. Редиректы только относительные (`Response::redirect`) или на белый список хостов.

## БД
`PDO::ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, `utf8mb4`, `time_zone='+00:00'`. `QueryBuilder` принимает значения только через биндинги, а имена таблиц и колонок проверяет по шаблону `[A-Za-z_][A-Za-z0-9_]*`; операторы и направление сортировки берутся из белого списка; `DELETE` без `WHERE` запрещён.

## Что приедет позже
Аутентификация пользователей, 2FA, роли и политики (этапы 02–04), загрузки файлов (05), подпись вебхуков (06+), hardening и pentest-чек-лист (19).
