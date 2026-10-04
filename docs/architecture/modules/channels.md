# Каналы и Telegram (этап 06)

Общая модель «канал + учётные данные + адаптер платформы» и первая настоящая платформа, Telegram. Код: `src/Domain/Channel`, `src/Integrations/Social`, `src/Http/Controllers/Channels`, `src/Http/Controllers/Webhooks`, шаблоны `templates/workspace/channels/`, скрипт `public/assets/js/channels.js`.

## Таблицы
- `channels`: канал или группа пространства. `public_id` (ULID для URL), `platform` (`telegram`, позже `vk`, `max`, `instagram`; `fake` только вне production), `external_id` (id чата в сети), `mode` (`shared_bot` — публикует наш бот, `own_bot` — бот клиента), `title` (из сети), `alias` (название только для команды), `username`, `kind` (`channel` | `group`), `avatar_key` (копия картинки в нашем хранилище), `status` (`active` | `paused` | `error` | `revoked`), `credential_id`, `settings_json` (`rights`: что боту разрешено: `post`, `edit`, `delete`, `pin`), `last_health_at`, `last_error`. `UNIQUE(workspace_id, platform, external_id)`; один и тот же чат можно подключить в разных пространствах.
- `platform_credentials`: токен бота клиента. `secret_enc` и `refresh_enc` — только шифртекст `Crypto` (ключ записан внутри значения, поэтому отдельного `key_id` нет), `hint` — маска вида `1234…abcd` для показа. Токен нашего общего бота в БД не хранится (переменная `TELEGRAM_BOT_TOKEN`).
- `channel_connect_codes`: одноразовые коды подключения. В таблице только SHA-256 кода, срок 15 минут, `used_at`, `channel_id`, `failure` (почему последняя попытка не подключила канал: это показывает страница ожидания).
- `member_channel_access.channel_id` теперь с внешним ключом на `channels` (`ON DELETE CASCADE`).
Всё удаляется каскадом вместе с пространством.

## Платформы
`Integrations/Social/Contracts`: `Platform` (enum), `PlatformAdapter` (`capabilities`, `validate`, `publish`, `delete`, `pin`, `healthCheck`), `ChannelConnector` (подключение по секрету клиента), `Capabilities`, `PublishRequest` + `PublishMedia` (что публиковать: текст, файлы, кнопки, опрос), `PublishResult` (`externalId`, `url`, `allIds` для альбомов), `HealthStatus`, `Credential` (секрет в памяти на время вызова, скрыт из дампов), `PlatformError` с классификацией `ErrorKind`: `temporary | permanent | auth | rate_limited | unknown_outcome` (смысл и реакция конвейера: мастер-план §4.4).
`PlatformRegistry` собирает адаптеры и оставляет включённые флагом `PLATFORMS_ENABLED`; `fake` игнорируется в production. Новая сеть = один класс-адаптер + строка в `config/services.php`.

**Отличие от мастер-плана:** `publish()` принимает `PublishRequest`, а не `PostVariant` (поста ещё нет; этап 07 соберёт запрос из варианта поста), `delete`/`pin` принимают `PublishResult`.

## Telegram
`TelegramClient`: свой клиент Bot API поверх `HttpClientInterface` (один экземпляр на токен): `getMe`, `getChat`, `getChatMember`, `getChatAdministrators`, `sendMessage`, `sendPhoto`, `sendVideo`, `sendDocument`, `sendMediaGroup`, `sendPoll`, `pinChatMessage`, `unpinChatMessage`, `deleteMessage`, `editMessageText`, `setWebhook`, `deleteWebhook`, `getWebhookInfo`, `getUpdates`, `getFile`. Ошибки:
- 429 → `rate_limited` с `retry_after`; 5xx и нечитаемый ответ → `temporary`; 401, 403, «нет прав» → `auth`; «chat not found», слишком длинный текст и прочее 4xx → `permanent`;
- обрыв соединения **до** отправки (DNS, отказ, TLS) → `temporary`; любой другой обрыв у методов, которые что-то отправляют → `unknown_outcome` (без автоповтора, иначе дубль);
- токен стоит в URL, поэтому исключения Guzzle не цепляются и не цитируются: в логи и на страницы он попасть не может (тест).
`TelegramAdapter`: текст до 4096, подпись до 1024, альбом до 10 файлов, опросы, кнопки-ссылки, тихая отправка. Если текст не помещается в подпись, он уходит отдельным сообщением сразу после файлов (кнопки под текстом); если это второе сообщение не ушло, результат `unknown_outcome` (файлы уже опубликованы). `validate()` возвращает русские замечания для редактора. У Telegram нет ключей идемпотентности, `$idempotencyKey` игнорируется.
`TelegramInspector`: «что это за чат и что может бот»: тип чата (канал, супергруппа, группа), права из `getChatMember` (в канале публикация требует `can_post_messages`; в группе бот должен быть администратором), разбор того, что набрал человек (`@name`, `t.me/name`, `-100…`). Идентификатор бота берётся из префикса токена, лишнего `getMe` нет.

