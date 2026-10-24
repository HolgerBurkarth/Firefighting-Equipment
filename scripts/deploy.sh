#!/usr/bin/env bash
# Deploy-Skript für den STRATO-Server.
#
# Aufruf auf dem Server:
#   cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh
#
# Ablauf: Prüfung auf lokale Änderungen, Sicherung (ohne vendor/),
# git fetch + fast-forward auf origin/main, composer install, Health-Check.
# Bei einem Fehler wird NICHT automatisch zurückgesprungen; das Skript
# gibt die Befehle für den Rücksprung aus.
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

BACKUP_FILE="$BACKUP_DIR/ff-deploy-$(date +%Y%m%d-%H%M%S)-$OLD_SHORT.tar.gz"
echo "Sicherung:       $BACKUP_FILE"
(
    umask 077
    tar -czf "$BACKUP_FILE" --exclude=./vendor -C "$PROJECT_DIR" .
)
chmod 600 "$BACKUP_FILE"

# Nur die neuesten Sicherungen behalten (Dateinamen sind nach Zeit sortierbar).
shopt -s nullglob
backups=("$BACKUP_DIR"/ff-deploy-*.tar.gz)
shopt -u nullglob
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
