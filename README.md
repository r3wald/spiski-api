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
| `POST` | `/v1/lists/{id}/share`, `/v1/lists/{id}/share/renew` | Bearer |
| `DELETE` | `/v1/lists/{id}/share`, `/v1/lists/{id}/membership` | Bearer |
| `POST` | `/v1/shares/{code}` | Bearer |
| `GET` | `/join/{code}` | – (statische Seite, Teilen-Link) |
| `GET` | `/.well-known/assetlinks.json` | – (Android App Links) |
| `GET` | `/v1/health` | – |
| `GET` | `/status.json`, `/status.html` | – (Statusseite, siehe unten) |
| `GET` | `/` | – |
| `GET` | `/admin` | Admin-Token im Header (HTML-Seite) |
| `GET` | `/v1/admin/lists` | Admin-Token |

`/` liefert eine statische Startseite (`public/index.html`, dazu `public/robots.txt`). FrankenPHP liefert sie direkt aus, ohne PHP – siehe `handle /` in `docker/frankenphp/Caddyfile` und `Caddyfile.prod`. Alle anderen Pfade außerhalb von `/v1` landen weiterhin bei Symfony und ergeben dort einen 404.

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

## Statusseite

`/status.json` und `/status.html` zeigen den Zustand der Anwendung, **ohne Anmeldung**. Beide liefern dieselben Daten; die HTML-Seite kommt ohne JavaScript aus und lädt sich alle 60 Sekunden neu.

| Bereich | Inhalt |
|---|---|
| `status` | `ok`, `degraded` (Migrationen ausstehend) oder `down` (Datenbank nicht erreichbar) |
| `api` | `version` – Version der API, dieselbe wie `info.version` in `openapi.yaml`; `revision` – Git-Commit, `-dirty` bei uncommitteten Änderungen; `deployedAt` |
| `database` | erreichbar ja/nein, Antwortzeit in ms |
| `migrations` | Schema aktuell oder Zahl ausstehender Migrationen, zuletzt angewendete Migration |
| `statistics` | Summen: Konten (gesamt, aktiv in 24 h / 7 / 30 Tagen), Listen (aktiv, gelöscht, geteilt), Einträge (aktiv, archiviert), Mitgliedschaften |

HTTP-Status ist `503` bei `down`, sonst `200` – ein externer Monitor muss nur den Statuscode prüfen. Antworten sind `no-store`; `/status.json` erlaubt CORS (`*`). Höchstens 60 Anfragen pro Minute und IP, danach `429`.

**Version.** Die API-Version steht an genau einer Stelle, dem Parameter `spiski.api_version` in `config/services.yaml`; `openapi.yaml` übernimmt sie (danach `make openapi`). Revision und Deployment-Zeitpunkt schreibt `update.sh` bei jedem Deployment nach `build-info.json` (nicht im Repo, landet über `COPY . .` im Image). Lokal fehlt die Datei, dann steht dort „unbekannt“.

**Datenschutz.** Die Seite zeigt nur Summen – keine Account-UUIDs, keine Kürzel, keine Listennamen oder Einträge, keine Software-Versionen der Infrastruktur und keine Fehlermeldungen. Die Nutzungszahlen sind damit öffentlich sichtbar.

`/v1/health` bleibt der schlanke Check für den Container-Healthcheck.

## Admin-Seite

`/admin` zeigt im Browser alle Listen aller Accounts: Name und Farbe, Account-Kürzel, Einträge (aktiv / gesamt), letzte Änderung (UTC) und Status. Unter jeder Liste klappen **ihre Einträge** auf (Menge, Einheit, Name wie in der App; dazu Notiz sowie die Kennzeichen *erledigt*, *verschoben* und *archiviert*). **Archivierte** (gelöschte) Einträge sind standardmäßig ausgeblendet und lassen sich einblenden; die Zusammenfassung nennt ihre Zahl. Die Seite lässt sich nach Listenname oder Account-Kürzel filtern, gelöschte Listen lassen sich ausblenden, und sie ist seitenweise blätterbar. Sie wird serverseitig als reines HTML gerendert: **ohne JavaScript, ohne Formular zur Anmeldung, ohne Session und ohne Cookie.**

**Das Token wird bei jedem Request mitgeschickt**, im Header:

```
Authorization: Bearer <ADMIN_TOKEN>
```

Fehlt der Header oder stimmt das Token nicht, antwortet `/admin` mit `401` (Problem-Details-JSON mit dem Hinweis auf den Header). Ein Token in der URL (`?token=…`) wird **bewusst nicht akzeptiert**: URLs landen in Server-Logs, im Browserverlauf und im Referer.

**Im Browser** braucht es dafür eine Erweiterung, die einen Request-Header setzt, z. B. *ModHeader* (Chrome/Firefox): Header `Authorization` mit dem Wert `Bearer <ADMIN_TOKEN>`. **Unbedingt einen URL-Filter auf `https://spiski.r3wald.net/*` setzen**, sonst schickt die Erweiterung das Token an jede Website, die du besuchst. Ohne Erweiterung zeigt der Browser nur das `401`. Per Kommandozeile:

```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" https://spiski.r3wald.net/admin          # HTML
curl -H "Authorization: Bearer $ADMIN_TOKEN" "https://spiski.r3wald.net/v1/admin/lists?q=milch"   # JSON
```

