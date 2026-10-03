# Мастер-план: сервис отложенного постинга в VK, MAX, Telegram, Instagram

> Рабочее название — **VKPoster** (меняется через `APP_NAME`; финальное имя решает владелец, см. вопрос в этапе 00).
> Аналог ecotime.me, но мультиплатформенный, командный и с монетизацией.
> Этот файл — «что и зачем». Правила «как писать код» — в [ENGINEERING_RULES.md](ENGINEERING_RULES.md).
> Порядок работы агентов — в [AGENT_PROMPT.md](AGENT_PROMPT.md), статус — в [PROGRESS.md](PROGRESS.md).

---

## 1. Принятые решения

| Вопрос | Решение |
|---|---|
| Стек | **Чистый PHP 8.3** без фреймворков (никаких Laravel/Symfony/Slim). Свой небольшой каркас в `src/Kernel`. Разрешены только узкие библиотеки Composer из белого списка (см. ENGINEERING_RULES §2) |
| Текущий код | Переписываем с нуля в этом же репозитории. Старый код помечается тегом `legacy-v0` и удаляется на этапе 00 (остаётся в истории git) |
| БД | MySQL 8.0 (InnoDB, utf8mb4, всё время в UTC) |
| Кэш / сессии / rate limit | Redis 7 |
| Очередь задач | Таблица в MySQL + `SELECT … FOR UPDATE SKIP LOCKED` (без отдельного брокера) |
| Шаблоны | Twig 3 (автоэкранирование: главная защита от XSS) |
| Фронтенд | Без сборки Node: Bootstrap 5 + Alpine.js + htmx, файлы лежат в `public/assets/vendor` с фиксированными версиями |
| Платежи | ЮKassa, интернет-эквайринг Т-Банка, CloudPayments, Stripe, Paddle, крипта, Telegram Stars (за единым интерфейсом `PaymentGateway`) |
| Окружение | Вся разработка и тесты — в Docker (`docker compose`) |
| Git | Каждый этап делается в ветке `stage-NN-slug`, затем PR, зелёный CI, squash-merge в `main` |

---

## 2. Продукт

### 2.1 Целевая аудитория
1. SMM-специалисты и фрилансеры, которые ведут 3–30 сообществ/каналов.
2. Агентства: команды, согласование с клиентами, отчёты.
3. Владельцы бизнеса и блогеры, которые ведут 1–5 своих площадок.
4. Админы TG-каналов, которым нужен кросспостинг в VK и MAX.

### 2.2 Платформы и что в них умеем

| Платформа | Как подключаем | Что публикуем | Ограничения и риски |
|---|---|---|---|
| **VK** | VK ID (OAuth 2.1 + PKCE), токен пользователя с правами на стену/фото/видео сообществ, где он админ | Текст, фото (до 10), видео, документы, опросы; первый комментарий; закрепление; удаление по таймеру | VK ID выдаёт права на стену только после модерации приложения. Лимит ~50 постов в сутки на сообщество. Перед этапом 08 сверить с dev.vk.com |
| **Telegram** | Наш бот добавляется админом в канал или группу (код привязки). Опционально свой бот клиента (его токен) | Текст (4096 символов), фото/видео/документы, альбомы (до 10, подпись до 1024), опросы, кнопки-ссылки, тихая отправка, закрепление, удаление | Rate limit Bot API: ~20 сообщений в минуту на группу, ~30 в секунду на бота. Медиа до 50 МБ без своего Local Bot API |
| **MAX** | Бот MAX, `https://platform-api2.max.ru`, токен в заголовке `Authorization`. Бот добавляется админом в канал | Текст (до 4000 символов, markdown/html), вложения через `POST /uploads`, кнопки | **Бота может зарегистрировать только верифицированное юрлицо РФ.** Нужно либо юрлицо владельца сервиса (один общий бот), либо режим «свой бот клиента». Лимит 2 сообщения в секунду на чат |
| **Instagram** | Instagram API with Instagram Login (Business/Creator аккаунт) | Фото, карусели (до 10), Reels, Stories; первый комментарий | Нужны Meta App Review и публичный HTTPS-URL медиа (S3/CDN). Суточный лимит публикаций через API. **Юридический риск в РФ (Meta признана экстремистской):** модуль включается feature-флагом, решение за владельцем (этап 12) |

