# MAX (этап 09)

Публикация в каналы и группы MAX. Решение и список непроверенного: [ADR 0007](../../adr/0007-max-bot-api.md). Код: `src/Integrations/Social/Max`, `src/Domain/Channel` (`MaxUpdateHandler`, `ChannelService::connectOwnMaxBot`, `SharedBot`), `src/Http/Controllers/Channels/MaxConnectController.php`, `src/Http/Controllers/Webhooks/MaxWebhookController.php`, `src/Kernel/Console/Command/Max{Poll,Webhook}Command.php`, шаблон `templates/workspace/channels/connect_max.twig`.

## Включение
`PLATFORMS_ENABLED=telegram,vk,max`. Для режима общего бота: `MAX_BOT_TOKEN` (бот верифицированного юрлица, кабинет `business.max.ru`), `MAX_WEBHOOK_SECRET` (16–128 символов `A-Za-z0-9_-`), по желанию `MAX_BOT_USERNAME` (иначе берётся из `GET /me` и кэшируется на час), затем `make console CMD="max:webhook set https://публичный-хост"` (нужен HTTPS на порту 443) или для разработки `make console CMD="max:poll"`. `MAX_API_BASE` менять не нужно (по умолчанию `https://platform-api2.max.ru`). Без `MAX_BOT_TOKEN` страница подключения говорит, что бот сервиса не настроен, и работает режим «свой бот».

## Подключение
- **Через бота сервиса.** `/w/{id}/channels/connect/max` (право `channels.manage`): добавить бота администратором канала, получить код (`ConnectCodes`, одноразовый, 15 минут, в БД только SHA-256, для платформы `max`; код Telegram в MAX не подходит), написать `/connect КОД`. `POST /webhooks/max/{secret}` → `MaxWebhookController` проверяет путь и заголовок `X-Max-Bot-Api-Secret` до чтения тела, отвечает 200 на любое подлинное обращение. `MaxUpdateHandler`: `message_created` в канале (`chat_type=channel`, у сообщения нет `sender`: писать там могут только администраторы) или в группе (`chat`: отправитель проверяется `GET /chats/{id}/members?user_ids=`, нужен администратор или владелец); лимиты `max-connect:{chat}` (10 за 10 минут) и `max-connect:all` (600 за 10 минут); код смотрится (`peek`), права бота проверяются `MaxInspector::inspect`, право `channels.manage` автора кода и лимит каналов проверяются заново, код гасится атомарно (`consume`), создаётся канал (`mode=shared_bot`), сообщение с кодом удаляется при любом исходе. Ошибки видны ждущей странице через `channel_connect_codes.failure`. Личное сообщение боту (`/start`, `/help`, `/connect`) получает инструкцию. `bot_removed` запускает проверку здоровья каналов этого чата.
- **Свой бот.** Вкладка «Свой бот» (`POST …/connect/max/own`, лимит 15 в час): токен (формат не документирован, отсекается только явный мусор), ссылка `max.ru/имя`, `@имя`, имя или номер чата. `MaxAdapter::connect`: `GET /me` (неверный токен говорит «токен», а не «канал не найден»), `MaxInspector::inspect`, шифрованная запись в `platform_credentials` (`CredentialVault`), канал `mode=own_bot`; повторное подключение того же канала меняет токен на месте. Токен на страницы и в журнал не попадает; при ошибке в форме остаётся только введённая ссылка.
- **Списка чатов нет:** MAX не даёт перечислить чаты бота (с июня 2026), поэтому нет шага «выберите из списка», а канал указывается ссылкой или номером.

## Клиент и ошибки
`MaxClient` (по экземпляру на токен, `MaxClientFactory`): заголовок `Authorization: <токен>` (без `Bearer`), JSON, `http_errors=false`. Адрес файлового сервера принимается только по HTTPS и получает файл без токена. Классы ошибок:

| Ответ MAX | Класс | Смысл |
|---|---|---|
| 401 | `auth` | токен не подошёл |
| 403 | `auth` | бота убрали или забрали права |
| 400 `attachment.not.ready` | `temporary` (3 с) | файл ещё обрабатывается (адаптер ждёт сам) |
| 404, `*.not.found` | `permanent` | канал не найден |
| 429 | `rate_limited` (2 с) | слишком часто |
| 5xx, нечитаемый ответ, 0 | `temporary` | сбой MAX |
| 200 `success:false` | `permanent` | редактирование, удаление или закрепление отклонены |
| остальные 4xx | `permanent` | запрос не принят |

Обрыв после отправки публикующего вызова (сообщение, правка, удаление, закрепление) даёт `unknown_outcome`; обрыв до соединения и сбои загрузок и чтения: `temporary`.

## Что умеет адаптер
Текст до 4000 символов (`format: html`, `textFormat=html`: `<b> <i> <s> <a>`), до 10 вложений в одном сообщении (фото ≤ 50 МБ, видео ≤ 250 МБ, файлы ≤ 2 ГБ по проверке адаптера, 100 МБ по лимиту библиотеки), до 30 кнопок-ссылок (вложение `inline_keyboard`, каждая кнопка в своей строке, подпись ≤ 128 символов, только http/https), тихая отправка (`notify=false`, при отказе канала откат на обычную: ADR 0007 п. 5), отключение предпросмотра (`disable_link_preview`), закрепление и снятие закрепления, удаление, правка текста (вложения и кнопки не трогаются). Нет: опросов, первого комментария. Файлы: слот `POST /uploads?type=`, затем файл полем `data`; токен вложения из слота или из ответа файлового сервера (`photos.*.token`); отправка повторяется при «не готово» с паузами 1, 2, 4, 8, 15, 30 секунд, затем `temporary` с повтором конвейера через 30 с. Результат: `externalId` = `message.body.mid`, ссылка = `message.url` (только публичные каналы).

Лимит 2 сообщения в секунду на чат: `MaxRateGate` (Redis `max:rate:{sha256(токен:чат)[0:16]}:{секунда}`).

## Здоровье
`healthCheck`: `GET /chats/{id}` (`status` не `active` → «бота убрали», `revoked`), `GET /chats/{id}/members/me` (нет права публикации → `error` без `revoked`). 401/403/404 помечают канал сломанным (403 и 404 как `revoked`), сбои сети и MAX оставляют статус.

## Тесты
`tests/Unit/Channel/{MaxClientTest,MaxAdapterTest}.php` (заголовок с токеном, загрузка в два шага, ожидание обработки, откат тихой отправки, права, разбор ссылок), `tests/Feature/Channel/{MaxWebhookTest,MaxConnectTest,MaxCommandsTest}.php` (подлинность вебхука, код в канале и группе, права, лимиты, свой бот, роли, CSRF, флаг), `tests/Integration/Post/MaxPublishingTest.php` (пост от разметки до MAX через настоящий конвейер). Ответы MAX: `tests/Fixtures/max/*.json`, загрузчик `tests/Support/MaxFixtures`. Ни один тест не ходит в сеть.
