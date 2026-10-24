#!/usr/bin/env bash
# Deploy-Skript für den STRATO-Server.
#
# Aufruf auf dem Server:
#   cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh
#
# Ablauf: Prüfung auf lokale Änderungen, Sicherung (ohne vendor/),
# git fetch + fast-forward auf origin/main, composer install,
# bei offenen Migrationen: Datenbanksicherung (bin/db-dump.php), dann
# bin/migrate.php run; zum Schluss Health-Check.
# Bei einem Fehler wird NICHT automatisch zurückgesprungen und keine
# Sicherung zurückgespielt; das Skript gibt die nötigen Befehle aus.
#
# Das Skript verändert nur den Projektordner und ~/htdocs/apps/_backup.

set -euo pipefail

HEALTH_URL="https://www.prodad.de/firefighting/service/api/v1/health"
PHP_BIN="php85"
COMPOSER_BIN="/usr/bin/composer"
BRANCH="main"
BACKUP_KEEP=5

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd -P)"
APPS_DIR="$(dirname "$PROJECT_DIR")"
BACKUP_DIR="$APPS_DIR/_backup"

COMPOSER_CMD=("$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-cache)

fail() {
    echo "FEHLER: $*" >&2
    exit 1
}

print_rollback() {
    echo "" >&2
    echo "==================================================================" >&2
    echo " DEPLOYMENT FEHLGESCHLAGEN – es wurde NICHT zurückgesprungen." >&2
    echo "==================================================================" >&2
    echo " Rücksprung auf den vorherigen Stand ($OLD_COMMIT):" >&2
    echo "" >&2
    echo "   cd $PROJECT_DIR" >&2
    echo "   git reset --hard $OLD_COMMIT" >&2
    echo "   ${COMPOSER_CMD[*]}" >&2
    echo "" >&2
    echo " Sicherung vor dem Deployment: $BACKUP_FILE" >&2
    echo "==================================================================" >&2
}

print_migration_failure() {
    echo "" >&2
    echo "==================================================================" >&2
    echo " MIGRATION FEHLGESCHLAGEN – es wurde NICHTS zurückgespielt." >&2
    echo "==================================================================" >&2
    echo " Datenbanksicherung vor der Migration:" >&2
    echo "   $DB_BACKUP_FILE" >&2
    echo "" >&2
    echo " Zurückspielen (ausführlich in README.md, \"Sicherung zurückspielen\"):" >&2
    echo "  1. Fehlermeldung oben lesen. Tabellen, die die fehlgeschlagene" >&2
    echo "     Migration neu angelegt hat, stehen nicht in der Sicherung und" >&2
    echo "     müssen von Hand gelöscht werden." >&2
    echo "  2. Zugangsdatei anlegen (Passwort nie in die Befehlszeile):" >&2
    echo "       (umask 077 && nano $BACKUP_DIR/.restore.cnf)" >&2
    echo "     Inhalt:" >&2
    echo "       [client]" >&2
    echo "       user=\"BENUTZER\"" >&2
    echo "       password=\"PASSWORT\"" >&2
    echo "       host=\"HOST\"" >&2
    echo "  3. gunzip -c $DB_BACKUP_FILE \\" >&2
    echo "       | mysql --defaults-extra-file=$BACKUP_DIR/.restore.cnf DATENBANKNAME" >&2
    echo "  4. rm -f $BACKUP_DIR/.restore.cnf" >&2
    print_rollback
}

cd "$PROJECT_DIR"

# --- Vorprüfungen ----------------------------------------------------------

[ -d .git ] || fail "$PROJECT_DIR ist kein Git-Repository."
[ -d "$BACKUP_DIR" ] || fail "Sicherungsordner $BACKUP_DIR fehlt."
[ -f "$BACKUP_DIR/.htaccess" ] || fail "$BACKUP_DIR/.htaccess fehlt – Sicherungen wären ungeschützt. Abbruch."
command -v "$PHP_BIN" >/dev/null || fail "$PHP_BIN nicht gefunden."
[ -f "$COMPOSER_BIN" ] || fail "$COMPOSER_BIN nicht gefunden."

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$CURRENT_BRANCH" = "$BRANCH" ] || fail "Aktueller Branch ist '$CURRENT_BRANCH', erwartet '$BRANCH'."

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no >&2
    fail "Versionierte Dateien sind lokal geändert. Änderungen nie direkt auf dem Server machen."
fi

OLD_COMMIT="$(git rev-parse HEAD)"
OLD_SHORT="$(git rev-parse --short HEAD)"
echo "Projektordner:   $PROJECT_DIR"
echo "Aktueller Stand: $OLD_COMMIT"

# --- Sicherung -------------------------------------------------------------

# Zeitstempel in UTC mit Kennzeichen Z.
BACKUP_FILE="$BACKUP_DIR/ff-deploy-$(date -u +%Y%m%d-%H%M%SZ)-$OLD_SHORT.tar.gz"
echo "Sicherung:       $BACKUP_FILE"
(
    umask 077
    tar -czf "$BACKUP_FILE" --exclude=./vendor -C "$PROJECT_DIR" .
)
chmod 600 "$BACKUP_FILE"

# Nur die neuesten Sicherungen behalten. Erkannt werden alte Namen (Ortszeit, ohne Z) und
# neue (UTC, mit Z); sortiert wird nach dem Zeitstempel im Namen. Andere Dateien bleiben.
mapfile -t backups < <(
    for f in "$BACKUP_DIR"/ff-deploy-*.tar.gz; do
        name="${f##*/}"
        if [[ -f "$f" && "$name" =~ ^ff-deploy-([0-9]{8}-[0-9]{6})Z?-[0-9a-f]+\.tar\.gz$ ]]; then
            printf '%s	%s
' "${BASH_REMATCH[1]}" "$f"
        fi
    done | LC_ALL=C sort | cut -f2-
)
if [ "${#backups[@]}" -gt "$BACKUP_KEEP" ]; then
    for old in "${backups[@]:0:${#backups[@]}-BACKUP_KEEP}"; do
        echo "Lösche alte Sicherung: $old"
        rm -f -- "$old"
    done
fi

# --- Aktualisieren ---------------------------------------------------------

trap print_rollback ERR

git fetch origin
git merge --ff-only "origin/$BRANCH"
NEW_COMMIT="$(git rev-parse HEAD)"

"${COMPOSER_CMD[@]}"

# --- Datenbank-Migrationen -------------------------------------------------

# check: 0 = nichts offen, 3 = Migrationen offen, sonst Fehler. Mit || abgefangen,
# damit die ERR-Falle nicht schon beim Exit-Code 3 auslöst.
DB_BACKUP_FILE=""
CHECK_STATUS=0
"$PHP_BIN" bin/migrate.php check || CHECK_STATUS=$?

case "$CHECK_STATUS" in
    0)
        ;;
    3)
        echo "Datenbanksicherung vor der Migration ..."
        DUMP_STATUS=0
        DB_BACKUP_FILE="$("$PHP_BIN" bin/db-dump.php --target-dir="$BACKUP_DIR" --suffix="vor-migration-$OLD_SHORT")" || DUMP_STATUS=$?
        if [ "$DUMP_STATUS" -ne 0 ] || [ ! -f "$DB_BACKUP_FILE" ]; then
            trap - ERR
            echo "FEHLER: Datenbanksicherung fehlgeschlagen. Migrationen wurden NICHT ausgeführt." >&2
            echo "        Der neue Code ist eingespielt, die Datenbank hat noch das alte Schema." >&2
            print_rollback
            exit 1
        fi
        echo "Datenbanksicherung: $DB_BACKUP_FILE"

        MIGRATE_STATUS=0
        "$PHP_BIN" bin/migrate.php run || MIGRATE_STATUS=$?
        if [ "$MIGRATE_STATUS" -ne 0 ]; then
            trap - ERR
            print_migration_failure
            exit 1
        fi
        ;;
    *)
        trap - ERR
        echo "FEHLER: Prüfung der Migrationen fehlgeschlagen (Exit-Code $CHECK_STATUS)." >&2
        echo "        Migrationen wurden NICHT ausgeführt." >&2
        print_rollback
        exit 1
        ;;
esac

# --- Health-Check ----------------------------------------------------------

echo "Health-Check:    $HEALTH_URL"
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$HEALTH_URL" || true)"
if [ "$HTTP_CODE" != "200" ]; then
    echo "FEHLER: Health-Check lieferte HTTP $HTTP_CODE statt 200." >&2
    trap - ERR
    print_rollback
    exit 1
fi
echo "Health-Check OK (HTTP 200)."

trap - ERR

# --- Ergebnis --------------------------------------------------------------

echo ""
echo "Deployment erfolgreich."
echo "  Alter Commit: $OLD_COMMIT"
echo "  Neuer Commit: $NEW_COMMIT"
if [ "$OLD_COMMIT" = "$NEW_COMMIT" ]; then
    echo "  (keine neuen Commits)"
fi
if [ -n "$DB_BACKUP_FILE" ]; then
    echo "  Datenbanksicherung vor der Migration: $DB_BACKUP_FILE"
fi
