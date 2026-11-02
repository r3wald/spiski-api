DC  := docker compose
PROD := docker compose --env-file .env.prod -f compose.prod.yaml
PHP := $(DC) run --rm php

.DEFAULT_GOAL := help
.PHONY: help build up down logs shell install migrate diff test stan openapi openapi-check check backup prod-build prod-up prod-down prod-restart prod-logs prod-migrate prod-backup

help: ## Diese Uebersicht
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk -F':.*?## ' '{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

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

prod-up: ## Prod-Stack starten (nur auf 127.0.0.1, Port HTTP_PORT, Default 8081)
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
	set -a; . ./.env.prod; set +a; COMPOSE_FILE=compose.prod.yaml BACKUP_MODE=docker ./bin/backup.sh
