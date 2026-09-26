# ---------------------------------------------------------------------------
# Mordomus — atalhos do ambiente de desenvolvimento (T1.1 + T1.3 / ADR-011)
#   make secrets   gera .env + chaves JWT RS256 + JWKS
#   make up        sobe tudo (build + containers)
#   make health    saúde do ambiente (gateway, api, postgres, redis)
#   make test      php artisan test no monólito
#   make smoke     smoke E2E do identity via gateway (T1.0.10 / T1.3.8)
#   make migrate   php artisan migrate
# ---------------------------------------------------------------------------
SHELL := /bin/bash
COMPOSE ?= docker compose
BASE_IMAGE ?= mordomus-php:8.3
SERVICE ?= api

.DEFAULT_GOAL := help

.PHONY: help secrets base build install up dev down restart logs ps health test smoke migrate shell

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

up: secrets ## sobe o ambiente completo
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

smoke: ## smoke E2E do identity via gateway (exige gateway de pé)
	@bash bin/smoke.sh

migrate: ## php artisan migrate
	$(COMPOSE) run --rm --no-deps $(SERVICE) php artisan migrate

shell: ## shell no monólito: make shell [SERVICE=api]
	$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $(SERVICE) sh
