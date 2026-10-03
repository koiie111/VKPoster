# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

VKPoster is a plain-PHP (no framework) web app for scheduling posts in VK (VKontakte) communities. UI text is in Russian. There is no build step, linter, or test suite. `vendor/` is committed (only dependency: `vkcom/vk-php-sdk`).

## Running

```bash
docker compose up --build      # app at http://localhost:8080 (php:8.2-apache + mysql:8.0)
docker compose down -v         # reset DB (schema is loaded from docker/init.sql on first MySQL start only)
docker compose --profile tools up   # adds phpMyAdmin at :8081
```

- `/dev_login` logs in without VK OAuth (enabled only when `DEV_LOGIN=1`; off by default — run `DEV_LOGIN=1 docker compose up`).
- Real VK auth needs `VK_CLIENT_ID` / `VK_CLIENT_SECRET`; `APP_URL` is used as the OAuth redirect base.
- Any VK API call for groups needs a real token stored in `users.private_tocken` (the column/method names deliberately use the misspelling "tocken" — keep it).
- DB config comes from env vars `DB_HOST/DB_USER/DB_PASSWORD/DB_NAME` (see `system/db.php`); the repo is bind-mounted into the container, so edits are live.

## Architecture

- **Routing**: `.htaccess` mod_rewrite maps clean URLs to scripts (`/auth`, `/auth_callback`, `/dev_login`, `/main`, `/main/groups` → `modules/...`). `index.php` is the landing page. New routes need a `RewriteRule` there.
- **Bootstrap**: every page/endpoint includes `system/extensions.php` (directly or via `style/head.php`), which loads the DB (`$db`, global mysqli), composer autoload, starts the session, registers a class autoloader for `system/classes/*.php` (class name = filename), creates `$Core`, and creates `$User` if `$_SESSION['id']` is set. Code relies on these globals (`global $db`) and on `$_SERVER["DOCUMENT_ROOT"]` includes.
- **Pages** use `style/head.php` / `style/foot.php` as the layout (Bootstrap-based); `$title` is set before including head. Data endpoints such as `modules/main/get_groups.php` return JSON consumed by the frontend; `js/toastes.js` holds client-side toasts.
- **Classes** (`system/classes`): `Core` (app URL/name, `outputText()` = htmlspecialchars helper), `User` (loads a `users` row; holds VK access token and the separate "private" token used for group API calls), `Groups` (loads a `groups` row and fetches name/avatar/members/type from the VK API using the user's private token on construction — one API call per group).
- **Schema** (`docker/init.sql`): `users` (`id_vk` unique, tokens) and `groups` (`id_group` = VK group id, `id_admin` = admin's VK id).

## Gotchas

- Much of the code is work in progress: several `User` getters are empty stubs, and `get_groups.php` returns only the current user's groups, with a placeholder admin field.
- All SQL uses prepared statements; keep it that way. State-changing endpoints must check `csrf_check()`, and redirects must use `redirect()` (it exits).
- `VK_CLIENT_SECRET` comes only from the environment; never hard-code secrets. The old leaked secret must be rotated in VK.
