# Spiski Sync API

Backend zur Synchronisierung der Einkaufslisten und Artikel der Spiski-App zwischen mehreren Geräten desselben Nutzers.

Das fachliche Konzept – Datenmodell, Sync-Protokoll, Konfliktauflösung, Sicherheits- und Datenschutzentscheidungen – steht in [CLAUDE.md](CLAUDE.md). Diese Datei beschreibt nur den Betrieb.

## Umgebungen

| Umgebung | Wo | `APP_ENV` | Datenbank | Konfiguration | URL / Port | Wer nutzt sie |
|---|---|---|---|---|---|---|
| Entwicklung | lokal, `compose.yaml` | `dev` | Service `postgres` (Volume) | `.env`, `.env.dev`, `.env.local` | `http://localhost:8081` | lokale Entwicklung, App mit `API_BASE_URL` |
| Test | lokal, `compose.yaml` | `test` | Service `postgres_test` (tmpfs) | `.env.test` | – | `make test`, CI |
| Staging | Server, `~/spiski-dev` | `prod` | Compose-Projekt `spiski-staging` | `.env.staging` | `https://spiski-dev.r3wald.net` → `127.0.0.1:8082` | Dev-App „Spiski (dev)“, Integrationstests |
| Produktion | Server, `~/spiski` | `prod` | Compose-Projekt `spiski-prod` | `.env.prod` | `https://spiski.r3wald.net` → `127.0.0.1:8081` | Release-App, Web-App |

Staging und Produktion laufen mit demselben `compose.prod.yaml`, aber in getrennten Verzeichnissen und Compose-Projekten: eigene Container, eigenes Netz, eigene Datenbank, eigene Secrets, eigener Image-Tag (`spiski-api:staging` bzw. `:prod`). Deployt wird mit `./update-staging.sh` bzw. `./update-prod.sh`, siehe Abschnitt „Server“.

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
| `make prod-up` / `prod-migrate` / `prod-backup` | Produktions-Stack, siehe Abschnitt „Server" |
| `make staging-up` / `staging-migrate` / `staging-backup` | Staging-Stack, dasselbe für Staging |

`make help` listet alles auf.

## Endpunkte

| Methode | Pfad | Auth |
|---|---|---|
| `POST` | `/v1/sync` | Bearer |
| `DELETE` | `/v1/me` | Bearer |
| `POST` | `/v1/lists/{id}/share`, `/v1/lists/{id}/share/renew` | Bearer |
| `DELETE` | `/v1/lists/{id}/share`, `/v1/lists/{id}/membership` | Bearer |
| `POST` | `/v1/shares/{code}` | Bearer |
| `GET` | `/v1/me/encryption` | Bearer (Header `X-Spiski-Protocol: 2`) |
| `POST` | `/v1/me/encryption/begin`, `/v1/me/encryption/commit` | Bearer (Header `X-Spiski-Protocol: 2`) |
| `GET` | `/join/{code}` | – (statische Seite, Teilen-Link) |
| `GET` | `/.well-known/assetlinks.json` | – (Android App Links) |
| `GET` | `/v1/health` | – |
| `GET` | `/status.json`, `/status.html` | – (Statusseite, siehe unten) |
| `GET` | `/` | – |
| `GET` | `/admin` | Admin-Token im Header (HTML-Seite) |
| `GET`, `POST` | `/admin/items/{id}/delete` | Admin-Token im Header; `POST` zusätzlich Bestätigungstoken (Eintrag löschen) |
| `GET`, `POST` | `/admin/lists/{id}/delete` | wie oben (ganze Liste löschen) |
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
| `SPISKI_ENCRYPTION_DEADLINE` | Frist für die Umstellung auf Verschlüsselung, ISO-Datum (z. B. `2027-01-31T00:00:00Z`); leer = keine Frist. Erst setzen, wenn die App mit Verschlüsselung veröffentlicht ist – siehe „Verschlüsselung“ |

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

**Version.** Die API-Version steht an genau einer Stelle, dem Parameter `spiski.api_version` in `config/services.yaml`; `openapi.yaml` übernimmt sie (danach `make openapi`). Revision und Deployment-Zeitpunkt schreiben `update-prod.sh` bzw. `update-staging.sh` bei jedem Deployment nach `build-info.json` (nicht im Repo, landet über `COPY . .` im Image). Lokal fehlt die Datei, dann steht dort „unbekannt“.

