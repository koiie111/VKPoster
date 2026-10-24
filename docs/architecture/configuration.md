# Конфигурация (переменные окружения)

Источник правды — `.env.example`. `make init` копирует его в `.env` и генерирует `APP_KEY`. Реальные значения секретов в git не попадают.

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `local` | `local` · `testing` · `production`. Dev-фичи (DEV_LOGIN, Fake-адаптеры) только при `local` |
| `APP_URL` | `http://localhost:8080` | Базовый URL (ссылки в письмах, OAuth-коллбеки) |
| `APP_NAME` | `ezposter` | Название продукта |
| `APP_DEBUG` | `0` | Показывать детали ошибок (только local/testing). В production приложение не запустится при `1` |
| `APP_KEY` | — | **Обязательна.** Ключ libsodium `secretbox` в base64 (32 байта). Шифрует токены соцсетей и TOTP, из него выводится ключ `Signer` |
| `APP_KEY_ID` | `k1` | Идентификатор текущего ключа в шифртексте (`v1:<id>:…`) |
| `APP_KEYS` | — | Старые ключи для расшифровки после ротации: `id:base64,id:base64` (см. `security.md`) |
| `TRUSTED_PROXIES` | — | IP и CIDR обратных прокси через запятую; только от них принимаются `X-Forwarded-For/Proto`. `*` не поддерживается намеренно |
| `LOG_LEVEL` | `info` | `debug` · `info` · `notice` · `warning` · `error` · `critical` |
| `LOG_DISABLE_STDERR` | — | `1` отключает дублирование логов в stderr (нужно только тестам) |
| `SESSION_IDLE_TTL` | `7200` | Секунд бездействия до конца сессии |
| `SESSION_ABSOLUTE_TTL` | `2592000` | Максимальная жизнь сессии (30 дней) |
| `ARGON_MEMORY_KIB` `ARGON_TIME_COST` | `65536` `4` | Параметры Argon2id (в тестах занижены) |
| `DEV_*` | — | Любой флаг `DEV_*` со значением `1/true/yes/on` запрещён в production (приложение не запустится) |
| `DB_HOST` `DB_PORT` | — `3306` | MySQL. `DB_HOST` обязательна |
| `DB_DATABASE` | — | **Обязательна.** Имя БД (в тестах `app_test`, задаётся `phpunit.xml`) |
| `DB_USERNAME` `DB_PASSWORD` | — | Доступ приложения к БД. `DB_USERNAME` обязательна |
| `DB_ROOT_PASSWORD` | `root` | Только для инициализации контейнера MySQL (dev) |
| `REDIS_HOST` `REDIS_PORT` | — `6379` | Redis. `REDIS_HOST` обязательна |
| `REDIS_DB` | `0` | Номер базы Redis (тесты используют 15) |
| `MAIL_HOST` `MAIL_PORT` | `mailpit` `1025` | SMTP (в dev — Mailpit) |
| `MAIL_FROM` `MAIL_FROM_NAME` | | Адрес и имя отправителя |
| `SUPPORT_EMAIL` | `MAIL_FROM` | Ящик, куда приходят сообщения «Сообщить о проблеме» |
| `HTTP_PORT` | `8080` | Только `compose.prod.yaml`: порт nginx на `127.0.0.1` за обратным прокси |
| `MAIL_DSN` | — | Полный DSN почтового транспорта (`smtp://user:pass@host:587`); если задан, `MAIL_HOST`/`MAIL_PORT` игнорируются |
| `DEV_LOGIN` | `1` | `/dev/login-as/{id или email}`: вход без пароля. Работает только при `APP_ENV=local` (и в тестах); при `DEV_LOGIN=1` и `APP_ENV=production` приложение не запустится |
| `PASSWORD_HIBP` | `0` | `1` включает проверку новых паролей в Have I Been Pwned (отправляются только 5 символов SHA-1; при недоступности сервиса проверка пропускается) |
| `REMEMBER_DAYS` | `30` | Срок жизни cookie «Запомнить меня» |
| `VKID_CLIENT_ID` `VKID_CLIENT_SECRET` | — | VK ID (id.vk.com). Без `VKID_CLIENT_ID` кнопка скрыта; секрет необязателен для потока с PKCE |
| `YANDEX_CLIENT_ID` `YANDEX_CLIENT_SECRET` | — | Яндекс ID; нужны оба значения |
| `GOOGLE_CLIENT_ID` `GOOGLE_CLIENT_SECRET` | — | Google (OIDC); нужны оба значения |
| `TELEGRAM_LOGIN_BOT_TOKEN` `TELEGRAM_LOGIN_BOT_NAME` | — | Бот для Telegram Login Widget (имя без `@`); нужны оба значения. В BotFather выполните `/setdomain` с публичным доменом |
| `OAUTH_ORDER` | `vkid,yandex,telegram,google` | Порядок кнопок входа; не перечисленные в списке провайдеры идут в конце |
| `DEV_OAUTH_FAKE` | `0` | `1` включает встроенного тестового провайдера `/dev/oauth/fake`. Префикс `DEV_`: при `APP_ENV=production` приложение не запустится |
| `PLATFORMS_ENABLED` | `telegram` | Флаги платформ через запятую (`telegram`, `vk`, `max`, `instagram`); выключенная платформа не видна в интерфейсе. `fake` (тестовая сеть) работает только вне production |
| `VK_CLIENT_ID` `VK_CLIENT_SECRET` | `VKID_*` | Приложение VK ID для публикации в сообщества (права на стену); адрес возврата `<APP_URL>/channels/connect/vk/callback`. Включается флагом `vk` в `PLATFORMS_ENABLED`. См. [modules/vk.md](modules/vk.md) |
| `VK_SCOPE` | `wall photos video docs groups` | Права, которые просит подключение VK |
| `VK_POSTS_PER_DAY` | `50` | Суточный лимит постов одного сообщества для предупреждения при планировании |
| `INSTAGRAM_APP_ID` `INSTAGRAM_APP_SECRET` | — | Meta-приложение «Instagram API with Instagram Login»; адрес возврата `<APP_URL>/channels/connect/instagram/callback`. Включается флагом `instagram` в `PLATFORMS_ENABLED` (по умолчанию выключено); `APP_URL` должен быть публичным https. См. [modules/instagram.md](modules/instagram.md) |
| `INSTAGRAM_SCOPE` | `instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments` | Права, которые просит подключение Instagram |
| `INSTAGRAM_API_VERSION` | `v23.0` | Версия Graph API |
| `INSTAGRAM_POSTS_PER_DAY` | `100` | Суточный лимит публикаций через API на аккаунт для предупреждения при планировании |
| `MAX_BOT_TOKEN` | — | Общий бот MAX (бот верифицированного юрлица, business.max.ru). Без него работает только режим «свой бот». Включается флагом `max` в `PLATFORMS_ENABLED` |
| `MAX_BOT_USERNAME` | — | Имя бота MAX без `@` для страницы подключения (иначе спрашивается у MAX и кэшируется) |
| `MAX_WEBHOOK_SECRET` | — | 16–128 символов `A-Za-z0-9_-`: часть адреса вебхука `/webhooks/max/{secret}`; из него выводится секретный заголовок. Задать, затем `max:webhook set https://публичный-хост` |
| `MAX_API_BASE` | `https://platform-api2.max.ru` | Адрес MAX API (менять не нужно) |
| `TELEGRAM_BOT_TOKEN` | — | Общий бот сервиса (создаётся в @BotFather); без него подключение «через бота сервиса» недоступно, «свой бот» работает |
| `TELEGRAM_BOT_USERNAME` | — | Имя бота без `@` для подсказки на странице подключения; если пусто, спрашивается у Telegram и кэшируется на час |
| `TELEGRAM_WEBHOOK_SECRET` | — | 16–128 символов `A-Za-z0-9_-`: секретная часть адреса вебхука; из него же выводится значение заголовка `X-Telegram-Bot-Api-Secret-Token`. Без него вебхук отвечает 404 |
| `CHANNEL_PREFLIGHT_MINUTES` | `15` | Канал проверяется прямо перед публикацией, если последняя проверка старше этого числа минут |
| `CHANNELS_MAX` | `1000` | Глобальный потолок числа каналов в пространстве (с паузой включительно); лимит тарифа считает только активные |
| `S3_KEY` `S3_SECRET` | `minioadmin` | Доступ к MinIO (профиль `s3`) и ключи S3 для `MEDIA_DISK=s3` |
| `MEDIA_DISK` | `local` | Где хранить файлы медиатеки: `local` (`MEDIA_LOCAL_ROOT`) или `s3` |
| `MEDIA_LOCAL_ROOT` | `storage/media` | Каталог локального хранилища (относительно проекта или абсолютный); вне docroot nginx |
| `S3_ENDPOINT` `S3_BUCKET` `S3_REGION` `S3_PATH_STYLE` | — / `ezposter` / `us-east-1` / `1` | Адрес (для MinIO `http://minio:9000`), бакет, регион и стиль адресов S3; нужны при `MEDIA_DISK=s3` |
| `MEDIA_MAX_FILE_MB` | `50` | Максимальный размер одного файла (должен быть не больше `upload_max_filesize` в `docker/php/php.ini` и `client_max_body_size` в nginx) |
| `MEDIA_QUOTA_MB` | `0` | Необязательное глобальное ограничение размера медиатеки сверху (0 = нет); размер даёт тариф |
| `MEDIA_MAX_VIDEO_SECONDS` | `900` | Максимальная длина видео |
| `MEDIA_URL_TIMEOUT` | `30` | Лимит времени (секунды) на загрузку файла по ссылке |
| `MEDIA_SIGNED_URL_TTL` | `3600` | Срок жизни подписанных ссылок на файлы по умолчанию (секунды) |

