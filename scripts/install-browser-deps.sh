#!/usr/bin/env bash
# Systempakete der Browser für die Browser-Tests der CI (Job e2e in .github/workflows/ci.yml).
#
# Aufruf: bash scripts/install-browser-deps.sh BEFEHL [ARGUMENTE …]
#         (in der CI: npx playwright install-deps chromium firefox webkit)
#
# Der Paket-Mirror des Runners (azure.archive.ubuntu.com) lieferte mehrmals so langsam, dass der
# Job nach 20 Minuten abbrach, bevor ein Test lief (05./06.11.2026, normal dauert der Schritt
# 25–70 s). Daher: jeder Versuch höchstens TIMEOUT Sekunden, insgesamt ATTEMPTS Versuche; vor
# jeder Wiederholung werden übrig gebliebene apt-get/dpkg beendet, ein abgebrochener dpkg-Lauf
# abgeschlossen und auf archive.ubuntu.com umgestellt. Schon geladene Pakete bleiben im Cache von
# apt und werden nicht erneut geladen.
#
# Nur für Tests (nie in der CI setzen): FF_BROWSER_DEPS_TIMEOUT (Sekunden je Versuch),
# FF_BROWSER_DEPS_SUDO (Befehl vor pkill, pgrep, dpkg und sed), FF_BROWSER_DEPS_SOURCES (Paketquellen,
# durch Leerzeichen getrennt).
#
# Exit-Codes: 0 OK, 1 alle Versuche gescheitert, 2 falscher Aufruf.

set -uo pipefail

ATTEMPTS=3
# Sekunden, die übrig gebliebene apt-get/dpkg nach SIGTERM zum Beenden haben (danach SIGKILL).
STOP_WAIT=30
TIMEOUT="${FF_BROWSER_DEPS_TIMEOUT:-180}"
SUDO="${FF_BROWSER_DEPS_SUDO:-sudo}"
# Die Runner laden über die Mirror-Liste apt-mirrors.txt (ubuntu.sources verweist mit
# mirror+file: darauf); apt wechselt dort nur bei Fehlern, nicht bei langsamen Downloads.
SOURCES="${FF_BROWSER_DEPS_SOURCES:-/etc/apt/apt-mirrors.txt /etc/apt/sources.list.d/ubuntu.sources /etc/apt/sources.list}"
MIRROR=azure.archive.ubuntu.com
FALLBACK=archive.ubuntu.com

if [ "$#" -eq 0 ] || [[ ! "$TIMEOUT" =~ ^[1-9][0-9]*$ ]]; then
    echo "Aufruf: bash $0 BEFEHL [ARGUMENTE …]" >&2
    exit 2
fi

for attempt in $(seq 1 "$ATTEMPTS"); do
    if [ "$attempt" -gt 1 ]; then
        # timeout beendet nur den Befehl; das von sudo gestartete apt-get läuft in einer eigenen
        # Sitzung weiter und hält die Sperre von dpkg (CI 06.11.2026). Erst beenden, dann warten.
        $SUDO pkill -TERM -x apt-get || true
        $SUDO pkill -TERM -x dpkg || true
        for _ in $(seq 1 "$STOP_WAIT"); do
            $SUDO pgrep -x 'apt-get|dpkg' > /dev/null || break
            sleep 1
        done
        $SUDO pkill -KILL -x 'apt-get|dpkg' || true
        # Ein abgebrochener Versuch kann dpkg mitten in der Installation verlassen haben.
        $SUDO dpkg --configure -a || true
        for file in $SOURCES; do
            if [ -f "$file" ] && grep -q "$MIRROR" "$file"; then
                $SUDO sed -i "s/$MIRROR/$FALLBACK/g" "$file"
                echo "Paketquelle umgestellt: $file → $FALLBACK"
                grep -n "$FALLBACK" "$file" | sed 's/^/  /'
            fi
        done
    fi
    echo "Versuch $attempt von $ATTEMPTS (höchstens $TIMEOUT s): $*"
    timeout --kill-after=30 "$TIMEOUT" "$@"
    status=$?
    if [ "$status" -eq 0 ]; then
        exit 0
    fi
    if [ "$status" -eq 124 ] || [ "$status" -eq 137 ]; then
        echo "::warning::Systempakete der Browser: Versuch $attempt nach $TIMEOUT s abgebrochen."
    else
        echo "::warning::Systempakete der Browser: Versuch $attempt endete mit Exit-Code $status."
    fi
done

echo "::error::Systempakete der Browser: alle $ATTEMPTS Versuche gescheitert."
exit 1
