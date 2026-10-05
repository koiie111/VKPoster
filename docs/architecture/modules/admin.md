# Админка владельца (этап 20)

Код: `src/Http/Controllers/Admin/*`, `src/Domain/{Admin,Analytics,Campaign,Content,Design,Support}`, `src/Domain/Settings/{Settings,SiteSettings}.php`, `src/Http/Admin/*`, middleware `RequireStaff`, `RequireStaffPermission`, `RequireAdminUnlock`, `AdminAuditTrail`, `Maintenance`, `TrackVisit`; шаблоны `templates/admin/*`, компоненты `templates/components/admin.twig`; скрипты `public/assets/js/admin-{charts,design}.js`. Определения метрик: [admin-metrics.md](admin-metrics.md). Решения: [ADR 0009](../../adr/0009-admin-roles-and-metrics.md). Базовая админка этапа 11 описана в [site-admin.md](site-admin.md).

## Доступ и безопасность
- **Роли** (`StaffRole`): `superadmin` (владелец, `users.is_superadmin`), `finance`, `support`, `content`, `analyst`. Назначает владелец на `/admin/staff` по почте существующего аккаунта (нужен код). Матрица прав: `config/admin_permissions.php`, одно право на маршрут (`RequireStaffPermission`); отказ → 403 и запись `admin.denied`. Не сотрудник и гость → 404 на всём `/admin/*`.
- **Вход**: `Authenticate` → `RequireVerifiedEmail` → лимит → `RequireStaff` (роль, список адресов `ADMIN_IP_ALLOWLIST`, обязательная 2FA) → `RequireAdminUnlock` (код раз в 8 часов **и** замок после 30 минут без запросов, `RequireAdminUnlock::IDLE`) → `AdminAuditTrail`.
- **Повторное подтверждение** опасных действий (`App\Http\Admin\StepUp`, поле `confirm_code` в форме): возврат, блокировка, начисление, вход под пользователем, смена цен, сброс 2FA, удаление аккаунта (анонимизация), запуск рассылки, режим обслуживания и закрытие регистрации, назначение ролей. Код одноразовый (шаг TOTP не принимается дважды), лимит попыток как у входа. Локально аккаунт без 2FA из `/dev/login-as` проходит.
- **Аудит**: `AdminAuditTrail` пишет каждый изменяющий запрос (`admin.request`: метод, путь, статус; тело не пишется), контроллеры пишут смысловую запись с `before`/`after` (блокировка с причиной, начисление с причиной, цены, настройки, роли). Журнал: `/admin/audit` (фильтры по действию, человеку, объекту, датам; CSV; сам экспорт тоже пишется).

## Разделы и права
| Раздел | Адрес | Право |
|---|---|---|
| Обзор, дашборд | `/admin` | `dashboard.view` (деньги: `finance.view`, цифры: `stats.view`) |
| Публикации и сети, состояние системы | `/admin/stats`, `/admin/system` | `stats.view`, `system.view` |
| Пользователи, карточка, CSV | `/admin/users…` | `users.view`, `users.manage`, `users.secure`, `users.impersonate`, `users.export`, `grants.manage` |
| Запросы по 152-ФЗ | `/admin/privacy` | `privacy.manage` |
| Платежи, сверка, уведомления провайдеров, выгрузка | `/admin/payments…`, `/admin/webhooks…` | `finance.view`, `finance.manage` |
| Тарифы и цены, подписки | `/admin/plans…`, `/admin/subscriptions` | `finance.view`, `plans.manage` |
| Поддержка | `/admin/support…` | `support.view`, `support.manage` |
| Рассылки, объявления | `/admin/campaigns…`, `/admin/announcements` | `campaigns.manage`, `content.manage` |
| Тексты и документы, письма сервиса | `/admin/content…`, `/admin/mail-templates` | `content.manage` |
| Дизайн и цвета | `/admin/design` | `design.manage` |
| Настройки сайта | `/admin/settings` | `settings.manage` |
| Сотрудники, журнал действий | `/admin/staff`, `/admin/audit` | `staff.manage`, `audit.view` |
| Очереди, каналы, переключатели сетей | `/admin/queues`, `/channels`, `/platforms` | `system.view`, `ops.manage` |
| Поиск | `/admin/search` | `dashboard.view` (группы результатов по правам) |

## Дашборд и статистика
Метрики: `MetricsAggregator` → `metrics_daily` (раз в час за 3 последних дня, идемпотентно), чтение `MetricsReader`, фильтры `ReportFilters` (период, тариф, сеть, источник, валюта). KPI с сравнением с прошлым периодом, графики Chart.js (выручка по провайдерам, MRR и его движение, регистрации по источникам, платящие по тарифам), воронка, когорты по неделям. Операционная статистика (`OperationalStats`): публикации по сетям, успешность, ошибки по видам, задержка p50/p95, проблемные каналы и пространства, доля ошибок по часам, хранилище, AI. Система (`SystemStatus`): очередь, пульс воркера и планировщика (`Support\Heartbeat` в Redis), MySQL, Redis, диск, версия (`APP_VERSION`, `APP_DEPLOYED_AT`), последние ошибки журнала без контекста. Ежедневный отчёт владельцу (`DailyReport`): час и пояс в настройках, один раз в сутки, почта и Telegram.

