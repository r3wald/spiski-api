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
- Die Admin-Seite (`GET /admin`, serverseitig gerendertes HTML, und `GET /v1/admin/lists`, JSON) nutzt ein eigenes Token (`ADMIN_TOKEN`, ≥ 32 Zeichen; leer = abgeschaltet) im `Authorization: Bearer`-Header **bei jedem Request**: kein Formular, keine Session, kein Cookie, kein JavaScript, ein Token in der URL wird nicht akzeptiert. Eigene Firewall, IP-Limit 30/Minute. Sie liefert **nie** Account-UUIDs (das Zugangs-Secret der Nutzer), nur ein gehashtes Kürzel. Gezeigt werden Listen und ihre Einträge (archivierte nur auf Wunsch) als Nutzerinhalt sowie je Liste, ob sie geteilt ist und wie viele Konten (Besitzer + Mitglieder) sie sehen dürfen – nie der Teilen-Code, nur ob er aktiv ist.
- **Einträge und Listen löschen von der Admin-Seite** (`App\Admin\AdminDeleter`): zweistufig, ohne JavaScript – `GET /admin/items/{id}/delete` bzw. `/admin/lists/{id}/delete` zeigt die Bestätigung, erst der `POST` von dort löscht. Eine Liste bekommt wie in der App nur selbst den Tombstone (Artikel, Mitgliedschaften und Teilen-Code bleiben; der Besitzer kann sie aus dem Archiv der App wiederherstellen). Gelöscht wird als **Tombstone** wie in der App, nie hart (eine hart gelöschte Zeile legte der nächste Push wieder an): exklusive `SyncLock`, Kontozeilen der Zielgruppe sperren, neue Version in jedem Konto der Liste (`VersionPublisher`), `updated_at = deleted_at` strikt nach dem bisherigen Stand, `last_client_id` = `ffffffff-…` (gewinnt Gleichstände), bei Artikeln `last_user_id = NULL` (kein `changedByOther`), Live-Update nach dem Commit. **CSRF:** Header-Erweiterungen hängen das Admin-Token auch an Formulare fremder Seiten. Deshalb braucht der `POST` ein Bestätigungstoken von der Bestätigungsseite (`App\Admin\AdminConfirmation`: HMAC über Aktion, Ziel und Ablauf mit `APP_SECRET`, 1 h gültig) und wird bei `Sec-Fetch-Site` ≠ `same-origin` abgelehnt.
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
- Datenschutzerklärung unter `/datenschutz/` (`public/datenschutz/index.html`). Bei Änderungen an gespeicherten Feldern, Logs, Cookies, Fristen oder Drittanbietern mitpflegen.
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
| `POST` | `/v1/lists/{id}/share` | ja | Teilen-Code abrufen/anlegen (nur Besitzer), siehe Abschnitt 12 |
| `POST` | `/v1/lists/{id}/share/renew` | ja | Neuer Teilen-Code, alter ungültig (nur Besitzer) |
| `DELETE` | `/v1/lists/{id}/share` | ja | Teilen beenden, alle Mitglieder verlieren den Zugriff |
| `DELETE` | `/v1/lists/{id}/membership` | ja | Geteilte Liste verlassen |
| `POST` | `/v1/shares/{code}` | ja | Einer geteilten Liste beitreten (verschlüsselt: Body `{"wrappedKey"}`) |
| `GET` | `/v1/me/encryption` | ja | Verschlüsselungszustand und `keyCheck`, siehe Abschnitt 14 |
| `POST` | `/v1/me/encryption/begin` | ja | Umstellung beginnen (löst alle Teilen-Beziehungen) |
| `POST` | `/v1/me/encryption/commit` | ja | Umstellung abschließen |
| `GET` | `/v1/health` | nein | Health-Check (App-Status + DB-Erreichbarkeit) |
| `GET` | `/status.json`, `/status.html` | nein | Statusseite: API-Version, Revision, DB, Schema, Summen über alle Konten. IP-Limit 60/Minute. Nicht in der OpenAPI-Spezifikation |
| `GET` | `/v1/admin/lists` | Admin-Token | Alle Listen aller Accounts als JSON. Nicht in der OpenAPI-Spezifikation |
| `GET` | `/admin` | Admin-Token | Dieselben Daten als HTML-Seite. Nicht in der OpenAPI-Spezifikation |
| `GET`, `POST` | `/admin/items/{id}/delete` | Admin-Token (+ Bestätigungstoken) | Eintrag löschen: GET zeigt die Bestätigung, POST archiviert. Nicht in der OpenAPI-Spezifikation |
| `GET`, `POST` | `/admin/lists/{id}/delete` | Admin-Token (+ Bestätigungstoken) | Liste löschen, ebenso. Nicht in der OpenAPI-Spezifikation |
| `GET` | `/datenschutz/` | nein | Datenschutzerklärung, statisch (`public/datenschutz/index.html`), von FrankenPHP ohne PHP ausgeliefert. Nicht in der OpenAPI-Spezifikation |

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
| `409` | Verschlüsselung: `migration-in-progress`, `already-encrypted`, `encryption-incomplete`, `encryption-required` (Abschnitt 14) |
| `413` | Body > 1 MiB oder > 500 Push-Zeilen |
| `422` | Request strukturell gültig, aber `since`/`limit` außerhalb des zulässigen Bereichs |
| `426` | `upgrade-required`: alte App (ohne `X-Spiski-Protocol: 2`) bei verschlüsseltem Konto oder nach der Frist (Abschnitt 14) |
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
    frankenphp/Caddyfile, Caddyfile.prod   // Webserver + Mercure-Hub
    php/php.dev.ini, php.prod.ini
  src/
    Admin/
      AdminListReader.php      // Lese-Abfrage für die Admin-Seite (DBAL)
      AdminPageRenderer.php    // HTML der Admin-Seite, ohne JavaScript
      AdminPageLimits.php
      AdminDeleter.php         // Eintrag/Liste löschen (Tombstone, Versionen für alle Teilnehmer)
      AdminConfirmation.php    // Bestätigungstoken gegen CSRF (HMAC, befristet)
    Command/
      PurgePlaintextCommand.php   // app:purge-plaintext, Frist-Job (Abschnitt 14)
    Controller/
      AdminController.php
      EncryptionController.php    // /v1/me/encryption[/begin|/commit]
      SyncController.php
      AccountController.php
      HealthController.php
      StatusController.php     // /status.json, /status.html (öffentlich)
    Doctrine/
      DateTimeMsType.php       // TIMESTAMPTZ mit Millisekunden statt TIMESTAMP(0)
    Entity/
      User.php
      ShoppingList.php
      ShoppingItem.php
    Encryption/                // Zustand, Frist, Protokoll-Header, Formatpruefung (Abschnitt 14)
    EventListener/
      ApiExceptionListener.php // Ausnahmen -> Problem Details
      ProtocolGuardListener.php // 426 fuer alte Apps (Abschnitt 14)
    Exception/
      ApiProblemException.php
    Http/
      ProblemResponseFactory.php
    Repository/
    Security/
      UuidBearerAuthenticator.php
      AdminTokenAuthenticator.php
      UserProvider.php
    Status/
      StatusReader.php         // Zustand: Version, DB, Migrationen, Summen – nie UUIDs oder Nutzerinhalt
      StatusPageRenderer.php   // HTML der Statusseite, ohne JavaScript
      BuildInfo.php            // liest build-info.json (Revision, Deployment-Zeit; schreiben update-*.sh)
    Sync/
      SyncService.php        // Transaktion, Zähler-Vergabe, Push/Pull-Orchestrierung
      ConflictResolver.php     // LWW inkl. clientId-Tiebreaker
      ClockSkewGuard.php       // Kappen von updatedAt-Werten aus der Zukunft
      SyncRequestParser.php    // Parsing + Feld-Constraints
      SyncResponseSerializer.php
      SyncLimits.php           // alle harten Grenzen an einer Stelle
      Dto/
  public/
    index.html, robots.txt     // statische Startseite, von FrankenPHP ohne PHP ausgeliefert
    datenschutz/index.html     // Datenschutzerklärung, ebenfalls statisch
  tests/
    Functional/              // API-Tests gegen echte PostgreSQL-Instanz
    Unit/                      // ConflictResolver, ClockSkewGuard
  .github/workflows/ci.yml
  openapi.yaml                 // generiert, versioniert, in CI gegen Drift geprüft
  compose.yaml               // php (FrankenPHP), postgres, postgres_test
  compose.prod.yaml          // Server (prod + staging): nur 127.0.0.1, TLS beim Nginx davor, Secrets in .env.prod/.env.staging
  update-prod.sh, update-staging.sh  // Deployment per rsync/ssh, je Server-Umgebung eins
  Dockerfile                   // Stages: base, dev, prod
  Makefile                     // make check = stan + test + openapi-check
