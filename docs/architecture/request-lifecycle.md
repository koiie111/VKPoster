# Путь HTTP-запроса

```
браузер → (reverse proxy / TLS) → nginx → php-fpm → public/index.php
        → Application::create()   конфиг, контейнер, маршруты (падает при плохой конфигурации)
        → Request::fromGlobals()
        → Application::handle()
```

## 1. nginx
`docker/nginx/default.conf`: docroot только `public/`; файлы из `/assets/` отдаются напрямую, всё остальное уходит в `index.php`. Точки (`.env`, `.git`) закрыты, любые другие `.php` дают 404. `client_max_body_size 55m`.

## 2. Загрузка (`public/index.php`)
`Application::create()` читает `config/*.php`, регистрирует сервисы (`config/services.php`) и маршруты (`config/routes.php`). Ошибка конфигурации (нет `APP_KEY`, `APP_DEBUG=1` в production, `DEV_*` в production) пишется в `error_log` без значений, пользователь видит только «Server error».

## 3. Request и контекст
`Request::fromGlobals()` — единственное место, где читаются суперглобальные массивы. IP клиента и схема берутся из `X-Forwarded-*` только если непосредственный адрес входит в `TRUSTED_PROXIES`; `X-Forwarded-For` читается справа налево, пропуская доверенные прокси, поэтому клиент не может подделать IP. `_method` в POST-форме превращается в PUT/PATCH/DELETE. JSON-тело разбирается при `Content-Type: application/json`.

`RequestContext` хранит данные текущего запроса для middleware и Twig: сам Request, nonce для CSP (16 случайных байт на запрос) и сессию.

## 4. Маршрутизация
`Router::match()` вызывается до конвейера, чтобы middleware (CSRF) знали, какой маршрут сработал. Результат кладётся в атрибут `route` запроса. 404 и 405 (с заголовком `Allow`) выбрасываются уже внутри конвейера, поэтому тоже проходят через `SecurityHeaders` и `ErrorHandler`.

## 5. Конвейер middleware
Порядок (снаружи внутрь):

1. `SecurityHeaders` — CSP с nonce, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, HSTS в production, `Cache-Control: no-store` по умолчанию.
2. `ErrorHandler` — превращает исключения в ответы (см. ниже).
3. `StartSession` — читает cookie `__Host-sid` (`sid` на http в local), загружает сессию из Redis, после ответа сохраняет её и ставит cookie при новой или пересозданной сессии.
4. `VerifyCsrf` — для всех методов, кроме GET/HEAD/OPTIONS, проверяет токен и `Origin`/`Sec-Fetch-Site`, иначе 419. Маршрут может отказаться: `->withoutCsrf()` (вебхуки, API с токеном).
5. Middleware маршрута или группы: `Authenticate`, `RateLimit` и т. д. Параметры задаются так: `[RateLimit::class, ['bucket' => 'login', 'max' => 5, 'seconds' => 60]]`.
6. Контроллер.

`SecurityHeaders` стоит снаружи `ErrorHandler` намеренно (отклонение от порядка в плане этапа 01): страницы ошибок 404/419/500 должны получать те же заголовки и CSP, что и обычные страницы. Побочный эффект: если исключение вылетело из запроса, сессия не сохраняется (изменения неудавшегося запроса не фиксируются).

Анонимный посетитель получает сессию только когда в неё что-то записано (например, при показе страницы с CSRF-токеном). `/healthz` и ответы без токена cookie не создают.

## 6. Контроллер
Метод вызывается через `Container::call`: параметр `$request` получает `Request`, параметры с именами из маршрута (`{id}`) получают строки, остальное подставляется из контейнера по типу. Возвращать можно `Response`, строку (HTML) или массив (JSON).

## 7. Ошибки (`ErrorHandler`)
| Исключение | Ответ |
|---|---|
| `HttpException` (404, 405, 419, 429…) | страница из `templates/errors/` или JSON для `/api/*` и `Accept: application/json`; заголовки исключения (`Allow`, `Retry-After`) копируются в ответ |
| любое другое | 500; в лог пишется исключение и `error_id` (ULID), пользователю показывается страница без деталей и тот же `error_id` для обращения в поддержку |

Трассировка показывается только при `APP_DEBUG=1`, а в production такая конфигурация не запускается. Если не удалось отрисовать страницу ошибки, отдаётся короткий текст.

## 8. Ответ
`Response::send()` выставляет статус, заголовки и cookie. Для HEAD тело не отправляется. Редиректы только на относительные URL (`Response::redirect`) или на хосты из белого списка (`redirectToTrusted`).

## CLI
`bin/console` собирает то же приложение и запускает `Console` (список команд: `bin/console list`). `worker` и `scheduler` в Docker — это `queue:work` и `schedule:run`.
