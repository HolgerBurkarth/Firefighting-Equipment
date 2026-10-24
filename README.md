# Firefighting-Equipment

## REST-API

### Adressen

```
https://www.prodad.de/firefighting/<kürzel>/<pfad>    # Stadt
https://www.prodad.de/firefighting/service/<pfad>     # Super-Admin
```

| Methode | Pfad                                 | Zugriff    | Antwort                                        |
|---------|--------------------------------------|------------|------------------------------------------------|
| GET     | `api/v1/health`                      | öffentlich | `{"status":"ok","database":"ok"}`              |
| GET     | `api/v1/tenant`                      | öffentlich | `{"slug":"immendingen","name":"Feuerwehr …"}`  |
| POST    | `api/v1/auth/login`                  | öffentlich | `{"user":…,"tenant":…,"session":…}` + Cookie   |
| POST    | `api/v1/auth/logout`                 | öffentlich | `{"logged_out":true}`, Cookie wird gelöscht    |
| GET     | `api/v1/auth/me`                     | angemeldet | wie Login                                      |
| GET     | `api/v1/auth/sessions`               | angemeldet | `{"sessions":[{"id":…,"current":true,…}]}`     |
| DELETE  | `api/v1/auth/sessions/{id}`          | angemeldet | `{"deleted":true,"current":false}`             |
| POST    | `api/v1/auth/sessions/logout-others` | angemeldet | `{"ended_sessions":2}`                         |
| POST    | `api/v1/auth/password`               | angemeldet | wie Login, mit neuem Cookie                    |

`api/v1/tenant` gibt es nur unter einer Stadt (unter `service`: 404), alle anderen Routen unter
`service` und unter jeder Stadt. Super-Admins melden sich nur unter `service` an, Konten einer Stadt
nur unter ihrer Stadt; ein Cookie gilt nur dort.

Unter einer Stadt antwortet die API nur, wenn die Stadt existiert und aktiv ist. Der Health-Check
prüft die Datenbankverbindung; bei einer Störung antwortet er mit 503 und
`{"status":"error","database":"error"}`. Das Deploy-Skript ruft ihn unter `service` auf.

### Fehler

Alle Fehler haben das Format `{"error":"code","message":"deutscher Text"}`:

| HTTP | `error`                    | Bedeutung                                                  |
|------|----------------------------|------------------------------------------------------------|
| 400  | `invalid_request`          | Body kein JSON-Objekt, zu groß (> 16 KiB), Feld fehlt      |
| 401  | `not_authenticated`        | keine oder abgelaufene Sitzung                             |
| 401  | `invalid_credentials`      | E-Mail oder Passwort falsch (bewusst nicht unterschieden)  |
| 403  | `forbidden`                | angemeldet, aber die Rolle reicht nicht                    |
| 403  | `origin_not_allowed`       | Header `Origin` nicht erlaubt (Schutz gegen CSRF)          |
| 403  | `account_locked`           | Passwort richtig, Konto gesperrt                           |
| 403  | `email_not_verified`       | Passwort richtig, E-Mail-Adresse noch nicht bestätigt      |
| 403  | `pending_approval`         | Passwort richtig, Freischaltung durch Admin steht aus      |
| 403  | `invalid_current_password` | Passwort ändern: aktuelles Passwort falsch                 |
| 403  | `tenant_inactive`          | Stadt gesperrt                                             |
| 404  | `not_found`                | Adresse oder Kürzel ungültig, Pfad unbekannt               |
| 404  | `tenant_not_found`         | Kürzel gültig, aber keine Stadt dazu                       |
| 405  | `method_not_allowed`       | Methode nicht erlaubt (erlaubte im Header `Allow`)         |
| 415  | `unsupported_media_type`   | ändernde Anfrage ohne `Content-Type: application/json`     |
| 422  | `weak_password`            | neues Passwort verletzt die Passwortregeln                 |
| 429  | `too_many_attempts`        | zu viele Fehlversuche (Wartezeit im Header `Retry-After`)  |
| 503  | `database_unavailable`     | Datenbank nicht erreichbar                                 |
| 500  | `internal_error`           | unerwarteter Fehler; Details nur im Server-Log             |

