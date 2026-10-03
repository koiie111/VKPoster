# All tooling runs in Docker; PHP is not needed on the host.
COMPOSE ?= docker compose
EXEC    ?= $(COMPOSE) exec -T app
RUN     ?= $(COMPOSE) run --rm --no-deps -T app
CMD     ?=

.PHONY: init up down build sh logs console migrate seed test stan cs cs-fix audit docs check

init: ## create .env and generate APP_KEY
	@test -f .env || cp .env.example .env
	@if ! grep -qE '^APP_KEY=.+' .env; then \
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
