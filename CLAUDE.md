# Spiski Backend – Projektplan

## 1. Ziel

Ein reines API-Backend, das die lokalen Daten der Spiski-App (Einkaufslisten und Artikel) zwischen mehreren Geräten desselben Nutzers synchronisiert. Kein klassischer Account: Jede App-Installation erzeugt eine zufällige UUID, die gegenüber dem Backend als alleiniger Identitätsnachweis dient. Es werden keine weiteren personenbezogenen Daten (Name, E-Mail, o. ä.) gespeichert.

## 2. Rahmenbedingungen (Entscheidungen)

| Thema | Entscheidung |
|---|---|
| Framework | Symfony 8.1 (API-only Skeleton, kein Twig/Webapp-Bundle) |
| PHP | 8.5 (aktuelle Stable-Branch, Active Support bis 12/2027; Symfony 8.1 verlangt ≥ 8.4) |
| Datenbank | PostgreSQL 18 – produktiv **und** in Tests, kein SQLite |
| Static Analysis | PHPStan Level 6 (mittleres Level) |
| API-Beschreibung | Vollständige OpenAPI-3-Spezifikation, aus dem Code generiert und versioniert im Repo |
| Identität (Account) | Pro Nutzer eine UUIDv4 (`accountId`), client-generiert, dient als Bearer-Token |
| Identität (Gerät) | Pro Installation zusätzlich eine UUIDv4 (`clientId`), **nicht** übertragbar, nur Konflikt-Tiebreaker |
| Übertragung auf Zweitgerät | QR-Code-Anzeige in den Einstellungen + Klartext-Code als Fallback (Copy/Paste) |
| Entitäts-IDs | App wird von Autoincrement-Int auf client-generierte UUIDs (Primärschlüssel) umgestellt |
| Konfliktauflösung | Last-Write-Wins über `(updatedAt, clientId)` als totale Ordnung |
| Sync-Modus | Pull/Push per Polling (kein Realtime/WebSocket in v1) |
| Backups | Selbst betrieben, per `bin/backup.sh` (pg_dump); kein Managed-Backup vorausgesetzt |
| Hosting | Offen – Plan bleibt Infrastruktur-agnostisch, Docker-basiertes Dev-Setup als Basis |

> Hinweis zur Symfony-Version: 8.1 ist ein Standard-Release mit kurzem Support-Fenster (EOL 01/2027). Vor diesem Datum muss auf 8.2/8.3 aktualisiert werden; alternativ ist 7.4 LTS (Support bis 11/2028) eine bewusste Ausweichoption, falls der Wartungsaufwand zu hoch wird.

## 3. Datenmodell

Serverseitige Entitäten spiegeln das bestehende Drift-Schema (`app/lib/data/database/tables.dart`), erweitert um Sync-relevante Felder.

Gemeinsame Sync-Felder auf allen Nutzdaten-Tabellen:

- `serverVersion` (bigint, NOT NULL) – Wert des Nutzer-Zählers beim letzten akzeptierten Schreiben, siehe Abschnitt 5.
- `lastClientId` (UUID, NOT NULL) – `clientId` des Geräts, dessen Schreibvorgang zuletzt gewonnen hat. Nur für den Tiebreaker.

### User

- `id` (UUID, = die App-UUID / `accountId`, Primärschlüssel, kein Login)
- `versionCounter` (bigint, NOT NULL, Default 0) – **monotoner Sync-Zähler dieses Nutzers.** Lebt bewusst auf dem User-Datensatz und nicht in einer globalen Sequence, damit der Cursor pro Nutzer dicht und klein bleibt und `DELETE /v1/me` ihn mit zurücksetzt.
- `createdAt`
- `lastSeenAt` (für Aufräum-/Inaktivitäts-Jobs)

