# Spiski Sync API

Backend zur Synchronisierung der Einkaufslisten und Artikel der Spiski-App zwischen mehreren Geräten desselben Nutzers.

Das fachliche Konzept – Datenmodell, Sync-Protokoll, Konfliktauflösung, Sicherheits- und Datenschutzentscheidungen – steht in [CLAUDE.md](CLAUDE.md). Diese Datei beschreibt nur den Betrieb.

## Schnellstart

```bash
make build       # Images bauen
make up          # Stack starten
make migrate     # Schema anlegen
curl localhost:8080/v1/health
```

Die API hört auf `http://localhost:8080`. Ein Sync-Request braucht nichts weiter als eine selbst erzeugte UUID als Bearer-Token – der zugehörige Account entsteht beim ersten Kontakt:

```bash
ACCOUNT=$(uuidgen | tr 'A-Z' 'a-z')
CLIENT=$(uuidgen | tr 'A-Z' 'a-z')

curl -s -X POST localhost:8080/v1/sync \
  -H "Authorization: Bearer $ACCOUNT" \
  -H 'Content-Type: application/json' \
  -d "{\"since\": 0, \"clientId\": \"$CLIENT\"}"
```

## Befehle

| Befehl | Zweck |
|---|---|
| `make up` / `make down` | Stack starten / stoppen |
| `make migrate` | Migrations anwenden |
| `make diff` | Migration aus geänderten Entities erzeugen |
| `make test` | Testsuite gegen PostgreSQL |
| `make stan` | PHPStan Level 6 |
| `make openapi` | `openapi.yaml` neu erzeugen |
| `make check` | Analyse + Tests + OpenAPI-Diff (wie die CI) |
| `make backup` | Dump der Entwicklungsdatenbank nach `./backups` |

`make help` listet alles auf.

## Endpunkte

| Methode | Pfad | Auth |
|---|---|---|
| `POST` | `/v1/sync` | Bearer |
| `DELETE` | `/v1/me` | Bearer |
| `GET` | `/v1/health` | – |

Die vollständige Beschreibung liegt als [openapi.yaml](openapi.yaml) im Repo und wird in der CI gegen den Code geprüft. Im dev-Environment ist sie zusätzlich unter `/api/doc.json` abrufbar.

## Umgebung

| Variable | Bedeutung |
|---|---|
| `DATABASE_URL` | PostgreSQL-DSN |
| `APP_ENV` | `dev`, `test` oder `prod` |
| `APP_SECRET` | Symfony-Secret |
| `SPISKI_CLOCK_SKEW_TOLERANCE` | Sekunden, die ein `updatedAt` in der Zukunft liegen darf, bevor der Server es kappt (Default 300) |

Achtung: `APP_ENV` und `DATABASE_URL` sind bewusst **nicht** im `php`-Service der `compose.yaml` gesetzt. Echte Umgebungsvariablen haben in Symfony Vorrang vor `.env`-Dateien; gesetzt würden sie `.env.test` aushebeln und den Testlauf auf die Entwicklungsdatenbank lenken, die er zwischen den Tests per `TRUNCATE` leert.

## Tests

Die Testsuite läuft gegen eine echte PostgreSQL-Instanz (Compose-Service `postgres_test`, Daten in tmpfs), nicht gegen SQLite: `SELECT … FOR UPDATE`, `bigint` und `ON DELETE CASCADE` verhalten sich dort abweichend – ausgerechnet bei der Logik, die am schwersten zu debuggen ist. Das Schema wird zu Beginn jedes Laufs über die echten Migrations aufgebaut.

## Backups

`bin/backup.sh` erzeugt einen `pg_dump` im Custom-Format, prüft ihn mit `pg_restore --list`, schreibt eine SHA-256-Summe und rotiert alte Dumps.

```bash
BACKUP_DIR=/srv/backups RETENTION_DAYS=30 bin/backup.sh
```

Die Dumps enthalten personenbezogene Daten: verschlüsselt und außerhalb des Servers ablegen. Und der wichtigste Teil bleibt manuell – ein Backup, das nie zurückgespielt wurde, ist keins.
