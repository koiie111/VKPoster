# Changelog

Формат: [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/).

## [Unreleased]

### Added
- Этап 00: Docker-стек (nginx, php-fpm, worker, scheduler, mysql, redis, mailpit; профили `s3`, `tunnel`), `Makefile`, CI на GitHub Actions, PHPUnit, PHPStan (level 8 + strict-rules), php-cs-fixer, `/healthz`.
- Скелет документации и ADR 0001–0002.

### Changed
- Название продукта: `ezposter` (`APP_NAME`).

### Removed
- Весь legacy-код (остался в теге `legacy-v0`), `vendor/` больше не коммитится.