```

Betriebshinweise stehen in `README.md`.

**Fallstrick, der zweimal zugeschlagen hat:** Weder `APP_ENV` noch `DATABASE_URL` dürfen im `php`-Service der `compose.yaml` oder als `ENV` im dev-Stage des Dockerfiles gesetzt werden. Echte Umgebungsvariablen haben in Symfony Vorrang vor `.env`-Dateien, und `KernelTestCase` liest zusätzlich `$_ENV` vor `$_SERVER`. Gesetzt führen sie dazu, dass der Testlauf im dev-Environment bootet und die **Entwicklungsdatenbank** truncatet.

## 9. Deployment, Tests, Backups (v1)

### Lokale Entwicklung

Docker Compose: FrankenPHP (PHP 8.5, Caddy und Mercure-Hub in einem Container), PostgreSQL 18. Zweiter Postgres-Service (bzw. separate Datenbank) für Tests.

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

### Umgebungen

Vier Umgebungen: **Entwicklung** (lokal, `compose.yaml`, `APP_ENV=dev`), **Test** (lokal, `postgres_test`, `APP_ENV=test`), **Staging** (Server, `~/spiski-dev`, `https://spiski-dev.r3wald.net` → `127.0.0.1:8082`, Compose-Projekt `spiski-staging`, `.env.staging`) und **Produktion** (Server, `~/spiski`, `https://spiski.r3wald.net` → `127.0.0.1:8081`, Compose-Projekt `spiski-prod`, `.env.prod`). Staging und Produktion teilen `compose.prod.yaml` und laufen beide mit `APP_ENV=prod`, aber mit getrennten Datenbanken und Secrets. Die Dev-App (Debug-Build, `net.r3wald.spiski.dev`) und die Integrationstests der App sprechen Staging an, Release-Builds die Produktion. Details: README, „Umgebungen“ und „Server“.

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