**Datenschutz.** Die Seite zeigt nur Summen – keine Account-UUIDs, keine Kürzel, keine Listennamen oder Einträge, keine Software-Versionen der Infrastruktur und keine Fehlermeldungen. Die Nutzungszahlen sind damit öffentlich sichtbar.

`/v1/health` bleibt der schlanke Check für den Container-Healthcheck.

## Admin-Seite

`/admin` zeigt im Browser alle Listen aller Accounts: Name und Farbe, Account-Kürzel, **Zugriff** (ob und mit wie vielen Nutzern die Liste geteilt ist), Einträge (aktiv / gesamt), letzte Änderung (UTC) und Status. Unter jeder Liste klappen **ihre Einträge** auf (Menge, Einheit, Name wie in der App; dazu Notiz sowie die Kennzeichen *erledigt*, *verschoben* und *archiviert*). **Archivierte** (gelöschte) Einträge sind standardmäßig ausgeblendet und lassen sich einblenden; die Zusammenfassung nennt ihre Zahl. Die Seite lässt sich nach Listenname oder Account-Kürzel filtern, gelöschte Listen lassen sich ausblenden, und sie ist seitenweise blätterbar. Sie wird serverseitig als reines HTML gerendert: **ohne JavaScript, ohne Formular zur Anmeldung, ohne Session und ohne Cookie.**

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

**Zugriff / Teilen.** Die Spalte zeigt, ob eine Liste geteilt ist (mindestens ein Mitglied, wie auf der Statusseite) und wie viele Nutzer sie sehen dürfen, **Besitzer + Mitglieder**: „geteilt · 3 Nutzer, Besitzer + 2 Mitglieder". Ist ein Teilen-Code aktiv, aber noch niemand beigetreten, steht dort „Code aktiv, noch niemand beigetreten" (noch nicht geteilt, 1 Nutzer). Der Code selbst wird **nie** angezeigt: Wer ihn kennt, kann der Liste beitreten. Im JSON stehen dazu pro Liste `shared`, `memberCount` (ohne Besitzer), `userCount` (Besitzer + Mitglieder) und `shareCodeActive`.

**Einträge und Listen löschen.** Jeder nicht archivierte Eintrag und jede nicht gelöschte Liste (Spalte *Status*) hat einen Link **Löschen**. Er führt auf eine Bestätigungsseite (`GET /admin/items/{id}/delete`). Sie zeigt den Eintrag bzw. die Liste (mit Zahl der Einträge), das Account-Kürzel und wie viele Nutzer betroffen sind, und ändert noch nichts. Erst **„Ja, … löschen“** schickt das Formular ab (`POST`; für Listen `/admin/lists/{id}/delete`). Danach geht es zurück zur Übersicht mit demselben Filter, und ein Hinweis bestätigt das Löschen.

- **Wirkung wie in der App:** Der Eintrag wird archiviert (Tombstone), nicht hart entfernt, und verschwindet beim nächsten Sync auf allen Geräten aller Nutzer der Liste – bei geteilten Listen also auch bei den Mitgliedern. Geöffnete Apps erfahren es sofort per Live-Update. Ein hartes Löschen ginge nicht: Das nächste Gerät mit dem alten Stand würde den Eintrag per Sync wieder anlegen.
- **Gegen Wiederauferstehung:** Der Tombstone ist immer neuer als der bisherige Stand. Ein Gerät, das seinen alten Stand erneut schickt, verliert den Last-Write-Wins-Vergleich und bekommt den Tombstone zurück. Ändert ein Nutzer den Eintrag danach in der App, gilt wie immer die spätere Änderung.
- **Listen** werden wie in der App gelöscht: Nur die Liste bekommt den Tombstone, ihre Einträge bleiben unverändert und verschwinden mit ihr. Der Besitzer findet sie danach im **Archiv** der App und kann sie wiederherstellen. Mitgliedschaften und Teilen-Code bleiben; nach einer Wiederherstellung sehen die Mitglieder sie wieder.
- **Verschlüsselte Einträge und Listen** lassen sich genauso löschen; der Chiffretext bleibt unberührt.
- **Schutz gegen fremde Formulare (CSRF):** Eine Header-Erweiterung schickt das Admin-Token bei *jeder* Anfrage an die Domain mit, auch bei einem Formular, das eine fremde Website abschickt. Deshalb verlangt der `POST` ein Bestätigungstoken, das nur auf der Bestätigungsseite steht (HMAC mit `APP_SECRET`, eine Stunde gültig, an Aktion und Eintrag bzw. Liste gebunden). Zusätzlich lehnt er Browser-Anfragen ab, deren `Sec-Fetch-Site` nicht `same-origin` ist. Fehlt das Token oder ist es abgelaufen: `403`, nichts geändert.
- Doppelt abgeschickt (Neuladen, Zurück-Taste): Eintrag bzw. Liste sind schon gelöscht, es passiert nichts mehr.

