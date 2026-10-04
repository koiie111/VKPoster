# VKPoster

Сервис отложенного постинга в VK, MAX, Telegram и Instagram. Чистый PHP 8.3 (без фреймворков), MySQL 8, Redis, всё запускается в Docker. Интерфейс на русском.

> Репозиторий переписывается с нуля по плану из [docs/plans/](docs/plans/). Старый код доступен в теге `legacy-v0`.

## Быстрый старт

Нужен только Docker. PHP на хосте не требуется.

```bash
make init   # создаёт .env и генерирует APP_KEY
make up     # собирает образы, поднимает стек, ставит composer-зависимости
make check  # cs + stan + test + audit + docs
```

- Приложение: http://localhost:8080 (проверка здоровья: http://localhost:8080/healthz → `{"db":"ok","redis":"ok"}`)
- Mailpit (письма): http://localhost:8025
- Порты опубликованы только на `127.0.0.1`.

## Команды make

| Команда | Что делает |
|---|---|
| `make init` | `.env` из `.env.example` + `APP_KEY` |
| `make up` / `make down` | поднять / остановить стек |
| `make build` | пересобрать образы |
| `make sh` / `make logs` | shell в контейнере `app` / логи |
| `make console CMD="…"` | `bin/console …` в контейнере |
| `make migrate` / `make seed` | миграции / сиды |
| `make test` | PHPUnit (Unit, Integration, Feature) |
| `make stan` | PHPStan level 8 + strict-rules |
| `make cs` / `make cs-fix` | проверка / исправление стиля (PSR-12) |
| `make css` / `css-watch` / `css-check` | сборка Tailwind CSS, пересборка на лету, проверка актуальности |
| `make ui-snap STAGE=NN` / `make a11y` / `make ui-behavior` | скриншоты и axe-core / только axe / проверка поведения компонентов |
| `make audit` | `composer audit` |
| `make docs` | справочник API кода (phpDocumentor) в `docs/reference/` |
| `make check` | всё вместе: `cs` + `stan` + `test` + `audit` + `docs` |

Профили: `docker compose --profile s3 up -d` (MinIO), `docker compose --profile tunnel up -d` (публичный HTTPS через cloudflared для OAuth/вебхуков). Сброс БД: `docker compose down -v`.

## Документация

- [docs/plans/](docs/plans/): мастер-план, этапы, прогресс, правила разработки, промт агента
- [docs/architecture/](docs/architecture/): [обзор](docs/architecture/overview.md), [путь запроса](docs/architecture/request-lifecycle.md), [безопасность](docs/architecture/security.md), [очередь](docs/architecture/queue.md), конфигурация, схема БД
- [docs/adr/](docs/adr/): архитектурные решения
- [docs/CHANGELOG.md](docs/CHANGELOG.md)

Разработка ведётся этапами: ветка `stage-NN-slug` → PR → зелёный CI → squash-merge в `main`. В `main` напрямую не коммитим.