## 12. Geteilte Listen

Einzelne Listen lassen sich mit anderen Konten teilen. Das ist etwas anderes als das Koppeln eines Zweitgeräts (Abschnitt 6): Dort teilen sich Geräte **ein** Konto und sehen alles; hier sieht ein fremdes Konto **nur diese eine Liste**.

### Entscheidungen

| Thema | Entscheidung |
|---|---|
| Rechte | Mitglieder dürfen Artikel anlegen, ändern, abhaken, löschen. Die Liste selbst (Name, Farbe, Reihenfolge, Löschen) ändert nur der Besitzer; ein Listen-Push eines Mitglieds wird mit `reason: "forbidden"` abgelehnt, das Echo liefert den Serverstand |
| Teilen-Code | 24 Zeichen Crockford-Base32 (120 Bit, `shopping_list.share_code`), **unabhängig von der Account-UUID** – wer eine Liste teilt, gibt nicht den Zugang zu seinen anderen Listen preis. Unbefristet und mehrfach nutzbar, bis der Besitzer ihn erneuert oder das Teilen beendet. Beim Einlösen zählen Groß-/Kleinschreibung, Bindestriche, Leerzeichen und die Verwechslungen O/0, I/L/1 nicht |
| Link | `https://<host>/join/<code>`. Mit installierter App öffnet Android ihn direkt (App Link, `public/.well-known/assetlinks.json`); sonst liefert FrankenPHP eine statische Anleitung (`public/join.html`) |
| Kontingent | Zählt beim Besitzer: `shopping_item.user_id` ist immer der Besitzer der Liste, auch wenn ein Mitglied den Artikel angelegt hat |
| Grenzen | 20 Mitglieder je Liste, 100 Mitgliedschaften je Konto, 30 Beitrittsversuche pro Stunde und Konto |
| Konto löschen | Besitzer: Die Liste verschwindet auch bei den Mitgliedern. Mitglied: tritt nur aus |
| Artikel verschieben | Nur zwischen Listen mit denselben Teilnehmern (`invalid` sonst), damit niemand einen Artikel weiter sieht, der ihn nichts mehr angeht |