Архитектура должна позволять подключить новую сеть (OK, Дзен, Threads, X, Pinterest, YouTube Shorts) одним классом-адаптером без изменений ядра.

### 2.3 Функции

**MVP (этапы 00–11): то, без чего сервис не продать**
- Регистрация и вход по email + паролю, подтверждение email, сброс пароля, 2FA (TOTP), список активных сессий.
- Вход и регистрация через VK ID, Яндекс ID, Telegram, Google. Привязка нескольких способов входа к одному аккаунту.
- Рабочие пространства (workspace), приглашения, роли.
- Подключение каналов TG, VK, MAX; монитор состояния подключений (токен истёк, бота убрали из админов).
- Единый редактор поста: общий текст и **вариант под каждую сеть** (свой текст, медиа, кнопки), живое превью, счётчики лимитов символов.
- Медиатека: загрузка, проверка, сжатие, превью, кадрирование под форматы (1:1, 4:5, 9:16), водяной знак.
- Календарь (месяц/неделя/список), перенос перетаскиванием, черновики.
- Надёжная публикация: очередь, повторы с backoff, без дублей, журнал публикаций, уведомления об ошибках (email и Telegram-бот).
- Тарифы, лимиты, пробный период, оплата (ЮKassa и Т-Банк), чеки по 54-ФЗ.
- Лендинг, оферта, политика ПДн, админ-панель.

**Расширенные функции (этапы 12–18): то, что отличает нас от ecotime**
1. **Instagram** (за feature-флагом).
2. **Слоты расписания (очередь)**: задаёшь «пн–пт 10:00, 15:00, 19:00», а посты сами встают в ближайший свободный слот.
3. **Повторяющиеся и вечнозелёные посты**: повтор по расписанию, ротация пула постов.
4. **Триггеры** (как в ecotime, только шире): удалить через N часов, закрепить или открепить по времени, поставить или снять постинг на паузу, опубликовать следующий пост после успешной публикации этого.
5. **Автокросспостинг из Telegram**: пост в TG-канале автоматически уходит в VK/MAX (бот слушает `channel_post`), с правилами фильтрации и преобразования.
6. **Импорт**: RSS/Atom → черновики или автопубликация; массовая загрузка из CSV/XLSX.
7. **Согласование**: роль «автор» отправляет на согласование, редактор одобряет; комментарии к черновику; **гостевая ссылка для клиента агентства** (одобрить или вернуть пост без регистрации).
8. **Аналитика**: просмотры, охват, реакции, репосты, комментарии по постам и каналам; рост подписчиков; **«лучшее время для публикации»** по своим данным; экспорт CSV/PDF; отчёт для клиента по ссылке.
9. **AI-ассистент** (за кредиты): сгенерировать или переписать текст под сеть, сократить до лимита, подобрать хэштеги, сгенерировать ALT-текст, проверить орфографию. Провайдер LLM за интерфейсом (YandexGPT/GigaChat/Claude, выбор за владельцем).
10. **Ссылки**: автоматические UTM-метки по каналу, свой сокращатель со статистикой кликов.
11. **Шаблоны и библиотеки**: шаблоны постов, подписи, наборы хэштегов, сохранённые кнопки.
12. **Публичный REST API + исходящие вебхуки** (для тарифов Pro и Agency): интеграция с CRM и n8n/Make.
13. **Журнал аудита**: кто что сделал в workspace.

