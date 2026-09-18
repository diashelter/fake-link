COMPOSE := docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_TEST := COMPOSE_PROJECT_NAME=fake_link_test docker compose --env-file docker/versions.env -f docker-compose.yml --profile test
COMPOSE_E2E := COMPOSE_PROJECT_NAME=fake_link_e2e docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e
REPO_ROOT := $(CURDIR)

.DEFAULT_GOAL := help

.PHONY: help trust-ca build up up-docs down ps logs shell-backend shell-frontend migrate smoke smoke-docs test test-backend test-backend-coverage test-frontend test-frontend-coverage lint lint-openapi lint-backend lint-frontend analyse-backend md-backend format-backend test-e2e-auth

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
	$(COMPOSE) run --rm \
		-e APP_ENV=testing \
		-e DB_CONNECTION=pgsql \
		-e DB_HOST=postgres \
		-e DB_DATABASE=fake_link_testing \
		-e DB_USERNAME=fake_link \
		-e DB_PASSWORD=change-me \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		-e LINKS_DESTINATION_KEYRING='{"testing-key-1":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="}' \
		-e LINKS_DESTINATION_ACTIVE_KEY_ID=testing-key-1 \
		-e LINKS_ETAG_HMAC_KEY=testing-links-etag-hmac-key \
		-e LINKS_CURSOR_HMAC_KEY=testing-links-cursor-hmac-key \
		-e LINKS_RATE_LIMIT_HMAC_KEY=testing-links-rate-limit-hmac-key \
		-e LINKS_IDEMPOTENCY_KEY_HASH_HMAC_KEY=testing-links-idempotency-key-hash-hmac \
		-e LINKS_IDEMPOTENCY_FINGERPRINT_HMAC_KEY=testing-links-idempotency-fingerprint-hmac \
		-e LINKS_IDEMPOTENCY_KEYRING='{"testing-idem-1":"AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE="}' \
		-e LINKS_IDEMPOTENCY_ACTIVE_KEY_ID=testing-idem-1 \
		backend php -d memory_limit=512M artisan test

test-architecture: ## Run Pest Architecture suite in the backend container
	@test -f .env || cp .env.example .env
	$(COMPOSE) run --rm --no-deps \
		-e DB_CONNECTION=sqlite \
		-e DB_DATABASE=:memory: \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		backend vendor/bin/pest tests/Architecture

test-backend-coverage: ## Run Pest tests with PCOV coverage in the backend container
	@test -f .env || cp .env.example .env
	$(COMPOSE) run --rm \
		-e APP_ENV=testing \
		-e DB_CONNECTION=pgsql \
		-e DB_HOST=postgres \
		-e DB_DATABASE=fake_link_testing \
		-e DB_USERNAME=fake_link \
		-e DB_PASSWORD=change-me \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		-e LINKS_DESTINATION_KEYRING='{"testing-key-1":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="}' \
		-e LINKS_DESTINATION_ACTIVE_KEY_ID=testing-key-1 \
		-e LINKS_ETAG_HMAC_KEY=testing-links-etag-hmac-key \
		-e LINKS_CURSOR_HMAC_KEY=testing-links-cursor-hmac-key \
		-e LINKS_RATE_LIMIT_HMAC_KEY=testing-links-rate-limit-hmac-key \
		-e LINKS_IDEMPOTENCY_KEY_HASH_HMAC_KEY=testing-links-idempotency-key-hash-hmac \
		-e LINKS_IDEMPOTENCY_FINGERPRINT_HMAC_KEY=testing-links-idempotency-fingerprint-hmac \
		-e LINKS_IDEMPOTENCY_KEYRING='{"testing-idem-1":"AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE="}' \
		-e LINKS_IDEMPOTENCY_ACTIVE_KEY_ID=testing-idem-1 \
		backend composer run test:coverage

test-frontend: ## Run Vitest tests in the frontend container
	$(COMPOSE) run --rm --no-deps frontend pnpm test

test-frontend-coverage: ## Run Vitest with coverage thresholds (≥75% on modules/**)
	$(COMPOSE) run --rm --no-deps frontend pnpm test:coverage

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
	bash tests/compose/e2e-profile.sh
	bash tests/compose/prod-config.sh
	bash tests/compose/backend-quality-gates.sh
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