### Datenmodell

- `list_member (list_id, user_id, joined_at)` – Mitglieder ohne den Besitzer, beide FKs `ON DELETE CASCADE`.
- `sync_view (user_id, kind, row_id, version)` – **was ein Konto beim Pull sieht**, mit der Version aus *seinem* Zähler. `kind` ist `list`, `item` oder `revoked` (Liste, auf die das Konto keinen Zugriff mehr hat).

Der Pull liest nur noch `sync_view`. `shopping_list.server_version` und `shopping_item.server_version` tragen weiterhin die Version aus dem Zähler des Besitzers, werden aber für den Pull nicht mehr gebraucht.

### Warum eine Sicht je Konto statt eines globalen Zählers

Der Cursor des Clients muss lückenlos und monoton bleiben (Abschnitt 5.2). Ein globaler Zähler (Sequence) würde bei gleichzeitigen Transaktionen außer der Reihe committen: Ein Pull könnte eine kleinere Version überspringen, die erst später sichtbar wird. Deshalb bleibt jedes Konto bei seinem eigenen Zähler, und jede akzeptierte Änderung an einer geteilten Liste bekommt **in jedem betroffenen Konto** eine eigene Version (`App\Sync\VersionPublisher`). Cursor, Last-Write-Wins, Echo-Regel und `fullResync` bleiben dadurch unverändert.

### Sperren

Eine Änderung an einer geteilten Liste sperrt mehrere `app_user`-Zeilen. Damit sich zwei solche Transaktionen nicht verklemmen:

1. **Zuerst** eine transaktionsweite Advisory-Sperre (`App\Sync\SyncLock`): **exklusiv**, wenn der Request eine Liste berührt, die einem anderen Konto gehört oder Mitglieder hat, sowie bei jedem Beitritt, Austritt, Teilen-Beenden und `DELETE /v1/me`; sonst **geteilt**.
2. Danach die Kontozeilen, sortiert nach ID (`SELECT … FOR UPDATE`). Mit geteilter Sperre nur die eigene.

Mitgliedschaften ändern sich nur unter der exklusiven Sperre und sind für Inhaber der geteilten Sperre deshalb stabil. Der Normalfall – ein Konto synchronisiert eigene, nicht geteilte Listen – läuft weiter parallel. Wird eine Liste genau zwischen der Einordnung und dem Sperren geteilt, antwortet der Sync mit `503` und `Retry-After: 1`; der Client wiederholt ohnehin mit Backoff.

### Protokoll-Erweiterungen (abwärtskompatibel)