### 2.4 Админка владельца (этапы 11 и 20)
Отдельная панель `/admin` с ролями персонала (superadmin, finance, support, content, analyst), обязательной 2FA и аудитом каждого действия:
- **Бизнес-дашборд:** MRR/ARR, выручка по дням и провайдерам, регистрации и их источники (UTM), активация, DAU/WAU/MAU, конверсия trial → paid, churn, ARPU/LTV, воронка, когорты удержания, распределение по тарифам; ежедневный отчёт владельцу в Telegram и на email.
- **Операционная статистика:** публикации и их успешность по платформам, задержка публикации, ошибки API соцсетей (раннее обнаружение падений), очередь и failed jobs, состояние системы, расход и себестоимость AI.
- **Управление:** пользователи и workspace (карточка, блокировка, начисления через ledger, impersonation, запросы по 152-ФЗ), платежи и возвраты, подписки, тарифы и цены, промокоды и рефералы, выгрузка для бухгалтерии.
- **Контент и коммуникации:** CMS лендинга, FAQ, базы знаний и юр. документов, объявления в приложении, email-рассылки по сегментам, шаблоны писем, тикеты поддержки, режим обслуживания.

### 2.5 Нефункциональные требования
- Публикация в течение **60 секунд** от запланированного времени (p95).
- Ни одного дубля поста при сбоях (идемпотентность, см. §4.4).
- Целевой уровень безопасности: **OWASP ASVS 4.0 Level 2**.
- 152-ФЗ: персональные данные граждан РФ хранятся на серверах в РФ; согласие на обработку ПДн при регистрации.
- Доступность UI: адаптив до 360px, клавиатурная навигация, контраст по WCAG AA.

---

## 3. Монетизация

### 3.1 Тарифы (стартовые, цены проверяются на этапе 10)

| | **Free** | **Старт** | **Про** | **Агентство** |
|---|---|---|---|---|
| Цена/мес | 0 ₽ | 390 ₽ | 990 ₽ | 2 990 ₽ |
| Цена/год | — | 3 900 ₽ (−17%) | 9 900 ₽ | 29 900 ₽ |
| Каналы | 2 | 10 | 30 | 100 (+пакеты) |
| Постов в месяц | 30 | безлимит | безлимит | безлимит |
| Workspace / участники | 1 / 1 | 1 / 2 | 3 / 5 | ∞ / 20 |
| Медиатека | 500 МБ | 5 ГБ | 20 ГБ | 100 ГБ |
| Слоты, повторы, триггеры | — | ✓ | ✓ | ✓ |
| Автокросспостинг TG→VK/MAX, RSS | — | 1 правило | ✓ | ✓ |
| Аналитика | 7 дней | 30 дней | 1 год + экспорт | 1 год + отчёты клиентам |
| Согласование, гостевые ссылки | — | — | ✓ | ✓ + white-label |
| AI-кредиты в месяц | 10 | 100 | 500 | 2 000 |
| API и вебхуки | — | — | ✓ | ✓ |
| Поддержка | база знаний | email | приоритет | персональный менеджер |

### 3.2 Дополнительные источники дохода
1. **Пробный период 14 дней тарифа Про** без карты; после окончания мягкий переход на Free (данные не удаляются, лишние каналы ставятся на паузу).
2. **Пакеты сверх тарифа**: +10 каналов, +участники, +хранилище.
3. **Пакеты AI-кредитов**: разовая покупка, кредиты не сгорают 12 месяцев.
4. **Годовая оплата** со скидкой ~17%.
5. **Реферальная программа**: 20% с платежей приглашённых на внутренний баланс (оплата тарифа) или вывод для агентств-партнёров; у приглашённого +7 дней пробного периода.
6. **Промокоды** (процент, фиксированная сумма, бесплатные дни; лимит активаций, срок действия).
7. **White-label для агентств**: свой логотип и домен в гостевых ссылках и отчётах.
8. **Оплата в Telegram Stars**: продление прямо в боте уведомлений (дополнительный канал продаж внутри TG).

