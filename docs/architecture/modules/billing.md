# Биллинг: тарифы, лимиты, подписка, оплата (этап 10)

Код: `src/Domain/Billing`, `src/Integrations/Payments`, `src/Http/Controllers/Billing`, `src/Http/Controllers/Webhooks/PaymentWebhookController.php`, `src/Http/Middleware/PlanFeature.php`, шаблоны `templates/workspace/billing/`, письма `templates/emails/billing_*`. Решения и что не проверено: [ADR 0008](../../adr/0008-billing-gateways-and-limits.md). Пользовательская инструкция: [docs/user/billing.md](../../user/billing.md).

## Модель
- **Источник правды о подписке — наша БД.** Провайдеры только принимают деньги.
- `plans` и `plan_prices` — прайс-лист (лимиты и возможности в JSON, цены в копейках по сроку и валюте). Стартовый каталог лежит в `config/billing.php`; миграция и `billing:plans --sync` добавляют только недостающее. Free — обычный тариф без цен.
- `subscriptions` — одна строка на пространство: тариф, статус (`trialing`, `active`, `past_due`), срок (`current_period_*`), `price_amount` (полная цена текущего периода: из неё считается зачёт при апгрейде), `cancel_at_period_end`, отложенный переход (`pending_plan_id`, `pending_period`), способ оплаты, счётчик попыток продления и `next_renewal_attempt_at`. Пространство без строки считается Free (`Entitlements`).
- `invoices` (счёт: вид `new` | `upgrade` | `renewal`, сумма и полная цена, срок, e-mail плательщика для чека) → `payments` (попытка оплатить счёт через провайдера, `provider_status` — последний сырой статус) → `payment_methods` (ссылка на карту у провайдера, номер карты у нас не появляется).
- `webhook_events` — журнал уведомлений и защита от повторов. `ledger_accounts` и `ledger_entries` — журнал двойной записи (проводки одной транзакции дают 0, баланс счёта — сумма проводок, отдельного изменяемого числа нет). Сейчас пишутся оплаты и возвраты: `provider:<имя>` против `revenue:subscriptions`.
- `usage_counters` — учёт расхода по периодам (ИИ-кредиты, этап 17). Посты считаются на лету.
- `workspaces.plan_id` повторяет текущий тариф для запросов и админки.

## Тарифы и лимиты
`Entitlements` — единственное место ответа «можно ли»: `canAddChannel`/`assertCanAddChannel`, `channelsLeft`, `postsInMonth`/`postsLeftInMonth`/`assertCanPlanPost`, `assertCanAddMember`, `storageBytes`, `assertCanCreateWorkspace`, `hasFeature`/`assertFeature`, `usage()`. Сервисы вызывают его сами (`ChannelService::assertRoom`, `PostService::schedule` и `reschedule`, `TeamService::invite`, `MediaService`, `WorkspaceService::create`), поэтому лимит держится при любом входе (страница, вебхук бота). Отказ — `PlanLimitException` (или `ChannelException`/`PostException`/`MediaException` с флагом `planLimit`), страница показывает баннер «Это ограничение тарифа» с кнопкой «Перейти на тариф» (`FormFlash::refusal`, `View::planLimitNotice`). Маршруты с возможностью тарифа закрываются `PlanFeature` (редирект на тарифы, 402 для JSON).

Правила подсчёта см. ADR 0008 п. 8. Возможности: `slots`, `crosspost`, `approvals`, `guest_links`, `api`, `analytics_export`, `client_reports`, `white_label`; лимиты: `channels`, `posts_per_month`, `workspaces`, `members`, `storage_bytes`, `crosspost_rules`, `analytics_days`, `ai_credits` (`null` = без ограничений).