## Биллинг (этап 10)
| Переменная | По умолчанию | Назначение |
|---|---|---|
| `BILLING_GATEWAYS` | `yookassa,tbank` | Способы оплаты на странице тарифов; шлюз без ключей не показывается; `fake` (тестовая страница оплаты) добавляется сам вне production |
| `YOOKASSA_SHOP_ID` `YOOKASSA_SECRET_KEY` | — | Магазин ЮKassa (тестовый или боевой). Адрес уведомлений в кабинете: `<APP_URL>/webhooks/billing/yookassa` |
| `YOOKASSA_VERIFY_IP` | `1` | Проверка адреса отправителя уведомлений; `0` только вне production (туннель). Платёж в любом случае перечитывается из API |
| `TBANK_TERMINAL_KEY` `TBANK_PASSWORD` | — | Терминал интернет-эквайринга Т-Банка; пароль подписывает запросы. Адрес уведомлений уходит в `Init` сам: `<APP_URL>/webhooks/billing/tbank` |
| `YOOKASSA_API_BASE` `TBANK_API_BASE` | боевые адреса | Менять не нужно |
| `CLOUDPAYMENTS_PUBLIC_ID` `CLOUDPAYMENTS_API_SECRET` `STRIPE_SECRET_KEY` `STRIPE_WEBHOOK_SECRET` `PADDLE_API_KEY` `PADDLE_WEBHOOK_SECRET` `PADDLE_SANDBOX` `CRYPTO_MERCHANT_ID` `CRYPTO_API_KEY` | — | Ключи добавленных провайдеров. **Основное место для них — админка** (`/admin/providers`, шифруются в базе); переменные только засевают свежую установку, сохранённое в админке побеждает. Адреса уведомлений показаны на той же странице |
| `BILLING_TAX_SYSTEM` | `npd` | Налогообложение: `npd` (самозанятый: чек провайдеру не передаётся), `osn`, `usn_income`, `usn_income_outcome`, `patent`, `envd`, `esn` |
| `BILLING_VAT` | `none` | Ставка НДС в чеках: `none`, `vat0`, `vat10`, `vat20`, `vat22` |
| `BILLING_SELLER_NAME` `BILLING_SELLER_INN` | — | Продавец на PDF-квитанции (пусто: строка не печатается) |
| `BILLING_RECEIPT_FONT` | DejaVu Sans из образа | TrueType-шрифт с кириллицей для квитанции |

