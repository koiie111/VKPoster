# Схема БД

Статус: этап 01 — созданы только служебные таблицы очереди. Миграции лежат в `database/migrations/` (файл возвращает анонимный класс `Migration` с `up()` и `down()`); каждая миграция обновляет этот документ.

Общие правила: MySQL 8.0, InnoDB, `utf8mb4_unicode_ci`, время в UTC (`DATETIME(6)`), деньги — `BIGINT` в минимальных единицах. Ключевые таблицы и связи — `docs/plans/00-master-plan.md` §4.3.

Базы в dev: `app` (приложение) и `app_test` (тесты, создаётся `docker/mysql/init/01-test-db.sql` при первом старте MySQL; для пересоздания: `docker compose down -v`).

## Служебные таблицы

`migrations(id, migration UNIQUE, batch)` — учёт применённых миграций (создаётся `Migrator`).

`jobs` и `failed_jobs` — очередь задач (подробности и жизненный цикл в `queue.md`):

```mermaid
erDiagram
    jobs {
        bigint id PK
        varchar queue
        json payload_json
        datetime6 available_at
        datetime6 reserved_at
        varchar reserved_by
        smallint attempts
        smallint max_attempts
        text last_error
        datetime6 created_at
    }
    failed_jobs {
        bigint id PK
        varchar queue
        json payload_json
        smallint attempts
        text error
        datetime6 failed_at
    }
    user_identities {
        bigint id PK
        bigint user_id FK "ON DELETE CASCADE"
        varchar provider "vkid | yandex | google | telegram"
        varchar provider_user_id "UNIQUE вместе с provider"
        varchar email "что сообщил провайдер, справочно"
        varchar display_name
        datetime6 linked_at
        datetime6 last_login_at
    }
```

### Аутентификация (этап 02)

```mermaid
erDiagram
    users ||--o{ auth_tokens : has
    users ||--o{ user_sessions : has
    users ||--o{ recovery_codes : has
    users ||--o{ login_attempts : has
    users ||--o{ user_identities : has
    users {
        bigint id PK
        varchar email UK "NULL для входа через соцсети"
        datetime6 email_verified_at
        varchar password_hash "Argon2id, NULL без пароля"
        varchar name
        text totp_secret_enc "Crypto, v1:key:..."
        datetime6 totp_enabled_at
        bigint totp_last_step "защита от повторного кода"
        tinyint is_superadmin
        varchar status "active | blocked"
        varchar consent_version
        datetime6 consent_at
    }
    auth_tokens {
        bigint id PK
        bigint user_id FK
        varchar type "email_verify | password_reset | email_change | remember"
        char24 selector UK "только remember"
        char64 token_hash UK "SHA-256"
        json payload_json "новый email / предыдущий validator"
        datetime6 expires_at
        datetime6 used_at
    }
    user_sessions {
        bigint id PK
        char26 public_id UK "ULID для URL"
        bigint user_id FK
        char64 session_id_hash UK "SHA-256 id = ключ в Redis"
        varchar ip
        varchar user_agent
        datetime6 last_seen_at
        datetime6 revoked_at
    }
    recovery_codes {
        bigint id PK
        bigint user_id FK
        char64 code_hash "SHA-256"
        datetime6 used_at
    }
    login_attempts {
        bigint id PK
        bigint user_id FK
        varchar outcome "success | bad_password | bad_2fa | blocked"
        varchar ip
        datetime6 created_at
    }
    audit_log {
        bigint id PK
        bigint workspace_id
        bigint actor_id
        varchar action "auth.login, auth.password.changed..."
        varchar subject_type
        varchar subject_id
        varchar ip
        json meta_json
        datetime6 created_at
    }
```

Связанные таблицы удаляются каскадом вместе с пользователем. `audit_log` без внешних ключей: журнал переживает удаление пользователей. Счётчики неудачных входов хранятся в Redis (`auth:fail:*`, `auth:lock:*`), а не в БД.

Команды: `make migrate`, `make console CMD="migrate:status"`, `migrate:rollback` (последний batch), `migrate:fresh` (удаляет все таблицы; в production отключена), `seed` (dev-данные из `database/seeds/`; в production отключена).