## Жизнь подписки
- Регистрация: личное пространство получает пробный период тарифа `billing.trial.plan` (Pro) на `billing.trial.days` (14) дней без карты; пространства, созданные вручную, стартуют на Free (`SubscriptionService::startTrial/startFree`).
- Выбор тарифа (`BillingService::quote/checkout`, расчёт `PriceCalculator`): с Free, пробного периода или окончившегося срока — полная цена, новый период с сегодняшнего дня; апгрейд внутри периода — доплата за остаток, срок не меняется; дешевле — записывается на конец срока, сейчас бесплатно; повтор того же тарифа раньше чем за 3 дня до конца отклоняется.
- Оплата: `checkout` отменяет прошлый открытый счёт, создаёт счёт и платёж и отдаёт адрес страницы провайдера; браузер идёт на `/billing/pay/{платёж}` (обычная загрузка, а не редирект из формы: CSP `form-action 'self'`) и оттуда на провайдера. Возврат: `/billing/return?payment=` сам спрашивает провайдера.
- Применение платежа — `BillingService::apply` (в одной транзакции): блокировки платёж → счёт → подписка, сверка суммы и валюты, платёж `succeeded`, проводка, подписка (`settle`: тариф, период, цена, способ оплаты, следующая попытка), пауза каналов сверх нового лимита, аудит, письмо с квитанцией. Повторное применение — `AlreadySettled`; платёж по отменённому счёту — `Orphaned` (записан, не применён).
- Автопродление (`RenewalService::tick`, каждый час и `billing:renew`): отмена просроченных счетов и опрос зависших платежей; напоминание об окончании пробного периода за 3 дня (один раз); списание сохранённой картой за 3 дня до конца с повторами через 1 и 3 дня от первой попытки; пробный период и отменённая подписка закрываются в момент окончания, остальные после 3 дней отсрочки (`past_due`) уходят на Free. Отложенный переход применяется при продлении (счёт на новый тариф по его цене).
- Переход на Free (`SubscriptionService::dropToFree`): данные не удаляются; самые новые каналы сверх лимита ставятся на паузу с причиной, запланированные в них посты отменяются с причиной; участники, файлы и пространства сверх лимита остаются.
- `billing:grant` и `SubscriptionService::grant` дают тариф без оплаты (аудит `billing.plan_granted`).

## Платёжные шлюзы
Интерфейс `PaymentGateway` (`createPayment`, `chargeSaved`, `refund`, `parseWebhook`, `fetchStatus`, `webhookAck`). Реализации: `YooKassa\YooKassaGateway` (API v3, Basic-авторизация, `Idempotence-Key` = id нашего платежа, чек с `vat_code` и `payment_subject=service`, проверка адреса отправителя), `TBank\TBankGateway` (API v2, `Token` по `TBankSigner`, `Init` → `Charge` по `RebillId`, ответ на уведомление `OK`), `Fake\FakeGateway` (страница «оплатить / отклонить» `/dev/billing/pay/{id}`, только вне production). `GatewayRegistry` отдаёт включённые (`BILLING_GATEWAYS`) и настроенные шлюзы; вебхуки и старые платежи обслуживаются и выключенным в продаже, но настроенным шлюзом. Статусы провайдеров приводятся к `PaymentStatus` внутри шлюза.

Вебхуки: `POST /webhooks/billing/{provider}` (без сессии и CSRF). Подлинность проверяет шлюз до любой обработки (чужой адрес или неверная подпись → 403 без обращения к провайдеру); повтор уведомления → 200 без работы; провайдер недоступен → 503 (повторит). Адреса для настройки в кабинетах: ЮKassa `<APP_URL>/webhooks/billing/yookassa`, Т-Банк `<APP_URL>/webhooks/billing/tbank` (передаётся и в `Init` как `NotificationURL`).

## Интерфейс
`/w/{id}/billing` (тариф, использование, способ оплаты, история платежей, отключение автопродления), `/billing/plans` (карточки и сравнение, выбор способа оплаты и «Продлевать автоматически»), `/billing/return`, `/billing/invoices/{id}/receipt` (PDF-квитанция, `Support/Pdf`). Доступ только владельцу (`workspace.billing`), чужое пространство даёт 404. В боковой панели карточка тарифа (пробный период, расход постов), на странице каналов подсказка про число мест.

## Команды
`make console CMD="billing:renew"` — прогнать час биллинга; `billing:renew --force ID_ПОДПИСКИ` — списать немедленно, игнорируя расписание и отмену; `billing:plans [--sync]` — показать прайс-лист и добавить недостающие тарифы; `billing:grant ID_ПРОСТРАНСТВА ТАРИФ [month|year]` — выдать тариф без оплаты.

## Безопасность
Все POST с CSRF (кроме вебхуков); тарифные страницы только владельцу; чужие счета, платежи и квитанции дают 404; провайдер не вызывается внутри транзакции; ключи магазинов только из env и не попадают в логи; ссылка на оплату принимается только с `https://` (или относительная у тестового шлюза); редирект на провайдера через `redirectToTrusted`.
