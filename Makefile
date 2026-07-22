COMPOSE := docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_TEST := COMPOSE_PROJECT_NAME=fake_link_test docker compose --env-file docker/versions.env -f docker-compose.yml --profile test
REPO_ROOT := $(CURDIR)

.DEFAULT_GOAL := help

.PHONY: help trust-ca build up up-docs down ps logs shell-backend shell-frontend migrate smoke smoke-docs test test-backend test-backend-coverage test-frontend lint lint-backend analyse-backend md-backend format-backend

help: ## List available operational targets
	@printf "Fake Link — Docker environment targets\n\n"
	@grep -E '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

trust-ca: ## Generate dev TLS certificates and show trust-store instructions
	bash docker/scripts/generate-dev-certs.sh
	bash docker/scripts/trust-ca.sh

build: ## Build all Docker Compose images
	$(COMPOSE) build

up: ## Validate environment, ensure dev certs, and start the stack
	@test -f .env || cp .env.example .env
	@ENV_FILE=$(REPO_ROOT)/.env bash docker/scripts/validate-env.sh
	@$(MAKE) trust-ca
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -T backend php artisan migrate --force

up-docs: ## Start the stack with Swagger UI (profile docs)
	@test -f .env || cp .env.example .env
	@ENV_FILE=$(REPO_ROOT)/.env bash docker/scripts/validate-env.sh
	@$(MAKE) trust-ca
	$(COMPOSE) --profile docs up -d --wait
	$(COMPOSE) exec -T backend php artisan migrate --force

down: ## Stop and remove containers
	$(COMPOSE) --profile docs down

ps: ## Show compose service status
	$(COMPOSE) ps

logs: ## Follow compose service logs
	$(COMPOSE) logs -f

shell-backend: ## Open an interactive shell in the backend container
	$(COMPOSE) exec backend bash

shell-frontend: ## Open an interactive shell in the frontend container
	$(COMPOSE) exec frontend bash

migrate: ## Run Laravel database migrations
	$(COMPOSE) exec backend php artisan migrate --force

smoke: ## Run HTTPS health and nginx routing smoke checks
	bash tests/smoke/health.sh
	bash tests/smoke/nginx-routes.sh

smoke-docs: ## Run Swagger UI smoke check via app.localhost/docs
	bash tests/smoke/docs.sh

test-backend: ## Run Pest tests in the backend container
	@test -f .env || cp .env.example .env
	$(COMPOSE) run --rm --no-deps \
		-e DB_CONNECTION=sqlite \
		-e DB_DATABASE=:memory: \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		backend php artisan test

test-backend-coverage: ## Run Pest tests with PCOV coverage in the backend container
	@test -f .env || cp .env.example .env
	$(COMPOSE) run --rm --no-deps \
		-e DB_CONNECTION=sqlite \
		-e DB_DATABASE=:memory: \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		backend composer run test:coverage

test-frontend: ## Run Vitest tests in the frontend container
	$(COMPOSE) run --rm --no-deps frontend pnpm test

test: ## Run unit tests, compose validation, and integration smoke checks
	$(MAKE) test-backend
	$(MAKE) test-frontend
	bash tests/compose/config.sh
	bash tests/compose/env-example.sh
	bash tests/compose/depends-on.sh
	bash tests/compose/test-profile.sh
	bash tests/compose/docs-profile.sh
	bash tests/compose/benchmark-profile.sh
	bash tests/compose/observability-profile.sh
	bash tests/compose/prod-config.sh
	@test -f .env || cp .env.example .env
	@$(MAKE) trust-ca
	$(COMPOSE) --profile docs up -d --wait
	$(COMPOSE) exec -T backend php artisan migrate --force
	bash tests/compose/redis-policies.sh
	bash tests/compose/redis-hosts.sh
	bash tests/smoke/services-healthy.sh
	bash tests/compose/graceful-stop.sh
	bash tests/compose/unhealthy-report.sh
	$(MAKE) smoke
	$(MAKE) smoke-docs

lint-backend: ## Run Pint, PHPStan, and PHPMD in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run quality

analyse-backend: ## Run PHPStan/Larastan in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run analyse

md-backend: ## Run PHPMD in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run md

format-backend: ## Run Pint style check in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run lint

lint: ## Run backend static analysis then Pest tests (fail-fast)
	$(MAKE) lint-backend
	$(MAKE) test-backend