## Подключение канала
**Через бота сервиса** (`TELEGRAM_BOT_TOKEN`):
1. На странице `/w/{id}/channels/connect/telegram` человек получает код (10 символов без похожих букв, 15 минут, в БД только хэш; новый код отменяет прежний неиспользованный). Страница опрашивает `…/status/{codeId}` (`channels.js`, раз в 3 с) и сама перекидывает в список, когда канал появился.
2. Человек добавляет бота администратором с правом «Публикация сообщений» и пишет в канале `/connect КОД`.
3. Telegram присылает апдейт на `/webhooks/telegram/{secret}` (или `bin/console telegram:poll` в разработке). `TelegramUpdateHandler`: ограничение частоты (10 за 10 минут на чат, 600 за 10 минут всего) → код есть и жив (`peek`, не расходуя) → отправитель администратор (в канале писать могут только администраторы; в группе проверяется `getChatMember`) → бот может публиковать (`inspect`) → у владельца кода всё ещё есть право `channels.manage` → лимит каналов → код расходуется атомарно (`UPDATE … WHERE used_at IS NULL`) → строка канала создаётся (или обновляется, если чат подключали раньше: id канала, посты и доступы сохраняются) → копия аватарки → запись `channel.connected` в журнал → сообщение с кодом удаляется. Неудача не расходует код и записывает причину в `failure`.
Запись о подключении не зависит от сессии человека: код сам служит учётными данными, а контекст пространства собирается из владельца кода (его членство перепроверяется).

**Свой бот**: человек вводит токен (поле `password`, в форму он не возвращается) и канал. `ChannelService::connectOwnTelegramBot`: формат токена → `getMe` (неверный токен говорит «токен», а не «канал не найден») → `getChat`/`getChatMember` → токен шифруется в `platform_credentials`. Bot API **не умеет перечислять чаты бота**, поэтому «выбор канала из тех, где бот админ» заменён вводом `@имя` или номера канала. Повторное подключение того же чата заменяет токен на месте.

**Вебхук**: `POST /webhooks/telegram/{secret}` без сессии и CSRF; два секрета проверяются до чтения тела: путь (`TELEGRAM_WEBHOOK_SECRET`) и заголовок `X-Telegram-Bot-Api-Secret-Token` (производный от первого, `TelegramWebhook::headerToken`). Нет секрета в настройках: 404; неверные: 403; подлинный вызов всегда получает 200, чтобы Telegram не слал повторов. Установка: `bin/console telegram:webhook set https://публичный-адрес` (секрет не печатается).

## Проверка здоровья
`ChannelHealthService::check()`: `healthCheck` адаптера → `active` (права обновляются, ошибка стирается) | `error`/`revoked` с причиной | «не удалось выяснить» (сеть, лимит: статус не меняется). Запускается: раз в час планировщиком (`channels-health`: в очередь ставится `CheckChannelHealthJob` на каждый канал, которому больше 6 часов), по сообщению бота об изменении членства (`my_chat_member`), кнопкой «Проверить сейчас», при возобновлении после паузы; этап 07 вызовет её же перед публикацией. Когда активный канал ломается, владельцу и администраторам уходит одно письмо (`channel_broken`); повторные проверки сломанного канала не шлют новых. Канал на паузе сохраняет статус и молчит, но причина записывается.
Статусы: `revoked`: бота удалили или канал недоступен (403, «chat not found»); `error`: не хватает прав или токен не подошёл.

## Права и видимость
`channels.view` (владелец, администратор, редактор, автор, наблюдатель; клиент не видит раздел) и `channels.manage` (владелец, администратор). Участник с ограничением (`member_channel_access`) видит и открывает только назначенные каналы, чужие дают 404. Все репозитории данных пространства наследуют `WorkspaceScopedRepository`; запросы без контекста (вебхук, проверка здоровья) вынесены в `ChannelSystem` и `ConnectCodeRedeemer`.

## Журнал и аудит
`channel.connected`, `channel.disconnected`, `channel.paused`, `channel.resumed`, `channel.renamed` (подписи в `AuditActions`). Токены и коды в журнал не попадают.

## Команды
- `telegram:poll [--once]`: долгий опрос `getUpdates` для разработки без публичного HTTPS; снимает вебхук, выходит по SIGTERM/SIGINT; в production отказывается работать.
- `telegram:webhook set [URL] | delete | info`.
- `channels:check`: поставить в очередь проверки просроченных каналов (то же делает планировщик).
- `crypto:rotate [--dry-run]`: перешифровать все секреты (`users.totp_secret_enc`, `platform_credentials.*_enc`) текущим `APP_KEY`; запускать после смены ключа, пока старый лежит в `APP_KEYS`. Новый столбец `*_enc` нужно добавить в список команды.

## Разработка и тесты
- Тестовая сеть `fake` (`FakeAdapter`, платформа «Тестовая сеть»): подключается страницей `/w/{id}/channels/connect/fake`, «публикует» в журнал и в память, ошибки можно запрограммировать (`failNext`), канал с id `broken-…` не проходит проверку. Включается `PLATFORMS_ENABLED=telegram,fake`, в production не работает.
- Записанные ответы Bot API: `tests/Fixtures/telegram/*.json`, загрузчик `tests/Support/TelegramFixtures`, база тестов `tests/Support/ChannelTestCase` (HTTP замокан, ни один тест не ходит в Telegram).

## Что сделал этап 07 (см. [posts.md](posts.md))
Адаптер получает `PublishRequest` и локальные пути файлов (варианты под сеть из `VariantService`); результат хранить целиком, включая `allIds` (удаление альбома удаляет все сообщения); `Channel::postUrl()` строит ссылку на пост по `username`; перед публикацией вызвать `ChannelHealthService::check()`; при удалении канала отменять его запланированные посты (сейчас отключение канала постов не касается, их ещё нет).