Цены, лимиты, пробный период (14 дней Pro), лесенка повторов (за 3 дня до конца, через 1 и 3 дня, затем ежедневно все 3 дня отсрочки) лежат в `config/billing.php`; цены и лимиты после первой миграции живут в БД.

Обязательные переменные проверяются при старте (`Config::load`): если чего-то нет, приложение и `bin/console` не запускаются, а в журнал попадает имя переменной без значения. В `config/*.php` env читается только через `Env`; прямых `getenv()` в коде нет.


## Админка (этап 20)

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `ADMIN_IP_ALLOWLIST` | пусто | Адреса и сети через запятую; если задано, `/admin` отвечает 404 всем остальным |
| `APP_VERSION`, `APP_DEPLOYED_AT` | пусто | Что выкачено; показывается на странице «Состояние системы» (ставит деплой) |

Остальное, что владелец меняет без выкладки, лежит в таблице `app_settings` (кэш в Redis): `site.maintenance`, `site.maintenance_message`, `site.registration`, `site.support_email`, `site.support_telegram`, `site.requisites`, `limits.max_workspaces_per_user`, `platforms.off`, `status.notices`, `design.colors`, `finance.fee_percent`, `report.*`, `campaigns.per_minute`, `metrics.refreshed_at`. Секретов там нет.

