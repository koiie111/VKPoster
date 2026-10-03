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
make test      # PHPUnit: Unit, Integration, Feature (DB app_test)
make stan      # PHPStan level 8 + strict-rules
make cs | cs-fix   # php-cs-fixer (PSR-12 + strict_types)
make audit     # composer audit
make docs      # phpDocumentor -> docs/reference (gitignored)
make check     # cs + stan + test + audit + docs; must be green before a PR
```

Config comes from env vars (see `.env.example`, `docs/architecture/configuration.md`). The repo is bind-mounted into the `app` container, so edits are live. Compose profiles: `s3` (MinIO), `tunnel` (cloudflared).

## Layout (current)

`public/index.php` (front controller; stub until stage 01), `src/` (PSR-4 `App\`), `bin/console`, `config/`, `templates/`, `database/`, `tests/{Unit,Integration,Feature}`, `docker/`, `docs/`. Target structure: master plan §4.1.

## Rules in short

- Every PHP file has `declare(strict_types=1);`; no frameworks; dependencies only from the whitelist in ENGINEERING_RULES §2 (others need an ADR in `docs/adr/`). `vendor/` is not committed, `composer.lock` is.
- Prepared statements only; state-changing routes need CSRF; never commit secrets or `.env`; never call real social/payment APIs from tests.
- Docs are part of every feature (ENGINEERING_RULES §7): update `docs/architecture/`, `docs/CHANGELOG.md`, `.env.example`, README/CLAUDE/AGENTS when commands or env change.

## Git workflow

Each stage lives on its own branch `stage-NN-slug`, then PR → green CI → `gh pr merge --squash --delete-branch` into `main`. Never commit or push to `main` directly, never force-push, never `--no-verify`. If a stage needs the owner's manual check or a decision, write the questions into `docs/plans/PROGRESS.md`, set the stage to `NEEDS_OWNER` and stop without merging.