### 3.3 Платёжная архитектура
- **Источник правды о подписке — наша БД** (`subscriptions`, `invoices`, `payments`, `ledger_entries`). Платёжки только принимают деньги.
- Для ЮKassa, Т-Банка и CloudPayments: сохраняем способ оплаты (рекуррент), продление списывает наш планировщик за 3 дня до конца периода и делает до 3 повторов с паузами.
- Для Stripe и Paddle подписку ведёт провайдер, мы синхронизируемся по вебхукам.
- Крипта и Telegram Stars: разовые платежи за период, без автопродления (у Stars есть нативные подписки на 30 дней, их можно использовать).
- Все вебхуки: проверка подписи, идемпотентность по `provider_event_id`, повторная сверка статуса через API провайдера.
- Чеки 54-ФЗ: передаются через ЮKassa, Т-Банк и CloudPayments (онлайн-касса провайдера).
- Внутренний баланс и кредиты — **журнал двойной записи** (`ledger_entries`), баланс никогда не хранится одним изменяемым числом.

---

## 4. Архитектура

### 4.1 Структура репозитория

```
/
├── public/                      # ЕДИНСТВЕННЫЙ docroot nginx
│   ├── index.php                # front controller: вообще всё идёт через него
│   └── assets/                  # css, js, vendor (bootstrap, alpine, htmx), images
├── src/                         # PSR-4: namespace App\
│   ├── Kernel/                  # самописный каркас
│   │   ├── Application.php      # сборка контейнера, пайплайн middleware
│   │   ├── Container.php        # простой DI (autowiring через Reflection + явные фабрики)
│   │   ├── Config.php           # конфиг из env + config/*.php
│   │   ├── Http/                # Request, Response, JsonResponse, RedirectResponse, Router, Route, UploadedFile
│   │   ├── Middleware/          # MiddlewareInterface + pipeline
│   │   ├── Database/            # Connection (PDO), QueryBuilder (минимальный), Migrator, Transaction
│   │   ├── Session/             # RedisSessionHandler, Session, Flash
│   │   ├── Security/            # Csrf, Crypto (libsodium), PasswordHasher, RateLimiter, Signer, Csp
│   │   ├── Validation/          # Validator + правила
│   │   ├── View/                # Twig-обвязка, расширения (csrf_field, asset, csp_nonce, t)
│   │   ├── Queue/               # Job, Queue (MySQL SKIP LOCKED), Worker
│   │   ├── HttpClient/          # HttpClient поверх Guzzle + SSRF-guard
│   │   ├── Log/                 # Monolog + фильтр секретов
│   │   └── Console/             # мини-CLI для bin/console
│   ├── Http/
│   │   ├── Controllers/         # тонкие контроллеры: валидация → сервис → ответ
│   │   │   ├── Auth/  Account/  Workspace/  Channels/  Posts/  Media/  Billing/  Admin/  Api/V1/  Webhooks/
│   │   └── Middleware/          # SecurityHeaders, StartSession, VerifyCsrf, Authenticate, RequireVerifiedEmail,
│   │                            # Require2fa, ResolveWorkspace, Authorize, RateLimit, PlanFeature, ApiTokenAuth
│   ├── Domain/                  # бизнес-логика без HTTP
│   │   ├── User/  Auth/  Workspace/  Channel/  Post/  Media/  Scheduling/
│   │   ├── Publishing/  Analytics/  Billing/  Notification/  Ai/  Link/  Audit/
│   │   │   (в каждом: Entity, Repository, Service, Policy, Events, Exceptions)
│   ├── Integrations/
│   │   ├── Social/
│   │   │   ├── Contracts/       # PlatformAdapter, Publisher, StatsFetcher, ChannelConnector, PublishResult
│   │   │   ├── Telegram/  Vk/  Max/  Instagram/
│   │   │   └── Fake/            # фейковые адаптеры для тестов и dev
│   │   ├── OAuth/               # OAuthProvider + VkId, Yandex, Google, TelegramLogin
│   │   ├── Payments/            # PaymentGateway + YooKassa, TBank, CloudPayments, Stripe, Paddle, Crypto, TelegramStars, Fake
│   │   ├── Ai/                  # LlmProvider + реализации + Fake
│   │   └── Storage/             # LocalStorage, S3Storage
│   └── Support/                 # Clock, Uuid, Str, Money, helpers
├── config/                      # app.php, database.php, security.php, plans.php, platforms.php, payments.php
├── templates/                   # Twig: layouts/, components/, auth/, app/, admin/, emails/, landing/
├── database/
│   ├── migrations/              # 2026_10_04_000001_create_users.php (up/down)
│   └── seeds/                   # dev-сиды (тестовый пользователь, план-лимиты)
├── bin/
│   ├── console                  # migrate, migrate:rollback, seed, user:create-admin, queue:work, schedule:run, ...
├── tests/
│   ├── Unit/  Integration/  Feature/   # Feature = HTTP-тесты через Application in-process
│   ├── Fixtures/                # записанные ответы API соцсетей и платёжек
│   └── Support/                 # TestCase, фабрики, RefreshDatabase, FakeClock
├── docker/
│   ├── nginx/default.conf  php/Dockerfile  php/php.ini  php/php-dev.ini  mysql/my.cnf
├── storage/                     # вне docroot: logs/, cache/twig, tmp/, media/ (локальное хранилище)
├── docs/
│   ├── plans/                   # этот план, этапы, прогресс, промт агента
│   ├── architecture/            # документация по коду (см. §7)
│   ├── adr/                     # записи архитектурных решений
│   ├── api/                     # публичный API (OpenAPI, этап 18)
│   ├── user/                    # база знаний для пользователей (исходники статей)
│   ├── deploy.md  runbook.md  CHANGELOG.md
│   └── reference/               # генерируемый phpDocumentor справочник (в .gitignore)
├── scripts/agent-loop.sh        # автозапуск этапов агентом
├── .github/workflows/ci.yml
├── compose.yaml  compose.prod.yaml  Makefile  .env.example
├── phpunit.xml  phpstan.neon  .php-cs-fixer.php
├── AGENTS.md  CLAUDE.md  README.md
```