Alle Antworten tragen `Cache-Control: no-store` und `X-Content-Type-Options: nosniff`.

### Anmeldung und Sitzungen

- Nach dem Login steht die Sitzung im Cookie `ff_session` (`HttpOnly`, `Secure`,
  `SameSite=Strict`, Pfad `/firefighting/<kürzel>/` bzw. `/firefighting/service/`, `Max-Age` =
  Höchstdauer der Rolle). Die Datenbank kennt nur den SHA-256 des Tokens.
- Sitzungsdauer (Inaktivität / Höchstdauer): `user` 30 / 90 Tage, `admin` 7 / 30 Tage,
  `superadmin` 1 / 12 Stunden.
- Ändernde Anfragen (POST, DELETE) brauchen `Content-Type: application/json` und einen JSON-Body,
  auch wenn er leer ist (`{}`). Ein mitgesendeter `Origin` muss `https://www.prodad.de` sein; lokal
  lassen sich weitere über `http.allowed_origins` in `config/config.local.php` erlauben (siehe
  `config/config.example.php`).
- Bremse: 5 Fehlversuche je E-Mail und Stadt oder 30 je IP-Bereich (/24 bzw. /48) innerhalb von
  15 Minuten führen zu 429. Eine erfolgreiche Anmeldung setzt nur den Zähler der E-Mail zurück.
- Passwort ändern beendet alle anderen Sitzungen und ersetzt die aktuelle (neues Cookie).

### Lokal starten

```
php -S localhost:8000 public/index.php
```

Dann z. B. `http://localhost:8000/firefighting/service/api/v1/health`. Die Anwendung verwendet
`config/config.local.php`; fehlt sie, antwortet der Health-Check mit 503.

Zum Anmelden `localhost` verwenden, nicht `127.0.0.1`: Browser und curl nehmen das
`Secure`-Cookie über `http://` nur für `localhost` an.

Beispiel mit curl und Cookie-Datei (in PowerShell `curl.exe` schreiben, nicht `curl`). Die
Zugangsdaten stehen in einer Datei, damit das Passwort nicht im Befehlsverlauf landet:

```
# login.json enthält: {"email":"admin@example.org","password":"…"}
curl.exe -c cookies.txt -H "Content-Type: application/json" --data-binary "@login.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/login
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/auth/me
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/auth/sessions
curl.exe -b cookies.txt -X DELETE -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/auth/sessions/12
curl.exe -b cookies.txt -c cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/auth/logout
```

`login.json` und `cookies.txt` (enthält das Sitzungs-Token im Klartext) danach löschen und nie
committen.

### Neuen Endpunkt anlegen

1. Handler in `src/Api/V1/` anlegen, der `FirefightingEquipment\Http\RequestHandler` implementiert.
   Er bekommt einen `RequestContext`:
   - `$context->tenant`: die aktive Stadt (unter `service`: `null`); jede Abfrage nach deren ID
     filtern.
   - `$context->db->get()`: die PDO-Verbindung; sie wird erst hier aufgebaut.
   - `$context->auth()`: angemeldetes Konto und Sitzung (nur bei Routen, die nicht öffentlich sind).
   - `$context->param('id')`: Wert eines Platzhalters; `$context->stringField('name')`: Textfeld aus
     dem JSON-Body (fehlt es, folgt 400).
   - Bekannte Fehler als `HttpException` werfen (Fabriken in `src/Http/HttpException.php`, neue
     Codes dort ergänzen); alles andere wird automatisch zu 500.