## Внутренний Sources reader

`SOURCES_READER_SECRET` — отдельный общий секрет PHP/reader, минимум 32 случайных символа; пустое значение выключает API. В конфигурации reader: `VKPOSTER_INTERNAL_URL` (локально `http://host.docker.internal:8080`). Telegram API credentials, телефон и session остаются только у reader. Не передавать их браузеру или PHP. После изменения `.env` пересоздать app; session volume reader сохраняется.

### Content provider opt-in

`config/content_providers.php` reads `CONTENT_TEXT_PROVIDER`, `CONTENT_SEMANTIC_PROVIDER` (fake/openai), `CONTENT_IMAGE_SEARCH_PROVIDER` (fake/tineye), `CONTENT_IMAGE_ENHANCEMENT_PROVIDER` (fake/disabled/replicate), `CONTENT_VIDEO_PROVIDER` (fake/replicate). All default to fake. Credentials: `OPENAI_API_KEY`, `TINEYE_API_KEY`, `REPLICATE_API_TOKEN`; configurable `OPENAI_CONTENT_MODEL`. See [real provider operations](modules/content-real-providers.md).

Stage 3.2 is accepted on mocked HTTP/contracts. Real integrations need explicit selection plus corresponding env credentials; absent credentials prevent activation. OpenAI/TinEye/Replicate live smoke is deferred to Stage 3.5; Fake remains the dev/test default.

Content hardening env: `CONTENT_CONCURRENCY=2`, `CONTENT_PROVIDER_PER_MINUTE=20`, `CONTENT_VIDEO_PER_HOUR=3`, `CONTENT_VIDEO_PENDING=3`, `CONTENT_STUCK_SECONDS=3600`; cleanup disabled via `CONTENT_RETENTION_ENABLED=false`, `CONTENT_RETENTION_DAYS=30`. Reader independently defaults `READER_ACK_RETENTION_DAYS=0`. See [limits/recovery/retention](modules/content-hardening.md).

### Analytics (Stage15)

ANALYTICS_COLLECTION_ENABLED=false по умолчанию: явное включение read-only сбора.
ANALYTICS_PDF_URL=http://analytics-pdf:3000 — внутренний фиксированный renderer.
Подпись POST использует domain-separated derived key, а не master APP_KEY.
Compose profile analytics: PHP init пишет derived key в dedicated volume, Chromium
читает только read-only key file (ANALYTICS_PDF_KEY_FILE), без .env/APP_KEY/токенов.
Новый пользовательский секрет не требуется. analytics-worker обслуживает очередь
analytics отдельно от publish,default; capacity deferral не расходует attempts. Тарифные
analytics_days / analytics_export / client_reports используются без новых тарифов.

## ИИ-ассистент (этап 17)
Модели и ключи задаются в админке (`/admin/ai`, право `ai.manage`, только суперадмин), не в окружении. В окружении только ограничения:

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `AI_FAKE` | `true` | без подключённого провайдера отвечает тестовая модель (только вне production) |
| `AI_MAX_INPUT_CHARS` | `6000` | максимум знаков текста и пожеланий за запрос |
| `AI_MAX_OUTPUT_TOKENS` | `1024` | потолок ответа модели |
| `AI_WORKSPACE_PER_MINUTE` | `10` | запросов в минуту на пространство (Redis; при сбое Redis запрос отклоняется) |
| `AI_TIMEOUT_SECONDS` | `60` | ожидание ответа модели |

## Ссылки в постах (этап 17)
UTM-метки и включение сокращения задаются в пространстве (`/w/<id>/settings/links`), чёрный список доменов в админке (`/admin/links`). В окружении:

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `SHORT_LINK_DOMAIN` | пусто | хост коротких ссылок (`https://<домен>/<код>`); пусто = `APP_URL/s/<код>` |
| `SHORT_LINK_MANUAL_PER_DAY` | `100` | ссылок, сделанных вручную, в сутки на пространство |
| `SHORT_LINK_CLICK_RETENTION_DAYS` | `400` | сколько хранить отдельные переходы (счётчики остаются) |

## Stage 19A: частичная подготовка (IN_PROGRESS)

- Production startup требует HTTPS `APP_URL` и непустой `TRUSTED_PROXIES` со списком
  IP/CIDR. Некорректные адреса и глобальные `/0` отклоняются. Значения выбираются по
  фактической proxy chain; корректный синтаксис не доказывает безопасную топологию.
- `APP_IMAGE` / `WEB_IMAGE` — Compose image references, defaults `ezposter-app:prod` /
  `ezposter-web:prod`. Не являются включённым CD; workflow ещё не реализован.
