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
    workspaces ||--o{ media_folders : has
    workspaces ||--o{ media : owns
    workspaces ||--o{ watermarks : has
    media_folders |o--o{ media : holds
    workspaces ||--o{ channels : connects
    workspaces ||--o{ platform_credentials : stores
    workspaces ||--o{ channel_connect_codes : issues
    platform_credentials |o--o{ channels : publishes_with
    channels ||--o{ member_channel_access : restricts
    channels |o--o{ channel_connect_codes : created_by
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
    workspaces {
        bigint id PK
        char26 public_id UK "ULID in URLs"
        bigint owner_id FK
        varchar name
        varchar timezone
        varchar locale
        bool is_personal
    }
    workspace_members {
        bigint workspace_id PK
        bigint user_id PK
        char26 public_id UK
        varchar role "owner | admin | editor | author | viewer | client"
        bool channels_restricted
        bigint invited_by
    }
    invitations {
        bigint id PK
        char26 public_id UK
        bigint workspace_id FK
        varchar email
        varchar role
        char64 token_hash UK "SHA-256"
        datetime6 expires_at
        datetime6 accepted_at
        datetime6 revoked_at
    }
    member_channel_access {
        bigint workspace_id PK
        bigint user_id PK
        bigint channel_id PK "FK to channels, CASCADE"
    }
    media_folders {
        bigint id PK
        char26 public_id UK
        bigint workspace_id FK
        varchar name "UK with workspace_id"
    }
    media {
        bigint id PK
        char26 public_id UK "ULID in URLs"
        bigint workspace_id FK
        bigint uploader_id FK "SET NULL"
        bigint folder_id FK "SET NULL"
        varchar kind "image | video | document"
        varchar original_name "display only"
        varchar storage_key "internal"
        varchar thumb_key
        varchar mime "detected from content"
        bigint size "stored bytes, counts toward the quota"
        int width
        int height
        int duration_ms
        varchar codec
        bool animated
        char64 sha256 "of the upload; UK with workspace_id"
        text variants_json "cache of crops and watermarked copies"
    }
    watermarks {
        bigint id PK
        char26 public_id UK
        bigint workspace_id FK
        varchar name
        varchar storage_key
        int width
        int height
        char2 position "tl tc tr ml mc mr bl bc br"
        tinyint opacity
        tinyint scale
        tinyint margin
        bool is_default
    }
    channels {
        bigint id PK
        char26 public_id UK "ULID in URLs"
        bigint workspace_id FK
        varchar platform "telegram | vk | max | instagram | fake"
        varchar external_id "chat id on the platform, UK with workspace_id and platform"
        varchar mode "shared_bot | own_bot"
        varchar title "from the platform"
        varchar alias "name for the team only"
        varchar username
        varchar kind "channel | group"
        varchar avatar_key "our copy of the picture"
        varchar status "active | paused | error | revoked"
        bigint credential_id FK "SET NULL, empty for the shared bot"
        text settings_json "rights the bot has: post edit delete pin"
        datetime6 last_health_at
        varchar last_error
        bigint created_by FK "SET NULL"
    }
    platform_credentials {
        bigint id PK
        char26 public_id UK
        bigint workspace_id FK
        varchar platform
        varchar kind "bot_token | oauth"
        text secret_enc "Crypto ciphertext only"
        text refresh_enc "Crypto ciphertext only"
        datetime6 expires_at
        varchar scopes
        varchar hint "masked token for display"
    }
    channel_connect_codes {
        bigint id PK
        char26 public_id UK
        bigint workspace_id FK
        bigint user_id FK
        varchar platform
        char64 code_hash "SHA-256, UK"
        bigint channel_id FK "SET NULL"
        varchar failure "why the last attempt did not connect"
        datetime6 expires_at "15 minutes"
        datetime6 used_at
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

Медиатека (`media_folders`, `media`, `watermarks`) принадлежит пространству и удаляется вместе с ним; подробности и правила хранения файлов: [modules/media.md](modules/media.md). Каналы (`channels`, `platform_credentials`, `channel_connect_codes`) принадлежат пространству и удаляются вместе с ним; секреты лежат только в шифрованных столбцах `*_enc`, подробности: [modules/channels.md](modules/channels.md). Посты (`posts`, `post_variants`, `publications`, `publication_attempts`, `post_templates`) принадлежат пространству; при отключении канала `channel_id` в вариантах и публикациях становится NULL, а история остаётся; уведомления (`notifications`, `notification_settings`, `telegram_links`, `telegram_link_tokens`) принадлежат пользователю; подробности: [modules/posts.md](modules/posts.md). Связанные таблицы удаляются каскадом вместе с пользователем (в том числе его пространства и членства; см. [modules/workspaces.md](modules/workspaces.md)). `audit_log` без внешних ключей: журнал переживает удаление пользователей. Счётчики неудачных входов хранятся в Redis (`auth:fail:*`, `auth:lock:*`), а не в БД.

Команды: `make migrate`, `make console CMD="migrate:status"`, `migrate:rollback` (последний batch), `migrate:fresh` (удаляет все таблицы; в production отключена), `seed` (dev-данные из `database/seeds/`; в production отключена).
