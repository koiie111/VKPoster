# API и вебхуки

Настройки пространства → **API и вебхуки** доступны владельцу и администратору на тарифе Про и выше. Создайте токен с минимальными правами, при необходимости задайте срок. Скопируйте его сразу: повторно увидеть токен нельзя. Отзыв действует для всех последующих запросов. Токен работает от имени создавшего его участника, с его **текущей** ролью и доступом к каналам. Удаление участника или блокировка пользователя прекращает доступ.

Интерактивная документация: `/api/docs`; OpenAPI 3.1: `/api/openapi.yaml`.

## curl

Задайте адрес вашей установки и токен локально, не в репозитории. Значения ниже — placeholders.

```sh
export VKPOSTER_URL='https://poster.example.com'
export VKPOSTER_TOKEN='<your-token>'
curl "$VKPOSTER_URL/api/v1/channels" \
  -H "Authorization: Bearer $VKPOSTER_TOKEN"

curl "$VKPOSTER_URL/api/v1/posts" \
  -H "Authorization: Bearer $VKPOSTER_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: crm-entry-123-draft-v1' \
  --data '{"text":"Новый материал","channels":["<channel-public-id>"]}'

curl "$VKPOSTER_URL/api/v1/posts/<post-public-id>/schedule" \
  -H "Authorization: Bearer $VKPOSTER_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: crm-entry-123-schedule-v1' \
  --data '{"scheduled_at":"2026-11-01T12:00:00+03:00"}'

curl "$VKPOSTER_URL/api/v1/media" \
  -H "Authorization: Bearer $VKPOSTER_TOKEN" \
  -H 'Idempotency-Key: asset-123-v1' \
  -F 'file=@image.jpg'
```

Для загрузки по URL отправьте JSON `{"url":"https://images.example.com/photo.jpg"}` вместо multipart. Действуют проверки SSRF, MIME и существующие лимиты медиатеки.

Все изменения требуют `Idempotency-Key` (1–128 печатных ASCII-символов). Повторяйте **тот же** ключ и payload после сетевой ошибки: успешный ответ сохраняется на 24 часа, конкурентные запросы создают один результат. Другой payload с тем же ключом даёт 409. После истечения TTL используйте собственный external-id журнал, чтобы не создавать повторно материал. 4xx/5xx не сохраняются как успешный результат. Текущие права проверяются и при replay. Ключи принадлежат отдельному токену.

Пагинация: `?limit=25&cursor=<next_cursor>`; максимум 100. Курсор непрозрачный, привязан к токену и коллекции. Завершение — `next_cursor: null`. Из-за фильтрации доступа страница может быть пустой при непустом следующем курсоре: продолжайте обход.

Scopes: `channels:read`, `posts:read`, `posts:write`, `media:write`, `stats:read`. Планирование не обходит согласование, тарифные лимиты и разрешения каналов. API создаёт обычный PostDraft; дальнейшую работу выполняет существующий publishing pipeline.

Лимит: Про — 60, Агентство — 300 запросов/минуту на токен (явный лимит плана имеет приоритет). Следите за `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`. При 429 ждите `Retry-After`. При недоступном Redis API возвращает 503, доступ не разрешается обходным путём. Ошибки имеют вид `{"error":{"code":"...","message":"...","details":null}}`.

Статистика: `GET /api/v1/stats?from=2026-10-01&to=2026-10-18&channel=<channel-public-id>`. Доступный период определяется тарифом. Неизвестные метрики — `null`, а не 0; Telegram Bot API не предоставляет views/reach для этой аналитики.

## n8n

Создайте **HTTP Request** node. Base URL — ваша установка; Authentication → Header Auth, имя `Authorization`, значение `Bearer <your-token>` в credentials n8n. Для POST `/api/v1/posts` добавьте JSON body и `Idempotency-Key` из устойчивого ID записи исходной системы + версии операции. При retry сохраняйте ключ; не генерируйте новый случайный ключ для каждой попытки. Следующий node получает `data.id` и отдельным POST планирует публикацию. Loop хранит `next_cursor`, пока тот не станет null.

## Make

Используйте **HTTP → Make a request**, метод POST, URL `<base>/api/v1/posts`, JSON body. Authorization храните в защищённых настройках соединения. Передавайте `Idempotency-Key` из ID исходной записи; повтор сценария использует прежний ключ. В error handler различайте 429/503 (повтор с задержкой) и 403/409/422 (исправление прав/данных). Для входящих событий используйте webhook, за которым стоит проверяющий HMAC endpoint: URL Make без собственной проверки подписи не считайте доверенной границей.

## Проверка подписи вебхука

Создайте подписку на публичный HTTP(S) URL. Для production используйте HTTPS. Секрет показывается один раз, хранится сервером зашифрованно. Подписки на все события пространства доступны только администратору с доступом ко всем каналам.

События: `post.published`, `post.failed`, `channel.error`, `approval.submitted`, `approval.approved`, `approval.changes_requested`, `approval.revision_changed`, `approval.scheduled`, `approval.settings_updated` (в UI `approval.*`). Payload содержит `id`, `type`, `created_at`, `workspace_id` (public ID), `version: 1`, `data` с безопасными public ID/статусами. Полные посты, внутренние ошибки и credentials не отправляются.

`X-Signature: t=<unix-seconds>,v1=<hex-HMAC-SHA256>` подписывает **точные байты** `timestamp + '.' + raw_body`. Пример PHP на стороне получателя (не код VKPoster):

```php
$raw = file_get_contents('php://input');
$header = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if (!preg_match('/^t=([0-9]+),v1=([a-f0-9]{64})$/D', $header, $m)
    || abs(time() - (int) $m[1]) > 300
    || !hash_equals(hash_hmac('sha256', $m[1] . '.' . $raw, getenv('VKPOSTER_WEBHOOK_SECRET')), $m[2])) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
// Atomically persist event.id under a UNIQUE constraint with your business action/outbox.
// A previously committed event.id returns 2xx without repeating that action.
http_response_code(204);
```

Принимайте событие только после проверки подписи, возраста timestamp и durable сохранения. Доставка **at least once**: crash после HTTP-ответа получателя до сохранения результата может привести к повтору с тем же event ID. Порядок разных событий не гарантируется. На каждую попытку формируется актуальная подпись. Не полагайтесь только на `X-Webhook-Id` без HMAC.

Доставка через стандартный worker/default queue. Scheduler каждую минуту переносит durable outbox в очередь и восстанавливает потерянные задания. Timeout 10 секунд, соединение 3 секунды; redirects не выполняются. URL/DNS проверяются при создании и каждой попытке, адрес соединения закрепляется SSRF-защитой. До 5 попыток, задержки 60/300/900/3600 секунд. После 10 последовательных неудачных попыток подписка отключается; можно включить её заново и повторить failed delivery в журнале. HTTP body получателя не сохраняется: только код ответа и безопасная категория ошибки. Отзыв прав/тарифа либо выключение подписки пропускает ожидающую доставку.

`WEBHOOK_FAILURE_LIMIT=10`, `WEBHOOK_RETENTION_DAYS=35` настраивают порог и retention (минимум 35 дней). Удаляются только завершённые события без pending/processing доставок; незавершённые сохраняются для recovery. Просроченные записи идемпотентности очищаются ежедневным scheduler. Следите за scheduler/worker и системным временем (подписи чувствительны к рассинхронизации часов).
