UID := $(shell id -u)
GID := $(shell id -g)
export UID
export GID

COMPOSE := docker compose
TOOLS := $(COMPOSE) run --rm --no-deps tools
NODE := $(COMPOSE) run --rm --no-deps frontend

.PHONY: help install keys up down reset logs shell migrate user test lint stan deptrac cs fix front-install front-lint front-test front-build

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

install: ## Install PHP and frontend dependencies
	$(TOOLS) composer install --no-interaction
	$(NODE) npm install --no-audit --no-fund

keys: ## Generate a JWT signing keypair into .env if there is none
	@grep -q '^JWT_SECRET_KEY=' .env 2>/dev/null || { \
		echo "generating a JWT keypair into .env"; \
		$(TOOLS) php bin/console kanso:jwt:generate-keys 2>/dev/null | grep '^JWT_' >> .env; }

up: keys ## Build and start the installation (http://localhost:8090)
	$(COMPOSE) up -d --build api worker frontend proxy

down: ## Stop everything
	$(COMPOSE) down

reset: ## Stop everything and delete the data volumes
	$(COMPOSE) down -v

logs: ## Follow the api and worker logs
	$(COMPOSE) logs -f api worker

shell: ## Shell in the tooling container
	$(TOOLS) sh

migrate: ## Run migrations
	$(COMPOSE) run --rm migrate

user: ## Create a user (make user EMAIL=… PASSWORD=… ROLE=ROLE_OPERATOR)
	@test -n "$${EMAIL}" -a -n "$${PASSWORD}" || { echo "EMAIL and PASSWORD are required"; exit 64; }
	$(TOOLS) php bin/console kanso:user:create "$${EMAIL}" "$${PASSWORD}" --role=$${ROLE:-ROLE_OPERATOR}

test: ## Run the backend test suite (needs mysql and redis up)
	$(COMPOSE) run --rm --no-deps -e APP_ENV=test tools vendor/bin/phpunit $(ARGS)

lint: cs stan deptrac front-lint ## Run every static check

stan: ## PHPStan
	$(TOOLS) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

deptrac: ## Layer boundaries
	$(TOOLS) vendor/bin/deptrac analyse --no-progress

cs: ## Coding standards (dry run)
	$(TOOLS) vendor/bin/php-cs-fixer check --diff

fix: ## Apply coding standards
	$(TOOLS) vendor/bin/php-cs-fixer fix

front-install: ## Install frontend dependencies (inside the container: Alpine needs its own binaries)
	$(NODE) npm install --no-audit --no-fund

front-lint: ## Lint the frontend
	$(NODE) npm run lint

front-test: ## Test the frontend
	$(NODE) npm test

front-build: ## Build the frontend bundle
	$(NODE) npm run build