- Listen in `changes.lists` tragen zusätzlich `role` (`owner`/`member`), `memberCount` und `joinedAt` (Beitritt des abfragenden Kontos aus `list_member.joined_at`, `null` bei eigenen Listen). Sie werden nie gepusht.
- `revokedLists`: Listen-IDs, auf die das Konto keinen Zugriff mehr hat. Der Client löscht sie samt Artikeln, auch mit ausstehenden Änderungen.
- Neuer Ablehnungsgrund `forbidden`.
- Artikel in `changes.items` tragen `changedByOther`: `true`, wenn zuletzt ein anderes Konto den Artikel geändert hat (eigene Geräte zählen nicht). Grundlage ist `shopping_item.last_user_id`, das nie ausgeliefert wird. Die App benachrichtigt damit über Änderungen anderer. Wird nie gepusht.

Ältere Clients ignorieren die neuen Felder. `forbidden` kann nur ein Mitglied einer geteilten Liste erhalten, also nur ein Client, der Teilen kennt.

### Beitritt und Entzug

- **Beitritt:** Mitgliedschaft anlegen, dann die Liste für alle Teilnehmer neu versionieren (Mitgliederzahl) und jeden Artikel für das neue Mitglied – in dieser Reihenfolge, damit kein Artikel vor seiner Liste ankommt.
- **Entzug** (Austritt, Teilen beenden, Besitzer gelöscht): Die Einträge des Kontos für Liste und Artikel aus `sync_view` entfernen und einen `revoked`-Eintrag mit neuer Version anlegen. Ein erneuter Beitritt entfernt ihn wieder; ein Konto hat für eine Liste also nie gleichzeitig Zeilen und einen `revoked`-Eintrag.

### Offen

- Mitglieder können die Reihenfolge geteilter Listen nur lokal ändern; ändert der Besitzer die Liste, gilt wieder seine Position.
- `assetlinks.json` enthält für `net.r3wald.spiski` Upload-, Debug- und Play-App-Signaturschlüssel, für die Dev-App `net.r3wald.spiski.dev` den Debug-Schlüssel. Die Dev-App öffnet nur Links von `spiski-dev.r3wald.net` (Staging), Release-Builds nur `spiski.r3wald.net` (`app/docs/05-geteilte-listen.md`, „App Link“).
- Keine Liste der Mitglieder (wer genau mitliest) – es gibt keine Namen, nur Konten.

## 13. Live-Updates (Mercure)

Geöffnete Apps (Android und Web) erfahren Änderungen sofort statt erst beim nächsten Intervall-Sync. Der Mercure-Hub läuft in FrankenPHP (`mercure`-Block in `docker/frankenphp/Caddyfile*`), ohne eigenen Container und ohne Persistenz.

- **Nur ein Signal:** Über den Hub geht nie ein Inhalt, nur „es gibt Neues“ als `{"origin": <clientId|null>}`. Der Client synchronisiert daraufhin wie gewohnt; der Sync bleibt die einzige Datenquelle.
- **Wer bekommt etwas:** jedes Konto, dessen Zähler in der Transaktion gestiegen ist (`VersionPublisher::flush()` liefert sie), gesammelt in `App\Live\LiveUpdates` und **erst nach dem Commit** gesendet (`LiveUpdates::around()`). Schlägt das Senden fehl, wird nur geloggt; der Sync ist nie vom Hub abhängig.
- **`origin`:** die clientId des auslösenden Geräts, nur auf dem eigenen Konto (das Gerät ignoriert die Meldung). Andere Konten bekommen `null` und erfahren nie fremde clientIds.
- **Topic:** `urn:spiski:account:<HMAC-SHA256(Konto-ID, APP_SECRET)>`. Die Konto-ID ist das Secret und darf weder im Topic noch im Hub-Log stehen. Updates sind immer `private`.
- **Abo:** `GET /v1/live` liefert Hub (relativ), Topic und ein Token (1 h), das nur dieses Topic lesen darf. Android schickt es als `Authorization: Bearer`, der Browser per HttpOnly-Cookie `__Secure-mercure_access_token` (Pfad `/.well-known/mercure`), weil EventSource keine Header kann. Abonniert wird mit `?match=<topic>` (Mercure-Protokoll 1.0).
- **Token:** HS256 mit `MERCURE_JWT_SECRET` (Hub und Symfony lesen denselben Wert, in prod aus `.env.prod`). `iss` = `https://spiski.r3wald.net`, `aud` = `https://spiski.r3wald.net/.well-known/mercure`, im Caddyfile als `issuer` und `resource_identifier` fest eingetragen – feste Kennungen, damit dasselbe Token intern (`http://localhost`), lokal (Port 8081) und hinter dem Nginx gilt.
- **Logs:** Das Caddyfile filtert den Query-Parameter `authorization` aus den Access-Logs.
- **Tests:** `tests/Functional/LiveTest.php`; in der Testumgebung ersetzt `App\Tests\Support\RecordingHub` den Hub.
- **Nginx:** eigener `location /.well-known/mercure` ohne Pufferung und mit langem Timeout (README, „Nginx“).

