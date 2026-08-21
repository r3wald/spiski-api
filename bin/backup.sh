#!/usr/bin/env bash
#
# Erstellt einen komprimierten pg_dump der Spiski-Datenbank, verifiziert ihn,
# schreibt eine Prüfsumme und rotiert alte Dumps.
#
# Konfiguration über Environment-Variablen:
#   DATABASE_URL     postgresql://user:pass@host:port/db  (sonst aus .env.local / .env)
#   BACKUP_DIR       Zielverzeichnis                       (default: <repo>/backups)
#   RETENTION_DAYS   Aufbewahrung in Tagen, 0 = unbegrenzt (default: 14)
#   BACKUP_MODE      auto | local | docker                 (default: auto)
#   COMPOSE_SERVICE  Compose-Service mit Postgres          (default: postgres)
#
# Beispiele:
#   bin/backup.sh
#   BACKUP_DIR=/srv/backups RETENTION_DAYS=30 bin/backup.sh
#   BACKUP_MODE=docker bin/backup.sh
#
# Exit-Codes: 0 = ok, 1 = Konfigurations-/Laufzeitfehler.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

BACKUP_DIR="${BACKUP_DIR:-${REPO_ROOT}/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
BACKUP_MODE="${BACKUP_MODE:-auto}"
COMPOSE_SERVICE="${COMPOSE_SERVICE:-postgres}"

die() { printf 'backup: %s\n' "$*" >&2; exit 1; }
log() { printf '[%s] %s\n' "$(date '+%H:%M:%S')" "$*"; }

# --- DATABASE_URL ermitteln ---------------------------------------------------
# Symfony-Konvention: .env.local überschreibt .env. Wir lesen nur die eine Zeile,
# statt die Dateien zu sourcen, damit nichts anderes aus .env hier landet.
if [[ -z "${DATABASE_URL:-}" ]]; then
  for env_file in "${REPO_ROOT}/.env.local" "${REPO_ROOT}/.env"; do
    [[ -f "${env_file}" ]] || continue
    line="$(grep -E '^[[:space:]]*DATABASE_URL=' "${env_file}" | tail -n 1 || true)"
    [[ -n "${line}" ]] || continue
    value="${line#*=}"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    DATABASE_URL="${value}"
    break
  done
fi

[[ -n "${DATABASE_URL:-}" ]] || die "DATABASE_URL nicht gesetzt und in .env(.local) nicht gefunden."

urldecode() { printf '%b' "${1//%/\\x}"; }

# postgresql://user:pass@host:port/dbname?params
url_no_scheme="${DATABASE_URL#*://}"
url_no_query="${url_no_scheme%%\?*}"
credentials="${url_no_query%%@*}"
hostpath="${url_no_query#*@}"

[[ "${url_no_query}" == *@* ]] || die "DATABASE_URL enthält keine Zugangsdaten: ${url_no_query}"

DB_USER="$(urldecode "${credentials%%:*}")"
DB_PASS=""
[[ "${credentials}" == *:* ]] && DB_PASS="$(urldecode "${credentials#*:}")"

hostport="${hostpath%%/*}"
DB_NAME="${hostpath#*/}"
DB_HOST="${hostport%%:*}"
DB_PORT="5432"
[[ "${hostport}" == *:* ]] && DB_PORT="${hostport##*:}"

[[ -n "${DB_NAME}" ]] || die "Kein Datenbankname in DATABASE_URL."

# --- Modus wählen -------------------------------------------------------------
if [[ "${BACKUP_MODE}" == "auto" ]]; then
  if command -v pg_dump >/dev/null 2>&1; then
    BACKUP_MODE="local"
  elif docker compose version >/dev/null 2>&1; then
    BACKUP_MODE="docker"
  else
    die "Weder pg_dump noch 'docker compose' gefunden. BACKUP_MODE explizit setzen."
  fi
fi

# Im Container ist Postgres unter seinem Service-Namen erreichbar, nicht unter
# dem Host-Mapping aus DATABASE_URL.
if [[ "${BACKUP_MODE}" == "docker" ]]; then
  DB_HOST="${COMPOSE_SERVICE}"
  DB_PORT="5432"
fi

run_pg() {
  local tool="$1"; shift
  case "${BACKUP_MODE}" in
    local)
      PGPASSWORD="${DB_PASS}" "${tool}" "$@"
      ;;
    docker)
      docker compose --project-directory "${REPO_ROOT}" exec -T \
        -e "PGPASSWORD=${DB_PASS}" "${COMPOSE_SERVICE}" "${tool}" "$@"
      ;;
    *)
      die "Unbekannter BACKUP_MODE: ${BACKUP_MODE}"
      ;;
  esac
}

