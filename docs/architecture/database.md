# Схема БД

Статус: этап 00 — таблиц пока нет. Миграции появятся с этапа 01 (`database/migrations/`); каждая миграция обновляет этот документ.

Общие правила: MySQL 8.0, InnoDB, `utf8mb4_unicode_ci`, время в UTC (`DATETIME(6)`), деньги — `BIGINT` в минимальных единицах. Ключевые таблицы и связи — `docs/plans/00-master-plan.md` §4.3.

Базы в dev: `app` (приложение) и `app_test` (тесты, создаётся `docker/mysql/init/01-test-db.sql` при первом старте MySQL; для пересоздания: `docker compose down -v`).
