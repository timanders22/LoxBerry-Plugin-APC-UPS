#!/bin/sh

# To use important variables from command line use the following code:
PDIR=$3       # Third argument is Plugin installation folder
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
LBPLOG="${LBPLOG:-$5/log/plugins}"
# sudo -n -u loxberry setzt die Umgebung zurueck - ohne diesen
# Rueckfall zeigte $LBPDATA ins Nichts und der Pfad auf /<ordner>.
LBPDATA="${LBPDATA:-$5/data/plugins}"
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PLOG=$LBPLOG/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Und /tmp ist fuer
# jeden lesbar. Geaendert am 10.08.2026 nach der Durchsicht aller Plugins.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$LBPDATA/$PDIR.upgrade_sicherung"
echo "<INFO> Backing up existing config files"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null
cp -a "$PCONFIG/." "$SICHER/" 2>/dev/null \
    && echo "<OK> Konfiguration gesichert nach $SICHER (Rechte 0700)."

# Laufenden Dienst anhalten, damit er nicht in die neue Fassung hineinlaeuft.
#
# Frueher stand hier  pkill -f apc_service.py.  Das erschlaegt jeden Prozess,
# in dessen Befehlszeile die Zeichenkette vorkommt - auch einen offenen
# Editor oder ein Sicherungsskript, das gerade den Ordner durchsucht. Der
# Dienst schreibt seine Prozessnummer in eine Datei; danach wird punktgenau
# beendet, mit zehn Sekunden Zeit zum Aufraeumen.
PIDF=/run/shm/apc_ups_ng.pid
[ -f "$PIDF" ] || PIDF="$PLOG/apc_ups_ng.pid"
if [ -f "$PIDF" ]; then
    P=$(cat "$PIDF" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        kill "$P" 2>/dev/null
        i=0
        while [ $i -lt 10 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        # Nur hart beenden, wenn er noch lebt UND es wirklich unser Dienst ist.
        if kill -0 "$P" 2>/dev/null && grep -qa "apc_service.py" "/proc/$P/cmdline" 2>/dev/null; then
            kill -9 "$P" 2>/dev/null
        fi
    fi
    rm -f "$PIDF"
    echo "<INFO> Laufender Dienst angehalten."
fi


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-apc_ups_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/apc_ups_ng.cfg" ]; then
    cp -p "$NETZ_CFG/apc_ups_ng.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.apc_ups_ng.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.apc_ups_ng.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