2. Route in `src/Http/Routes.php` mit Zugriffsstufe eintragen, z. B.
   `->add('GET', 'api/v1/devices/{id}', Access::Authenticated, new DeviceHandler())` in `tenant()`
   (Städte) oder `service()` (Super-Admin). Stufen: `Public`, `Authenticated`, `Admin` (nur Städte),
   `SuperAdmin` (nur `service`). Pfad ohne Slash am Anfang und Ende; ein Platzhalter `{name}` passt
   nur auf positive Ganzzahlen.
3. Tests: Unit-Test für die Logik, Integrationstest in `tests/Integration/Http/` (erbt von
   `DomainTestCase`) bzw. `tests/Integration/Auth/` (erbt von `AuthTestCase`, mit Anmeldung) für die
   Antworten mit echter Datenbank.
4. Endpunkt in der Tabelle oben eintragen.

## Datenbank-Migrationen

```
php bin/migrate.php status                 # ausgeführte und offene Migrationen
php bin/migrate.php run --dry-run          # zeigt nur, was passieren würde
php bin/migrate.php run                    # führt offene Migrationen aus
php bin/migrate.php check                  # nur prüfen: Exit-Code 0 = nichts offen, 3 = offen
php bin/migrate.php status --config=PFAD   # andere Konfiguration (Standard: config/config.local.php)
```

Auf dem Server immer `php85` statt `php` verwenden. Regeln für Migrationen stehen in
`CLAUDE.md`.

## Deployment

Auf dem Server per SSH:

```
cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh
```

Ablauf:

1. Abbruch, wenn versionierte Dateien auf dem Server geändert sind.
2. Sicherung des Projektordners (ohne `vendor/`) nach
   `~/htdocs/apps/_backup/ff-deploy-JJJJMMTT-HHMMSSZ-<commit>.tar.gz`; die 5 neuesten (nach
   Änderungsdatum der Datei) bleiben, die gerade erzeugte wird nie gelöscht
   (`scripts/prune-deploy-backups.sh`).
3. `git fetch` und `git merge --ff-only origin/main`. Hat der Merge `scripts/deploy.sh` geändert,
   startet sich das Skript sofort mit der neuen Fassung neu und setzt dort fort, ohne Sicherung
   und Merge zu wiederholen. Danach `composer install`.
4. `php85 bin/migrate.php check`. Sind Migrationen offen:
   1. Datenbanksicherung mit `php85 bin/db-dump.php --suffix=vor-migration-<alter Commit>`.
      Schlägt sie fehl, bricht das Skript ab, **ohne** zu migrieren. Gibt es noch keine Tabelle
      `fe_*` (erste Migration), gibt es nichts zu sichern: Das Skript zeigt einen Hinweis und
      migriert ohne Sicherung.
   2. `php85 bin/migrate.php run`. Schlägt die Migration fehl, zeigt das Skript den Pfad der
      Sicherung und die Befehle zum Zurückspielen an. Es spielt **nichts** automatisch zurück.
5. Health-Check `https://www.prodad.de/firefighting/service/api/v1/health` (erwartet HTTP 200;
   prüft auch die Datenbankverbindung, scheitert also z. B. bei fehlender
   `config/config.local.php`).

Bei einem Fehler zeigt das Skript außerdem die Befehle für den Rücksprung auf den vorherigen
Commit. Wichtig: Schlagen Sicherung oder Migration fehl, ist der neue Code bereits eingespielt,
die Datenbank aber noch nicht (vollständig) migriert.

Alle Zeitstempel in Dateinamen sind in UTC (Kennzeichen `Z`).

## Datenbanksicherung

```
php bin/db-dump.php                                   # Sicherung nach ~/htdocs/apps/_backup/
php bin/db-dump.php --suffix=vor-update               # mit Zusatz im Dateinamen
php bin/db-dump.php --target-dir=PFAD                 # anderer Zielordner (muss .htaccess enthalten)
php bin/db-dump.php --mysqldump=/usr/bin/mysqldump    # anderes mysqldump-Programm
php bin/db-dump.php --config=PFAD                     # andere Konfiguration
```

