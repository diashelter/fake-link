COMPOSE := docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_TEST := COMPOSE_PROJECT_NAME=fake_link_test docker compose --env-file docker/versions.env -f docker-compose.yml --profile test
REPO_ROOT := $(CURDIR)

.DEFAULT_GOAL := help

.PHONY: help trust-ca build up down ps logs shell-backend shell-frontend migrate smoke test test-backend test-frontend lint

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

down: ## Stop and remove containers
	$(COMPOSE) down

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

test-backend: ## Run Pest tests in the backend container
	@test -f .env || cp .env.example .env
	$(COMPOSE) run --rm --no-deps \
		-e DB_CONNECTION=sqlite \
		-e DB_DATABASE=:memory: \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		backend php artisan test

test-frontend: ## Run Vitest tests in the frontend container
	$(COMPOSE) run --rm --no-deps frontend pnpm test

test: ## Run unit tests, compose validation, and integration smoke checks
	$(MAKE) test-backend
	$(MAKE) test-frontend
	bash tests/compose/config.sh
	bash tests/compose/test-profile.sh
	@test -f .env || cp .env.example .env
	@$(MAKE) trust-ca
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -T backend php artisan migrate --force
	bash tests/compose/redis-policies.sh
	bash tests/smoke/services-healthy.sh
	$(MAKE) smoke

lint: ## Placeholder for future container lint targets
	@echo "lint targets will be added in a later phase"
