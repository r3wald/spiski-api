# Spiski Sync API

Backend zur Synchronisierung der Einkaufslisten und Artikel der Spiski-App zwischen mehreren Geräten desselben Nutzers.

Das fachliche Konzept – Datenmodell, Sync-Protokoll, Konfliktauflösung, Sicherheits- und Datenschutzentscheidungen – steht in [CLAUDE.md](CLAUDE.md). Diese Datei beschreibt nur den Betrieb.

## Schnellstart

```bash
make build       # Images bauen
make up          # Stack starten
make migrate     # Schema anlegen
curl localhost:8081/v1/health
```

Die API hört auf `http://localhost:8081`. Ein Sync-Request braucht nichts weiter als eine selbst erzeugte UUID als Bearer-Token – der zugehörige Account entsteht beim ersten Kontakt:

```bash
ACCOUNT=$(uuidgen | tr 'A-Z' 'a-z')
CLIENT=$(uuidgen | tr 'A-Z' 'a-z')

curl -s -X POST localhost:8081/v1/sync \
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
| `make prod-up` / `prod-migrate` / `prod-backup` | Produktions-Stack, siehe Abschnitt „Produktion" |

`make help` listet alles auf.

## Endpunkte

| Methode | Pfad | Auth |
|---|---|---|
| `POST` | `/v1/sync` | Bearer |
| `DELETE` | `/v1/me` | Bearer |
| `GET` | `/v1/health` | – |
| `GET` | `/` | – |
| `GET` | `/admin/` | – (Token wird in der Seite abgefragt) |
| `GET` | `/v1/admin/lists` | Admin-Token |

`/` liefert eine statische Startseite (`public/index.html`, dazu `public/robots.txt`). Caddy liefert sie direkt aus, ohne PHP – siehe `handle /` in `docker/caddy/Caddyfile` und `Caddyfile.prod`. Alle anderen Pfade außerhalb von `/v1` landen weiterhin bei Symfony und ergeben dort einen 404.

Die vollständige Beschreibung liegt als [openapi.yaml](openapi.yaml) im Repo und wird in der CI gegen den Code geprüft. Im dev-Environment ist sie zusätzlich unter `/api/doc.json` abrufbar.

## Umgebung

| Variable | Bedeutung |
|---|---|
| `DATABASE_URL` | PostgreSQL-DSN |
| `APP_ENV` | `dev`, `test` oder `prod` |
| `APP_SECRET` | Symfony-Secret |
| `ADMIN_TOKEN` | Token für die Admin-Seite, mindestens 32 Zeichen. Leer oder kürzer = Admin-Zugang abgeschaltet |
| `SPISKI_CLOCK_SKEW_TOLERANCE` | Sekunden, die ein `updatedAt` in der Zukunft liegen darf, bevor der Server es kappt (Default 300) |

Achtung: `APP_ENV` und `DATABASE_URL` sind bewusst **nicht** im `php`-Service der `compose.yaml` gesetzt. Echte Umgebungsvariablen haben in Symfony Vorrang vor `.env`-Dateien; gesetzt würden sie `.env.test` aushebeln und den Testlauf auf die Entwicklungsdatenbank lenken, die er zwischen den Tests per `TRUNCATE` leert.

## Admin-Seite

`/admin/` zeigt im Browser alle Listen aller Accounts: Name und Farbe, Account-Kürzel, Artikel (aktiv / gesamt), letzte Änderung und Status. Man kann nach Name oder Kürzel filtern und gelöschte Listen ausblenden. Die Seite ist statisch (`public/admin/`), die Daten kommen von `GET /v1/admin/lists`.

**Anmeldung per Token.** Die Seite fragt das Token ab und schickt es als `Authorization: Bearer <ADMIN_TOKEN>`. Es wird nur im Arbeitsspeicher des Tabs gehalten, nicht in `localStorage`, Cookies oder der URL; ein Neuladen meldet ab. Es gibt keine Sessions.

```bash
openssl rand -hex 32      # Token erzeugen, in .env.prod (Produktion) bzw. .env.local (lokal) als ADMIN_TOKEN eintragen
make prod-restart         # danach neu starten
```

Ist `ADMIN_TOKEN` leer oder kürzer als 32 Zeichen, ist der Zugang abgeschaltet und jede Anfrage ergibt 401 (dieselbe Meldung wie bei einem falschen Token). Das Token wechselt man, indem man es in `.env.prod` ersetzt und neu startet.

**Datenschutz.** Die Seite zeigt keine Artikel, nur Zähler. Die Account-UUID ist das Zugangs-Secret der Nutzer und wird deshalb nie ausgeliefert, sondern nur ein 12-stelliges Kürzel (SHA-256 der UUID), mit dem sich Listen demselben Account zuordnen lassen. Die Seite macht personenbezogene Daten sichtbar: nur mit dem Token zugänglich halten und nicht weitergeben.

**Missbrauchsschutz.** Höchstens 30 Anfragen pro Minute und IP an `/v1/admin` (auch erfolgreiche); danach 429 mit `Retry-After`. Der Endpunkt steht bewusst nicht in `openapi.yaml`.

## Produktion

`compose.prod.yaml` startet PHP-FPM (Code im Image), Caddy und PostgreSQL. Die Anwendung ist **nur lokal erreichbar**: Caddy lauscht auf `127.0.0.1:${HTTP_PORT:-8081}`, PostgreSQL veröffentlicht keinen Port. TLS und die öffentliche Erreichbarkeit übernimmt ein Nginx-Reverse-Proxy auf dem Host.

Alle Secrets stehen in `.env.prod` (nicht im Repo, nicht im Image, Rechte `600`). Die Make-Targets setzen `--env-file .env.prod` automatisch:

| Befehl | Zweck |
|---|---|
| `make prod-up` | Image bauen und Stack starten |
| `make prod-migrate` | Migrations auf die Produktionsdatenbank anwenden |
| `make prod-logs` | Logs von php und caddy folgen |
| `make prod-backup` | `pg_dump` der Produktionsdatenbank nach `./backups` |
| `make prod-down` | Stack stoppen (Daten bleiben im Volume `postgres_data`) |

Ersteinrichtung: `.env.prod` prüfen (`DEFAULT_URI`), dann `make prod-up && make prod-migrate`. Bei jedem Update dasselbe: Das Image enthält Code und `vendor/`, `composer install` und `cache:warmup` laufen beim Bauen.

`public/` wird als Bind-Mount in den Caddy-Container gelegt und muss deshalb zum selben Stand wie das Image gehören (gleicher Checkout bzw. gleicher rsync).

### Nginx

Caddy spricht kein TLS. Der Nginx terminiert es und muss die Client-IP weiterreichen, sonst sieht das IP-Limit für neue Accounts nur die Proxy-Adresse:

```nginx
server {
    listen 443 ssl;
    server_name spiski.r3wald.net;
    # ssl_certificate / ssl_certificate_key ...

    # Die API akzeptiert höchstens 1 MiB (SyncLimits::MAX_BODY_BYTES); PHP erlaubt 2 MiB.
    client_max_body_size 2m;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Steht vor dem Nginx noch ein weiterer Proxy oder CDN, statt `$remote_addr` `$proxy_add_x_forwarded_for` verwenden. `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS` (in `.env.prod`) und `trusted_proxies` in `docker/caddy/Caddyfile.prod` vertrauen nur privaten Adressbereichen; das ist unbedenklich, solange der Port ausschließlich an `127.0.0.1` gebunden bleibt.

### Backups

`make prod-backup` legt Dumps in `./backups` ab. Die Integritätsprüfung per `pg_restore --list` läuft auf dem Host und wird übersprungen, wenn dort kein `pg_restore` installiert ist. Cron, Ablage außerhalb des Servers, Verschlüsselung und ein Restore-Test sind weiterhin offen (siehe `CLAUDE.md`, Abschnitt 9).

## Tests

Die Testsuite läuft gegen eine echte PostgreSQL-Instanz (Compose-Service `postgres_test`, Daten in tmpfs), nicht gegen SQLite: `SELECT … FOR UPDATE`, `bigint` und `ON DELETE CASCADE` verhalten sich dort abweichend – ausgerechnet bei der Logik, die am schwersten zu debuggen ist. Das Schema wird zu Beginn jedes Laufs über die echten Migrations aufgebaut.

## Backups

`bin/backup.sh` erzeugt einen `pg_dump` im Custom-Format, prüft ihn mit `pg_restore --list`, schreibt eine SHA-256-Summe und rotiert alte Dumps.

```bash
BACKUP_DIR=/srv/backups RETENTION_DAYS=30 bin/backup.sh
```

Die Dumps enthalten personenbezogene Daten: verschlüsselt und außerhalb des Servers ablegen. Und der wichtigste Teil bleibt manuell – ein Backup, das nie zurückgespielt wurde, ist keins.