- Gesichert werden alle Tabellen `fe_*` der Datenbank aus der Konfiguration.
- Speicherort: `~/htdocs/apps/_backup/ff-db-JJJJMMTT-HHMMSSZ[-zusatz].sql.gz` (UTC, gzip,
  Rechte 600). Der Ordner muss existieren und eine `.htaccess` enthalten.
- Es bleiben die 10 neuesten `ff-db-*.sql.gz` (nach Änderungsdatum der Datei, nicht nach Namen);
  ältere werden nach jeder erfolgreichen Sicherung gelöscht, die gerade erzeugte nie. Andere
  Dateien im Ordner werden nicht angefasst.
- Gibt es keine Tabelle `fe_*` (z. B. vor der ersten Migration), wird nichts gesichert, keine
  Datei angelegt und mysqldump nicht aufgerufen (Exit-Code 4).
- Die Zugangsdaten stehen nie in der Befehlszeile, sondern nur kurz in einer Datei mit Rechten
  600, die danach gelöscht wird.
- Ausgabe bei Erfolg: nur der Pfad der Sicherung. Exit-Codes: 0 OK, 1 Fehler, 2 falscher Aufruf,
  4 nichts zu sichern (keine Tabellen `fe_*`).

Auf dem Server immer `php85` statt `php` verwenden.

### Sicherung zurückspielen

Nur von Hand, per SSH. Das Passwort nie in die Befehlszeile schreiben: Es wäre in der
Prozessliste und in der Shell-Historie sichtbar.

1. Code auf den Stand vor dem Deployment zurücksetzen (die Befehle zeigt `deploy.sh` an):
   ```
   cd ~/htdocs/apps/firefighting-equipment
   git reset --hard <alter Commit>
   php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-cache
   ```
2. Zugangsdatei nur für den eigenen Benutzer anlegen:
   ```
   (umask 077 && nano ~/htdocs/apps/_backup/.restore.cnf)
   ```
   Inhalt (Werte aus `config/config.local.php`; in den Werten `\` als `\\` und `"` als `\"`
   schreiben):
   ```
   [client]
   user="BENUTZER"
   password="PASSWORT"
   host="HOST"
   ```
3. Sicherung einspielen (`--defaults-extra-file` muss die erste Option sein):
   ```
   gunzip -c ~/htdocs/apps/_backup/ff-db-JJJJMMTT-HHMMSSZ-vor-migration-COMMIT.sql.gz \
     | mysql --defaults-extra-file=$HOME/htdocs/apps/_backup/.restore.cnf DATENBANKNAME
   ```
4. Zugangsdatei sofort löschen:
   ```
   rm -f ~/htdocs/apps/_backup/.restore.cnf
   ```
5. Prüfen: `php85 bin/migrate.php status`.

Hinweise:

- Die Sicherung enthält nur Tabellen `fe_*`. Beim Einspielen werden diese Tabellen gelöscht
  und neu angelegt (`DROP TABLE IF EXISTS`). Tabellen, die eine fehlgeschlagene Migration
  **neu** angelegt hat, stehen nicht in der Sicherung und bleiben bestehen. Sie müssen von Hand
  gelöscht werden, sonst schlägt die Migration beim nächsten Versuch erneut fehl.
- Die Protokolltabelle `fe_schema_migrations` ist Teil der Sicherung und hat danach wieder den
  Stand vor der Migration.

## Städte (Mandanten) verwalten

```
php bin/tenant.php list                                                  # alle Städte
php bin/tenant.php create --slug=immendingen --name="Feuerwehr Immendingen"
php bin/tenant.php deactivate --slug=immendingen                         # sperren
php bin/tenant.php activate --slug=immendingen                           # entsperren
php bin/tenant.php list --config=PFAD                                    # andere Konfiguration
```