checksum() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1"
  else
    shasum -a 256 "$1"
  fi
}

# --- Dump ---------------------------------------------------------------------
mkdir -p "${BACKUP_DIR}"

stamp="$(date '+%Y%m%d-%H%M%S')"
target="${BACKUP_DIR}/spiski-${DB_NAME}-${stamp}.dump"
tmp="${target}.part"

# Muss 0 liefern: als EXIT-Trap würde ein non-zero Rückgabewert sonst den
# Exit-Code des gesamten Skripts überschreiben.
# shellcheck disable=SC2329  # wird per trap aufgerufen
cleanup() { rm -f "${tmp}"; return 0; }
trap cleanup EXIT

log "Dump ${DB_NAME}@${DB_HOST}:${DB_PORT} (Modus: ${BACKUP_MODE}) -> ${target}"

# --format=custom: komprimiert und mit pg_restore selektiv wiederherstellbar.
# Ohne --file schreibt pg_dump nach stdout; die Datei landet dadurch im
# Docker-Modus auf dem Host statt im Container. Ein explizites
# --file=/dev/stdout waere hier falsch: pg_dump fsynct die Zieldatei, und auf
# einer Pipe schlaegt das fehl.
run_pg pg_dump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USER}" \
  --dbname="${DB_NAME}" \
  --format=custom \
  --compress=9 \
  --no-owner \
  --no-privileges > "${tmp}"

[[ -s "${tmp}" ]] || die "Dump ist leer – Abbruch, vorhandene Backups bleiben unangetastet."

# --- Verifizieren -------------------------------------------------------------
# pg_restore --list liest das Inhaltsverzeichnis und schlägt fehl, wenn der Dump
# abgeschnitten oder korrupt ist. Billiger Integritätstest, ersetzt aber keinen
# echten Restore-Test (siehe CLAUDE.md, Abschnitt 9).
# Gegen die Datei auf dem Host, nicht über eine Pipe in den Container: pg_restore
# braucht für das Inhaltsverzeichnis eines Custom-Format-Dumps eine seekbare Quelle.
if command -v pg_restore >/dev/null 2>&1; then
  pg_restore --list "${tmp}" >/dev/null || die "Dump ist nicht lesbar (pg_restore --list)."
else
  log "Hinweis: pg_restore nicht verfügbar, Integritätsprüfung übersprungen."
fi

mv "${tmp}" "${target}"
trap - EXIT

chmod 600 "${target}"
checksum "${target}" > "${target}.sha256"

size="$(du -h "${target}" | cut -f1)"
log "Fertig: ${target} (${size})"

# --- Rotation -----------------------------------------------------------------
if [[ "${RETENTION_DAYS}" -gt 0 ]]; then
  removed=0
  while IFS= read -r -d '' old; do
    rm -f "${old}" "${old}.sha256"
    removed=$((removed + 1))
  done < <(find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'spiski-*.dump' \
             -mtime "+${RETENTION_DAYS}" -print0)
  [[ "${removed}" -gt 0 ]] && log "Rotation: ${removed} Dump(s) älter als ${RETENTION_DAYS} Tage gelöscht."
fi

exit 0
