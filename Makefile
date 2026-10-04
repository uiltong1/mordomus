# ---------------------------------------------------------------------------
# Mordomus — atalhos do ambiente de desenvolvimento
#   make secrets   gera .env + chaves JWT RS256 + JWKS
#   make up        sobe tudo (build + containers)
#   make health    saúde do ambiente (gateway, api, postgres, redis)
#   make test      php artisan test no monólito
#   make lint      pint --test + phpstan
#   make contracts conferência dos JSON Schemas de evento
#   make smoke     smoke E2E do identity via gateway
#   make e2e       jornadas Playwright (exige o ambiente no ar)
#   make migrate   php artisan migrate
#   make seed      RBAC (roles e capabilities)
#   make seed-demo cenário de demonstração (duas casas)
#   make openapi   regenera api/storage/openapi.yaml a partir das anotações
# ---------------------------------------------------------------------------
SHELL := /bin/bash
COMPOSE ?= docker compose
BASE_IMAGE ?= mordomus-php:8.3
SERVICE ?= api
PHP_RUN = $(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 -e AUTO_COMPOSER=0 $(SERVICE)

.DEFAULT_GOAL := help

.PHONY: help secrets base build install up dev down restart logs ps health test lint contracts smoke migrate seed seed-demo e2e shell openapi

help: ## mostra esta ajuda
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

secrets: ## gera .env, chaves JWT RS256 e JWKS local
	@bash bin/generate-secrets.sh

base: ## build da imagem base PHP (nginx + fpm + extensões)
	docker build -f docker/php/Dockerfile -t $(BASE_IMAGE) .

build: base ## build da imagem do monólito
	$(COMPOSE) build

install: ## composer install no monólito (sem subir dependências)
	$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $(SERVICE) true

up: secrets base ## sobe o ambiente completo
	$(COMPOSE) up -d --remove-orphans

dev: up ## alias de `up` (fluxo documentado: make dev)
	@echo "ambiente no ar — make health | make test | make logs"

down: ## derruba o ambiente (mantém volumes)
	$(COMPOSE) down --remove-orphans

clean: ## derruba e remove volumes
	$(COMPOSE) down -v --remove-orphans

restart: ## reinicia os serviços
	$(COMPOSE) restart

logs: ## logs agregados
	$(COMPOSE) logs -f --tail=200

ps: ## status dos containers
	$(COMPOSE) ps

health: ## verifica a saúde do ambiente
	@bash bin/healthcheck.sh

test: ## php artisan test no monólito
	$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $(SERVICE) php artisan test

lint: ## pint --test e phpstan no monólito
	$(PHP_RUN) vendor/bin/pint --test
	$(PHP_RUN) vendor/bin/phpstan analyse --no-progress

contracts: ## confere os JSON Schemas de evento contra o que a aplicação publica
	$(PHP_RUN) php artisan contracts:validate

smoke: ## smoke E2E do identity via gateway (exige gateway de pé)
	@bash bin/smoke.sh

migrate: ## php artisan migrate
	$(COMPOSE) run --rm --no-deps $(SERVICE) php artisan migrate

seed: ## RBAC: roles de sistema e catálogo de capabilities
	$(PHP_RUN) php artisan db:seed --class=Database\\Seeders\\RbacSeeder --force

seed-demo: ## cenário de demonstração: 2 casas, 3 cômodos, 5 itens, 4 regras, 6 contas
	$(PHP_RUN) php artisan db:seed --demo --force

e2e: ## jornadas Playwright (exige o ambiente no ar)
	cd e2e && npx playwright test

shell: ## shell no monólito: make shell [SERVICE=api]
	$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $(SERVICE) sh

openapi: ## gera api/storage/openapi.yaml a partir das anotações
	$(PHP_RUN) vendor/bin/openapi app -o storage/openapi.yaml
