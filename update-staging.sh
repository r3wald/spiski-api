#!/usr/bin/env bash
#
# Deployt dieses Verzeichnis (api/) ins STAGING (Integrations-Tests u. ae.) und startet die Anwendung neu.
#
#   Server-Verzeichnis  ~/spiski-dev
#   URL                 https://spiski-dev.r3wald.net  (Nginx -> 127.0.0.1:8082)
#   Make-Targets        staging-*  (compose.prod.yaml mit .env.staging)
#
#   ./update-staging.sh
#
# Gegenstueck fuer die Produktion: update-prod.sh. Beide Skripte sind bis auf den Block
# "Ziel" gleich – Aenderungen bitte in beiden nachziehen.
#
# Voraussetzungen auf dem Server: ssh-Host "private", docker (mit compose) und make.

set -euo pipefail

# --- Ziel ------------------------------------------------------------------------
HOST="private"
REMOTE_DIR="spiski-dev"
STAGE="staging"
OTHER_ENV=".env.prod"      # Secrets der jeweils anderen Stage nie mitkopieren
# ---------------------------------------------------------------------------------

ENV_FILE=".env.${STAGE}"

cd "$(dirname "${BASH_SOURCE[0]}")"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "update: ${ENV_FILE} fehlt - ohne Secrets kein Deployment." >&2
  exit 1
fi

# Admin-Oberflaeche lokal bauen (npm im Dev-Container), damit public/admin zum Code passt.
make up admin-build
if [[ ! -f public/admin/index.html ]]; then
  echo "update: public/admin/index.html fehlt - Build der Admin-Oberflaeche fehlgeschlagen." >&2
  exit 1
fi

# Code, compose.prod.yaml, Makefile, bin/backup.sh und die Env-Datei dieser Stage
# (Rechte 600 bleiben erhalten). vendor/ wird nicht kopiert: composer install laeuft
# beim Image-Bau.
rsync -av ./ "${HOST}:${REMOTE_DIR}/" \
  --exclude=.git \
  --exclude=vendor \
  --exclude=tests \
  --exclude='var/' \
  --exclude=backups \
  --exclude='*.md' \
  --exclude='update-*.sh' \
  --exclude=/admin/ \
  --exclude=/public/admin/ \
  --exclude="${OTHER_ENV}"

# Admin-Oberflaeche separat und mit --delete: Vite erzeugt Dateinamen mit Hash, alte
# Builds sollen nicht liegen bleiben.
rsync -av --delete public/admin/ "${HOST}:${REMOTE_DIR}/public/admin/"

# Code-Stand fuer /status.json und /status.html (App\Status\BuildInfo). Landet ueber
# "COPY . ." im Image. Nicht im Repo; "-dirty" = mit uncommitteten Aenderungen deployt.
REVISION="$(git rev-parse --short HEAD 2>/dev/null || true)"
if [[ -n "${REVISION}" && -n "$(git status --porcelain 2>/dev/null)" ]]; then
  REVISION="${REVISION}-dirty"
fi
printf '{"revision": "%s", "deployedAt": "%s"}\n' "${REVISION}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  | ssh "${HOST}" "cat > ${REMOTE_DIR}/build-info.json"

# Reihenfolge: Image bauen, Schema migrieren, Stack aktualisieren, php + caddy neu starten.
# Migriert wird vor dem Start des neuen Codes, damit er nie auf einem alten Schema laeuft.
# make bricht beim ersten fehlgeschlagenen Target ab; zusammen mit set -e stoppt dann alles.
ssh "${HOST}" "cd ${REMOTE_DIR} && make ${STAGE}-build ${STAGE}-migrate ${STAGE}-up ${STAGE}-restart"