## 14. Ende-zu-Ende-Verschlüsselung (Server umgesetzt)

Der Server soll von den Listen nur Chiffretext halten. Verschlüsselt wird auf dem Gerät. **Stand:** Server (Phase 2) und App (Phasen 3–5: Sync, Umstellung, Teilen) sind umgesetzt; offen sind der nicht exportierbare Web-Schlüssel und der Betrieb. Das vollständige Konzept (Schlüssel, Datenformat, Konto-Code, Migration, Teilen, Web, Nutzerhinweise, Tests) steht in [`app/docs/07-verschluesselung.md`](../app/docs/07-verschluesselung.md); hier die Folgen für den Server.

### Entscheidungen

| Thema | Entscheidung |
|---|---|
| Umfang | Verschlüsselt: Name (Liste, Artikel), Menge, Einheit, Notiz. Lesbar: `isChecked`, `color`, `sortOrder`, `deletedAt`, `movedAt`, Zeitstempel, IDs, `listId`, Besitz und Mitglieder |
| Schlüssel | Kontoschlüssel (256 Bit) und je Liste ein Listenschlüssel; der Server sieht nie einen Schlüssel, nur `wrappedKey` (Listenschlüssel, mit dem Kontoschlüssel verpackt) |
| Verfahren | AES-256-GCM, Format `v1.<nonce>.<chiffretext>` (`enc`), AAD an `listId`/`id` gebunden, Padding auf 32 Byte |
| Migration | Pro Konto, Zustand `plaintext` → `migrating` → `encrypted`; alte Apps (Protokoll 1, ohne Header `X-Spiski-Protocol`) bekommen ab `migrating` `426 upgrade-required` |
| Geteilte Listen | `begin` löst **ohne Rücksicht** alle Teilen-Beziehungen des Kontos: eigene Listen (Mitglieder entfernt, `share_code` gelöscht, `revokedLists`) und Mitgliedschaften in fremden Listen (Austritt) |
| Frist | Eine Woche (Produktion: bis 23.11.2026, 24:00 Uhr MEZ; Staging: sofort); danach `426` für alle Klartext-Clients und tägliches Löschen der Klartext-Daten nicht migrierter Konten |
| Lokale Datenbank | Bleibt unverschlüsselt (Klartext in SQLite); verschlüsselt wird nur an der Grenze zum Server |

### Datenmodell

- `app_user`: `encryption_state` (`plaintext`, `migrating`, `encrypted`; Standard `plaintext`), `encryption_changed_at`, `migration_client_id` (das Gerät, das die Migration führt), `key_check` (Prüfwert des Kontoschlüssels, vom Client geliefert).
- `shopping_list`: `enc` (Name), `wrapped_key` (Listenschlüssel des **Besitzers**), beide `NULL` für Klartext-Zeilen. `name` bleibt `NOT NULL` und trägt bei verschlüsselten Zeilen einen Platzhalter.
- `shopping_item`: `enc`; `name` (Platzhalter), `quantity`, `unit`, `note` sind bei verschlüsselten Zeilen leer.
- `list_member`: `wrapped_key` (Listenschlüssel des **Mitglieds**, mit dessen Kontoschlüssel verpackt).

