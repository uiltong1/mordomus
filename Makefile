# ---------------------------------------------------------------------------
# Mordomus — atalhos do ambiente de desenvolvimento (T1.1)
#   make secrets  gera .env + chaves JWT RS256 + JWKS
#   make up       sobe tudo (build + containers)
#   make health   GET /health em todos os serviços
#   make test     php artisan test em cada serviço
# ---------------------------------------------------------------------------
SHELL := /bin/bash
COMPOSE ?= docker compose
BASE_IMAGE ?= mordomus-php:8.3
SERVICES := identity maintenance financial scheduling notification

.DEFAULT_GOAL := help

.PHONY: help secrets base build install up dev down restart logs ps health test clean shell

help: ## mostra esta ajuda
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

secrets: ## gera .env, chaves JWT RS256 e JWKS local
	@bash bin/generate-secrets.sh

base: ## build da imagem base PHP (nginx + fpm + extensões)
	docker build -f docker/php/Dockerfile -t $(BASE_IMAGE) .

build: base ## build de todas as imagens do compose
	$(COMPOSE) build

install: ## composer install em cada serviço (sem subir dependências)
	@for s in $(SERVICES); do \
		echo "==> composer install: $$s"; \
		$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $$s true || exit 1; \
	done

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

health: ## verifica GET /health em todos os serviços
	@bash bin/healthcheck.sh

test: ## php artisan test em cada serviço
	@for s in $(SERVICES); do \
		echo "==> $$s"; \
		$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $$s php artisan test || exit 1; \
	done

shell: ## shell em um serviço: make shell SERVICE=identity
	$(COMPOSE) run --rm --no-deps -e WAIT_FOR_DB=0 $(SERVICE) sh
