# Jedes Target laeuft innerhalb und ausserhalb des php-Containers.
#
# inside  = lokaler php-Container (/.dockerenv) oder GitLab-CI-Job (GITLAB_CI=true;
#           die Kubernetes-Runner haben kein /.dockerenv). Bewusst nicht CI: viele
#           lokale Werkzeuge und Agent-Shells setzen CI=true auf dem Host.
# outside = Host mit Docker. PHP-Befehle gehen per `docker compose exec` in den
#           laufenden php-Container; Targets, die ihn brauchen, haengen an `up`.
#
# Im Container ist `up` ein No-op (der Stack laeuft ja, bzw. die CI stellt die
# Dienste). Targets, die Docker selbst steuern (build, down, logs, prod-*,
# staging-*), haengen an `host-only` und brechen im Container mit Hinweis ab.
ifeq ($(wildcard /.dockerenv)$(GITLAB_CI),)
CONTEXT := outside
IN_PHP  := docker compose exec php
else
CONTEXT := inside
IN_PHP  :=
endif

DC := docker compose

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
.PHONY: help host-only build up down logs shell install migrate diff test stan lint analyze \
	format format-check mago mago-baseline-prune openapi openapi-check check backup \
	admin-install admin-build admin-dev admin-check \
	prod-build prod-up prod-down prod-restart prod-logs prod-migrate prod-backup \
	staging-build staging-up staging-down staging-restart staging-logs staging-migrate staging-backup

help: ## Diese Uebersicht
	@echo "Kontext: $(CONTEXT) des Containers"
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

host-only:
ifeq ($(CONTEXT),inside)
	@echo "make $(MAKECMDGOALS): braucht Docker und laeuft nur ausserhalb des Containers." >&2; exit 1
endif

# --- Lokaler Stack ------------------------------------------------------------

build: host-only ## Images bauen
	$(DC) build

# --wait: kehrt erst zurueck, wenn alle Dienste laufen bzw. healthy sind, damit
# das folgende `exec` (Tests, Migrations) die Datenbanken schon erreicht.
up: ## Stack starten (API auf http://localhost:8081)
ifeq ($(CONTEXT),outside)
	$(DC) up -d --wait
else
	@:
endif

down: host-only ## Stack stoppen
	$(DC) down

logs: host-only up ## Logs des php-Containers folgen
	$(DC) logs -f php

shell: up ## Shell im php-Container
	$(IN_PHP) bash

install: up ## Composer-Abhaengigkeiten installieren
	$(IN_PHP) composer install

migrate: up ## Migrations auf die Entwicklungsdatenbank anwenden
	$(IN_PHP) bin/console doctrine:migrations:migrate --no-interaction

diff: up ## Neue Migration aus dem Entity-Stand erzeugen
	$(IN_PHP) bin/console doctrine:migrations:diff --no-interaction

# Im Container erreicht pg_dump die Datenbank direkt ueber DATABASE_URL (Host
# postgres), ausserhalb laeuft es per `docker compose exec` im postgres-Container.
backup: up ## Dump der Entwicklungsdatenbank nach ./backups
ifeq ($(CONTEXT),outside)
	BACKUP_MODE=docker COMPOSE_SERVICE=postgres ./bin/backup.sh
else
	BACKUP_MODE=local ./bin/backup.sh
endif

# --- Pruefungen -----------------------------------------------------------------

test: up ## Testsuite gegen PostgreSQL
	$(IN_PHP) vendor/bin/phpunit

stan: up ## Statische Analyse (PHPStan Level 6)
	$(IN_PHP) vendor/bin/phpstan analyse --memory-limit=512M --no-progress

# Mago (mago.toml): Neue Befunde ab Stufe "warning" schlagen fehl, der Bestand steht in
# den Baselines mago-lint-baseline.toml und mago-analysis-baseline.toml.
MAGO_FAIL := --minimum-fail-level=warning

lint: up ## Mago-Linter
	$(IN_PHP) mago lint $(MAGO_FAIL)

analyze: up ## Mago-Analyse
	$(IN_PHP) mago analyze $(MAGO_FAIL)

format: up ## Code mit Mago formatieren
	$(IN_PHP) mago format

format-check: up ## Pruefen, ob der Code formatiert ist
	$(IN_PHP) mago format --check

mago: format-check lint analyze ## Alle Mago-Pruefungen

mago-baseline-prune: up ## Behobene Befunde aus den Mago-Baselines entfernen
	$(IN_PHP) mago lint --remove-outdated-baseline-entries
	$(IN_PHP) mago analyze --remove-outdated-baseline-entries