### Protokoll

- **Protokoll per Header:** `X-Spiski-Protocol: 2` auf **jedem** Request (fehlt = Protokoll 1). Ein Body-Feld reicht nicht, weil Teilen, Live-Updates und Löschen keinen Body haben. Konten in `migrating`/`encrypted` werden nur mit Protokoll 2 bedient, sonst `426` (`upgrade-required`), für Push **und** Pull: Eine alte App würde sonst Platzhalter-Namen übernehmen und ihre lokalen Namen überschreiben. **Ausnahmen:** `DELETE /v1/me` (Recht auf Löschung) und `GET /v1/health`.
- `GET /v1/me/encryption` → `{state, keyCheck}`. `keyCheck` ist ein fester Text, mit dem Kontoschlüssel verschlüsselt; der Server speichert ihn bei `begin` und kann ihn nicht prüfen, nur ausliefern. Geräte erkennen daran einen falschen Konto-Code vor dem ersten Sync.
- Zeilen tragen `enc` statt `name`/`quantity`/`unit`/`note`; Listen zusätzlich `wrappedKey`. Der Server prüft nur `enc` (`^v1\.[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]+$`, höchstens **6144** Zeichen) und `wrappedKey` (höchstens **256**). Klartext-Felder von verschlüsselten Konten → `invalid`.
- `POST /v1/me/encryption/begin` mit `{clientId, keyCheck}` (idempotent für dasselbe Gerät; `409 migration-in-progress` für ein anderes, `409 already-encrypted` danach): setzt `migrating` und löst in **derselben Transaktion**, **vor** der ersten Umstellung einer Zeile, alle Teilen-Beziehungen des Kontos: eigene Listen wie `ShareService::stop`, fremde wie `leave` (vgl. `detachAccount`). Ohne das Austreten säße ein verschlüsseltes Konto in Klartext-Listen fremder Konten.
- In `migrating` ersetzt eine gepushte verschlüsselte Zeile eine **Klartext-Zeile unbedingt**, ohne Last-Write-Wins (gleicher `updatedAt` wäre sonst ein No-Op), und leert die Klartext-Spalten. Verschlüsselte Zeilen folgen wieder Last-Write-Wins. Der Pull liefert dem migrierenden Gerät noch nicht umgestellte Zeilen weiter im Klartext (Reste, die ein anderes Gerät kurz vor `begin` gepusht hat).
- In `migrating` darf nur das Gerät synchronisieren, das `begin` aufgerufen hat (`migration_client_id`); andere Geräte mit Protokoll 2 bekommen beim Sync `409 migration-in-progress`.
- `POST /v1/me/encryption/commit` mit `{clientId, keyCheck?}`: nur wenn **keine** Klartext-Zeile des Kontos mehr existiert, sonst `409 encryption-incomplete` mit der Zahl; setzt `encrypted`. Ein Konto ohne Zeilen (neu angelegt) darf direkt `commit` aufrufen (mit `keyCheck`).
- Beitritt (`POST /v1/shares/{code}`) einer verschlüsselten Liste braucht `{"wrappedKey"}` und ein verschlüsseltes Konto von Besitzer **und** Mitglied, sonst `409 encryption-required`. Der Teilen-Code bleibt serverseitig; der Listenschlüssel steht nur im Link-Fragment. `public/join.js` muss `location.hash` an den Verweis zur Web-App anhängen; der nackte Code reicht zum Beitritt nicht mehr.
- Kontingente, Last-Write-Wins, Echo, `fullResync`, Tombstones, Live-Updates bleiben unverändert. Der Push wird zusätzlich nach Bytes (ca. 900 KiB) geschnitten, weil 500 große `enc` mehr als 1 MiB ergeben können.