| Parameter | Wirkung | `/admin` | `/v1/admin/lists` |
|-----------|---------|:--------:|:-----------------:|
| `q` | Teilstring des Listennamens (ohne Groß-/Kleinschreibung) **oder** Anfang eines Account-Kürzels; `%` und `_` gelten wörtlich | ja | ja |
| `hideDeleted=1` | gelöschte Listen ausblenden | ja | – |
| `includeDeleted=0` | gelöschte Listen ausblenden | – | ja |
| `archivedItems=1` | archivierte (gelöschte) Einträge mit anzeigen | ja | – |
| `open=1` | Einträge aufgeklappt darstellen | ja | – |
| `includeItems=1` | die Einträge jeder Liste unter `items` mitliefern | – | ja |
| `includeArchivedItems=1` | dabei auch archivierte Einträge (nur zusammen mit `includeItems=1`) | – | ja |
| `limit`, `offset` | Seitengröße (Standard 50 bzw. 100, höchstens 500) und Versatz | ja | ja |

Token erzeugen und wechseln:

```bash
openssl rand -hex 32      # als ADMIN_TOKEN in .env.prod (Produktion) bzw. .env.local (lokal) eintragen
make prod-restart         # danach neu starten
```

Ist `ADMIN_TOKEN` leer oder kürzer als 32 Zeichen, ist der Zugang abgeschaltet und jede Anfrage ergibt `401` (dieselbe Meldung wie bei einem falschen Token).

**Datenschutz.** Die Seite zeigt Listennamen, Einträge und Notizen und damit Nutzerinhalt, aber keine Account-UUID: Sie ist das Zugangs-Secret der Nutzer und wird deshalb nie ausgeliefert, sondern nur ein 12-stelliges Kürzel (SHA-256 der UUID), mit dem sich Listen demselben Account zuordnen lassen. Alle Nutzertexte (Listennamen) werden HTML-escaped. Die Antworten sind `no-store`, tragen `Referrer-Policy: no-referrer` und eine Content-Security-Policy ohne Skripte. Die Seite macht personenbezogene Daten sichtbar: nur mit dem Token zugänglich halten und nicht weitergeben.

**Missbrauchsschutz.** Höchstens 30 Anfragen pro Minute und IP an `/admin` und `/v1/admin` (auch erfolgreiche); danach `429` mit `Retry-After`. Die Admin-Endpunkte stehen bewusst nicht in `openapi.yaml`.

## Produktion

`compose.prod.yaml` startet FrankenPHP (PHP und Webserver in einem Container, Code im Image, mit eingebautem Mercure-Hub für Live-Updates) und PostgreSQL. Die Anwendung ist **nur lokal erreichbar**: FrankenPHP lauscht auf `127.0.0.1:${HTTP_PORT:-8081}`, PostgreSQL veröffentlicht keinen Port. TLS und die öffentliche Erreichbarkeit übernimmt ein Nginx-Reverse-Proxy auf dem Host.

Alle Secrets stehen in `.env.prod` (nicht im Repo, nicht im Image, Rechte `600`). Die Make-Targets setzen `--env-file .env.prod` automatisch:

| Befehl | Zweck |
|---|---|
| `make prod-up` | Image bauen und Stack starten |
| `make prod-migrate` | Migrations auf die Produktionsdatenbank anwenden |
| `make prod-logs` | Logs von FrankenPHP folgen |
| `make prod-restart` | FrankenPHP neu starten (lädt auch ein geändertes `Caddyfile.prod`) |
| `make prod-backup` | `pg_dump` der Produktionsdatenbank nach `./backups` |
| `make prod-down` | Stack stoppen (Daten bleiben im Volume `postgres_data`) |

Ersteinrichtung: `.env.prod` prüfen (`DEFAULT_URI`, `MERCURE_JWT_SECRET` mit mindestens 32 zufälligen Zeichen, z. B. `openssl rand -hex 32`), dann `make prod-up && make prod-migrate`. Bei jedem Update dasselbe: Das Image enthält Code und `vendor/`, `composer install` und `cache:warmup` laufen beim Bauen.

`public/` wird als Bind-Mount in den Container gelegt und muss deshalb zum selben Stand wie das Image gehören (gleicher Checkout bzw. gleicher rsync). So kommen auch die App-Downloads (`spiski.apk`, `spiski.json`) und die Web-App (`public/app/`) ohne Neubau dazu.

### Nginx

FrankenPHP spricht hier kein TLS. Der Nginx terminiert es und muss die Client-IP weiterreichen, sonst sieht das IP-Limit für neue Accounts nur die Proxy-Adresse. Für den Mercure-Hub braucht er einen eigenen `location`-Block: Die Abos sind dauerhafte Server-Sent-Events-Verbindungen, die Nginx weder puffern noch nach 60 Sekunden schließen darf.

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

    # Live-Updates (CLAUDE.md 13): dauerhafte SSE-Verbindungen.
    location /.well-known/mercure {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 24h;
    }
}
```

Steht vor dem Nginx noch ein weiterer Proxy oder CDN, statt `$remote_addr` `$proxy_add_x_forwarded_for` verwenden. `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS` (in `.env.prod`) und `trusted_proxies` in `docker/frankenphp/Caddyfile.prod` vertrauen nur privaten Adressbereichen; das ist unbedenklich, solange der Port ausschließlich an `127.0.0.1` gebunden bleibt.

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
