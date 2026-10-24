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

Production использует `CRYPTO_BACKEND=vault`: purpose-isolated Transit, без выдачи
master encryption/HMAC/pepper keys PHP-процессу. Новый ciphertext:
`v3:<purpose>:vault:vN:...`. App/webhook/PDF/password/links/clicks имеют отдельные
non-exportable keys; подмена purpose блокируется. См. [ADR0025](../adr/0025-purpose-isolated-crypto.md).
Startup отвергает Sodium, APP_KEY/APP_KEYS и локальные pepper files в production.
Readiness проверяет доступность и безопасную policy каждого purpose. Ошибка сервиса
не включает local fallback. PDF renderer получает отдельный verify-only token.

Для local/testing сохраняется Sodium secretbox: `v1:<key_id>:<nonce|cipher>`;
APP_KEY/APP_KEYS используются только в этой явно локальной конфигурации. Для
существующих encrypted values — отдельный offline CLI migration с private old
keyring file, CAS, dry-run и возобновлением после сбоя; обычный production app старые
raw keys не получает. Production rotation использует remote rewrap. Backup/recovery
должны сохранять исходную БД и её key recovery set до проверенного завершения миграции.

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

## Аутентификация (этап 02)
Подробности и сценарии: [modules/auth.md](modules/auth.md). Коротко:
- Вход: общая ошибка «неверная почта или пароль», фиктивная проверка хэша для неизвестных адресов, блокировка аккаунта с нарастающей задержкой (с 5-й неудачи: 30 с, 60 с… до 15 мин; одинаково для несуществующих адресов), письмо владельцу после 10 неудач, лимит 20 попыток за 10 минут с IP.
- Регистрация и сброс пароля не раскрывают, есть ли аккаунт: ответ одинаковый, существующему адресу уходит письмо «у вас уже есть аккаунт».
- Одноразовые ссылки (подтверждение почты, сброс пароля, смена почты): 32 случайных байта, в БД только SHA-256, TTL, атомарное погашение, привязка к типу; GET показывает страницу с кнопкой, действие выполняет POST (сканеры писем не гасят ссылки).
- Токены в очереди писем шифруются (`Crypto`), чтобы одноразовые ссылки не лежали в таблице `jobs` открытым текстом.
- Сессия получает новый id и новый CSRF-токен при входе; устройство записывается в `user_sessions`, отзыв устройства сразу уничтожает сессию в Redis. `Authenticate` каждый запрос сверяет сессию с `user_sessions` и статусом пользователя.
- «Запомнить меня»: `selector:validator`, в БД хэш валидатора, ротация при каждом использовании, 60 секунд льготы для параллельных запросов; повтор старого валидатора = кража: удаляются все remember-токены и все сессии пользователя.
- 2FA: TOTP (SHA-1, 6 цифр, 30 с, окно ±1 шаг), секрет шифруется, каждый шаг принимается один раз (`totp_last_step`), 5 попыток за 10 минут; 10 резервных кодов хранятся хэшами. Смена пароля, почты и отключение 2FA требуют текущий пароль (5 попыток за 10 минут).
- После смены или сброса пароля завершаются все остальные сессии и remember-токены, владельцу приходит письмо.
- Пароли: 12–128 символов, не из локального списка частых паролей (`resources/data/common-passwords.txt`), опционально проверка по HIBP (`PASSWORD_HIBP`).

## Что приедет позже
Роли и политики (этап 04), загрузки файлов (05), подпись вебхуков (06+), hardening и pentest-чек-лист (19).

## Админка и вход под пользователем (этап 11)
- `/admin` доступна только суперадмину с включённой 2FA и свежим кодом (8 часов); остальные получают 404. Сессия «запомнить меня» кода не требует при входе, поэтому для админки код спрашивается отдельно.
- Вход под пользователем: время жизни 1 час, записи в аудит со сменой `meta.impersonator`, закрыты оплата, безопасность аккаунта и удаление пространства (`DenyWhenImpersonating`). Нельзя войти под сотрудником или заблокированным человеком.
- Блокировка пользователя завершает все его сессии. Содержимое упавших задач и секреты в админке не показываются.
- `app_settings` меняется только из админки; выключатель сети не может включить сеть, которой нет в `PLATFORMS_ENABLED`.