# Die Ausgabe wird im Container in die Datei geschrieben, nicht per Umleitung auf dem
# Host: `docker compose exec` haengt sonst ein TTY an, und die Datei bekaeme \r\n.
# var/ liegt im Bind-Mount und ist innen wie aussen derselbe Pfad.
openapi: up ## openapi.yaml neu erzeugen
	$(IN_PHP) bash -c 'bin/console nelmio:apidoc:dump --format=yaml --no-pretty 2>/dev/null > openapi.yaml'

openapi-check: up ## Pruefen, ob openapi.yaml zum Code passt
	@$(IN_PHP) bash -c 'bin/console nelmio:apidoc:dump --format=yaml --no-pretty 2>/dev/null > var/openapi.generated.yaml'
	@diff -u openapi.yaml var/openapi.generated.yaml \
		&& echo "openapi.yaml ist aktuell." \
		|| (echo "openapi.yaml weicht vom Code ab – 'make openapi' ausfuehren."; exit 1)

check: mago stan test openapi-check admin-check ## Alles, was auch die CI prueft

# --- Admin-Oberflaeche ----------------------------------------------------------

# Vue, Quellen in admin/: npm laeuft im php-Container (Node aus dem Image), gebaut
# wird nach public/admin. Caddy liefert sie unter /admin/ aus.
# node_modules/.package-lock.json schreibt npm nach jeder Installation: neu
# installiert wird nur, wenn sich package-lock.json geaendert hat. `up` ist
# order-only (|), sonst wuerde das phony Target jedes Mal neu installieren.
admin/node_modules/.package-lock.json: admin/package.json admin/package-lock.json | up
	$(IN_PHP) npm --prefix admin ci --no-fund --no-audit

admin-install: up ## Abhaengigkeiten der Admin-Oberflaeche installieren (npm ci)
	$(IN_PHP) npm --prefix admin ci --no-fund --no-audit

admin-build: admin/node_modules/.package-lock.json ## Admin-Oberflaeche nach public/admin bauen
	$(IN_PHP) npm --prefix admin run build

admin-dev: admin/node_modules/.package-lock.json ## Vite-Dev-Server: http://localhost:5173/admin/
	$(IN_PHP) npm --prefix admin run dev

admin-check: admin/node_modules/.package-lock.json ## Typpruefung und Tests der Admin-Oberflaeche
	$(IN_PHP) npm --prefix admin run typecheck
	$(IN_PHP) npm --prefix admin test

# --- Server (prod, staging) -------------------------------------------------------
# Laufen auf dem Server-Host, nie im Container.

prod-build: host-only ## Prod-Image bauen
	$(PROD) build

prod-up: host-only ## Prod-Stack starten (nur auf 127.0.0.1, Port 8081)
	$(PROD) up -d --build --remove-orphans

prod-down: host-only ## Prod-Stack stoppen (Daten bleiben im Volume)
	$(PROD) down

prod-restart: host-only ## FrankenPHP neu starten (laedt auch ein geaendertes Caddyfile.prod)
	$(PROD) restart php

prod-logs: host-only ## Prod-Logs folgen
	$(PROD) logs -f php

prod-migrate: host-only ## Migrations auf die Produktionsdatenbank anwenden
	$(PROD) run --rm migrate

prod-backup: host-only ## Dump der Produktionsdatenbank nach ./backups
	set -a; . ./.env.prod; set +a; $(call server_env,prod,$(PROD_PORT)) COMPOSE_FILE=compose.prod.yaml BACKUP_MODE=docker ./bin/backup.sh

staging-build: host-only ## Staging-Image bauen
	$(STAGING) build

staging-up: host-only ## Staging-Stack starten (nur auf 127.0.0.1, Port 8082)
	$(STAGING) up -d --build --remove-orphans

staging-down: host-only ## Staging-Stack stoppen (Daten bleiben im Volume)
	$(STAGING) down

staging-restart: host-only ## FrankenPHP (Staging) neu starten
	$(STAGING) restart php

staging-logs: host-only ## Staging-Logs folgen
	$(STAGING) logs -f php

staging-migrate: host-only ## Migrations auf die Staging-Datenbank anwenden
	$(STAGING) run --rm migrate

staging-backup: host-only ## Dump der Staging-Datenbank nach ./backups
	set -a; . ./.env.staging; set +a; $(call server_env,staging,$(STAGING_PORT)) COMPOSE_FILE=compose.prod.yaml BACKUP_MODE=docker ./bin/backup.sh