### 4.2 Docker-сервисы (dev)

| Сервис | Образ | Назначение |
|---|---|---|
| `nginx` | nginx:1.27-alpine | Отдаёт `public/`, проксирует PHP в fpm, порт `127.0.0.1:8080` |
| `app` | свой php:8.3-fpm-alpine | PHP-FPM, пользователь без root; расширения: pdo_mysql, redis, sodium, intl, gd/imagick, exif, zip, opcache, pcov (dev) |
| `worker` | тот же образ | `bin/console queue:work` (публикации, письма, статистика) |
| `scheduler` | тот же образ | цикл `bin/console schedule:run` раз в минуту |
| `mysql` | mysql:8.0 | основная БД + отдельная БД `app_test` для тестов |
| `redis` | redis:7-alpine | сессии, rate limit, кэш, блокировки |
| `mailpit` | axllent/mailpit | ловушка писем, UI на `127.0.0.1:8025` |
| `minio` (профиль `s3`) | minio/minio | S3-совместимое хранилище (нужно для Instagram) |
| `tunnel` (профиль `tunnel`) | cloudflare/cloudflared | публичный HTTPS для OAuth-коллбеков, вебхуков и IG при ручной проверке |

Все команды запускаются через `Makefile`: `make up`, `make down`, `make sh`, `make migrate`, `make seed`, `make test`, `make stan`, `make cs`, `make cs-fix`, `make audit`, `make check` (= cs + stan + test + audit).

### 4.3 Схема данных (ключевые таблицы; детали — в файлах этапов)

