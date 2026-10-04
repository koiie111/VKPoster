# Changelog

Формат: [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/).

## [Unreleased]

### Added
- Этап 03: вход и регистрация через VK ID, Яндекс ID, Google (OIDC с проверкой подписи `id_token`) и Telegram Login Widget; PKCE, `state` и `nonce`; правила сопоставления аккаунтов (подтверждённая почта не склеивает аккаунты без пароля); отдельный шаг согласия на обработку ПДн; страница «Способы входа» (привязка, отвязка, запрет отвязать последний способ, первый пароль и почта для аккаунта из соцсети); 2FA после входа через соцсеть; тестовый провайдер `/dev/oauth/fake` (`DEV_OAUTH_FAKE`); таблица `user_identities`; CSP точечно расширяется на страницах с виджетом Telegram; ADR 0004; `docs/architecture/modules/social-auth.md`, `howto-oauth-provider.md`. Демо-пользователь `social@ezposter.local` в сидере. Кнопки «Войти» и «Начать» в верхнем меню главной ведут на вход и регистрацию.
- Этап 02: регистрация и вход по почте, подтверждение почты, сброс и смена пароля, смена почты, «запомнить меня» с защитой от кражи cookie, двухфакторная защита TOTP с QR на сервере и резервными кодами, список устройств с отзывом, журнал входов, страница «Безопасность», письма (HTML и текст) через очередь, `/dev/login-as`, команды `user:create-admin` и `auth:prune`; таблицы `users`, `auth_tokens`, `user_sessions`, `recovery_codes`, `login_attempts`, `audit_log`; ADR 0003 (bacon-qr-code); `docs/architecture/modules/auth.md`.
- Этап 21 (часть B): дизайн-система направления «Индиго»: токены и темы (светлая, тёмная, как в системе), Tailwind-сборка с хэшем (`make css`, `css-watch`, `css-check`), 45 Twig-компонентов, макеты `base/app/auth/landing/admin`, витрина `/dev/ui`, прототипы онбординга, каналов, редактора с превью, календаря (месяц, неделя, список) и дашборда, Lucide-спрайт, `make ui-behavior`, страницы ошибок и главная на новых макетах; документация `docs/design/design-system.md`, `ux-writing.md`.
- Этап 21 (часть A): три варианта стиля `docs/design/directions/{a,b,c}.html`, референсы `docs/design/references.md`, `tailwind.config.js`, шрифт Inter (self-hosted), инструмент `tools/ui-snap` (Playwright + axe-core, `make ui-snap STAGE=NN`, `make a11y`).
- Этап 00: Docker-стек (nginx, php-fpm, worker, scheduler, mysql, redis, mailpit; профили `s3`, `tunnel`), `Makefile`, CI на GitHub Actions, PHPUnit, PHPStan (level 8 + strict-rules), php-cs-fixer, `/healthz`.
- Скелет документации и ADR 0001–0002.
- Этап 01: каркас приложения в `src/Kernel`: конфиг с проверками для production, DI-контейнер, Request/Response, роутер, конвейер middleware, обработка ошибок, заголовки безопасности и CSP с nonce, сессии в Redis, CSRF, PDO и QueryBuilder, миграции, консоль, валидатор с русскими сообщениями, Twig, шифрование (secretbox, ротация ключей), подписанные ссылки, rate limit, Argon2id, HTTP-клиент с защитой от SSRF, логи без секретов, очередь на MySQL (SKIP LOCKED) и планировщик.
- Правило PHPStan `RequireClassDocblockRule`: у каждого класса в `src/` должен быть docblock.
- Минимальный layout и страницы ошибок 403/404/419/429/500; htmx 2.0.4 и Alpine 3.14.9 (CSP-сборка) в `public/assets/vendor` с проверкой SHA-256.
- Документация: `request-lifecycle.md`, `security.md`, `queue.md`, обновлены `overview.md`, `configuration.md`, `database.md`.

### Changed
- Аккаунт без почты (создан через соцсеть) допускается в приложение без подтверждения почты; сброс 2FA и резервных кодов у аккаунта без пароля подтверждается кодом из приложения.
- `make init` пересоздаёт `APP_KEY`, если он не раскодируется в ровно 32 байта (раньше проверялась только длина строки).
- `Container::has()` больше не считает автосоздаваемыми классы без возможности создания (например, `Closure`); раньше это ломало автосборку `HttpClientInterface`.
- В тестах используется Redis db 15 (`REDIS_DB`), чтобы не затирать данные разработки.
- Layout приложения показывает демо-данные (рабочие пространства, тариф) только на прототипах; у вошедшего пользователя только существующие разделы.
- `make init` теперь определяет, что `APP_KEY` уже задан, по самому значению (раньше комментарий в строке `.env` считался значением).
- Образ PHP: добавлено расширение `pcntl`.
- `/healthz` и главная страница обслуживаются через `Application`; заглушки `bin/console` и `public/index.php` удалены.
- Название продукта: `ezposter` (`APP_NAME`).

### Removed
- Весь legacy-код (остался в теге `legacy-v0`), `vendor/` больше не коммитится.