### Frist-Job

`SPISKI_ENCRYPTION_DEADLINE` (ISO-Datum; leer = keine Frist). Danach:

1. `426` für **alle** Requests mit Protokoll 1 (Ausnahmen wie oben). Ein `plaintext`-Konto mit Protokoll 2 bekommt beim Sync `409 encryption-required` und darf nur noch `begin`/`commit`.
2. Täglicher Befehl (z. B. `app:purge-plaintext`): für Konten mit `encryption_state = 'plaintext'` Listen, Artikel, Mitgliedschaften, Teilen-Codes **und `sync_view`-Zeilen** löschen; das Konto bleibt. **`version_counter` nicht zurücksetzen:** Sonst bekäme ein Gerät `fullResync` und entfernte seine lokalen, nicht als dirty markierten Zeilen. Ein Gerät mit neuer App kann danach weiter migrieren (der Server ist leer, lokal werden alle Zeilen dirty).
3. Danach die betroffenen Tabellen mit `VACUUM FULL` zurückschreiben (aktualisierte und gelöschte Zeilen halten Klartext in toten Tupeln; sperrt die Tabellen, also im Wartungsfenster); alte Backups laufen nach der Rotation (14 Tage) aus.

### Auswirkungen

- **Admin-Seite:** verschlüsselte Zeilen zeigen „🔒 verschlüsselt"; der Namensfilter sieht nur Klartext-Konten. Zusätzlich zeigt sie je Konto den Zustand (`plaintext`, `migrating`, `encrypted`), im JSON als `encryptionState` je Liste; so sieht man vor Ablauf der Frist, wer noch nicht migriert hat.
- **Statusseite:** Zähler je Konto-Zustand.
- Der Server kann Längen und Typen der verschlüsselten Felder nicht mehr prüfen (Validierung nur noch in der App).

### Umsetzung (Server)

| Teil | Wo |
|---|---|
| Zustand, Frist, Header, Formatprüfung | `App\Entity\EncryptionState`, `App\Encryption\EncryptionPolicy` (`SPISKI_ENCRYPTION_DEADLINE`), `ClientProtocol`, `CipherFormat` |
| `begin`/`commit`/Zustand | `App\Encryption\EncryptionService`, `App\Controller\EncryptionController`; exklusive `SyncLock`-Sperre + `FOR UPDATE` auf die Kontozeile |
| `426` am Eingang | `App\EventListener\ProtocolGuardListener` (nach der Firewall). Verbindlich prüft der Sync noch einmal **unter der Kontosperre** (`SyncService::guardEncryption`), weil ein `begin` zwischen beiden liegen kann |
| Sync | `SyncRequestParser` (entweder Klartext oder `enc`, nie beides), `SyncService::decide` (unbedingtes Ersetzen in `migrating`), Form muss zum Zustand passen (sonst `invalid`), `SyncResponseSerializer` (verschlüsselt nur `enc`/`wrappedKey`; Mitglieder bekommen ihren eigenen `wrappedKey` aus `list_member`) |
| Teilen | `ShareService::join` (Zustände, `wrappedKey`), `detachForEncryption` (für `begin` und den direkten `commit`) |
| Frist-Job | `bin/console app:purge-plaintext [--dry-run]` (`App\Command\PurgePlaintextCommand`): nur nach der Frist, nur `plaintext`-Konten; `migrating` bleibt unberührt |
| Admin, Status | `name: null` + `encrypted: true` + `encryptionState`; Statuszähler `statistics.encryption` |
| Teilen-Seite | `public/join.js` hängt `#k=…&l=…` (Listenschlüssel und Listen-ID) an den Verweis zur Web-App |
| Tests | `tests/Functional/EncryptionTest.php` |

API-Version 1.1.0. Phasen der App und Testliste: `app/docs/07-verschluesselung.md`.