test-e2e-auth: ## Run the Playwright Auth security gate (profile e2e)
	$(COMPOSE_E2E) build backend frontend
	$(COMPOSE_E2E) run --rm --no-deps backend composer install --no-interaction --prefer-dist
	$(COMPOSE_E2E) run --rm --no-deps frontend pnpm install --frozen-lockfile
	$(COMPOSE_E2E) up -d --wait --scale openapi-tooling=0
	$(COMPOSE_E2E) exec -T backend php artisan migrate:fresh --force --env=testing
	-$(COMPOSE_E2E) exec -T frontend pnpm test:e2e ; status=$$? ; \
	  mkdir -p ./frontend/e2e/.artifacts ; \
	  $(COMPOSE_E2E) cp frontend:/app/e2e/.artifacts ./frontend/e2e/.artifacts 2>/dev/null || true ; \
	  $(COMPOSE_E2E) logs frontend backend analytics-worker notification-worker scheduler \
	    > ./frontend/e2e/.artifacts/compose.log 2>&1 || true ; \
	  $(COMPOSE_E2E) down -v ; \
	  if [ -f ./frontend/e2e/.artifacts/sentinel.txt ] && [ -s ./frontend/e2e/.artifacts/sentinel.txt ]; then \
	    SENTINEL=$$(cat ./frontend/e2e/.artifacts/sentinel.txt) ; \
	    SCAN_FAIL=0 ; \
	    if grep -rl "$$SENTINEL" ./frontend/e2e/.artifacts/ \
	        --exclude="sentinel.txt" --exclude="session-cookie.txt" \
	        2>/dev/null | grep -q .; then \
	      echo "ERROR: Bearer sentinel found in E2E artefacts — see grep output:" >&2 ; \
	      grep -rl "$$SENTINEL" ./frontend/e2e/.artifacts/ \
	          --exclude="sentinel.txt" --exclude="session-cookie.txt" 2>/dev/null >&2 ; \
	      SCAN_FAIL=1 ; \
	    fi ; \
	    if [ -f ./frontend/e2e/.artifacts/session-cookie.txt ] && [ -s ./frontend/e2e/.artifacts/session-cookie.txt ]; then \
	      COOKIE=$$(cat ./frontend/e2e/.artifacts/session-cookie.txt) ; \
	      if grep -rl "$$COOKIE" ./frontend/e2e/.artifacts/ \
	          --exclude="sentinel.txt" --exclude="session-cookie.txt" \
	          2>/dev/null | grep -q .; then \
	        echo "ERROR: Session cookie value found in E2E artefacts — see grep output:" >&2 ; \
	        grep -rl "$$COOKIE" ./frontend/e2e/.artifacts/ \
	            --exclude="sentinel.txt" --exclude="session-cookie.txt" 2>/dev/null >&2 ; \
	        SCAN_FAIL=1 ; \
	      fi ; \
	    fi ; \
	    if [ "$$SCAN_FAIL" -eq 1 ]; then echo "FAIL: secret leak detected in artefacts" >&2 ; exit 1 ; fi ; \
	  fi ; \
	  exit $$status

lint-openapi: ## Lint docs/openapi.yaml with Spectral (Docker openapi-tooling)
	bash scripts/lint-openapi.sh

lint-backend: ## Run Pint, PHPStan, and PHPMD in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run quality

analyse-backend: ## Run PHPStan/Larastan in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run analyse

md-backend: ## Run PHPMD in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run md

format-backend: ## Run Pint style check in the backend container
	$(COMPOSE) run --rm --no-deps backend composer run lint

lint-frontend: ## Run TypeScript, ESLint, and Prettier checks in the frontend container
	$(COMPOSE) run --rm --no-deps frontend sh -c 'pnpm typecheck && pnpm lint && pnpm format:check'
	node scripts/assert-lint-staged.mjs

lint: ## Run OpenAPI Spectral lint, backend quality, frontend lint, Architecture suite, then Pest tests (fail-fast)
	$(MAKE) lint-openapi
	$(MAKE) lint-backend
	$(MAKE) lint-frontend
	$(MAKE) test-architecture
	$(MAKE) test-backend
