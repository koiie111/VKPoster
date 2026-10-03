# Changelog

Формат: [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/).

## [Unreleased]

### Added
- Этап 00: Docker-стек (nginx, php-fpm, worker, scheduler, mysql, redis, mailpit; профили `s3`, `tunnel`), `Makefile`, CI на GitHub Actions, PHPUnit, PHPStan (level 8 + strict-rules), php-cs-fixer, `/healthz`.
- Скелет документации и ADR 0001–0002.
- Этап 01: каркас приложения в `src/Kernel`: конфиг с проверками для production, DI-контейнер, Request/Response, роутер, конвейер middleware, обработка ошибок, заголовки безопасности и CSP с nonce, сессии в Redis, CSRF, PDO и QueryBuilder, миграции, консоль, валидатор с русскими сообщениями, Twig, шифрование (secretbox, ротация ключей), подписанные ссылки, rate limit, Argon2id, HTTP-клиент с защитой от SSRF, логи без секретов, очередь на MySQL (SKIP LOCKED) и планировщик.
- Правило PHPStan `RequireClassDocblockRule`: у каждого класса в `src/` должен быть docblock.
- Минимальный layout и страницы ошибок 403/404/419/429/500; htmx 2.0.4 и Alpine 3.14.9 (CSP-сборка) в `public/assets/vendor` с проверкой SHA-256.
- Документация: `request-lifecycle.md`, `security.md`, `queue.md`, обновлены `overview.md`, `configuration.md`, `database.md`.

### Changed
- `make init` теперь определяет, что `APP_KEY` уже задан, по самому значению (раньше комментарий в строке `.env` считался значением).
- Образ PHP: добавлено расширение `pcntl`.
- `/healthz` и главная страница обслуживаются через `Application`; заглушки `bin/console` и `public/index.php` удалены.
- Название продукта: `ezposter` (`APP_NAME`).

### Removed
- Весь legacy-код (остался в теге `legacy-v0`), `vendor/` больше не коммитится.
