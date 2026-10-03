# Этап 00 — Инфраструктура, Docker, CI, удаление legacy

**Зависимости:** нет · **Ветка:** `stage-00-bootstrap`

## Цель
Пустой, но рабочий каркас: `make up` поднимает весь стек, `make check` зелёный, CI на GitHub гоняет то же самое. Старый код удалён.

## Задачи
- [ ] Поставить тег `legacy-v0` на текущий `main` и запушить тег (`git tag legacy-v0 && git push origin legacy-v0`).
- [ ] Удалить legacy: `index.php`, `modules/`, `system/`, `style/`, `js/`, `docker/init.sql`, `Dockerfile`, `docker-compose.yml`, `.htaccess`, `vendor/` (из git; добавить в `.gitignore`).
- [ ] Создать структуру каталогов из мастер-плана §4.1 (пустые каталоги с `.gitkeep`).
- [ ] `composer.json`: `php: ^8.3`, PSR-4 `App\\` → `src/`, `App\\Tests\\` → `tests/`, dev-зависимости из белого списка, скрипты `test`, `stan`, `cs`.
- [ ] `docker/php/Dockerfile` (multi-stage: `base` → `dev` с pcov и composer → `prod` с opcache preload, без dev-зависимостей), пользователь `app` (uid 1000), расширения из мастер-плана §4.2.
- [ ] `docker/php/php.ini` (prod: `expose_php=Off`, `display_errors=Off`, `session.use_strict_mode=1`, `upload_max_filesize=50M`, `memory_limit=256M`, `opcache.validate_timestamps=0`) и `php-dev.ini`.
- [ ] `docker/nginx/default.conf`: root `public/`, всё → `index.php`, запрет `/\.`, `client_max_body_size 55m`, отдача `assets/` с кэшем, `server_tokens off`.
- [ ] `compose.yaml`: nginx, app, worker, scheduler, mysql (healthcheck, `docker/mysql/init/01-test-db.sql` создаёт `app_test`), redis, mailpit, профили `s3` (minio) и `tunnel` (cloudflared). Порты только на `127.0.0.1`.
- [ ] `.env.example` со всеми переменными (APP_ENV, APP_URL, APP_NAME, APP_KEY, DB_*, REDIS_*, MAIL_*), `make init` копирует в `.env` и генерирует `APP_KEY` (`sodium_crypto_secretbox_keygen` → base64).
- [ ] `Makefile`: `init up down build sh logs console migrate seed test stan cs cs-fix audit docs check` (`console` = `bin/console $(CMD)` в контейнере; `docs` = phpDocumentor из образа `phpdoc/phpdoc` в `docs/reference/`, каталог в `.gitignore`).
- [ ] Скелет документации: `docs/architecture/overview.md` (черновик), `docs/architecture/configuration.md`, `docs/architecture/database.md`, `docs/adr/0001-plain-php-no-framework.md`, `docs/adr/0000-template.md`, `docs/CHANGELOG.md`.
- [ ] `public/index.php` — временная заглушка «OK» + `/healthz` (проверка БД и Redis, JSON).
- [ ] `phpunit.xml` (suites Unit, Integration, Feature; env `APP_ENV=testing`, `DB_DATABASE=app_test`), `phpstan.neon` (level 8, strict-rules), `.php-cs-fixer.php` (PSR-12 + `declare_strict_types`).
- [ ] Один smoke-тест (`tests/Feature/HealthTest.php`).
- [ ] `.github/workflows/ci.yml`: на `pull_request` и `push` в `main`: собрать образ `dev`, поднять mysql+redis сервисами, `composer install`, `make check`-эквивалент. Кэш composer.
- [ ] Переписать `README.md` (как запустить, команды make, ссылки на docs/plans).
- [ ] Переписать `CLAUDE.md` и создать `AGENTS.md` (одинаковое содержание: обзор, команды, ссылка на ENGINEERING_RULES и AGENT_PROMPT, правило «ветка → PR → merge»).
- [ ] Защита ветки `main` на GitHub: обязательный CI перед merge (`gh api` — если у токена нет прав, вынести в вопросы владельцу).

## Тесты
- `HealthTest`: `/healthz` → 200, `{"db":"ok","redis":"ok"}`.
- CI зелёный на PR.

## Проверка владельцем (вопросы — записать в PROGRESS.md)
1. Финальное название продукта и домен? (пока `APP_NAME=VKPoster`)
2. Есть ли юрлицо РФ (ООО/ИП)? Нужно для MAX-бота (только юрлицо), эквайринга и чеков 54-ФЗ. Есть ли иностранное юрлицо для Stripe/Paddle?
3. Где будет prod-хостинг (рекомендация для 152-ФЗ: Selectel / Timeweb Cloud / Yandex Cloud в РФ)?
4. Подтвердить, что legacy-код можно удалить из `main` (он останется в теге `legacy-v0`).
5. Выполнить у себя `make init && make up`, открыть http://localhost:8080/healthz — ответ `ok`?

## Definition of Done
`make init && make up && make check` с нуля проходит; CI зелёный; legacy удалён; ответы владельца записаны в PROGRESS.md.
