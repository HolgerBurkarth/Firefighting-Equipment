# Firefighting-Equipment – Regeln für KI-Agenten

## Zweck
Webanwendung für Feuerwehren: Geräte (z. B. Atemschutzmasken) werden Standorten
(Fahrzeuge, Räume/Lager, Extern) zugeordnet, ihr Status wird erfasst, jede
Änderung wird protokolliert. Mehrere Städte (Mandanten) nutzen denselben Code.

## Arbeitsweise
- In kleinen Schritten arbeiten. Vor größeren Änderungen erst einen Plan vorlegen.
- Bestehende Funktionen dürfen nicht kaputtgehen: Jede Änderung bekommt Tests,
  vor jedem Commit müssen alle Tests grün sein.
- Das Repository ist öffentlich: niemals Passwörter, Zugangsdaten oder
  `config/config.local.php` committen.
- Code und Bezeichner auf Englisch, Dokumentation und Oberflächentexte auf Deutsch.

## Zielumgebung (STRATO, Shared Hosting)
- PHP 8.5 (CGI), MariaDB 10.11, Apache mit `.htaccess` und mod_rewrite.
- Rewrite-Regeln mit `[L]`, nicht mit `[END]` (sonst greift die PHP-Konfiguration nicht).
- Verfügbare PHP-Erweiterungen: PDO_MySQL, MySQLi, mbstring, GD, BCMath, DOM, fileinfo,
  zip, curl, openssl. Nicht verwenden: intl, sodium, xmlreader, xmlwriter, SQLite.
- Keine Cronjobs: Fälligkeiten u. Ä. werden beim Aufruf berechnet.
- Composer ist auf dem Server vorhanden.
- E-Mail-Versand über `mail()` (sendmail).

## Aufbau und URLs
- Projektordner auf dem Server: `/apps/firefighting-equipment`
  - `public/` ist der einzige öffentlich erreichbare Ordner (Front-Controller `index.php`).
  - `config/` liegt außerhalb von `public/` und ist per `.htaccess` gesperrt.
    Zugangsdaten stehen in `config/config.local.php` (nicht im Repository).
- URL-Schema: `https://www.prodad.de/firefighting/<stadt>/`
  - REST-API: `/firefighting/<stadt>/api/v1/...`
  - Super-Admin: `/firefighting/service/`
- Der Mandant wird aus dem URL-Pfad abgeleitet. Das Kürzel einer Stadt ist unveränderlich.

## Server und .htaccess
- Die `.htaccess` in der Hauptebene des Webspace (`htdocs/.htaccess`) gehört NICHT zum
  Projekt. Sie steuert ganz www.prodad.de und darf von Agenten niemals angelegt,
  verändert oder vorgeschlagen werden.
- Sie enthält die Regel
  `RewriteRule ^firefighting/[a-z0-9-]+(/.*)?$ apps/firefighting-equipment/public/index.php [L]`.
  Daher braucht `public/` keine Rewrite-Regel; alle Anfragen landen bei `public/index.php`.
- Stadt-Kürzel bestehen nur aus Kleinbuchstaben, Ziffern und Bindestrich.
- Projektordner (`.htaccess`) und `config/` (`config/.htaccess`) sind per `.htaccess`
  gesperrt, `public/` ist freigegeben (`public/.htaccess`). Diese Dateien nur nach
  Rückfrage ändern; die Freigabe in `public/.htaccess` nie entfernen.
- Nur Dateien in `public/` sind von außen erreichbar; dort nichts Geheimes ablegen.

## Datenbank
- Nur eine Datenbank; alle Tabellen mit Präfix `fe_` (z. B. `fe_tenants`, `fe_users`,
  `fe_devices`, `fe_device_events`). Zeichensatz utf8mb4.
- Mandantendaten tragen immer eine Mandanten-Spalte; jede Abfrage filtert danach.
- Schemaänderungen nur über versionierte Migrationen.
- Tests laufen nur gegen Datenbanken, deren Name auf `_test` endet
  (lokal: `firefighting_test`). Der Testcode bricht sonst ab.

## Fachliche Entscheidungen (Version 1)
- Rollen: Super-Admin (verwaltet Mandanten), Mandanten-Admin (Geräte, Standorte, Benutzer),
  Benutzer (bucht um, setzt Status).
- Login mit E-Mail und Passwort (`password_hash`). Anzeigename vergibt der Admin, interner
  Schlüssel ist immer die ID. Registrierung nur mit Einladungscode, Admin schaltet frei,
  kann sperren/löschen. "Passwort vergessen" per E-Mail.
- Neue Stadt: Super-Admin legt Name, Kürzel und E-Mail des ersten Admins an; dieser erhält
  einen einmal nutzbaren Link zum Setzen des Passworts. Der Super-Admin wird per Skript angelegt.
- Gerät: Pflicht Kennung, Gerätetyp, Standort, Status; optional Seriennummer,
  Hersteller/Modell, Beschaffungsdatum, Notiz, "nächste Prüfung fällig".
- Gerätetypen vom Admin pflegbar (Start: Atemschutzmaske, Atemschutzgerät, Funkgerät,
  Wärmebildkamera).
- Status: einsatzbereit, in Wartung, defekt, ausgesondert.
- Standorte: Fahrzeug, Raum/Lager, Extern; optional mit übergeordnetem Standort; sperrbar,
  Historie bleibt erhalten.
- Jede Änderung von Standort oder Status wird mit Wer/Wann/Von/Nach protokolliert.
- Übersicht "überfällig" und "in 30 Tagen fällig".
- CSV-Export (Semikolon, UTF-8, Umlaute korrekt) und CSV-Import mit Prüfung und Fehleranzeige.
- Nicht in Version 1: Mengenartikel, QR-/Barcodes, Prüfprotokolle.

## Lokale Entwicklungsumgebung
- Windows 10, PowerShell, PHP 8.5 CLI, Composer, Git, MariaDB 10.11 (Port 3306).
- Node.js ist noch nicht installiert.

## Noch offen
- Frontend-Technik (Kandidat: Vue 3 mit Vite) – wird festgelegt, wenn die REST-API steht.
- Datenschutz (Verantwortlicher, Datenschutzerklärung, Löschfristen).