- `/healthz` — process liveness без session/DB/Redis. `/readyz` — DB/Redis и наличие
  ожидаемого количества миграций (200/503, только ok/fail). Полную сверку схемы не заменяет.
- Workers сохраняют общий heartbeat `worker` и пишут `worker:publish`, `worker:default`,
  `worker:analytics` для обслуживаемых очередей. Возраст >180 секунд считается stale.
- JSON application logs: `storage/logs/app-YYYY-MM-DD.log`, максимум 30 файлов.
  Полный Stage 19 log/storage limit и внешнее логирование ещё не реализованы.

Текущая точка остановки: [Stage 19A status](../security/stage-19a-status.md).


## Oracle backup/restore deadline

`BACKUP_PROCESS_TIMEOUT` is the single per-process deadline (seconds) for Oracle
`mysqldump` and `mysql` restore: default1800, positive integer1–86400; invalid values
fail configuration loading, including production startup. Elapsed time is monotonic.
The daily systemd template allows45min for the whole workflow (30min CLI budget,
lock wait, encryption/verification and offsite delivery). Raising the CLI deadline
requires an owner systemd override with additional workflow margin. A timeout is a
failed operation; an isolated partial restore target must be recreated before retry.

<!-- ENV_INVENTORY_START -->

## Checked environment inventory

Generated from literal Env access and public examples only. No local `.env` is read.
The source links define defaults, validation and whether a value is required.
Secrets are provisioned externally; inventory presence never means a credential is configured.

