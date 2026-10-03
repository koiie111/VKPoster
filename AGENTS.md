# AGENTS.md

Instructions for coding agents (Codex, Claude Code, etc.). Stage 00 will rewrite this file together with `CLAUDE.md` once the new skeleton exists.

- Project: a scheduled-posting web service for VK, MAX, Telegram and Instagram, written in plain PHP 8.3 (no frameworks), developed and tested only in Docker. UI text is in Russian.
- Start every session by following `docs/plans/AGENT_PROMPT.md`. It decides which stage to work on using `docs/plans/PROGRESS.md`.
- Mandatory rules: `docs/plans/ENGINEERING_RULES.md` (stack, allowed dependencies, tests, security checklist, git).
- What to build: `docs/plans/00-master-plan.md` and `docs/plans/stages/NN-*.md`.
- Git: branch `stage-NN-slug` → PR → green CI → squash-merge to `main`. Never commit or push to `main` directly.
- If the owner must test something by hand or make a decision, write the questions into `docs/plans/PROGRESS.md`, set the stage to `NEEDS_OWNER`, and stop without merging.
- Never commit secrets or `.env`. Never call real social-network or payment APIs from automated tests.
