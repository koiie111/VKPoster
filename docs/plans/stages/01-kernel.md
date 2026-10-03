# Этап 01 — Ядро приложения

**Зависимости:** 00 · **Ветка:** `stage-01-kernel`

## Цель
Самописный мини-каркас, на котором дальше строится всё. Безопасные значения по умолчанию заложены здесь.

## Задачи
- [x] `Kernel\Config` — загрузка `config/*.php`, значения из env, типизированные геттеры, падение при отсутствии обязательных переменных. При `APP_ENV=production` запрещены `APP_DEBUG=1` и `DEV_*`-флаги.
- [x] `Kernel\Container` — autowiring через Reflection, синглтоны, явные фабрики в `config/services.php`.
- [x] `Kernel\Http\Request/Response` — неизменяемый Request (query, body, json, files, headers, cookies, attributes), учёт `TRUSTED_PROXIES` для IP и схемы.
- [x] `Kernel\Http\Router` — маршруты в `config/routes.php`, методы, параметры с регулярками (`{id:[0-9A-Z]{26}}`), группы с префиксом и middleware, именованные маршруты + `url()`. 404/405.
- [x] Middleware pipeline (PSR-15-подобный, свой интерфейс): `ErrorHandler` → `SecurityHeaders` → `StartSession` → `VerifyCsrf` → маршрутные (`Authenticate`, `RateLimit` …).
- [x] `ErrorHandler`: в prod — красивая страница 500 без деталей + id ошибки в логе; в local — детали. JSON-ошибки для `/api/*`.
- [x] `SecurityHeaders` + `Csp` (nonce на запрос, `default-src 'self'; script-src 'self' 'nonce-…'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`), HSTS в prod.
- [x] `Session`: Redis-хранилище, cookie `__Host-sid` (в local без `__Host-` при http), `regenerate()`, flash, idle/absolute timeouts.
- [x] `Csrf`: токен в сессии, проверка `_token` или заголовка `X-CSRF-Token` + проверка `Origin`, Twig-функция `csrf_field()`, мета-тег для htmx.
- [x] `Database\Connection` (PDO, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, utf8mb4, `time_zone='+00:00'`), `transaction(callable)`, минимальный QueryBuilder (select/insert/update/delete с биндингом, без сырых строк от пользователя).
- [x] `Migrator` + команды `migrate`, `migrate:rollback`, `migrate:status`, `migrate:fresh` (только не в prod), `seed`.
- [x] `Kernel\Console` + `bin/console`.
- [x] `Validation\Validator`: required, string, int, email, min/max, in, regex, url (http/https), timezone, date, array, file (mime/size), confirmed; сообщения на русском.
- [x] `View`: Twig (кэш в `storage/cache/twig`, `strict_variables` в local), расширения `csrf_field`, `csp_nonce`, `asset` (с версией по хэшу), `url`, `t`, `old`, `errors`.
- [x] `Security\Crypto` (libsodium secretbox, формат `v1:<key_id>:<base64(nonce|cipher)>`, ротация ключей `APP_KEYS`), `Signer` (HMAC для подписанных URL), `RateLimiter` (Redis, скользящее окно), `PasswordHasher` (Argon2id).
- [x] `HttpClient`: обёртка над Guzzle, таймауты по умолчанию (connect 5 с, total 20 с), `SsrfGuard` для пользовательских URL, логирование без заголовков `Authorization`; в тестах `MockHttpClient`, который падает на непредусмотренный запрос.
- [x] `Log`: Monolog JSON в `storage/logs` и stderr, процессор, вырезающий `password|token|secret|authorization|code` из контекста.
- [x] `Queue`: таблица `jobs`/`failed_jobs`, `dispatch(Job, delay)`, `queue:work` (SKIP LOCKED, graceful shutdown по SIGTERM, `max_attempts`, backoff), `schedule:run` (регистрация периодических задач в коде).
- [x] PHPStan-правило `RequireClassDocblockRule` (`tests/PHPStan/` или `tools/phpstan/`): у каждого класса в `src/` есть docblock с описанием назначения.
- [x] Документация: `docs/architecture/overview.md` и `request-lifecycle.md` (путь запроса от nginx до ответа, middleware, DI, обработка ошибок), `docs/architecture/security.md` (сессии, CSRF, CSP, шифрование, ротация ключей), `docs/architecture/queue.md`.
- [x] Минимальный layout в Twig (Alpine + htmx из `public/assets/vendor`, без CDN) и страницы ошибок 403/404/419/429/500. Внешний вид здесь не важен: дизайн-система делается на этапе 21 и заменит эти шаблоны.

## Тесты
- Router (параметры, 404/405, группы), Container, Validator, Crypto (шифрование/расшифровка/ротация/подмена шифртекста → исключение), Csrf (нет токена → 419, чужой Origin → 419), SecurityHeaders (CSP с nonce присутствует), RateLimiter, SsrfGuard (127.0.0.1, 10.x, 169.254.169.254, `[::1]`, редирект на приватный IP → запрещено), Queue (две параллельные выборки не берут одну задачу), Migrator (up/down).

## Проверка владельцем
Не требуется.

## DoD
`make check` зелёный, покрытие Kernel ≥ 80%, PHPStan level 8 без baseline.
