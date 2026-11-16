DC  := docker compose
PHP := $(DC) run --rm php

# Server-Stacks aus compose.prod.yaml, je Stage eigenes Compose-Projekt (Container,
# Netz, Datenbank-Volume), eigene Env-Datei, eigener Image-Tag und eigener Port.
# $(1) = Stage, $(2) = Port auf 127.0.0.1
PROD_PORT    ?= 8081
STAGING_PORT ?= 8082
server_env = SPISKI_STAGE=$(1) HTTP_PORT=$(2) COMPOSE_PROJECT_NAME=spiski-$(1)
server     = $(call server_env,$(1),$(2)) docker compose --env-file .env.$(1) -f compose.prod.yaml
PROD    := $(call server,prod,$(PROD_PORT))
STAGING := $(call server,staging,$(STAGING_PORT))

.DEFAULT_GOAL := help
.PHONY: help build up down logs shell install migrate diff test stan openapi openapi-check check backup \
	prod-build prod-up prod-down prod-restart prod-logs prod-migrate prod-backup \
	staging-build staging-up staging-down staging-restart staging-logs staging-migrate staging-backup

help: ## Diese Uebersicht
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk -F':.*?## ' '{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

build: ## Images bauen
	$(DC) build

up: ## Stack starten (API auf http://localhost:8081)
	$(DC) up -d

down: ## Stack stoppen
	$(DC) down

logs: ## Logs folgen
	$(DC) logs -f php

shell: ## Shell im PHP-Container
	$(DC) run --rm php bash

install: ## Abhaengigkeiten installieren
	$(PHP) composer install

migrate: ## Migrations auf die Entwicklungsdatenbank anwenden
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction

diff: ## Neue Migration aus dem Entity-Stand erzeugen
	$(PHP) bin/console doctrine:migrations:diff --no-interaction

test: ## Testsuite gegen PostgreSQL
	$(PHP) vendor/bin/phpunit

stan: ## Statische Analyse (Level 6)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=512M --no-progress

openapi: ## openapi.yaml neu erzeugen
	$(PHP) bash -c 'bin/console nelmio:apidoc:dump --format=yaml --no-pretty 2>/dev/null' > openapi.yaml

openapi-check: ## Pruefen, ob openapi.yaml zum Code passt
	@$(PHP) bash -c 'bin/console nelmio:apidoc:dump --format=yaml --no-pretty 2>/dev/null' > /tmp/openapi.generated.yaml
	@diff -u openapi.yaml /tmp/openapi.generated.yaml \
		&& echo "openapi.yaml ist aktuell." \
		|| (echo "openapi.yaml weicht vom Code ab – 'make openapi' ausfuehren."; exit 1)

check: stan test openapi-check ## Alles, was auch die CI prueft

backup: ## Dump der Entwicklungsdatenbank nach ./backups
	BACKUP_MODE=docker COMPOSE_SERVICE=postgres ./bin/backup.sh

prod-build: ## Prod-Image bauen
	$(PROD) build

prod-up: ## Prod-Stack starten (nur auf 127.0.0.1, Port 8081)
	$(PROD) up -d --build --remove-orphans

prod-down: ## Prod-Stack stoppen (Daten bleiben im Volume)
	$(PROD) down

prod-restart: ## FrankenPHP neu starten (laedt auch ein geaendertes Caddyfile.prod)
	$(PROD) restart php

prod-logs: ## Prod-Logs folgen
	$(PROD) logs -f php

prod-migrate: ## Migrations auf die Produktionsdatenbank anwenden
	$(PROD) run --rm migrate

prod-backup: ## Dump der Produktionsdatenbank nach ./backups
	set -a; . ./.env.prod; set +a; $(call server_env,prod,$(PROD_PORT)) COMPOSE_FILE=compose.prod.yaml BACKUP_MODE=docker ./bin/backup.sh

staging-build: ## Staging-Image bauen
	$(STAGING) build

staging-up: ## Staging-Stack starten (nur auf 127.0.0.1, Port 8082)
	$(STAGING) up -d --build --remove-orphans

staging-down: ## Staging-Stack stoppen (Daten bleiben im Volume)
	$(STAGING) down

staging-restart: ## FrankenPHP (Staging) neu starten
	$(STAGING) restart php

staging-logs: ## Staging-Logs folgen
	$(STAGING) logs -f php

staging-migrate: ## Migrations auf die Staging-Datenbank anwenden
	$(STAGING) run --rm migrate

staging-backup: ## Dump der Staging-Datenbank nach ./backups
	set -a; . ./.env.staging; set +a; $(call server_env,staging,$(STAGING_PORT)) COMPOSE_FILE=compose.prod.yaml BACKUP_MODE=docker ./bin/backup.sh