| Variable | Definition / access |
|---|---|
| `ADMIN_IP_ALLOWLIST` | [.env.example](../../.env.example), [config/admin.php](../../config/admin.php) |
| `AI_FAKE` | [.env.example](../../.env.example), [config/ai.php](../../config/ai.php) |
| `AI_MAX_INPUT_CHARS` | [.env.example](../../.env.example), [config/ai.php](../../config/ai.php) |
| `AI_MAX_OUTPUT_TOKENS` | [.env.example](../../.env.example), [config/ai.php](../../config/ai.php) |
| `AI_TIMEOUT_SECONDS` | [.env.example](../../.env.example), [config/ai.php](../../config/ai.php) |
| `AI_WORKSPACE_PER_MINUTE` | [.env.example](../../.env.example), [config/ai.php](../../config/ai.php) |
| `ANALYTICS_COLLECTION_ENABLED` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/analytics.php](../../config/analytics.php) |
| `ANALYTICS_PDF_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/analytics.php](../../config/analytics.php) |
| `APP_DEBUG` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/app.php](../../config/app.php) |
| `APP_DEPLOYED_AT` | [.env.example](../../.env.example), [config/app.php](../../config/app.php) |
| `APP_ENV` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/app.php](../../config/app.php), [config/security.php](../../config/security.php), [config/services.php](../../config/services.php), [scripts/analytics-pdf-key.php](../../scripts/analytics-pdf-key.php) |
| `APP_IMAGE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `APP_KEY` | [.env.example](../../.env.example), [config/security.php](../../config/security.php), [scripts/analytics-pdf-key.php](../../scripts/analytics-pdf-key.php) |
| `APP_KEYS` | [.env.example](../../.env.example), [config/security.php](../../config/security.php) |
| `APP_KEY_ID` | [.env.example](../../.env.example), [config/security.php](../../config/security.php) |
| `APP_NAME` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/app.php](../../config/app.php), [config/mail.php](../../config/mail.php) |
| `APP_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/app.php](../../config/app.php), [config/session.php](../../config/session.php) |
| `APP_VERSION` | [.env.example](../../.env.example), [config/app.php](../../config/app.php) |
| `ARGON_MEMORY_KIB` | [config/security.php](../../config/security.php) |
| `ARGON_TIME_COST` | [config/security.php](../../config/security.php) |
| `BACKUP_BINLOG_DIRECTORY` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_BINLOG_ENABLED` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_BINLOG_ROTATE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_DB_HOST` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_DB_PASSWORD` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_DB_PORT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_DB_SOCKET` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_DB_USERNAME` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_DIRECTORY` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `BACKUP_KEY_FILE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php), [src/Kernel/Operations/BinlogBackup.php](../../src/Kernel/Operations/BinlogBackup.php) |
| `BACKUP_KEY_HOST_PATH` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `BACKUP_MYSQL_GID` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `BACKUP_PROCESS_TIMEOUT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/operations.php](../../config/operations.php) |
| `BACKUP_RETENTION_DAYS` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php) |
| `BACKUP_S3_BUCKET` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `BACKUP_S3_ENDPOINT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `BACKUP_S3_KEY` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `BACKUP_S3_REGION` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `BACKUP_S3_SECRET` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `BILLING_GATEWAYS` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/billing.php](../../config/billing.php) |
| `BILLING_RECEIPT_FONT` | [config/billing.php](../../config/billing.php) |
| `BILLING_SELLER_INN` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `BILLING_SELLER_NAME` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `BILLING_TAX_SYSTEM` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `BILLING_VAT` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `CHANNELS_MAX` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `CHANNEL_PREFLIGHT_MINUTES` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `CLAMD_ENDPOINT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php) |
| `CLOUDPAYMENTS_API_SECRET` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `CLOUDPAYMENTS_PUBLIC_ID` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `CONTENT_` | [config/content_providers.php](../../config/content_providers.php) |
| `CONTENT_CONCURRENCY` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_IMAGE_ENHANCEMENT_PROVIDER` | [.env.example](../../.env.example) |
| `CONTENT_IMAGE_SEARCH_PROVIDER` | [.env.example](../../.env.example) |
| `CONTENT_PROVIDER_PER_MINUTE` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_RETENTION_DAYS` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_RETENTION_ENABLED` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_SEMANTIC_PROVIDER` | [.env.example](../../.env.example) |
| `CONTENT_STUCK_SECONDS` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_TEXT_PROVIDER` | [.env.example](../../.env.example) |
| `CONTENT_VIDEO_PENDING` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_VIDEO_PER_HOUR` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CONTENT_VIDEO_PROVIDER` | [.env.example](../../.env.example) |
| `CONTENT_WORKSPACE_PER_MINUTE` | [.env.example](../../.env.example), [config/content_operations.php](../../config/content_operations.php) |
| `CRYPTO_API_KEY` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `CRYPTO_BACKEND` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/security.php](../../config/security.php), [config/services.php](../../config/services.php), [src/Domain/Analytics/AnalyticsPdf.php](../../src/Domain/Analytics/AnalyticsPdf.php), [src/Kernel/Config.php](../../src/Kernel/Config.php) |
| `CRYPTO_MERCHANT_ID` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `DB_DATABASE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/database.php](../../config/database.php) |
| `DB_HOST` | [.env.example](../../.env.example), [config/database.php](../../config/database.php), [scripts/check-schema.php](../../scripts/check-schema.php) |
| `DB_PASSWORD` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/database.php](../../config/database.php), [scripts/check-schema.php](../../scripts/check-schema.php) |
| `DB_PORT` | [.env.example](../../.env.example), [config/database.php](../../config/database.php), [scripts/check-schema.php](../../scripts/check-schema.php) |
| `DB_ROOT_PASSWORD` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `DB_USERNAME` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/database.php](../../config/database.php), [scripts/check-schema.php](../../scripts/check-schema.php) |
| `DEV_LOGIN` | [.env.example](../../.env.example), [config/auth.php](../../config/auth.php) |
| `DEV_OAUTH_FAKE` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `EGRESS_ANTHROPIC_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_ANTHROPIC_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_GLITCHTIP_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_GLITCHTIP_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_IDENTITY_SALT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Config.php](../../src/Kernel/Config.php), [src/Kernel/HttpClient/EgressRoutes.php](../../src/Kernel/HttpClient/EgressRoutes.php) |
| `EGRESS_MAX_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_MAX_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_OPENAI_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_OPENAI_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_PROBE_ENABLED` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/HttpClient/EgressProbe.php](../../src/Kernel/HttpClient/EgressProbe.php) |
| `EGRESS_REPLICATE_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_REPLICATE_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_REQUIRED_PROVIDERS` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/HttpClient/EgressHealth.php](../../src/Kernel/HttpClient/EgressHealth.php), [src/Kernel/HttpClient/EgressProbe.php](../../src/Kernel/HttpClient/EgressProbe.php), [src/Kernel/HttpClient/EgressRoutes.php](../../src/Kernel/HttpClient/EgressRoutes.php) |
| `EGRESS_TELEGRAM_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_TELEGRAM_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_TINEYE_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_TINEYE_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_VK_MODE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `EGRESS_VK_PROXY_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `GOOGLE_CLIENT_ID` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `GOOGLE_CLIENT_SECRET` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `HTTP_PORT` | [.env.production.example](../../.env.production.example) |
| `INSTAGRAM_API_VERSION` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `INSTAGRAM_APP_ID` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `INSTAGRAM_APP_SECRET` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `INSTAGRAM_POSTS_PER_DAY` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `INSTAGRAM_SCOPE` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `LOG_DISABLE_STDERR` | [config/services.php](../../config/services.php) |
| `LOG_LEVEL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/app.php](../../config/app.php) |
| `MAIL_DSN` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/mail.php](../../config/mail.php) |
| `MAIL_FROM` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/mail.php](../../config/mail.php) |
| `MAIL_FROM_NAME` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/mail.php](../../config/mail.php) |
| `MAIL_HOST` | [.env.example](../../.env.example), [config/mail.php](../../config/mail.php) |
| `MAIL_PORT` | [.env.example](../../.env.example), [config/mail.php](../../config/mail.php) |
| `MALWARE_SCANNER` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php) |
| `MAX_API_BASE` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `MAX_BOT_TOKEN` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `MAX_BOT_USERNAME` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `MAX_WEBHOOK_SECRET` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `MEDIA_DISK` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MEDIA_LOCAL_ROOT` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MEDIA_MAX_FILE_MB` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MEDIA_MAX_VIDEO_SECONDS` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MEDIA_QUOTA_MB` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MEDIA_SIGNED_URL_TTL` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MEDIA_URL_TIMEOUT` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `MYSQL_IMAGE` | [.env.production.example](../../.env.production.example) |
| `OAUTH_ORDER` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `OPENAI_API_KEY` | [.env.example](../../.env.example), [config/content_providers.php](../../config/content_providers.php) |
| `OPENAI_CONTENT_MODEL` | [.env.example](../../.env.example), [config/content_providers.php](../../config/content_providers.php) |
| `OPERATIONS_DB_TLS_CA_FILE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/OperationsDbPolicy.php](../../src/Kernel/Operations/OperationsDbPolicy.php) |
| `OPS_IMAGE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `OPS_MONITOR_ENABLED` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/OperationalMonitor.php](../../src/Kernel/Operations/OperationalMonitor.php) |
| `OPS_TELEGRAM_BOT_TOKEN` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/OperationalMonitor.php](../../src/Kernel/Operations/OperationalMonitor.php) |
| `OPS_TELEGRAM_CHAT_ID` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/OperationalMonitor.php](../../src/Kernel/Operations/OperationalMonitor.php) |
| `PADDLE_API_KEY` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `PADDLE_SANDBOX` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `PADDLE_WEBHOOK_SECRET` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `PASSWORD_HIBP` | [.env.example](../../.env.example), [config/auth.php](../../config/auth.php) |
| `PASSWORD_PEPPER_FILE` | [.env.example](../../.env.example), [config/services.php](../../config/services.php) |
| `PASSWORD_PEPPER_HOST_PATH` | [.env.example](../../.env.example) |
| `PASSWORD_PEPPER_ID` | [.env.example](../../.env.example), [config/services.php](../../config/services.php) |
| `PASSWORD_PEPPER_PREVIOUS_FILES` | [.env.example](../../.env.example), [config/services.php](../../config/services.php) |
| `PDF_IMAGE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `PLATFORMS_ENABLED` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/platforms.php](../../config/platforms.php) |
| `REDIS_DB` | [.env.example](../../.env.example), [config/database.php](../../config/database.php) |
| `REDIS_HOST` | [.env.example](../../.env.example), [config/database.php](../../config/database.php) |
| `REDIS_IMAGE` | [.env.production.example](../../.env.production.example) |
| `REDIS_PASSWORD` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/database.php](../../config/database.php) |
| `REDIS_PORT` | [.env.example](../../.env.example), [config/database.php](../../config/database.php) |
| `REMEMBER_DAYS` | [.env.example](../../.env.example), [config/auth.php](../../config/auth.php) |
| `REPLICATE_API_TOKEN` | [.env.example](../../.env.example), [config/content_providers.php](../../config/content_providers.php) |
| `RESTORE_DB_HOST` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php) |
| `RESTORE_DB_PASSWORD` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php) |
| `RESTORE_DB_PORT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php) |
| `RESTORE_DB_SOCKET` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php) |
| `RESTORE_DB_USERNAME` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [src/Kernel/Operations/BackupService.php](../../src/Kernel/Operations/BackupService.php) |
| `S3_BUCKET` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `S3_ENDPOINT` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `S3_KEY` | [.env.example](../../.env.example), [config/media.php](../../config/media.php), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `S3_PATH_STYLE` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `S3_REGION` | [.env.example](../../.env.example), [config/media.php](../../config/media.php) |
| `S3_SECRET` | [.env.example](../../.env.example), [config/media.php](../../config/media.php), [src/Kernel/Operations/BackupDestination.php](../../src/Kernel/Operations/BackupDestination.php) |
| `SCHEMA_CHECK_DATABASE` | [scripts/check-schema.php](../../scripts/check-schema.php) |
| `SENTRY_DSN` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php), [src/Kernel/Operations/GlitchTipReporter.php](../../src/Kernel/Operations/GlitchTipReporter.php) |
| `SESSION_ABSOLUTE_TTL` | [.env.example](../../.env.example), [config/session.php](../../config/session.php) |
| `SESSION_IDLE_TTL` | [.env.example](../../.env.example), [config/session.php](../../config/session.php) |
| `SHORT_LINK_CLICK_RETENTION_DAYS` | [.env.example](../../.env.example), [config/links.php](../../config/links.php) |
| `SHORT_LINK_DOMAIN` | [.env.example](../../.env.example), [config/links.php](../../config/links.php) |
| `SHORT_LINK_MANUAL_PER_DAY` | [.env.example](../../.env.example), [config/links.php](../../config/links.php) |
| `SOURCES_ENABLED` | [.env.example](../../.env.example), [config/permissions.php](../../config/permissions.php), [config/sources.php](../../config/sources.php) |
| `SOURCES_READER_SECRET` | [.env.example](../../.env.example), [config/sources.php](../../config/sources.php), [src/Kernel/HttpClient/EgressHealth.php](../../src/Kernel/HttpClient/EgressHealth.php) |
| `STRIPE_SECRET_KEY` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `STRIPE_WEBHOOK_SECRET` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `SUPPORT_EMAIL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/mail.php](../../config/mail.php) |
| `TBANK_API_BASE` | [config/billing.php](../../config/billing.php) |
| `TBANK_PASSWORD` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `TBANK_TERMINAL_KEY` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `TELEGRAM_BOT_TOKEN` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `TELEGRAM_BOT_USERNAME` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `TELEGRAM_LOGIN_BOT_NAME` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `TELEGRAM_LOGIN_BOT_TOKEN` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `TELEGRAM_WEBHOOK_SECRET` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `TINEYE_API_KEY` | [.env.example](../../.env.example), [config/content_providers.php](../../config/content_providers.php) |
| `TRUSTED_PROXIES` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/app.php](../../config/app.php) |
| `VAULT_APP_TOKEN_HOST_PATH` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `VAULT_CA_FILE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php), [src/Kernel/Config.php](../../src/Kernel/Config.php) |
| `VAULT_CA_HOST_PATH` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `VAULT_LEGACY_SIGNER_KEY` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php) |
| `VAULT_LEGACY_SIGNER_UNTIL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php) |
| `VAULT_LEGACY_TRANSIT_KEY` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php) |
| `VAULT_PDF_TOKEN_HOST_PATH` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `VAULT_TOKEN_FILE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php), [src/Kernel/Config.php](../../src/Kernel/Config.php) |
| `VAULT_TRANSIT_KEY` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php), [src/Kernel/Config.php](../../src/Kernel/Config.php) |
| `VAULT_TRANSIT_URL` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/services.php](../../config/services.php), [src/Kernel/Config.php](../../src/Kernel/Config.php) |
| `VKID_CLIENT_ID` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php), [config/platforms.php](../../config/platforms.php) |
| `VKID_CLIENT_SECRET` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php), [config/platforms.php](../../config/platforms.php) |
| `VK_CLIENT_ID` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `VK_CLIENT_SECRET` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `VK_POSTS_PER_DAY` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `VK_SCOPE` | [.env.example](../../.env.example), [config/platforms.php](../../config/platforms.php) |
| `WEBHOOK_FAILURE_LIMIT` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/api.php](../../config/api.php) |
| `WEBHOOK_RETENTION_DAYS` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example), [config/api.php](../../config/api.php) |
| `WEB_IMAGE` | [.env.example](../../.env.example), [.env.production.example](../../.env.production.example) |
| `YANDEX_CLIENT_ID` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `YANDEX_CLIENT_SECRET` | [.env.example](../../.env.example), [config/oauth.php](../../config/oauth.php) |
| `YOOKASSA_API_BASE` | [config/billing.php](../../config/billing.php) |
| `YOOKASSA_SECRET_KEY` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `YOOKASSA_SHOP_ID` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |
| `YOOKASSA_VERIFY_IP` | [.env.example](../../.env.example), [config/billing.php](../../config/billing.php) |

<!-- ENV_INVENTORY_END -->
