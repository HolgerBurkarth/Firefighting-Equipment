# Firefighting-Equipment

## REST-API

### Adressen

```
https://www.prodad.de/firefighting/<kürzel>/<pfad>    # Stadt
https://www.prodad.de/firefighting/service/<pfad>     # Super-Admin
```

| Methode | Pfad            | unter `service` | unter einer Stadt | Antwort                                      |
|---------|-----------------|-----------------|-------------------|----------------------------------------------|
| GET     | `api/v1/health` | ja              | ja                | `{"status":"ok","database":"ok"}`            |
| GET     | `api/v1/tenant` | nein (404)      | ja                | `{"slug":"immendingen","name":"Feuerwehr …"}` |

Unter einer Stadt antwortet die API nur, wenn die Stadt existiert und aktiv ist. Der Health-Check
prüft die Datenbankverbindung; bei einer Störung antwortet er mit 503 und
`{"status":"error","database":"error"}`. Das Deploy-Skript ruft ihn unter `service` auf.

### Fehler

Alle Fehler haben das Format `{"error":"code","message":"deutscher Text"}`:

| HTTP | `error`                | Bedeutung                                              |
|------|------------------------|--------------------------------------------------------|
| 404  | `not_found`            | Adresse oder Kürzel ungültig, Pfad unbekannt           |
| 404  | `tenant_not_found`     | Kürzel gültig, aber keine Stadt dazu                   |
| 403  | `tenant_inactive`      | Stadt gesperrt                                         |
| 405  | `method_not_allowed`   | Methode nicht erlaubt (erlaubte im Header `Allow`)     |
| 503  | `database_unavailable` | Datenbank beim Laden der Stadt nicht erreichbar        |
| 500  | `internal_error`       | unerwarteter Fehler; Details nur im Server-Log         |

Alle Antworten tragen `Cache-Control: no-store` und `X-Content-Type-Options: nosniff`.

### Lokal starten

```
php -S 127.0.0.1:8000 public/index.php
```

Dann z. B. `http://127.0.0.1:8000/firefighting/service/api/v1/health`. Die Anwendung verwendet
`config/config.local.php`; fehlt sie, antwortet der Health-Check mit 503.

### Neuen Endpunkt anlegen

1. Handler in `src/Api/V1/` anlegen, der `FirefightingEquipment\Http\RequestHandler` implementiert.
   Er bekommt einen `RequestContext`:
   - `$context->tenant`: die aktive Stadt (unter `service`: `null`); jede Abfrage nach deren ID
     filtern.
   - `$context->db->get()`: die PDO-Verbindung; sie wird erst hier aufgebaut.
   - Bekannte Fehler als `HttpException` werfen (Fabriken in `src/Http/HttpException.php`, neue
     Codes dort ergänzen); alles andere wird automatisch zu 500.
2. Route in `src/Http/Routes.php` eintragen, z. B.
   `->add('GET', 'api/v1/devices', new DevicesHandler())` in `tenant()` (Städte) oder
   `service()` (Super-Admin). Pfad ohne Slash am Anfang und Ende.
3. Tests: Unit-Test für die Logik, Integrationstest in `tests/Integration/Http/` (erbt von
   `DomainTestCase`) für die Antworten mit echter Datenbank.
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
