UID := $(shell id -u)
GID := $(shell id -g)
export UID
export GID

COMPOSE := docker compose
TOOLS := $(COMPOSE) run --rm --no-deps tools
NODE := $(COMPOSE) run --rm --no-deps frontend

.PHONY: help install keys up down reset logs shell migrate user test lint stan deptrac cs fix front-install front-lint front-test front-build project-install project-up project-check project-test observability-check e2e

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

PROMTOOL := docker run --rm -v "$(PWD)/observability/prometheus":/rules -w /rules --entrypoint promtool prom/prometheus:v3.5.0

observability-check: ## Check the Prometheus rules and run their unit tests
	$(PROMTOOL) check rules kanso-rules.yml kanso-alerts.yml
	$(PROMTOOL) test rules tests/kanso-alerts.test.yml

deptrac: ## Layer boundaries
	$(TOOLS) vendor/bin/deptrac analyse --no-progress

cs: ## Coding standards (dry run)
	$(TOOLS) vendor/bin/php-cs-fixer check --diff

fix: ## Apply coding standards
	$(TOOLS) vendor/bin/php-cs-fixer fix

e2e: ## End-to-end tests in a browser: this checkout's built frontend + the running API (make up first; ARGS="--grep ship")
	$(COMPOSE) --profile tools up -d --no-deps --build e2e-web e2e-proxy
	$(COMPOSE) run --rm --no-deps e2e sh -c 'npm ci --no-audit --no-fund --loglevel=error && npx playwright test $(ARGS)'

front-install: ## Install frontend dependencies (inside the container: Alpine needs its own binaries)
	$(NODE) npm install --no-audit --no-fund

front-lint: ## Lint the frontend
	$(NODE) npm run lint

front-test: ## Test the frontend
	$(NODE) npm test

front-build: ## Build the frontend bundle
	$(NODE) npm run build

# The customer project skeleton (project/, docs/extensions.md): the core as a
# Composer package plus Acme's bundle. The whole repository is mounted so the
# project's path repositories (../backend) resolve.
PROJECT := $(COMPOSE) run --rm --no-deps -v "$(PWD)":/repo -w /repo/project tools

project-install: ## Install the customer project skeleton (project/)
	$(PROJECT) composer install --no-interaction

project-up: keys ## Run the stack from project/ instead of backend/ (make up to switch back)
	$(COMPOSE) -f compose.yaml -f compose.project.yaml up -d --build --force-recreate api worker frontend proxy

project-check: ## PHPStan on project/ (incl. the no-internals rule) and a routing check
	$(PROJECT) vendor/bin/phpstan analyse --no-progress
	@# The container booting is not the same as the project having an API: one bad
	@# key in routes.yaml and the loader drops the whole file, the core's routes too.
	$(PROJECT) sh -c 'routes=$$(bin/console debug:router --format=txt) && for r in /api/auth/login /api/ext/acme/erp-sync; do echo "$$routes" | grep -q " $$r" || { echo "missing route $$r"; exit 1; }; done'

project-test: ## The project's tests, against its own database (kanso_project_test)
	@$(COMPOSE) exec -T mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS kanso_project_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL PRIVILEGES ON kanso_project_test.* TO 'kanso'@'%';" 2>/dev/null
	$(COMPOSE) run --rm --no-deps -e APP_ENV=test -v "$(PWD)":/repo -w /repo/project tools vendor/bin/phpunit $(ARGS)