Keine weiteren Felder. Der User-Datensatz existiert nur als Anker für die Zuordnung von Listen/Artikeln und wird implizit beim ersten Request angelegt („Upsert on first contact", siehe Missbrauchsschutz in Abschnitt 6).

### ShoppingList

- `id` (UUID, client-generiert)
- `userId` (FK → User, ON DELETE CASCADE)
- `name`, `color`, `sortOrder`
- `createdAt`, `updatedAt`, `deletedAt` (nullable, Tombstone für Soft-Delete)
- `serverVersion`, `lastClientId`

Index: `(userId, serverVersion)`.

`color` ist **bigint**, nicht int. Die App liefert `Color.toARGB32()`, also einen vorzeichenlosen 32-Bit-Wert; jede opake Farbe liegt wegen des Alpha-Bytes oberhalb von `INT32_MAX` und passt nicht in ein signiertes `INT`.

### ShoppingItem

- `id` (UUID, client-generiert)
- `userId` (FK → User, ON DELETE CASCADE) – **denormalisiert.** Nicht in der App vorhanden, aber serverseitig nötig, damit der Pull ohne Join über Listen läuft.
- `listId` (FK → ShoppingList, ON DELETE CASCADE)
- `name`, `quantity`, `unit`, `isChecked`, `note`, `sortOrder`
- `createdAt`, `updatedAt`, `deletedAt`, `movedAt`
- `serverVersion`, `lastClientId`

Index: `(userId, serverVersion)`.

Damit das Backend ohne Migrationssorgen auskommt, spiegelt es die App-Felder 1:1 statt eigener Business-Logik – das Backend ist reiner Sync-Speicher, keine zusätzliche Fachlogik (keine Validierung von Einheiten, keine Mengenberechnung etc.).

### Feld-Constraints

Es gibt keine Fachlogik, aber harte Obergrenzen als Missbrauchsschutz. Sie spiegeln die Limits der App:

| Feld | Constraint |
|---|---|
| alle `id`, `listId`, `clientId` | gültiges UUID-Format |
| `ShoppingList.name` | 1–100 Zeichen |
| `ShoppingList.color` | uint32 (0 – 4294967295), siehe oben |
| `ShoppingItem.name` | 1–200 Zeichen |
| `ShoppingItem.unit` | ≤ 20 Zeichen, nullable |
| `ShoppingItem.note` | ≤ 500 Zeichen, nullable |
| `ShoppingItem.quantity` | double ≥ 0, nullable |
| `sortOrder` | int32 |
| alle Zeitstempel | ISO-8601 mit Millisekunden in UTC, z. B. `2026-08-21T14:03:11.482Z` |

Zeitstempel werden serverseitig als `TIMESTAMPTZ` gespeichert und mit Millisekunden-Präzision verglichen.

## 4. Notwendige Änderungen an der App (Voraussetzung)

Aktuell nutzt Drift `IntColumn get id => integer().autoIncrement()()`. Für konfliktfreien Sync über mehrere Geräte ist vor dem produktiven Rollout umzustellen:

- **UUID-Primärschlüssel:** Spalte `id` als `text()` mit UUIDv4, generiert beim Anlegen eines Datensatzes (`uuid`-Package). `ShoppingItems.listId` referenziert dann die UUID der Liste statt eines int.
- **Millisekunden-Präzision für Zeitstempel:** Drift speichert `DateTimeColumn` per Default als Unix-**Sekunden**. Das erzeugt unnötig viele LWW-Gleichstände. Umstellen auf `storeDateTimeAsText: true` (ISO-8601-Text, behält Millisekunden).
- **`clientId`:** Beim ersten Start eine UUIDv4 pro Installation erzeugen und persistent ablegen. Sie ist **nicht** Teil des QR-Codes – beim Koppeln übernimmt das Zweitgerät nur die `accountId`, behält aber seine eigene `clientId`. Zwei Geräte mit identischer `clientId` würden den Tiebreaker aushebeln.
- **`lastSyncCursor`:** Persistente Ablage des zuletzt gesehenen `serverCursor` (bigint, initial 0).
- Da es noch keine produktiven Nutzer mit Bestandsdaten gibt, ist ein sauberer Schnitt (neue Drift-Migration mit Neuanlage der Tabellen) empfohlen statt einer Backfill-Migration.
- Diese Änderungen sind unabhängig vom Backend und sollten zuerst umgesetzt werden.

## 5. Sync-Mechanismus

Ein einzelner kombinierter Endpunkt, der Push (lokale Änderungen hochladen) und Pull (fremde Änderungen abholen) in einem Request erledigt – analog zu bekannten Offline-First-Sync-Patterns (z. B. CouchDB/PouchDB).

Statt Wall-Clock-Timestamps als Sync-Cursor verwendet das Backend den monotonen Zähler `User.versionCounter`. Das vermeidet Bugs durch abweichende Geräte-Uhrzeiten. `updatedAt`/`createdAt` sind Anzeige-Felder aus der App – und zusätzlich Eingangsgröße der Konfliktauflösung (siehe 5.3).

### 5.1 Endpoint: `POST /v1/sync`

Request:

```json
{
  "since": 1042,
  "clientId": "6f1c…",
  "limit": 500,
  "changes": {
    "lists": [ { "id": "…", "name": "…", "color": 4283, "sortOrder": 0,
                 "createdAt": "…", "updatedAt": "…", "deletedAt": null } ],
    "items": [ { "id": "…", "listId": "…", "name": "…", "quantity": 1.5, "unit": "kg",
                 "isChecked": false, "note": null, "sortOrder": 3,
                 "createdAt": "…", "updatedAt": "…", "deletedAt": null, "movedAt": null } ]
  }
}
```

Response:

```json
{
  "serverCursor": 1080,
  "hasMore": false,
  "fullResync": false,
  "changes": {
    "lists": [ … ],
    "items": [ … ]
  },
  "pushResults": {
    "lists": [ { "id": "…", "accepted": true } ],
    "items": [ { "id": "…", "accepted": false, "reason": "stale" } ]
  }
}
```

`reason` ist nur bei `accepted: false` gesetzt: `stale` (LWW verloren), `unknown_list` (kein `listId`-Treffer beim selben Nutzer), `invalid` (Constraint-Verletzung, siehe 3), `quota_exceeded`.

### 5.2 Ablauf serverseitig

Alles in **einer** DB-Transaktion:

1. `SELECT … FROM app_user WHERE id = :accountId FOR UPDATE` – die Zeilensperre serialisiert gleichzeitige Syncs desselben Nutzers. Ohne sie vergeben parallele Requests denselben `versionCounter`-Wert doppelt, wodurch Zeilen beim nächsten Pull übersprungen werden.
2. `since` auf `[0, versionCounter]` clampen. Lag der Wert darüber (Client-Cursor aus einer gelöschten/wiederhergestellten DB), wird `since = 0` gesetzt und `fullResync: true` in der Response signalisiert.
3. **Push, Listen vor Items.** Diese Reihenfolge ist zwingend, damit ein Item auf eine im selben Request neu angelegte Liste verweisen darf. Pro Zeile Konfliktauflösung nach 5.3. Jede akzeptierte Zeile: `versionCounter++`, der neue Wert wird zu `serverVersion` der Zeile, `lastClientId` wird auf die `clientId` des Requests gesetzt.
   - Items werden immer scoped auf `userId` aufgelöst; eine `listId`, die zu keiner Liste **dieses** Nutzers gehört, führt zu `unknown_list`. Damit ist das Schreiben in fremde Listen strukturell ausgeschlossen.
   - `ShoppingItem.userId` wird serverseitig gesetzt, nie aus dem Request übernommen.
4. **Pull.** Alle Zeilen mit `serverVersion > since`, nach `serverVersion` aufsteigend über beide Tabellen gemischt, auf `limit` gekürzt.
5. **Push-Zeilen immer mitliefern.** Zusätzlich zum Pull-Ergebnis wird für **jede** Zeile aus dem Push ihr autoritativer Serverstand in `changes` aufgenommen (dedupliziert).
   Das ist nicht redundant, sondern behebt einen sonst dauerhaften Divergenzfehler: Verliert eine gepushte Zeile den LWW-Vergleich, behält sie ihre alte `serverVersion`. Ist diese ≤ `since`, käme die Zeile im normalen Pull nicht vor – der Client hielte seinen abweichenden Stand für synchronisiert und würde ihn nie korrigieren.
6. `serverCursor` = `serverVersion` der letzten ausgelieferten Zeile, sonst `versionCounter`. `hasMore: true`, wenn gekürzt wurde – der Client synct dann sofort erneut mit dem neuen `since`.
7. `lastSeenAt` aktualisieren, Transaktion committen.

### 5.3 Konfliktauflösung (Last-Write-Wins mit Tiebreaker)

Vergleich der eingehenden Zeile mit dem Serverstand derselben `id`:

1. Zeile serverseitig unbekannt → **akzeptieren** (Insert).
2. `incoming.updatedAt > server.updatedAt` → **akzeptieren**.
3. `incoming.updatedAt < server.updatedAt` → **ablehnen** (`stale`).
4. Gleichstand und `incoming.clientId > server.lastClientId` (lexikografischer Byte-Vergleich der UUID-Strings) → **akzeptieren**.
5. Gleichstand und `incoming.clientId < server.lastClientId` → **ablehnen** (`stale`).
6. Gleichstand und identische `clientId` → **No-Op**: kein Schreibvorgang, kein neuer `serverVersion`, aber `accepted: true`. Das macht wiederholte Pushes nach Netzwerk-Timeouts idempotent, statt bei jedem Retry den Zähler hochzutreiben.

`(updatedAt, clientId)` ist damit eine totale Ordnung: das Ergebnis hängt nicht von der Verarbeitungsreihenfolge ab, und alle Geräte konvergieren unabhängig von der Sync-Reihenfolge auf denselben Zustand.

**Uhren-Schutz:** Vor dem Vergleich wird `updatedAt` auf `min(updatedAt, now + 5 min)` geclampt und in geclampter Form gespeichert und zurückgegeben. Ohne das gewinnt ein Gerät mit falsch gestellter Uhr dauerhaft jeden Konflikt und lässt sich nicht mehr korrigieren. Die Clamping-Toleranz deckt normale NTP-Abweichungen ab.

**Löschungen** sind normale Zeilen mit gesetztem `deletedAt` (Tombstones) und durchlaufen dieselbe LWW-Regel – Delete-vs-Update gewinnt also der spätere Zeitstempel, nicht pauschal das Delete. Ein separater Delete-Endpoint ist nicht nötig.

### 5.4 Grenzen und Client-Verhalten

- **Push:** max. 500 Zeilen (Lists + Items zusammen) pro Request, Request-Body max. 1 MiB. Darüber `413`. Der Client splittet größere Änderungsmengen selbst.
- **Pull:** `limit` default 500, max 1000. Bei `hasMore: true` sofort erneut syncen.
- **Anwendungsreihenfolge im Client:** Zeilen aus `changes` in Cursor-Reihenfolge anwenden und lokal bedingungslos überschreiben (der Serverstand ist autoritativ). Da eine Liste immer eine kleinere `serverVersion` hat als das Item, das sie erstmals referenziert, kann eine Seitengrenze kein Item vor seiner Liste ausliefern. Der Client sollte trotzdem tolerant sein und ein Item mit unbekannter `listId` verwerfen statt abzustürzen.
- **Echo:** Die eigenen gepushten Zeilen kommen in `changes` zurück. Der Client darf damit keine lokalen Änderungen überschreiben, die *nach* dem Absenden des Requests entstanden sind – Vergleich also gegen die Version, die gesendet wurde, nicht gegen den aktuellen lokalen Stand.
- **`fullResync: true`:** Der Client verwirft nach vollständigem Durchlauf (bis `hasMore: false`) alle lokalen Zeilen, die nicht in der gelieferten Menge vorkommen, und setzt `lastSyncCursor` neu.
- **`accepted: false` mit `reason: "stale"`** ist kein Fehler – der Client übernimmt einfach den Serverstand aus `changes` und leert seinen Outbox-Eintrag.

**Trigger für Sync in der App:** beim App-Start, bei Pull-to-Refresh, nach lokalen Änderungen mit kurzem Debounce, sowie optional per Timer alle X Minuten im Vordergrund.

**Tombstone-Aufräumen:** Ein Wartungs-Job kann Tombstones nach einer Karenzzeit (z. B. 90 Tage) endgültig löschen. Für v1 zurückgestellt, da das Datenvolumen pro Nutzer klein ist. Vorsicht: Ein Gerät, das länger als die Karenzzeit offline war, sieht die Löschung dann nicht mehr – das ist der Grund, die Karenzzeit großzügig zu wählen.

## 6. Authentifizierung & Sicherheit

- Die `accountId` wird als `Authorization: Bearer <uuid>` gesendet. Es gibt kein Passwort, keinen Refresh-Token – die UUID **ist** das Secret. Die `clientId` ist kein Secret und dient ausschließlich als Tiebreaker.
- Ein Custom Symfony Security-Authenticator liest den Bearer-Token, validiert das UUID-Format und legt bei erstem Kontakt automatisch einen `User`-Datensatz an (kein Registrierungs-Endpoint nötig).
- Da der Besitz der UUID vollen Zugriff gewährt, ist Transportsicherheit Pflicht: nur HTTPS, HSTS.
- Kein Logging von UUIDs im Klartext in Applikationslogs (nur gehasht, falls überhaupt), um das Risiko bei Log-Leaks zu minimieren.

### Missbrauchsschutz

Die implizite User-Anlage bedeutet, dass jeder Request mit beliebiger valider UUID eine Zeile erzeugt. Ohne Gegenmaßnahmen lässt sich die Datenbank damit trivial fluten:

- **Rate Limiting** (Symfony RateLimiter): 60 Sync-Requests/Minute pro `accountId`; zusätzlich pro IP eine Obergrenze für die Anlage *neuer* `accountId`s (z. B. 20/Stunde).
- **User-Anlage nur bei erfolgreichem Sync**, nicht bereits bei Health-Checks oder verworfenen Requests.
- **Quota pro Nutzer:** max. 200 Listen und 5000 Items (Tombstones eingerechnet). Überschreitung → betroffene Push-Zeilen mit `reason: "quota_exceeded"` ablehnen, bestehende Daten bleiben synchronisierbar.
- **Inaktivitäts-Cleanup:** Nutzer ohne Daten und mit `lastSeenAt` älter als 30 Tage können gelöscht werden.

### DSGVO

Die UUID + synchronisierte Listendaten sind einer Person zuordenbar und gelten datenschutzrechtlich als personenbezogene Daten, auch ohne Name/E-Mail. Konsequenzen:

- Endpoint `DELETE /v1/me` zum vollständigen Löschen aller Daten eines Nutzers (Recht auf Löschung), auslösbar über einen „Konto zurücksetzen"-Button in den App-Einstellungen. Löscht die User-Zeile hart, per `ON DELETE CASCADE` samt Listen und Items. Antwort `204`. Der nächste Request derselben UUID legt den Nutzer mit `versionCounter = 0` neu an – **der Client muss `lastSyncCursor` gleichzeitig lokal auf 0 zurücksetzen**, sonst greift beim nächsten Sync der `fullResync`-Pfad aus 5.2.
- Auftragsverarbeitungsvertrag (AVV) mit dem Hosting-Anbieter, sobald dieser feststeht.
- Datenschutzerklärung der App um den Hinweis auf die Synchronisierung ergänzen.
- Backup-Dumps enthalten personenbezogene Daten: verschlüsselt ablegen, Aufbewahrungsfrist begrenzen (siehe Abschnitt 9).

## 7. API-Design & OpenAPI

- Symfony API-only Skeleton (`symfony new . --version=8.1 --no-git`), Doctrine ORM + Migrations, PostgreSQL.
- Handgeschriebene Controller (kein API Platform), da der zentrale Sync-Endpoint kein klassisches CRUD-pro-Ressource-Muster ist. Für die wenigen zusätzlichen Endpunkte (Health-Check, Account-Löschung) reicht das ebenfalls.
- OpenAPI-Beschreibung über `nelmio/api-doc-bundle` mit PHP-Attributen an den Controllern, daraus generiertes `openapi.yaml` wird im Repo versioniert und in CI gegen Drift geprüft (Spec neu generieren, Diff gegen committete Datei muss leer sein).
- Versionierung über URL-Präfix (`/v1/...`), damit spätere Breaking Changes (`/v2/...`) parallel möglich sind.

### Endpunkte v1

| Methode | Pfad | Auth | Zweck |
|---|---|---|---|
| `POST` | `/v1/sync` | ja | Push + Pull, siehe Abschnitt 5 |
| `DELETE` | `/v1/me` | ja | Alle Daten des Nutzers löschen |
| `GET` | `/v1/health` | nein | Health-Check (App-Status + DB-Erreichbarkeit) |

### Fehlerformat

Alle Fehler als RFC 9457 Problem Details (`application/problem+json`):

```json
{ "type": "https://spiski.app/problems/rate-limited", "title": "Too Many Requests",
  "status": 429, "detail": "Sync-Limit erreicht." }
```

| Status | Fall |
|---|---|
| `400` | JSON kaputt oder Pflichtfelder (`since`, `clientId`) fehlen |
| `401` | Kein Bearer-Token oder kein gültiges UUID-Format |
| `413` | Body > 1 MiB oder > 500 Push-Zeilen |
| `422` | Request strukturell gültig, aber `since`/`limit` außerhalb des zulässigen Bereichs |
| `429` | Rate Limit, mit `Retry-After`-Header |
| `500` | Unerwarteter Fehler, ohne Details im Body |

Constraint-Verletzungen **einzelner Zeilen** sind kein Request-Fehler, sondern erscheinen als `accepted: false` mit `reason: "invalid"` in `pushResults` – ein defekter Datensatz soll nicht den gesamten Sync eines Geräts blockieren.

## 8. Projektstruktur

Repo-Wurzel ist `api/` (Schwester-Repo zu `app/`); es gibt kein zusätzliches `backend/`-Unterverzeichnis.

```
api/
  bin/
    backup.sh                // pg_dump-Wrapper, siehe Abschnitt 9
    console
  config/
  migrations/
  docker/
    caddy/Caddyfile
    php/php.dev.ini, php.prod.ini
  src/
    Controller/
      SyncController.php
      AccountController.php
      HealthController.php
    Doctrine/
      DateTimeMsType.php       // TIMESTAMPTZ mit Millisekunden statt TIMESTAMP(0)
    Entity/
      User.php
      ShoppingList.php
      ShoppingItem.php
    EventListener/
      ApiExceptionListener.php // Ausnahmen -> Problem Details
    Exception/
      ApiProblemException.php
    Http/
      ProblemResponseFactory.php
    Repository/
    Security/
      UuidBearerAuthenticator.php
      UserProvider.php
    Sync/
      SyncService.php        // Transaktion, Zähler-Vergabe, Push/Pull-Orchestrierung
      ConflictResolver.php     // LWW inkl. clientId-Tiebreaker
      ClockSkewGuard.php       // Kappen von updatedAt-Werten aus der Zukunft
      SyncRequestParser.php    // Parsing + Feld-Constraints
      SyncResponseSerializer.php
      SyncLimits.php           // alle harten Grenzen an einer Stelle
      Dto/
  tests/
    Functional/              // API-Tests gegen echte PostgreSQL-Instanz
    Unit/                      // ConflictResolver, ClockSkewGuard
  .github/workflows/ci.yml
  openapi.yaml                 // generiert, versioniert, in CI gegen Drift geprüft
  compose.yaml               // php-fpm, caddy, postgres, postgres_test
  Dockerfile                   // Stages: base, dev, prod
  Makefile                     // make check = stan + test + openapi-check
```

Betriebshinweise stehen in `README.md`.

**Fallstrick, der zweimal zugeschlagen hat:** Weder `APP_ENV` noch `DATABASE_URL` dürfen im `php`-Service der `compose.yaml` oder als `ENV` im dev-Stage des Dockerfiles gesetzt werden. Echte Umgebungsvariablen haben in Symfony Vorrang vor `.env`-Dateien, und `KernelTestCase` liest zusätzlich `$_ENV` vor `$_SERVER`. Gesetzt führen sie dazu, dass der Testlauf im dev-Environment bootet und die **Entwicklungsdatenbank** truncatet.

## 9. Deployment, Tests, Backups (v1)

### Lokale Entwicklung

Docker Compose: PHP-FPM 8.5, Caddy, PostgreSQL 18. Zweiter Postgres-Service (bzw. separate Datenbank) für Tests.

### Tests

Functional-Tests laufen gegen PostgreSQL, nicht gegen SQLite. Begründung: Die kritische Logik (`SELECT … FOR UPDATE`, bigint-Zähler, Upserts, `ON DELETE CASCADE`) verhält sich in SQLite abweichend – ausgerechnet dort, wo Fehler am teuersten sind. Pflicht-Szenarien:

- Gleicher Artikel auf zwei Geräten geändert (beide Reihenfolgen → identisches Ergebnis)
- Gleichstand bei `updatedAt` → `clientId`-Tiebreaker entscheidet deterministisch
- Löschung vs. Änderung in beiden Richtungen
- Abgelehnter Push wird trotzdem mit Serverstand zurückgeliefert (Regression zu 5.2 Schritt 5)
- Wiederholter identischer Push erzeugt keinen neuen `serverVersion`
- Zwei gleichzeitige Syncs desselben Nutzers (Nebenläufigkeit des Zählers)
- `since` > `versionCounter` → `fullResync`
- Pagination: `hasMore`-Kette liefert am Ende exakt den Gesamtbestand
- Item mit fremder/unbekannter `listId` → `unknown_list`, kein Schreibzugriff
- `updatedAt` weit in der Zukunft → geclampt

### Hosting

Konkretes Ziel noch offen; das Setup bleibt so, dass es auf einem einfachen VPS (Docker) genauso läuft wie auf einer Managed-Plattform (Fly.io, Hetzner, Platform.sh) – keine anbieter-spezifischen Abhängigkeiten in v1.

### CI (GitHub Actions o. ä.)

Tests gegen einen PostgreSQL-18-Service, PHPStan Level 6, OpenAPI-Diff-Check.

### Backups

Backups werden selbst betrieben, nicht vom Hoster erwartet. `bin/backup.sh` erzeugt einen `pg_dump` im Custom-Format (komprimiert, mit `pg_restore` selektiv wiederherstellbar), rotiert alte Dumps und schreibt eine Prüfsumme.

```
bin/backup.sh                      # Dump nach ./backups
BACKUP_DIR=/srv/backups bin/backup.sh
RETENTION_DAYS=30 bin/backup.sh
```

Betrieblich noch zu klären: Cron-Eintrag auf dem Zielsystem, Ablage außerhalb des Servers, Verschlüsselung der Dumps (personenbezogene Daten, siehe Abschnitt 6) und – am wichtigsten – ein regelmäßiger **Restore-Test**. Ein nie zurückgespieltes Backup ist kein Backup.

## 10. Phasenplan

1. **App-Vorbereitung:** UUID-Primärschlüssel, `storeDateTimeAsText`, `clientId` und `lastSyncCursor` in der Flutter-App (Voraussetzung für alles Weitere, siehe Abschnitt 4).
2. **Backend-Grundgerüst:** Symfony-Projekt, Doctrine-Entities inkl. `versionCounter`/`lastClientId`, Migrations, UUID-Bearer-Auth, `/v1/health`, Docker-Setup.
3. **Sync-Endpoint:** `POST /v1/sync` inkl. Zähler-Vergabe unter Zeilensperre, LWW mit Tiebreaker, Rückgabe abgelehnter Pushes, Pagination. Functional-Tests nach der Liste in Abschnitt 9.
4. **Härtung:** Rate Limiting, Quotas, Problem-Details-Fehlerformat, Clock-Clamping.
5. **OpenAPI:** Vollständige Spec-Generierung, Review, Veröffentlichung im Repo; darauf aufbauend Client-Code (Dart) ggf. generieren statt handschreiben.
6. **App-Integration:** Sync-Client in Flutter (Trigger-Punkte, Outbox für ausstehende Änderungen, `hasMore`-Schleife, `fullResync`-Behandlung, Echo-Regel aus 5.4).
7. **QR-/Code-Übertragung:** UI zum Anzeigen und Scannen der `accountId` zur Kopplung eines Zweitgeräts (`clientId` bleibt gerätespezifisch).
8. **Account-Löschung & Datenschutz:** `DELETE /v1/me` inkl. lokalem Cursor-Reset, Datenschutzerklärung anpassen.
9. **Hosting-Entscheidung & Rollout:** Infrastruktur festlegen, Deployment, Backup-Cron + Restore-Test, Monitoring/Basic-Alerting.

## 11. Offene Punkte für später

- Soll die `accountId` zusätzlich lokal gesichert werden (z. B. iCloud/Google-Backup des Schlüssels), damit ein Geräteverlust ohne vorherige Kopplung nicht zum vollständigen Datenverlust führt?
- Soll es einen Hinweis/Export-Mechanismus geben, falls ein Nutzer die App deinstalliert und die `accountId` dadurch verliert?
- `sortOrder` unter LWW: Sortieren zwei Geräte gleichzeitig um, entstehen doppelte oder springende Positionen. Für v1 bewusst akzeptiert; eine Fractional-Index- oder CRDT-Lösung wäre die Alternative, falls es in der Praxis stört.
- Der QR-Code enthält das Vollzugriffs-Secret ohne Ablauf und ohne Bestätigungsschritt am Erstgerät. Für v1 vertretbar, weil er nur lokal und kurz angezeigt wird; ein Kopplungscode mit Ablaufzeit wäre die Härtung.
- Aufräum-Job für alte Tombstones (Zeitpunkt/Kriterium) – kann in v1 zurückgestellt werden.
- Upgrade-Pfad Symfony 8.1 → 8.2/8.3 vor 01/2027 einplanen (siehe Abschnitt 2).