```
users(id, email UNIQUE NULL, email_verified_at, password_hash NULL, name, locale, timezone,
      totp_secret_enc NULL, totp_enabled_at, is_superadmin, status, created_at, updated_at)
user_identities(id, user_id, provider[vkid|yandex|google|telegram], provider_user_id, email, linked_at)  UNIQUE(provider, provider_user_id)
user_sessions(id, user_id, session_id_hash, ip, user_agent, created_at, last_seen_at, revoked_at)
auth_tokens(id, user_id, type[email_verify|password_reset|email_change|remember], token_hash, expires_at, used_at)
recovery_codes(id, user_id, code_hash, used_at)
workspaces(id, owner_id, name, slug, timezone, plan_id, created_at)
workspace_members(workspace_id, user_id, role[owner|admin|editor|author|viewer|client], invited_by, joined_at)
invitations(id, workspace_id, email, role, token_hash, expires_at, accepted_at)
channels(id, workspace_id, platform, external_id, title, avatar_url, status[active|paused|error|revoked],
         credential_id, settings_json, last_health_at, last_error)  UNIQUE(workspace_id, platform, external_id)
platform_credentials(id, workspace_id, platform, kind[oauth|bot_token], secret_enc, key_id, expires_at, refresh_enc, scopes)
media(id, workspace_id, uploader_id, storage_key, mime, size, width, height, duration, sha256, variants_json, created_at)
posts(id, workspace_id, author_id, status[draft|pending_approval|approved|scheduled|publishing|published|partially_failed|failed|cancelled],
      base_text, scheduled_at, timezone, created_at, updated_at)
post_variants(id, post_id, channel_id, text, media_ids_json, options_json[buttons, first_comment, pin, silent, delete_after...])
publications(id, post_variant_id, channel_id, status[queued|sending|sent|failed|unknown], attempt, idempotency_key UNIQUE,
             external_post_id, external_url, error_code, error_message, sent_at)
jobs(id, queue, payload_json, available_at, reserved_at, reserved_by, attempts, max_attempts, last_error, created_at)
failed_jobs(...)
schedule_slots, recurring_rules, triggers, crosspost_rules, rss_feeds         (этап 13)
approvals, post_comments, guest_links                                          (этап 14)
post_stats_snapshots, channel_stats_snapshots                                  (этап 15)
plans, subscriptions, invoices, payments, payment_methods, webhook_events,
promo_codes, promo_redemptions, ledger_accounts, ledger_entries, referrals    (этапы 10, 16)
ai_requests, short_links, link_clicks                                          (этап 17)
api_tokens, webhooks_out, webhook_deliveries                                   (этап 18)
audit_log(id, workspace_id, actor_id, action, subject_type, subject_id, ip, meta_json, created_at)
```

### 4.4 Конвейер публикации (сердце продукта)

```
[Пользователь планирует пост] → posts.status=scheduled, publications(queued) на каждый вариант
        │
[scheduler, каждую минуту]  SELECT publications с наступившим временем → кладёт PublishJob в jobs (идемпотентно)
        │
[worker] забирает job (SKIP LOCKED) → блокировка в Redis на publication_id
        → publications.status = sending (в транзакции, только из queued/failed-retryable)
        → PlatformAdapter::publish(variant)
             ├─ успех → sent + external_id/url, событие PostPublished → триггеры, уведомления
             ├─ временная ошибка (5xx, timeout ДО отправки, 429) → failed-retryable, backoff 1/5/15/60 мин, до 5 попыток
             ├─ постоянная ошибка (нет прав, токен отозван, контент отклонён) → failed + channel.status=error + уведомление
             └─ неизвестный исход (обрыв ПОСЛЕ отправки запроса) → unknown: без автоповтора (иначе дубль),
                для VK/TG сверка «последние посты канала», иначе решает пользователь
```
Правило: **лучше не опубликовать и сообщить, чем опубликовать дважды.**

### 4.5 Интерфейс адаптера платформы

```php
interface PlatformAdapter {
    public function platform(): Platform;                        // enum
    public function capabilities(): Capabilities;                // лимиты символов, медиа, кнопки, опросы, first comment...
    public function validate(PostVariant $v): ValidationResult;  // до планирования
    public function publish(PostVariant $v, Channel $c, Credential $cr, string $idempotencyKey): PublishResult;
    public function delete(Publication $p, Channel $c, Credential $cr): void;
    public function pin(Publication $p, Channel $c, Credential $cr, bool $pin): void;
    public function healthCheck(Channel $c, Credential $cr): HealthStatus;
}
// + отдельные интерфейсы StatsFetcher, ChannelConnector (OAuth / bot-код), InboundListener (для кросспостинга)
```

---

## 5. Безопасность (сводка; обязательный чек-лист — в ENGINEERING_RULES §4)