Token erzeugen und wechseln:

```bash
openssl rand -hex 32      # als ADMIN_TOKEN in .env.prod (Produktion) bzw. .env.local (lokal) eintragen
make prod-restart         # danach neu starten
```

Ist `ADMIN_TOKEN` leer oder kürzer als 32 Zeichen, ist der Zugang abgeschaltet und jede Anfrage ergibt `401` (dieselbe Meldung wie bei einem falschen Token).

**Datenschutz.** Die Seite zeigt Listennamen, Einträge und Notizen und damit Nutzerinhalt, aber keine Account-UUID: Sie ist das Zugangs-Secret der Nutzer und wird deshalb nie ausgeliefert, sondern nur ein 12-stelliges Kürzel (SHA-256 der UUID), mit dem sich Listen demselben Account zuordnen lassen. Alle Nutzertexte (Listennamen) werden HTML-escaped. Die Antworten sind `no-store`, tragen `Referrer-Policy: no-referrer` und eine Content-Security-Policy ohne Skripte. Die Seite macht personenbezogene Daten sichtbar: nur mit dem Token zugänglich halten und nicht weitergeben.

**Missbrauchsschutz.** Höchstens 30 Anfragen pro Minute und IP an `/admin` und `/v1/admin` (auch erfolgreiche); danach `429` mit `Retry-After`. Die Admin-Endpunkte stehen bewusst nicht in `openapi.yaml`.

## Server (Produktion und Staging)

`compose.prod.yaml` startet FrankenPHP (PHP und Webserver in einem Container, Code im Image, mit eingebautem Mercure-Hub für Live-Updates) und PostgreSQL. Die Anwendung ist **nur lokal erreichbar**: FrankenPHP lauscht auf `127.0.0.1:8081` (Produktion) bzw. `127.0.0.1:8082` (Staging), PostgreSQL veröffentlicht keinen Port. TLS und die öffentliche Erreichbarkeit übernimmt ein Nginx-Reverse-Proxy auf dem Host.

Alle Secrets stehen in `.env.prod` bzw. `.env.staging` (nicht im Repo, nicht im Image, Rechte `600`). Staging hat **eigene** Secrets: Ein Admin-Token oder Mercure-Schlüssel aus Staging öffnet in der Produktion nichts. Die Make-Targets setzen Env-Datei, Compose-Projekt (`spiski-prod`/`spiski-staging`), Image-Tag und Port automatisch:

| Produktion | Staging | Zweck |
|---|---|---|
| `make prod-up` | `make staging-up` | Image bauen und Stack starten |
| `make prod-migrate` | `make staging-migrate` | Migrations anwenden |
| `make prod-logs` | `make staging-logs` | Logs von FrankenPHP folgen |
| `make prod-restart` | `make staging-restart` | FrankenPHP neu starten (lädt auch ein geändertes `Caddyfile.prod`) |
| `make prod-backup` | `make staging-backup` | `pg_dump` der Datenbank nach `./backups` |
| `make prod-down` | `make staging-down` | Stack stoppen (Daten bleiben im Volume `postgres_data` des Projekts) |

**Deployment** vom Entwicklungsrechner aus, per rsync und ssh auf den Host `private`:

