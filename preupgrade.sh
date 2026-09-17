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

# ---------- Marke "Aktualisierung laeuft" - als Erstes ----------
# Der Installer legt die Cron-Datei rund eine Minute VOR postinstall.sh neu
# an (Regeln/06, am Geraet gemessen an der Einspeisebremse 08.09.2026).
# Faellt der Fuenf-Minuten-Takt in diese Luecke, startete der Waechter den
# Dienst mit der mitgelieferten Vorgabe-Konfiguration; in WSL gemessen
# (Pruefung-Upgradeluecke-2026-09-17, Befund B1) sendete dieser Dienst danach
# unter dem Vorgabe-Praefix statt unter dem eingestellten. Solange die Marke
# juenger als eine Stunde ist, startet der Waechter nichts; postupgrade.sh
# entfernt sie und startet den Dienst selbst. Sie liegt NEBEN dem
# Datenordner, weil purge_installation den Ordner selbst loescht.
APC_BASE="${5:-$LBHOMEDIR}"
APC_PDIR="${3:-apc_ups_ng}"
MARKE="$APC_BASE/data/plugins/$APC_PDIR.upgrade_laeuft"
mkdir -p "$APC_BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Dienststart bis zum Ende der Installation gesperrt."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - der Waechter"
    echo "<WARNING> kann den Dienst waehrend der Installation starten."
fi

# Ist PID ein Dienst dieses Plugins? Argumentweise (Regeln/03): argv[0] ein
# Python, argv[1] genau das Skript. Ein Editor mit der Datei offen, eine
# Suche mit dem Pfad im Muster oder ein Dienst aus einem anderen Baum wird
# nie getroffen.
apc_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline" | {
        IFS= read -r a0 || exit 1
        IFS= read -r a1 || exit 1
        case "${a0##*/}" in python|python3|python3.*) ;; *) exit 1 ;; esac
        [ "$a1" = "$2" ]
    }
}
# Alle Dienste mit Skript $1, die dem Benutzer mit der Nummer $2 gehoeren.
apc_dienste_suchen() {
    for d in /proc/[0-9]*; do
        [ "$(stat -c %u "$d" 2>/dev/null)" = "$2" ] || continue
        apc_ist_dienst "${d#/proc/}" "$1" && echo "${d#/proc/}"
    done
    return 0
}
# Beendet sie (zehn Sekunden Zeit, dann hart) und gibt die Nummern aus.
apc_dienste_beenden() {
    ZIEL=$(apc_dienste_suchen "$1" "$2")
    [ -n "$ZIEL" ] || return 0
    kill $ZIEL 2>/dev/null
    i=0
    while [ $i -lt 10 ] && [ -n "$(apc_dienste_suchen "$1" "$2")" ]; do
        sleep 1
        i=$((i + 1))
    done
    REST=$(apc_dienste_suchen "$1" "$2")
    [ -n "$REST" ] && kill -9 $REST 2>/dev/null
    echo $ZIEL
}
# Der Dienst laeuft als loxberry; wo es den Benutzer nicht gibt, als der
# eigene.
APC_UID=$(id -u loxberry 2>/dev/null || id -u)
APC_DIENST="$APC_BASE/bin/plugins/$APC_PDIR/apc_service.py"

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
#
# Geprueft wird VOR dem ersten Signal, nicht erst vor dem harten. Bis 1.2.10
# ging das SIGTERM ungeprueft an die Nummer aus der Datei, und nur das
# SIGKILL war abgesichert. Prozessnummern werden aber wiederverwendet: liegt
# eine alte Datei herum und traegt ihre Zahl inzwischen ein fremder Vorgang,
# beendete das erste Signal genau den. In WSL gemessen
# (Pruefung-APC-UPS-1.2.10, Fall fremd_pid): ein Prozess
# "python3 -c ... <Dienstpfad>" mit seiner Nummer in der PID-Datei war nach
# preupgrade.sh tot.
PIDF=/run/shm/apc_ups_ng.pid
[ -f "$PIDF" ] || PIDF="$PLOG/apc_ups_ng.pid"
if [ -f "$PIDF" ]; then
    P=$(cat "$PIDF" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null && apc_ist_dienst "$P" "$APC_DIENST"; then
        kill "$P" 2>/dev/null
        i=0
        while [ $i -lt 10 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        # Nur hart beenden, wenn er noch lebt UND es wirklich unser Dienst ist.
        if kill -0 "$P" 2>/dev/null && apc_ist_dienst "$P" "$APC_DIENST"; then
            kill -9 "$P" 2>/dev/null
        fi
        # Nur HIER gemeldet: eine liegengebliebene PID-Datei allein ist kein
        # laufender Dienst. Bis 1.2.8 stand die Zeile hinter dem schliessenden
        # fi und kam damit auch dann, wenn die Nummer in der Datei zu keinem
        # lebenden Vorgang gehoerte - das Protokoll behauptete dann etwas,
        # was nicht geschehen war.
        echo "<INFO> Laufender Dienst angehalten."
    elif [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        echo "<INFO> Die Nummer $P aus $PIDF gehoert einem fremden Vorgang -"
        echo "<INFO> es wurde nichts beendet, die Datei wird entfernt."
    else
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    fi
    rm -f "$PIDF"
fi

# Dazu jeder eigene Dienst OHNE PID-Datei. In WSL gemessen
# (Pruefung-Upgradeluecke-2026-09-17, Befund B5): fehlte die PID-Datei, lief
# der alte Dienst durch das ganze Upgrade weiter, und danach liefen zwei.
# Nur Python mit genau diesem Skript, nur der Benutzer des Dienstes - nie
# ein Teilwort systemweit.
WAISEN=$(apc_dienste_beenden "$APC_DIENST" "$APC_UID")
[ -n "$WAISEN" ] && echo "<INFO> Ein Dienst ohne PID-Datei lief und wurde beendet (PID $WAISEN)."


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
