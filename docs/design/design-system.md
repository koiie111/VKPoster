# Дизайн-система ezposter

Выбранное направление: **A «Индиго»** (светлая и тёмная тема, воздух, радиус 12–16 px, мягкие тени, шрифт Inter). Витрина всех компонентов: `http://localhost:8080/dev/ui` (только `APP_ENV=local`). Тексты: [ux-writing.md](ux-writing.md). Референсы: [references.md](references.md).

## Как это устроено
- **CSS:** Tailwind CSS standalone CLI (без Node). Исходник `resources/css/app.css`, конфиг `tailwind.config.js`. `make css` собирает `public/assets/build/app.<hash>.css` и `manifest.json`; `make css-watch` пересобирает на лету; `make css-check` падает, если сборка устарела. Шаблоны подключают файл через `asset('app.css')` (хэш берётся из манифеста). CSS собирается в prod-образе (`Dockerfile`, стадия `prod`) и в CI.
- **Компоненты:** Twig-макросы в `templates/components/` (`forms`, `display`, `overlay`, `nav`, `post`, `calendar`). Подключение: `{% import 'components/forms.twig' as f %}`, вызов `{{ f.button('Сохранить', variant = 'secondary') }}` с именованными параметрами. Иконки: функция `icon('имя', 'классы', 'подпись?')` (Lucide-спрайт `public/assets/icons/sprite.svg`; неизвестное имя вне production бросает исключение).
- **Слоты:** в Twig нет `caller()`. Содержимое передаётся параметром `body`, который собирается через `{% set x %}…{% endset %}`:
  ```twig
  {% set actions %}{{ f.button('Создать пост', icon = 'plus') }}{% endset %}
  {{ d.page_header('Календарь', body = actions) }}
  ```
- **Поведение:** Alpine.js (CSP-сборка) через `Alpine.data()` в `public/assets/js/components.js`: в разметке только `x-data="имя"`, без выражений и inline-скриптов. Модальные окна и панели — нативный `<dialog>` (фокус, Esc, `aria-modal` работают сами). htmx — для частичных обновлений.
- **CSP:** inline `style="…"` и `<script>` без `src` запрещены. Динамические цвета и ширины делайте классами (`plat-vk`, `<progress>`), а не стилями.
- **Тема:** `data-theme="light|dark"` на `<html>`; без атрибута работает `prefers-color-scheme`. `public/assets/js/theme.js` в `<head>` применяет сохранённый выбор до первой отрисовки (без вспышки). Переключатели: `n.theme_toggle()` (быстрый), `n.theme_choice()` (светлая / тёмная / как в системе, для профиля).

## Токены
Цвета — CSS-переменные с тройкой `R G B` (в Tailwind работают с прозрачностью: `bg-primary/10`). Значения для обеих тем в `resources/css/app.css`.

| Токен (Tailwind) | Назначение |
|---|---|
| `bg-bg`, `bg-surface`, `bg-sunken`, `border-line` | страница, карточки, утопленные блоки, границы |
| `text-fg`, `text-muted` | основной и вспомогательный текст |
| `bg-primary` (`-hover`, `-soft`), `text-primary-fg`, `text-primary-softfg`, `text-primary-text` | основной акцент: кнопки, выбранное, ссылки |
| `ok`, `warn`, `bad`, `info` + `-soft`, `-fg` | статусы: фон `-soft`, текст `-fg` (контраст ≥ 4.5:1 проверен) |
| `vk`, `tg`, `max`, `ig` | цвета соцсетей, **только как метки** (точка у названия, `plat-*`); текст на них не ставим |

Прочее: радиусы `rounded-sm` (8 px), `rounded-ctl` (12 px, контролы), `rounded-card` (16 px), `rounded-pill`; тени `shadow-card`, `shadow-pop`; слои `z-sticky 30`, `z-dropdown 40`, `z-overlay 50`, `z-modal 60`, `z-toast 70`; длительности `duration-fast/base/slow` (120/200/320 мс); отступы по шагу 4 px (стандартная шкала Tailwind). Шрифтовая шкала: 12 / 14 / 16 / 20 / 24 / 36 px (`text-xs … text-4xl`), Inter, заголовки `font-bold`/`font-semibold`.

Контраст токенов (WCAG AA) проверяется при выборе палитры; не вводите новые цвета текста без проверки. Сборка `make a11y` валит проверку на любом serious/critical нарушении axe-core.

## Компоненты

### Формы (`components/forms.twig`, импорт `f`)
| Макрос | Параметры (по умолчанию) | Заметки |
|---|---|---|
| `button` | `label, variant='primary' (secondary/ghost/danger), size='md'/'sm', type='button', icon, loading, disabled, href, block, attrs` | `href` даёт ссылку-кнопку; `loading` блокирует и показывает спиннер; для htmx спиннер включается сам (класс `htmx-request`) |
| `icon_button` | `icon, label, variant='ghost', type, attrs` | `label` обязателен: это `aria-label` |
| `form_field` | `id, label, hint, error, required, body` | обёртка для нестандартных контролов |
| `input` | `name, label, type, value, hint, error, placeholder, required, autocomplete, id, attrs` | ошибка рядом с полем, `aria-invalid`, `aria-describedby` |
| `textarea` | `name, label, value, rows, max, hint, error, …` | с `max` показывает живой счётчик «n из max» |
| `select` | `name, label, options (значение ⇒ подпись), value, hint, error, …` | |
| `checkbox`, `radio` | `name, label, checked, value, hint, disabled` | |
| `switch` | `name, label, checked, hint, disabled` | настоящий checkbox, работает без JS |
| `datetime` | `name, label, date, time, tz, tz_label, hint, error, required` | поля `{name}_date`, `{name}_time`, `{name}_tz`; часовой пояс виден всегда |
| `dropzone` | `name, label, hint, accept, multiple` | перетаскивание и выбор, список выбранных файлов |

