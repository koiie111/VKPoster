# Очередь задач и планировщик

Брокера нет: очередь живёт в MySQL, воркеры забирают задачи через `SELECT … FOR UPDATE SKIP LOCKED` (ADR 0001, мастер-план §1).

## Таблицы
- `jobs(id, queue, payload_json, available_at, reserved_at, reserved_by, attempts, max_attempts, last_error, created_at)` — ожидающие и выполняющиеся задачи. Индекс `(queue, reserved_at, available_at, id)`.
- `failed_jobs(id, queue, payload_json, attempts, error, failed_at)` — задачи, исчерпавшие попытки.

Все времена в UTC, `DATETIME(6)`.

## Задача
```php
final class SendWelcomeEmail extends AbstractJob
{
    public function __construct(public readonly string $userId) {}
    public static function fromPayload(array $payload): static { return new static((string) $payload['user_id']); }
    public function toPayload(): array { return ['user_id' => $this->userId]; }
    public function handle(Mailer $mailer): void { /* зависимости подставляет контейнер */ }
}

$queue->dispatch(new SendWelcomeEmail($id));                 // сразу
$queue->dispatch(new SendWelcomeEmail($id), delaySeconds: 600);   // через 10 минут
$queue->dispatch($job, 0, 'mail');                           // другая очередь
```
В payload кладём только идентификаторы и простые значения (JSON). Задача должна быть идемпотентной: после падения воркера она выполнится заново.

## Как работает воркер (`queue:work`)
1. `Queue::reserve()` в транзакции выбирает первую доступную строку (`available_at <= now` и не зарезервирована, либо резервация старше `visibilityTimeout` = 15 минут) с `FOR UPDATE SKIP LOCKED`, ставит `reserved_at`, `reserved_by`, увеличивает `attempts`. Параллельные воркеры никогда не берут одну задачу и не ждут друг друга.
2. `Worker` создаёт задачу из payload, вызывает `handle()` через контейнер.
3. Успех: строка удаляется. Исключение: если `attempts < max_attempts`, строка освобождается с задержкой `Job::backoff(attempts)` (по умолчанию 1, 5, 15, 60 минут) и записывается `last_error`; иначе задача переносится в `failed_jobs`.
4. SIGTERM и SIGINT: воркер дорабатывает текущую задачу и выходит (Docker шлёт SIGTERM при остановке). Для этого в образе есть `pcntl`.
5. `AbstractJob` задаёт 5 попыток; переопределите `maxAttempts()` и `backoff()` при необходимости.

Параметры команды: `queue:work [--queue=default] [--sleep=2] [--max-jobs=N]`.

## Планировщик
Периодические задачи описываются в коде, `config/schedule.php`:
```php
return static function (Schedule $schedule): void {
    $schedule->call('publish-due', '* * * * *', function (PublishScheduler $s): void { $s->enqueueDue(); });
    $schedule->job('stats', '0 * * * *', new FetchStatsJob(), $queue);
};
```
Cron в UTC, 5 полей: `*`, число, диапазон `A-B`, шаг `*/N`, списки через запятую.

`schedule:run` просыпается в начале каждой минуты и запускает задачи, срок которых наступил. Упавшая задача логируется и не мешает остальным. `schedule:run --once` делает один проход и выходит (удобно для ручной проверки и cron). Запускаем **один** экземпляр планировщика: блокировок между процессами нет. Тяжёлую работу задача планировщика только кладёт в очередь.

## Эксплуатация
- Состояние: `SELECT queue, COUNT(*) FROM jobs GROUP BY queue`; зависшие резервации видны по `reserved_at`.
- Просмотр проваленных: `SELECT id, queue, error, failed_at FROM failed_jobs ORDER BY id DESC`. Команды повторного запуска и очистки появятся вместе с админкой (этапы 11 и 20).