## Пользователи, деньги, 152-ФЗ
- Поиск по id, почте, имени, `@домен`, id во ВКонтакте и Telegram; фильтры тариф, статус, даты, источник, активность; сортировки по белому списку; CSV (`Support\Csv`: BOM, `;`, защита от формул).
- Карточка: профиль, способы входа, устройства, пространства, тариф и кошелёк, платежи (только `finance.view`), каналы, публикации, заметки, обращения, журнал. Действия: блокировка с причиной (человек видит её при входе), выход везде, сброс 2FA (не для сотрудников), подтверждение почты, заметка, вход под пользователем.
- **Начисления** (`UserActions::grant`, `Ledger::grant`): дни, кредиты, деньги через двойную запись, причина обязательна, пределы на разовую сумму; дни сдвигают конец оплаченного или пробного периода.
- **152-ФЗ** (`PersonalData`, таблица `data_requests`): запись запроса, выгрузка данных одного человека (JSON или ZIP; секреты не выгружаются), удаление = анонимизация: аккаунт обезличен, входы и содержимое удалены, счета, платежи и проводки остаются.
- Платёж: детали, сверка с провайдером (`BillingService::sync`), возврат, копия уведомлений провайдера без данных карты и контактов (`WebhookMask`), журнал проводок. Выгрузка для бухгалтерии CSV (`FinanceExport`, комиссия по проценту владельца, пустая если не задан). Цена тарифа меняется для следующих платежей; история в журнале аудита.

## Контент и общение
- Документы (`Documents`, `cms_pages`): ревизии, черновик и публикация, откат из истории; новая версия обязательного документа заставляет людей согласиться заново (`LegalDocuments::consentVersion()`). Статьи справки можно переопределять и добавлять. Markdown превращается безопасным `Support\Markdown` (HTML в тексте показывается как текст).
- Тексты лендинга и FAQ (`SiteContent`, `cms_blocks`), пустое поле возвращает текст по умолчанию.
- Объявления в приложении (`Announcements`): аудитория по тарифам и сетям каналов, период, закрытие человеком, важные закрыть нельзя.
- Рассылки (`Campaigns`): только согласившиеся на новости (`users.marketing_opt_in_at`) и не отписавшиеся; предпросмотр, тест себе, отправка через очередь с ограничением скорости (`campaigns.per_minute`), `List-Unsubscribe` и одноклик (`/unsubscribe/{id}` с подписью, RFC 8058), статистика. Согласие: галочка при регистрации и в «Уведомлениях».
- Письма сервиса (`MailTemplates`): переписать тему и текст с `{подстановками}`, предпросмотр, вернуть шаблон.
- Поддержка (`Tickets`): обращения из формы «Сообщить о проблеме» (письмо в ящик поддержки остаётся) и из личного чата с общим Telegram-ботом; контекст (тариф, последние ошибки); ответ письмом или в Telegram, внутренние заметки, статус, назначение.

## Настройки сайта и дизайн
`app_settings` (кэш в Redis, сброс при изменении): режим обслуживания (`Maintenance` пропускает персонал, вебхуки, `/healthz`, вход, статику), регистрация (открыта, по приглашениям, закрыта; коды `invite_codes` хранятся хэшем), контакты и реквизиты, предел пространств на человека, комиссии провайдеров, отчёт владельцу, скорость рассылок. Секреты здесь не хранятся.
«Дизайн и цвета» (`ThemeColors`): все токены цвета обеих тем, предпросмотр, проверка контраста WCAG, сброс одним нажатием; `/theme.css?v=хэш` подключается после основного CSS.

## Демо-данные
`make seed-demo` (`seed:demo`, только `APP_ENV=local`): 500 человек за полгода, каналы, публикации, пробные периоды, счета, продления, повышения, возвраты, активность, визиты, обращения; `--users=`, `--days=`, `--keep`. Сид `make seed` добавляет по одному сотруднику каждой роли (`finance@`, `support@`, `content@`, `analyst@ezposter.local`, вход `/dev/login-as/<почта>`).

## Тесты
`StaffAccessTest` (матрица ролей по всем маршрутам, замок, список адресов, подтверждение, аудит), `MetricsTest` и `MetricsFixture` («эталонный месяц» с известными ответами), `DashboardTest`, `OperationalStatsTest`, `UsersAdminTest`, `FinanceAdminTest`, `ContentAdminTest`, `CommsAdminTest`, `SiteSettingsTest`, `DesignTest`, `ReportAndSearchTest`, `DemoDataTest`.