```bash
./update-staging.sh   # -> ~/spiski-dev, https://spiski-dev.r3wald.net
./update-prod.sh      # -> ~/spiski,     https://spiski.r3wald.net
```

Beide Skripte kopieren nur die Env-Datei ihrer eigenen Stage (die andere ist ausgeschlossen), schreiben `build-info.json` und führen dann `make <stage>-build <stage>-migrate <stage>-up <stage>-restart` aus. Sie sind bis auf den Block „Ziel“ gleich; Änderungen in beiden nachziehen.

Ersteinrichtung: Env-Datei prüfen (`DEFAULT_URI`, `MERCURE_JWT_SECRET` mit mindestens 32 zufälligen Zeichen, z. B. `openssl rand -hex 32`), dann das Update-Skript ausführen – es baut, migriert und startet. Bei jedem Update dasselbe: Das Image enthält Code und `vendor/`, `composer install` und `cache:warmup` laufen beim Bauen.

Staging läuft wie die Produktion mit `APP_ENV=prod`, damit dort genau das Verhalten getestet wird, das später live geht. Die Mercure-Kennungen (`issuer`/`resource_identifier`, CLAUDE.md 13) sind feste Namen und bleiben auch in Staging `https://spiski.r3wald.net`; getrennt sind die Umgebungen über das Secret.

`public/` wird als Bind-Mount in den Container gelegt und muss deshalb zum selben Stand wie das Image gehören (gleicher Checkout bzw. gleicher rsync). So kommen auch die App-Downloads (`spiski.apk`, `spiski.json`) und die Web-App (`public/app/`) ohne Neubau dazu.

### Nginx

Zwei `server`-Blöcke nach demselben Muster: `spiski.r3wald.net` → `127.0.0.1:8081`, `spiski-dev.r3wald.net` → `127.0.0.1:8082`. Beispiel Produktion:

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

Für Staging denselben Block mit `server_name spiski-dev.r3wald.net;`, eigenem Zertifikat und `proxy_pass http://127.0.0.1:8082;` in beiden `location`s. Zusätzlich hält dort ein `add_header X-Robots-Tag "noindex, nofollow" always;` Suchmaschinen fern (die Startseite und die Teilen-Seiten sind sonst identisch zur Produktion).

Steht vor dem Nginx noch ein weiterer Proxy oder CDN, statt `$remote_addr` `$proxy_add_x_forwarded_for` verwenden. `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS` (in `.env.prod`) und `trusted_proxies` in `docker/frankenphp/Caddyfile.prod` vertrauen nur privaten Adressbereichen; das ist unbedenklich, solange der Port ausschließlich an `127.0.0.1` gebunden bleibt.

### Verschlüsselung: Frist und Löschen des Klartexts

Ablauf (`CLAUDE.md`, Abschnitt 14): Übergangszeit eine Woche. Eingetragen sind `SPISKI_ENCRYPTION_DEADLINE=2026-11-23T23:00:00Z` in `.env.prod` (24.11.2026, 0 Uhr deutscher Zeit) und ein bereits abgelaufenes Datum in `.env.staging` (Staging verlangt sofort Verschlüsselung). Wirksam erst nach dem Deployment (`./update-prod.sh` bzw. `./update-staging.sh`). Nach Ablauf bekommen alte Apps `426`, unverschlüsselte Konten dürfen nur noch umstellen.

Täglich per Cron auf dem Host (vorher einmal mit `--dry-run` prüfen):

```bash
docker compose --env-file .env.prod -f compose.prod.yaml exec -T php bin/console app:purge-plaintext
```

Vor Ablauf der Frist tut der Befehl nichts. Danach löscht er Listen und Artikel aller Konten, die noch `plaintext` sind (Konten selbst und ihr Zähler bleiben). PostgreSQL hält den Klartext danach noch in toten Tupeln; einmalig im Wartungsfenster (sperrt die Tabellen):

```bash
docker compose --env-file .env.prod -f compose.prod.yaml exec -T postgres \
  psql -U spiski -d spiski -c 'VACUUM FULL shopping_list, shopping_item, list_member'
```

Alte Backups mit Klartext laufen über die Rotation aus.

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
