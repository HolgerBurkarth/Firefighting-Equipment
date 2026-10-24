#!/usr/bin/env bash
# Löscht alte Projektsicherungen ff-deploy-*.tar.gz (aufgerufen von scripts/deploy.sh).
#
# Aufruf: bash scripts/prune-deploy-backups.sh ORDNER ANZAHL [BEHALTEN]
#
# Es bleiben die ANZAHL neuesten Sicherungen, sortiert nach dem Änderungsdatum der Datei
# (bei Gleichstand nach Namen). Nicht nach dem Namen sortieren: Ältere Namen enthalten Ortszeit
# (ohne Z), neuere UTC (mit Z). BEHALTEN ist die gerade erzeugte Sicherung; sie wird nie gelöscht
# und zählt zu den ANZAHL behaltenen.
# Betrachtet werden nur reguläre Dateien ff-deploy-JJJJMMTT-HHMMSS[Z]-<commit>.tar.gz;
# alle anderen Dateien bleiben unberührt.
#
# Exit-Codes: 0 OK, 1 Fehler, 2 falscher Aufruf.

set -euo pipefail
# Zeichenbereiche wie [a-f] und die Sortierung unabhängig von der Spracheinstellung.
export LC_ALL=C

if [ "$#" -lt 2 ] || [ "$#" -gt 3 ] || [[ ! "$2" =~ ^[1-9][0-9]*$ ]]; then
    echo "Aufruf: bash $0 ORDNER ANZAHL [BEHALTEN]   (ANZAHL mindestens 1)" >&2
    exit 2
fi

DIR="$1"
KEEP="$2"
PROTECT="${3:-}"
PROTECT="${PROTECT##*/}"

[ -d "$DIR" ] || { echo "FEHLER: Sicherungsordner $DIR fehlt." >&2; exit 1; }

others="$KEEP"
candidates=()
for f in "$DIR"/ff-deploy-*.tar.gz; do
    name="${f##*/}"
    if [[ ! -f "$f" || -L "$f" || ! "$name" =~ ^ff-deploy-[0-9]{8}-[0-9]{6}Z?-[0-9a-f]+\.tar\.gz$ ]]; then
        continue
    fi
    if [ -n "$PROTECT" ] && [ "$name" = "$PROTECT" ]; then
        others=$((KEEP - 1))
        continue
    fi
    # Namen enthalten wegen des Musters keine Leerzeichen.
    candidates+=("$(stat -c %Y -- "$f") $name")
done

[ "${#candidates[@]}" -gt "$others" ] || exit 0

# Neueste zuerst; bei gleichem Änderungsdatum entscheidet der Name. Gelöscht wird älteste zuerst.
mapfile -t obsolete < <(
    printf '%s\n' "${candidates[@]}" | LC_ALL=C sort -k1,1nr -k2,2r | cut -d' ' -f2 | tail -n +"$((others + 1))" | tac
)
for name in "${obsolete[@]}"; do
    echo "Lösche alte Sicherung: $DIR/$name"
    rm -f -- "$DIR/$name"
done
