# Архитектура: обзор (черновик)

> Статус: этап 00. Полное описание появится на этапе 01 вместе с каркасом (`docs/architecture/request-lifecycle.md`).

## Что есть сейчас
- `public/index.php` — единственная точка входа nginx; пока заглушка `OK` и `/healthz`.
- `src/Kernel/HealthCheck.php` — проверка MySQL и Redis для `/healthz` (временная реализация).
- `bin/console` — заглушка CLI (`migrate`, `seed`, `queue:work`, `schedule:run`); настоящий консольный каркас — этап 01.

## Сервисы Docker (dev)
| Сервис | Назначение |
|---|---|
| `nginx` | Отдаёт `public/`, всё остальное → `index.php`; порт `127.0.0.1:8080` |
| `app` | PHP-FPM 8.3 (образ `dev` с pcov и composer), не от root (uid 1000) |
| `worker` | `bin/console queue:work` |
| `scheduler` | `bin/console schedule:run` |
| `mysql` | основная БД `app` и тестовая `app_test` |
| `redis` | сессии, rate limit, кэш |
| `mailpit` | ловушка писем, `127.0.0.1:8025` |
| `minio` (профиль `s3`), `tunnel` (профиль `tunnel`) | S3 и публичный HTTPS-туннель для ручных проверок |

Целевая структура каталогов, схема данных и конвейер публикации — в `docs/plans/00-master-plan.md` §4.

## Образы
`docker/php/Dockerfile`: `base` → `dev` (pcov, composer) и `vendor` → `prod` (без dev-зависимостей, `opcache.validate_timestamps=0`). Решение про Debian вместо Alpine — `docs/adr/0002-debian-php-image.md`.