## Полная админка (этап 20)
- Роли персонала (`StaffRole`) и матрица прав в коде (`config/admin_permissions.php`); каждый маршрут `/admin` называет одно право, обход всех маршрутов в тесте проверяет «роль × маршрут» и 404 для всех остальных. Список адресов `ADMIN_IP_ALLOWLIST` скрывает админку (404) от чужих адресов.
- Замок админки через 30 минут без запросов (`RequireAdminUnlock::IDLE`) и обязательная 2FA для любого сотрудника.
- Опасные действия требуют свежий одноразовый код в самой форме (`StepUp`): возврат, блокировка, начисления, вход под пользователем, цены, сброс 2FA, анонимизация, запуск рассылки, режим обслуживания, закрытие регистрации, назначение ролей.
- Аудит всех изменений: `AdminAuditTrail` (каждый не-GET запрос, без тела) и смысловые записи с `before`/`after`, IP и сотрудником (при входе под пользователем запись помечается `impersonator`).
- Данные людей: выгрузка 152-ФЗ только по id одного человека без секретов; анонимизация сохраняет только финансовые записи; копии уведомлений провайдеров маскируют карту, контакты, токены и подписи (`WebhookMask`); последние ошибки журнала показываются без контекста.
- Рассылки только согласившимся, отписка по подписанной ссылке (`Signer`), одноклик RFC 8058 без CSRF-токена (подпись заменяет его); письма сервиса и тексты сайта проходят через экранирующий `Markdown`, значения подстановок вставляются как текст.
- Цвета сайта проходят проверку формата `#rrggbb` и хранятся как тройки чисел; CSS собирается только из них и отдаётся с `/theme.css` (inline-стили запрещены CSP).
- Экспорты CSV защищены от формул в таблицах (`Support\Csv`).


## Stage 19A authentication / key isolation (in progress)

See [ADR 0023](../adr/0023-authentication-hardening.md) and the
[12-item ASVS remediation matrix](../security/asvs-remediation-stage19a.md).
Passwords require 12 characters after repeated spaces are combined for length only.
The shared password control has local reveal/strength feedback. Admin CLI bootstrap
prints an existing one-use password-reset activation link (1-hour TTL), not a generated
long-term password. Treat that capability as secret; never run bootstrap into CI logs.

Production password pepper is a dedicated remote Transit HMAC purpose before
Argon2id. Hashes store the server key version; verification retains old versions
through rotation and successful login upgrades them. Raw password pepper never
enters PHP. Local/testing can still exercise private file-based legacy pepper;
those files are forbidden in normal production configuration. Inventory/reset any
experimental local pepper:v1 hashes before cutover; do not silently lock users out.

WebAuthn is an alternative second factor for users who already enrolled MFA: fresh
password (if present) plus TOTP/recovery proof before enrollment or revocation.
`webauthn_credentials` holds only public keys, counters and audit/revocation metadata;
`webauthn_challenges` binds user/session/purpose for 2 minutes and atomically consumes
once. Exact configured origin, user presence/verification and library signatures are
required. Reset/disable of MFA revokes hardware keys/challenges. No discoverable-user
or passwordless-first-factor flow. Classic U2F without user verification is not claimed.
Virtual-authenticator tests do not replace real hardware at the production RP origin.

`VaultTransit` uses the existing HTTP boundary, private token/CA files, bounded
responses/timeouts, no redirects and sanitized exceptions. ShortLinks uses remote
click HMAC; Signer uses versioned remote MAC/verify; AnalyticsPdf uses remote sign
and a renderer capability restricted to PDF verification. Egress observations use
an independent non-authority telemetry salt, never APP_KEY. All consumers and
migration/recovery evidence are inventoried in ADR0025. V1.6.2/V6.4.2 code boundaries
are implemented; actual production custody/HA/restore is owner19.8, not local conformance.

`MALWARE_SCANNER=clamd` is required by the production service binding; Fake is dev/test
only. Private INSTREAM scans cover media uploads, Source images and external downloads
before decode/archive. Bounds: max 50 MiB, total deadline 15 s, 1 KiB response, exact
clean response; infected=422 and unavailable=503. Readiness checks a bounded empty
scan. No original bytes/filenames/signature details are logged. Production ClamAV
uses a separate signature volume, no host port, resource limits and automatic official
signature updates. Local runtime tests use a clearly synthetic tiny signature database;
real CVD freshness/update failure and old asset quarantine are owner steps in 19.8.
Official scanner image currently requires amd64, with emulation for this ARM64 local
stand. Plan 4 GiB for production signature reload and verify the VDS memory budget.
