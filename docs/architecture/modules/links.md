# Ссылки в постах: UTM и короткие ссылки (этап 17)

Решения и ограничения: [ADR 0017](../../adr/0017-links-utm-shortener.md). Код: `src/Domain/Link`, `src/Http/Controllers/Link`, `src/Http/Controllers/Admin/LinksAdminController.php`.

## Как ссылка проходит через публикацию
`PublishRequestBuilder::build` → `LinkRewriter::rewrite(контекст, вариант, текст, кнопки, persist)`:
1. `UrlGuard::isBlocked` — адрес с запрещённого домена даёт `PostException` (пост не проходит проверку и не уходит);
2. `UtmTagger::tagUrl` — метки по правилу канала (`LinkSettings::effectiveRule`: своё правило канала или правило пространства);
3. если в пространстве включено сокращение и адрес допустим (`UrlGuard::problem`), `ShortLinks::forVariant` создаёт или находит короткую ссылку варианта.
Для проверок в редакторе (`withFiles = false`) ничего не сохраняется: подставляется образец адреса той же длины (`ShortLinks::sampleUrl`).

## Классы
- `UtmRule` — значение: включено ли, шаблоны `source/medium/campaign/content/term`, проверка `problems()`.
- `UtmTagger` — чистые функции: `tagUrl`, `mapUrls` (разметка редактора), `slug`.
- `LinkSettings` — настройки пространства (`link_workspace_settings`) и переопределения каналов (`link_channel_rules`).
- `UrlGuard` — допустимость адреса и чёрный список (`app_settings.links.blocked_domains`).
- `ShortLinks` — коды, ссылки, клики, отключение, поиск для админки, очистка кликов.
- `LinkRewriter` — склейка шагов для одного варианта поста.

## Страницы и маршруты
| Маршрут | Кто | Что |
|---|---|---|
| `GET /s/{код}` | все | редирект 302 (404 нет такой, 410 отключена или сайт запрещён); лимит 120/мин |
| `GET /{код}` | все, только хост `SHORT_LINK_DOMAIN` | то же без префикса (на других хостах 404) |
| `/w/{id}/settings/links` (+ `/channels/{id}`, `/short`, `/short/{id}/toggle`) | `workspace.settings` | правила UTM, сокращение, ссылки пространства с кликами |
| `/admin/links` (+ `/blocklist`, `/{id}/toggle`) | `links.manage` (суперадмин, поддержка) | все ссылки, поиск, отключение, чёрный список |

## Данные (миграция 43)
`link_workspace_settings`, `link_channel_rules`, `short_links` (`public_id`, `code`, `variant_id`, `platform`, `target_url`, `target_hash`, счётчики, `disabled_*`), `short_link_clicks` (`visitor_hash`, `is_bot`). Подробности: [database.md](../database.md).

## Планировщик и окружение
`short-link-clicks-prune` в 03:51 UTC удаляет клики старше `SHORT_LINK_CLICK_RETENTION_DAYS`. Переменные: `SHORT_LINK_DOMAIN`, `SHORT_LINK_MANUAL_PER_DAY`, `SHORT_LINK_CLICK_RETENTION_DAYS` ([configuration.md](../configuration.md)).

## Как настроить короткий домен
Купите домен, направьте его (A/AAAA или CNAME) на тот же сервер, добавьте в конфигурацию nginx/прокси серверное имя с тем же приложением и выпустите сертификат; в `.env` укажите `SHORT_LINK_DOMAIN=sho.rt`. Без него ссылки имеют вид `APP_URL/s/код`.

## Не сделано (техдолг)
Страна по GeoIP, Google Safe Browsing, UTM в первом комментарии, график кликов по дням, события в `analytics_events`.