- Пароли: `password_hash` с **Argon2id**, политика не короче 10 символов + проверка по утечкам (HIBP k-anonymity, опционально), прозрачный rehash.
- Сессии: на сервере в Redis, cookie `__Host-sid; Secure; HttpOnly; SameSite=Lax`, новый id при входе и смене привилегий, idle-таймаут 2 ч, абсолютный 30 дн («запомнить меня» — отдельный хэшированный токен), список устройств и отзыв сессий.
- CSRF: синхронизирующий токен для всех не-GET запросов + проверка `Origin`/`Sec-Fetch-Site`.
- XSS: Twig autoescape, строгий CSP с nonce, никакого inline JS/обработчиков, `|raw` запрещён (кроме проверенного санитайзера).
- SQLi: только PDO prepared statements, `ATTR_EMULATE_PREPARES=false`, идентификаторы из белого списка.
- IDOR: любой доступ к данным идёт через репозиторий с обязательным `workspace_id` + Policy; на каждый ресурс есть тест «чужой пользователь получает 404».
- Токены соцсетей и TOTP-секреты: шифрование libsodium `crypto_secretbox` (XSalsa20-Poly1305) ключом из env, с `key_id` для ротации.
- OAuth: `state` + PKCE + `nonce`; связывание аккаунтов только из уже аутентифицированной сессии; провайдерскому email без подтверждения не доверяем (защита от захвата аккаунта).
- Telegram Login: проверка HMAC-SHA256 подписи и `auth_date` не старше 24 ч.
- Загрузки: лимит размера, MIME через `finfo`, пересжатие изображений (удаляет полезную нагрузку и EXIF), случайные имена, хранение вне docroot, раздача через подписанные URL с TTL.
- SSRF: все исходящие запросы по URL пользователя (RSS, медиа по ссылке) идут через guard: только http(s), запрет приватных/loopback/link-local адресов после DNS-резолва, привязка к IP, лимит размера и времени.
- Rate limit: вход, регистрация, сброс пароля, 2FA, API, загрузка, AI.
- Вебхуки платёжек: подпись, IP allowlist (ЮKassa), повторная сверка статуса, идемпотентность.
- Заголовки: HSTS, CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'`.
- Инфраструктура: контейнеры не от root, БД и Redis не опубликованы наружу, `display_errors=Off` в prod, `expose_php=Off`, секреты только в env, `composer audit` в CI.
- Журнал аудита и логи безопасности (неудачные входы, смена пароля и 2FA, выдача ролей) без секретов и ПДн сверх необходимого.

---

## 6. Этапы

