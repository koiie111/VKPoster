# Промт для локального запуска: довести PR #20 (Stage 22: Sources)

Запускать Claude Code локально в клоне `koiie111/VKPoster`, где работают Docker и `gh`. Скопируйте текст ниже целиком.

---

Ты агент-разработчик проекта VKPoster (чистый PHP 8.3, всё запускается только в Docker). Общайся по-русски, код и коммиты на английском.

## Контекст
- Работаем в PR https://github.com/koiie111/VKPoster/pull/20 (ветка `claude/adoring-meitner-uqd3zl`, draft). Это продолжение PR #18 (Sources) с исправлениями CI.
- Сначала прочитай `CLAUDE.md`, `docs/plans/ENGINEERING_RULES.md`, `docs/plans/PROGRESS.md` и комментарии к PR #18 и #20 (`gh pr view 18 --comments`, `gh pr view 20 --comments`). Там ревью с блокерами.
- Уже исправлено: явная collation `utf8mb4_unicode_ci` в миграциях `000030` и `000031`, тест `ProviderLimitsTest::testUnknownHostDeniedAndRedisFailureFailsClosed`.

## Шаги
1. `git fetch origin && git checkout claude/adoring-meitner-uqd3zl && git pull --ff-only`.
2. `make init && make up && make migrate`.
3. Воспроизведи падения: `make test` (или точечно `docker compose exec app vendor/bin/phpunit tests/Feature/Source/AutomationTest.php`).
4. Главная нерешённая проблема: `AutomationTest::testImageWaitReaderAckAndSelectedOriginalProduceOneDraft` (строка ~235). Ожидается статус `completed`, приходит `needs_review`. Найди причину (проверь шаги `images`, `ack` от reader, время `clock->advance`, причину перехода в `needs_review`), исправь код, а не тест, если ошибка в коде.
5. Добейся зелёного `make check` (cs + stan + test + audit + docs). Тесты не отключать и не ослаблять.
6. Коммиты по Conventional Commits, пуш только в `claude/adoring-meitner-uqd3zl` (`git push -u origin claude/adoring-meitner-uqd3zl`). Без `--force` и `--no-verify`.
7. После пуша дождись CI: `gh pr checks 20 --watch`. Если красный, разбери `gh run view <id> --log-failed` и чини (не больше 5 итераций).

## Что НЕ делать без владельца
- Не мержить PR #20: остаются блокеры ревью (лимиты платных провайдеров по тарифам и пространствам, продакшен-уровень `tools/telegram-reader` и его тесты в CI) и вопросы владельцу (авторские права, аккаунт Telegram, провайдеры, тарифы) из плана этапа. Конфликт номера этапа: в `main` уже есть `22-short-video.md`.
- Не принимать продуктовые и юридические решения за владельца, не коммитить секреты и `.env`, не вызывать реальные API соцсетей, OpenAI, Replicate, TinEye в тестах.

## Формат ответа
Короткий отчёт: что было причиной каждого падения, что исправлено, что показал `make check` и CI, что осталось. Последняя строка: `AGENT_STATUS: NEEDS_OWNER 22` (или `BLOCKED 22`, если не удалось довести до зелёного).