Das Kürzel erscheint in der URL (`/firefighting/<kürzel>/`) und ist nach dem Anlegen
unveränderlich. Erlaubt sind Kleinbuchstaben `a-z`, Ziffern und Bindestrich (nicht am
Anfang oder Ende, nicht doppelt), höchstens 50 Zeichen; `service` ist reserviert. Städte
werden nicht gelöscht, sondern gesperrt; ihre Daten bleiben erhalten. Auf dem Server
`php85` statt `php` verwenden.

## Super-Admins und Benutzer verwalten

```
php bin/user.php superadmin:create --email=chef@example.org --name="Erika Musterfrau"
php bin/user.php superadmin:list                                  # alle Super-Admins
php bin/user.php superadmin:password --email=chef@example.org     # neues Passwort setzen
php bin/user.php superadmin:lock --email=chef@example.org         # sperren
php bin/user.php superadmin:unlock --email=chef@example.org       # entsperren
php bin/user.php list --tenant=immendingen                        # Konten einer Stadt
php bin/user.php superadmin:list --config=PFAD                    # andere Konfiguration
```

Auf dem Server:

```
cd ~/htdocs/apps/firefighting-equipment && php85 bin/user.php superadmin:create --email=… --name="…"
```

- Das Passwort wird **verdeckt** abgefragt und muss wiederholt werden; es wird nie als Argument
  übergeben. Ein neuer Super-Admin ist sofort aktiv, seine E-Mail-Adresse gilt als bestätigt.
- Die verdeckte Eingabe funktioniert nur in einem Linux-Terminal (z. B. per SSH auf dem Server).
  Unter Windows bricht der Befehl mit einem Hinweis ab; Super-Admins daher auf dem Server anlegen.
- `--password-stdin` liest das Passwort aus der Standardeingabe. Das ist **nur für Tests und
  Skripte** gedacht. Nicht von Hand per `echo "…" | php bin/user.php …` verwenden: Das Passwort
  landet sonst in der Befehlshistorie der Shell.
- Passwortregeln: 10 bis 128 Zeichen, keine Pflicht zu Sonderzeichen. Abgelehnt werden häufige
  Passwörter aus `resources/password-blocklist.txt`, auch mit angehängten Ziffern oder Zeichen
  (z. B. "Feuerwehr2025!"), sowie Passwörter mit Teilen der E-Mail-Adresse, des Namens, des
  Ortsnamens oder des Kürzels der Stadt. Allgemeine Wörter wie "Feuerwehr" im Stadtnamen
  zählen dabei nicht als persönliche Daten.
- Sperren und ein neues Passwort beenden alle Sitzungen des Kontos. Der letzte aktive, nicht
  gesperrte Super-Admin kann nicht gesperrt werden; zuerst einen weiteren anlegen.
- `list` zeigt alle Konten einer Stadt (auch gelöschte als "Gelöschter Benutzer #ID"), nie
  Passwort-Hashes.

## Tests

```
composer test              # alle Tests
composer test:unit         # nur Unit-Tests (ohne Datenbank)
composer test:integration  # nur Integrationstests (Testdatenbank)
```

### Testdatenbank einrichten

1. In MariaDB eine eigene Datenbank anlegen, deren Name auf `_test` endet:
   ```sql
   CREATE DATABASE firefighting_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. `config/config.example.php` nach `config/config.test.php` kopieren und die Zugangsdaten
   eintragen. Die Datei wird nie committet.

Fehlt `config/config.test.php`, werden die Integrationstests übersprungen. Endet der
Datenbankname nicht auf `_test`, bricht der Testlauf sofort ab.

Fachliche Integrationstests führen die echten Migrationen aus `migrations/` automatisch in
der Testdatenbank aus und leeren vor jedem Test die fachlichen Tabellen.

### Testdatenbank zurücksetzen

Wurde eine Migration geändert, nachdem sie in der Testdatenbank schon lief, brechen die
Tests mit einem Prüfsummenfehler ab. Dann die Testdatenbank neu anlegen:

```sql
DROP DATABASE firefighting_test;
CREATE DATABASE firefighting_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
