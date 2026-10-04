# CLAUDE.md

Instructions for Claude Code (claude.ai/code) in this repository. `AGENTS.md` has the same content for other agents; keep them in sync.

## Overview

VKPoster: a scheduled-posting web service for VK, MAX, Telegram and Instagram, written in plain PHP 8.3 (no frameworks), developed and tested only in Docker. UI text is in Russian; code, comments and commits are in English. The repository is being rewritten from scratch by stages (the old code is in tag `legacy-v0`).

## Start every session with

1. `docs/plans/AGENT_PROMPT.md`: the universal agent prompt (decides which stage to run next)
2. `docs/plans/PROGRESS.md`: stage statuses and questions for the owner
3. `docs/plans/ENGINEERING_RULES.md`: mandatory coding, security, test and git rules
4. `docs/plans/00-master-plan.md` and `docs/plans/stages/NN-*.md`: what to build

## Commands (all run in Docker, no PHP on the host)

```bash
make init      # .env from .env.example + APP_KEY
make up        # build + start stack (app http://localhost:8080, mailpit :8025) + composer install
make down      # stop
make sh        # shell in the app container
make console CMD="migrate"   # bin/console in the container
make migrate | seed
make console CMD="user:create-admin you@example.com --name=Имя"   # super-admin (random password printed once)
make console CMD="auth:prune"   # delete expired tokens/sessions/journal rows (also daily via the scheduler)
make console CMD="telegram:poll"     # dev: receive shared-bot updates by long polling (no public HTTPS needed); `telegram:webhook set|delete|info` for a public host; `max:poll` and `max:webhook set|delete|info` do the same for the shared MAX bot
make console CMD="billing:renew"      # billing tick now (renewals, trials, payment check); `billing:renew --force SUBSCRIPTION_ID` charges one now; `billing:plans [--sync]` price list; `billing:grant WORKSPACE PLAN [month|year]` gives a plan without payment
make console CMD="crypto:rotate"     # re-encrypt stored secrets with the current APP_KEY (after rotating the key); `channels:check` queues channel health checks; `bench:publish --fake` measures publication delay (stop worker and scheduler first)
make test      # PHPUnit: Unit, Integration, Feature (DB app_test)
make stan      # PHPStan level 8 + strict-rules
make cs | cs-fix   # php-cs-fixer (PSR-12 + strict_types)
make audit     # composer audit
make docs      # phpDocumentor -> docs/reference (gitignored)
make css | css-watch | css-check   # Tailwind build -> public/assets/build/app.<hash>.css
make ui-behavior         # browser smoke test of component behavior and CSP errors
make ui-snap STAGE=NN   # screenshots 375/768/1440 x light/dark + axe -> storage/ui-review/stage-NN/ (url items may use login_as + actions, see tools/ui-snap/urls/stage-02.json)
make a11y [STAGE=NN]     # axe-core only
make check     # cs + stan + test + audit + docs; must be green before a PR
```

Config comes from env vars (see `.env.example`, `docs/architecture/configuration.md`). The repo is bind-mounted into the `app` container, so edits are live. Compose profiles: `s3` (MinIO), `tunnel` (cloudflared).

## Layout (current)

`public/index.php` (front controller → `App\Kernel\Application`), `src/Kernel` (own mini-framework: container, router, middleware, session, DB, queue, console), `src/Http` (controllers, middleware), `src/Domain` (User, Auth incl. Auth/Social, Workspace (tenants, roles, team), Audit, Notification, Media (library: upload pipeline `MediaService`, `ImageProcessor`, `VariantService`; files leave the server only through `/media/{id}/{variant}`), Channel (connected channels, connect codes, `CredentialVault`: tokens only encrypted, health checks, `TelegramUpdateHandler`, `VkConnections`), Post (posts, variants, publications, `PostService`, publishing pipeline `Publisher`, `PublicationScheduler`, calendar read model, templates), Notification (`Notifier`, settings, Telegram link); workspace data goes through `WorkspaceScopedRepository` + `WorkspaceContext`, permissions in `config/permissions.php`), `src/Integrations/OAuth` (social sign-in providers; `DEV_OAUTH_FAKE=1` enables a fake one locally), `src/Integrations/Social` (publishing platforms: `Contracts`, `PlatformRegistry`, `Telegram`, `Vk` (own API client, OAuth account tokens refreshed by `OAuthRefresher`), `Max` (own API client, shared bot with connect codes or the customer's own bot), `Fake` test network; flags `PLATFORMS_ENABLED`), `src/Domain/Billing` (plans, subscriptions, `Entitlements` limits, invoices/payments, `BillingService`, `RenewalService`, ledger), `src/Integrations/Payments` (`PaymentGateway`: YooKassa, T-Bank, Fake), `src/Integrations/Storage` (`MediaStorage`: local disk or S3, `MEDIA_DISK`), `src/Support`, `bin/console`, `config/` (`app/database/security/session/permissions/media/platforms` read env; `routes`, `services`, `schedule` are code), `templates/{layouts,components,dev,errors}` (design system: `docs/design/design-system.md`, showcase `/dev/ui` locally), `database/`, `resources/lang`, `tests/{Unit,Integration,Feature}` (helpers in `tests/Support`), `tools/phpstan`, `docker/`, `docs/`. Target structure: master plan §4.1.

## Rules in short

- Every PHP file has `declare(strict_types=1);`; no frameworks; dependencies only from the whitelist in ENGINEERING_RULES §2 (others need an ADR in `docs/adr/`). `vendor/` is not committed, `composer.lock` is.
- Prepared statements only; state-changing routes need CSRF; never commit secrets or `.env`; never call real social/payment APIs from tests.
- Docs are part of every feature (ENGINEERING_RULES §7): update `docs/architecture/`, `docs/CHANGELOG.md`, `.env.example`, README/CLAUDE/AGENTS when commands or env change.

## Git workflow

Each stage lives on its own branch `stage-NN-slug`, then PR → green CI → `gh pr merge --squash --delete-branch` into `main`. Never commit or push to `main` directly, never force-push, never `--no-verify`. If a stage needs the owner's manual check or a decision, write the questions into `docs/plans/PROGRESS.md`, set the stage to `NEEDS_OWNER` and stop without merging.