| # | Этап | Файл | Ручная проверка владельцем |
|---|---|---|---|
| 00 | Инфраструктура, Docker, CI, удаление legacy | [stages/00-bootstrap.md](stages/00-bootstrap.md) | да (название, домен, юрлицо) |
| 01 | Ядро: роутер, DI, middleware, БД, сессии, Twig, безопасность | [stages/01-kernel.md](stages/01-kernel.md) | нет |
| 02 | Регистрация и вход по email, 2FA, сессии | [stages/02-auth-email.md](stages/02-auth-email.md) | да (UX входа) |
| 03 | Вход через соцсети: VK ID, Яндекс, Telegram, Google | [stages/03-auth-social.md](stages/03-auth-social.md) | да (ключи приложений) |
| 04 | Workspace, команда, роли, аудит | [stages/04-workspaces.md](stages/04-workspaces.md) | нет |
| 05 | Медиатека | [stages/05-media.md](stages/05-media.md) | нет |
| 06 | Каналы + Telegram-коннектор | [stages/06-channels-telegram.md](stages/06-channels-telegram.md) | да (токен бота) |
| 07 | Редактор, календарь, планировщик, публикация | [stages/07-posts-scheduler.md](stages/07-posts-scheduler.md) | да (реальный пост в TG) |
| 08 | VK | [stages/08-vk.md](stages/08-vk.md) | да |
| 09 | MAX | [stages/09-max.md](stages/09-max.md) | да |
| 10 | Биллинг: тарифы, лимиты, ЮKassa, Т-Банк | [stages/10-billing-core.md](stages/10-billing-core.md) | да (тестовые магазины) |
| 11 | Лендинг, юр. страницы, базовая админка → **MVP** | [stages/11-landing-admin-mvp.md](stages/11-landing-admin-mvp.md) | да |
| 20 | Полная админка владельца, бизнес-дашборд, статистика (идёт сразу после 11) | [stages/20-owner-admin.md](stages/20-owner-admin.md) | да |
| 12 | Instagram (feature-флаг) | [stages/12-instagram.md](stages/12-instagram.md) | да |
| 13 | Слоты, повторы, триггеры, кросспостинг, RSS, CSV | [stages/13-advanced-scheduling.md](stages/13-advanced-scheduling.md) | нет |
| 14 | Согласование и гостевые ссылки | [stages/14-approvals.md](stages/14-approvals.md) | нет |
| 15 | Аналитика | [stages/15-analytics.md](stages/15-analytics.md) | да (реальные данные) |
| 16 | Остальные платёжки, рефералка, промокоды | [stages/16-payments-extra.md](stages/16-payments-extra.md) | да |
| 17 | AI-ассистент, UTM и сокращатель | [stages/17-ai-links.md](stages/17-ai-links.md) | да (ключ LLM) |
| 18 | Публичный API и вебхуки | [stages/18-public-api.md](stages/18-public-api.md) | нет |
| 19 | Hardening, нагрузка, prod-деплой | [stages/19-hardening-prod.md](stages/19-hardening-prod.md) | да |

После этапа 11 продукт можно запускать (MVP). Этап 20 номером последний, но в очереди стоит сразу после 11: порядок выполнения задаёт таблица в PROGRESS.md, а не номер. Этапы 12–18 в основном независимы, их порядок может поменять владелец.

---

## 7. Документация по коду

Документация пишется вместе с кодом в том же PR; этап без неё не считается завершённым.

| Что | Где | Кто и когда обновляет |
|---|---|---|
| Обзор архитектуры, жизненный цикл запроса, DI, middleware | `docs/architecture/overview.md`, `request-lifecycle.md` | этапы 00–01, далее при изменениях |
| Документ на каждый модуль `Domain/*` (назначение, сущности, статусы, события, публичные сервисы, инварианты) | `docs/architecture/modules/<module>.md` | этап, создающий или меняющий модуль |
| Схема БД (таблицы, связи, индексы, ER-диаграмма в Mermaid) | `docs/architecture/database.md` | каждая миграция |
| Конвейер публикации, state machine | `docs/architecture/publishing.md` | этап 07, далее по изменениям |
| Руководства «как добавить новую соцсеть / платёжку / OAuth-провайдера / LLM» | `docs/architecture/howto-*.md` | этапы 06, 10, 03, 17 |
| Безопасность: модель угроз, как устроены auth, CSRF, шифрование, ротация ключей | `docs/architecture/security.md` | этапы 01–03, 19 |
| Конфигурация: все env-переменные с описанием | `docs/architecture/configuration.md` + `.env.example` | любое изменение env |
| Архитектурные решения | `docs/adr/NNNN-*.md` | при отклонении от плана |
| PHPDoc | в коде: класс-уровень у всех классов `src/`, у публичных методов `Domain`, `Integrations`, `Kernel`, где сигнатуры недостаточно (исключения, побочные эффекты, единицы измерения) | всегда; проверяет PHPStan-правило |
| Справочник API кода | `make docs` → phpDocumentor (docker-образ `phpdoc/phpdoc`) → `docs/reference/` | генерируется, не коммитится |
| Публичный REST API | `docs/api/openapi.yaml` + Redoc | этап 18 |
| Деплой, эксплуатация, инциденты | `docs/deploy.md`, `docs/runbook.md` | этапы 11, 19 |
| История изменений | `docs/CHANGELOG.md` (Keep a Changelog) | каждый этап |
| База знаний для пользователей | `docs/user/*.md` → страницы `/help` | этапы с пользовательскими функциями |
