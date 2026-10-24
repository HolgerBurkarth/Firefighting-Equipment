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
# Hat der Merge dieses Skript geändert, startet es sich direkt danach mit der neuen
# Fassung neu (exec) und setzt dort fort, ohne Sicherung und Merge zu wiederholen.
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
    if [ -z "$DB_BACKUP_FILE" ]; then
        echo " Es gibt keine Datenbanksicherung: Vor der Migration war keine" >&2
        echo " Tabelle fe_* vorhanden. Tabellen, die die fehlgeschlagene Migration" >&2
        echo " angelegt hat, von Hand löschen (Fehlermeldung oben lesen)." >&2
        print_rollback
        return
    fi
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

# Fortsetzung nach einem Selbst-Neustart (siehe unten): alter Commit und Sicherung kommen
# aus der Umgebung; Sicherung und Merge sind bereits erledigt.
RESUME_OLD_COMMIT="${FF_DEPLOY_RESUME_OLD_COMMIT:-}"
RESUME_BACKUP_FILE="${FF_DEPLOY_RESUME_BACKUP_FILE:-}"
unset FF_DEPLOY_RESUME_OLD_COMMIT FF_DEPLOY_RESUME_BACKUP_FILE

if [ -n "$RESUME_OLD_COMMIT" ]; then
    [[ "$RESUME_OLD_COMMIT" =~ ^[0-9a-f]{40}$ ]] && git merge-base --is-ancestor "$RESUME_OLD_COMMIT" HEAD \
        || fail "Fortsetzung: ungültiger alter Commit '$RESUME_OLD_COMMIT'."
    [[ "$RESUME_BACKUP_FILE" == "$BACKUP_DIR"/ff-deploy-*.tar.gz && -f "$RESUME_BACKUP_FILE" ]] \
        || fail "Fortsetzung: Sicherung '$RESUME_BACKUP_FILE' fehlt."
    OLD_COMMIT="$RESUME_OLD_COMMIT"
    OLD_SHORT="$(git rev-parse --short "$OLD_COMMIT")"
    BACKUP_FILE="$RESUME_BACKUP_FILE"
    echo "Fortsetzung mit der neuen Fassung von scripts/deploy.sh."
    echo "Projektordner:   $PROJECT_DIR"
    echo "Alter Stand:     $OLD_COMMIT"
    echo "Sicherung:       $BACKUP_FILE"
else
    OLD_COMMIT="$(git rev-parse HEAD)"
    OLD_SHORT="$(git rev-parse --short HEAD)"
    echo "Projektordner:   $PROJECT_DIR"
    echo "Aktueller Stand: $OLD_COMMIT"

    # --- Sicherung ---------------------------------------------------------

    # Zeitstempel in UTC mit Kennzeichen Z.
    BACKUP_FILE="$BACKUP_DIR/ff-deploy-$(date -u +%Y%m%d-%H%M%SZ)-$OLD_SHORT.tar.gz"
    echo "Sicherung:       $BACKUP_FILE"
    (
        umask 077
        tar -czf "$BACKUP_FILE" --exclude=./vendor -C "$PROJECT_DIR" .
    )
    chmod 600 "$BACKUP_FILE"

    # Nur die neuesten Sicherungen behalten (nach Änderungsdatum, nicht nach Namen); die gerade
    # erzeugte wird nie gelöscht. Andere Dateien bleiben.
    bash "$SCRIPT_DIR/prune-deploy-backups.sh" "$BACKUP_DIR" "$BACKUP_KEEP" "$BACKUP_FILE"
fi

# --- Aktualisieren ---------------------------------------------------------

trap print_rollback ERR

if [ -z "$RESUME_OLD_COMMIT" ]; then
    git fetch origin
    git merge --ff-only "origin/$BRANCH"

    # Die laufende Fassung dieses Skripts ist veraltet, wenn der Merge es geändert hat:
    # sofort mit der neuen Fassung neu starten und dort fortsetzen.
    if ! git diff --quiet "$OLD_COMMIT" HEAD -- scripts/deploy.sh; then
        echo "scripts/deploy.sh wurde geändert – Neustart mit der neuen Fassung."
        trap - ERR
        export FF_DEPLOY_RESUME_OLD_COMMIT="$OLD_COMMIT"
        export FF_DEPLOY_RESUME_BACKUP_FILE="$BACKUP_FILE"
        exec "$BASH" "$PROJECT_DIR/scripts/deploy.sh"
    fi
fi
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
        # db-dump: 0 = Sicherung erstellt, 4 = keine Tabellen fe_* (nichts zu sichern, z. B. vor
        # der ersten Migration; keine Datei), sonst Fehler.
        DUMP_STATUS=0
        DB_BACKUP_FILE="$("$PHP_BIN" bin/db-dump.php --target-dir="$BACKUP_DIR" --suffix="vor-migration-$OLD_SHORT")" || DUMP_STATUS=$?
        if [ "$DUMP_STATUS" -eq 4 ]; then
            DB_BACKUP_FILE=""
            echo "Hinweis: Keine Tabellen fe_* vorhanden – keine Datenbanksicherung nötig."
        elif [ "$DUMP_STATUS" -ne 0 ] || [ ! -f "$DB_BACKUP_FILE" ]; then
            trap - ERR
            echo "FEHLER: Datenbanksicherung fehlgeschlagen. Migrationen wurden NICHT ausgeführt." >&2
            echo "        Der neue Code ist eingespielt, die Datenbank hat noch das alte Schema." >&2
            print_rollback
            exit 1
        else
            echo "Datenbanksicherung: $DB_BACKUP_FILE"
        fi

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
