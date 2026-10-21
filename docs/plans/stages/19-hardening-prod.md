# Этап 19 — Hardening, нагрузка, production

**Зависимости:** 11 (можно повторять после крупных этапов) · **Ветка:** `stage-19-hardening-prod`

## Граница части A и production19.8

Локальная реализация/изолированная проверка19A описана в
`docs/security/stage-19a-status.md`. Stage19 остаётся IN_PROGRESS: production DoD
и проверка владельца ниже ещё не выполнены. Отметка local не означает production sign-off.
Секреты: ADR0025 заменяет raw APP_KEYS в обычном production-процессе на
purpose-isolated Transit API; старый keyring доступен только offline migration роли.

## Задачи
- [ ] Полный security-аудит по OWASP ASVS L2 (чек-лист в `docs/security/asvs-l2.md` с отметками и ссылками на код и тесты).
- [x] Автоматические сканеры в CI: `composer audit`, PHPStan с правилами безопасности, Semgrep (правила `php` и `owasp-top-ten`), Trivy по Docker-образу, gitleaks по истории.
- [x] DAST: OWASP ZAP baseline против локального стенда в docker (отдельный job в CI или `make zap`).
- [x] Нагрузка: k6 (в docker) — 200 RPS на основные страницы, 10 000 запланированных публикаций на одну минуту на Fake-адаптерах; цели: p95 страниц < 300 мс, публикация p95 < 60 с; масштабирование воркеров (`docker compose up --scale worker=N`).
- [x] Индексы БД по медленным запросам (`EXPLAIN`, slow log).
- [ ] Prod: `compose.prod.yaml` (образы `prod`, read-only rootfs для app, `no-new-privileges`, лимиты ресурсов, healthcheck, restart policy), nginx с TLS (Let's Encrypt через certbot или Caddy — ADR), HTTP/2, gzip/brotli.
- [ ] Секреты в prod (реальная custody/restore verification19.8; код19A реализован, ADR0025): файл `.env` с правами 600 или Docker secrets; `APP_KEYS` с процедурой ротации (`bin/console crypto:rotate`).
- [ ] Бэкапы: ежедневный `mysqldump` + бинлоги (или `xtrabackup`), шифрование, хранение вне сервера, **проверка восстановления** скриптом; бэкап медиа (S3 versioning).
- [ ] Мониторинг: `/healthz` и `/readyz`, метрики (длина очереди, задержка публикаций, ошибки по платформам, платежи), алерты в Telegram владельцу; Sentry-совместимый сбор ошибок (self-hosted GlitchTip — ADR), uptime-проверка.
- [x] Логи: ротация, хранение 30 дней, без ПДн сверх необходимого.
- [ ] CD: GitHub Actions → сборка образа → GHCR → деплой по SSH на сервер (`docker compose pull && up -d`, миграции, проверка `/readyz`, авто-откат при неудаче). Деплой только из `main` по тегу `v*`.
- [x] Аудит документации: каждый модуль `Domain/*` описан в `docs/architecture/modules/`, `database.md` совпадает с миграциями (скрипт сверки), все env-переменные в `configuration.md`, `make docs` без предупреждений, руководства `howto-*` проверены на актуальность.
- [x] `docs/deploy.md` и `docs/runbook.md` (что делать, если: VK API лежит, очередь растёт, платёж завис, утёк ключ).

## 19.2.5 — Regional Egress / Platform Network Routing

Добавлено владельцем 2026-10-20, до окончательной production runtime validation.
- [x] RU Core, per-provider direct / HTTP proxy / SOCKS5 / private tunnel, без глобального VPN.
- [x] Telegram publisher и отдельный reader используют configurable routed egress; VK/MAX могут идти direct.
- [x] Safe secrets/logging, bounded retry/backoff, сохранение unknown-outcome/idempotency.
- [x] Health/readiness/monitoring, config rotation guard, локальные transport/reader contracts.
- [x] Документация и ADR 0022; реальный EU VPS/exit validation остаётся в 19.8.

## Проверка владельцем
1. Доступ к prod-серверу (SSH-ключ деплоя — владелец добавляет сам в GitHub Secrets, агенту значения не передаются), домен и DNS.
2. Первый деплой на prod, проверка сценария «регистрация → оплата → публикация» на боевом домене.
3. Проверка восстановления из бэкапа на отдельном стенде.

## DoD
Prod работает, бэкап восстановлен, сканеры без критичных находок, тег `v1.0.0`.
