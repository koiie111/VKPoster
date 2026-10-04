# All tooling runs in Docker; PHP is not needed on the host.
COMPOSE ?= docker compose
EXEC    ?= $(COMPOSE) exec -T app
RUN     ?= $(COMPOSE) run --rm --no-deps -T app
CMD     ?=

.PHONY: init up down build sh logs console migrate seed test stan cs cs-fix audit docs check ui-snap a11y

init: ## create .env and generate APP_KEY
	@test -f .env || cp .env.example .env
	@if ! grep -qE '^APP_KEY=[A-Za-z0-9+/=]{40,}' .env; then \
		key=$$(openssl rand -base64 32 | tr -d '\n'); \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$key|" .env && rm -f .env.bak; \
		echo "APP_KEY generated"; \
	fi

up: init ## build and start the stack, install composer deps
	$(COMPOSE) up -d --build --wait
	$(EXEC) composer install --no-interaction --prefer-dist

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build

sh:
	$(COMPOSE) exec app bash

logs:
	$(COMPOSE) logs -f --tail=100

console:
	$(EXEC) php bin/console $(CMD)

migrate:
	$(EXEC) php bin/console migrate

seed:
	$(EXEC) php bin/console seed

test:
	$(EXEC) composer test

stan:
	$(EXEC) composer stan

cs:
	$(EXEC) composer cs

cs-fix:
	$(EXEC) composer cs-fix

audit:
	$(EXEC) composer audit

docs: ## phpDocumentor reference into docs/reference/
	docker run --rm -v "$(CURDIR):/data" phpdoc/phpdoc:3 run -d src -t docs/reference

check: cs stan test audit docs

STAGE ?=

ui-snap: ## screenshots (375/768/1440 x light/dark) + axe audit: make ui-snap STAGE=NN -> storage/ui-review/stage-NN/
	@test -n "$(STAGE)" || { echo "usage: make ui-snap STAGE=NN"; exit 2; }
	$(COMPOSE) --profile tools run --rm ui-snap $(STAGE)

a11y: ## axe-core only (no screenshots): make a11y [STAGE=NN]
	$(COMPOSE) --profile tools run --rm ui-snap $(or $(STAGE),21) --a11y-only