### Отображение (`display.twig`, импорт `d`)
`card(title, padded, class, body)` · `badge(text, tone, dot, icon)` · `status_badge(status)` (draft, scheduled, publishing, published, partial, failed, canceled; connected, needs_reauth, error, paused) · `platform_badge(platform, label)` · `avatar(name, src, size)` · `empty_state(title, text, icon, action_label, action_href, secondary_*)` · `skeleton(lines)` · `progress(value, max, label, aria_label)` · `stepper(steps, current)` · `breadcrumbs(items)` · `page_header(title, subtitle, body)` · `alert(kind, title, text, body)` · `tooltip(id, text, body)` · `table(columns, empty, empty_*, caption, body)` (сортировка ссылками, `aria-sort`, пустое состояние) · `pagination(page, pages, base)` · `logo(class, compact)`.

### Оверлеи (`overlay.twig`, импорт `o`)
`dropdown(id, label, icon, align, variant, icon_only, body)` + `menu_item(...)` (стрелки, Home/End, Esc, возврат фокуса) · `modal(id, title, description, size, body)` + `modal_footer(body)` · `drawer(id, title, side, body)` · `tabs(name, items, active, body)` + `tab_panel(name, id, active, body)` (стрелки, Home/End) · `toast_region(flash)` · `confirm_dialog(id, title, object_name, text, action, confirm_label, require_name, method)`.
Открыть окно: любой элемент с `data-dialog-open="id"`; закрыть: `data-dialog-close`. Тост: `window.toast(text, kind)`, `data-toast-trigger`, или заголовок ответа htmx `HX-Trigger: {"toast": {"text": "…", "kind": "success"}}`; «флэш» с сервера — параметр `flash` в шаблоне.

### Навигация (`nav.twig`, импорт `n`)
`sidebar_nav(items, current)` · `workspace_switcher(current, workspaces)` · `theme_toggle()` · `theme_choice()` · `user_menu(name, email)`.

### Посты и календарь (`post.twig` — `p`, `calendar.twig` — `c`)
`post_card(post)` · `preview(platform, author, text, hidden)` (превью ВК, Telegram, MAX, Instagram) · `c.week(days)`, `c.month(weeks)`, `c.list(groups)`, `c.item(item)`. Данные — обычные массивы, формат в `src/Http/Controllers/Dev/DemoData.php`.

## Макеты (`templates/layouts/`)
| Макет | Для чего | Блоки / переменные |
|---|---|---|
| `base.twig` | оболочка HTML, скрипты, тосты | `title`, `head`, `body_class`, `body`; `flash` |
| `app.twig` | приложение: сайдбар с lg, бургер-панель ниже, верхняя панель | `title`, `content`; `nav_current`, `nav_items`, `shell_badge` |
| `auth.twig` | вход, регистрация, сброс пароля, страницы ошибок | `title`, `content`, `auth_footer` |
| `landing.twig` | лендинг и юр. страницы | `title`, `content` |
| `admin.twig` | админка (то же, что `app` + своя навигация и метка «Админка») | как `app.twig` |

## Состояния экрана (обязательно, ENGINEERING_RULES §8)
Загрузка (`skeleton`, спиннер на кнопке) → пусто (`empty_state`) → ошибка (`alert` или ошибка у поля, данные не теряются) → успех (тост или явное изменение). Образцы: `/dev/proto/channels?state=loading|empty`, `?connected=1`, `/dev/proto/editor?error=1`, `/dev/proto/calendar?state=empty`.

## Как добавить компонент
1. Макрос в подходящем файле `templates/components/*.twig`; параметры с именами и значениями по умолчанию; весь пользовательский текст выводится как `{{ x }}` (автоэкранирование), без `|raw`.
2. Классы Tailwind пишите в шаблоне целиком (динамически собранные имена вроде `plat-{{ x }}` Tailwind не видит: добавьте в `safelist` в `tailwind.config.js`).
3. Состояния: hover, focus-visible, disabled, ошибка, загрузка; цель касания ≥ 44 px (`min-h-11`), `aria-*`.
4. Поведение — в `components.js` через `Alpine.data`, без inline-выражений. Проверка: `make ui-behavior`.
5. Добавьте компонент в `templates/dev/ui.twig` во всех состояниях, прогоните `make ui-snap STAGE=21` и `make a11y`, посмотрите скриншоты обеих тем на 360–375 px.
6. Обновите этот документ. Тест `tests/Feature/ComponentsTest.php` (XSS-проверка) дополните вызовом нового макроса.

## Инструменты проверки
| Команда | Что делает |
|---|---|
| `make ui-snap STAGE=NN` | скриншоты 375/768/1440 px × светлая/тёмная тема в `storage/ui-review/stage-NN/` (список адресов — `tools/ui-snap/urls/stage-NN.json`), axe-core (serious/critical валят проверку), горизонтальный скролл на 360–375 px |
| `make a11y [STAGE=NN]` | только axe-core, без скриншотов |
| `make ui-behavior` | браузерная проверка поведения (меню, вкладки, окна, тема, редактор) и отсутствия ошибок консоли/CSP |
| `make css-check` | сборка CSS не устарела |
