#!/bin/sh

# To use important variables from command line use the following code:
# A6 (1.2.23): mit Vorgabe wie APC_PDIR unten.
PDIR="${3:-apc_ups_ng}"       # Third argument is Plugin installation folder

# ---------- Die Wurzel: gelesen, nicht geraten ----------
# Bis 1.2.12 war sie das fuenfte Argument (weiter unten: oder LBHOMEDIR),
# ohne Pruefung. Fehlten beide, lauteten die Pfade /data/plugins,
# /config/plugins, /bin/plugins ab der Laufwerkswurzel (in WSL gemessen,
# Pruefung-APC-UPS-1.2.13, Fall H4). Dieselbe Stelle wie in preupgrade.sh:
# $5, dann LBHOMEDIR, dann die Suche nach config/plugins, data/plugins UND
# config/system/general.json; ohne Wurzel <WARNING> und Rueckgabe 1.
apc_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ "$i" -lt 8 ]; do
        if [ -d "$v/config/plugins" ] && [ -d "$v/data/plugins" ] \
           && [ -f "$v/config/system/general.json" ]; then
            echo "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
APC_BASE="${5:-}"
if [ -z "$APC_BASE" ] || [ ! -d "$APC_BASE/config/plugins" ] || [ ! -d "$APC_BASE/data/plugins" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        APC_BASE="$LBHOMEDIR"
    else
        APC_BASE=$(apc_wurzel_suchen) || APC_BASE=""
    fi
fi
if [ -z "$APC_BASE" ]; then
    echo "<WARNING> Es wurde keine LoxBerry-Wurzel gefunden: weder als fuenftes Argument"
    echo "<WARNING> noch in \$LBHOMEDIR, und oberhalb dieses Skripts traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts zurueckgespielt und kein Dienst gestartet."
    exit 1
fi
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
LBPCONFIG="${LBPCONFIG:-$APC_BASE/config/plugins}"
LBPBIN="${LBPBIN:-$APC_BASE/bin/plugins}"
# sudo -n -u loxberry setzt die Umgebung zurueck - ohne diesen
# Rueckfall zeigte $LBPDATA ins Nichts und der Pfad auf /<ordner>.
LBPDATA="${LBPDATA:-$APC_BASE/data/plugins}"

PCONFIG="$LBPCONFIG/$PDIR"

echo "<INFO> Copy back existing config files"
# -p erhaelt Rechte und Zeitstempel. E2 (1.2.23, berichtigt): LoxBerry fuehrt
# dieses Skript als loxberry aus (plugininstall.pl, sudo -n -u loxberry beim
# postupgrade-Aufruf), nicht als root. Das chown danach wirkt nur, wenn das
# Skript ausnahmsweise als root laeuft (von Hand), und ist sonst wirkungslos,
# aber harmlos.
#
# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp: /tmp
# ist auf dem LoxBerry eine Ramdisk und ausserdem fuer jeden lesbar.
SICHER="$LBPDATA/$PDIR.upgrade_sicherung"
# Die Sicherung faellt erst, wenn jede ihrer Dateien byteweise in der
# Konfiguration steht. Bis 1.2.11 wurde der Rueckgabewert von cp nicht
# gelesen, die Sicherung danach ohne Bedingung geloescht und der Erfolg
# unbedingt gemeldet. In WSL gemessen (Pruefung-APC-UPS-1.2.12, Fall F7,
# Schreiben scheitert unter "ulimit -f 0"): die Sicherung war weg, die
# Konfiguration stand auf der Vorgabe, und das Protokoll meldete
# "<OK> Konfiguration zurueckgestellt."
# Traegt eine apc_ups_ng.cfg einen vollstaendigen Stand? Wortgleich mit
# preupgrade.sh und postinstall.sh: Kopf [apc_ups_ng] und formtoken mit 32
# Hexzeichen als Merkmal einer von der Oberflaeche ganz geschriebenen Datei.
apc_cfg_hat_inhalt() {
    [ -f "$1" ] && [ -r "$1" ] || return 1
    grep -qE '^\[apc_ups_ng\][[:space:]]*$' "$1" 2>/dev/null || return 1
    grep -qE '^formtoken=[0-9a-fA-F]{32}[[:space:]]*$' "$1" 2>/dev/null
}
# A5 (1.2.23): hat postinstall.sh eine kaputte Konfiguration eben aus der
# Zweitschrift geheilt, darf die Sicherung sie nicht wieder ueberschreiben, wenn
# die gesicherte apc_ups_ng.cfg selbst keinen vollstaendigen Stand traegt (in der
# Probe des Pruefberichts stand danach "themenpr|" in der cfg). Eine heile
# Sicherung gewinnt weiter.
if [ -f "$SICHER/apc_ups_ng.cfg" ] && ! apc_cfg_hat_inhalt "$SICHER/apc_ups_ng.cfg" \
   && apc_cfg_hat_inhalt "$PCONFIG/apc_ups_ng.cfg"; then
    rm -f "$SICHER/apc_ups_ng.cfg"
    echo "<INFO> Die gesicherte apc_ups_ng.cfg traegt keinen vollstaendigen Stand - die wiederhergestellte bleibt."
fi
if [ -d "$SICHER" ]; then
    if cp -p -r "$SICHER/." "$PCONFIG/" 2>/dev/null; then CP_RC=0; else CP_RC=$?; fi
    # A4 (1.2.23): cp -p uebernimmt die Rechte der Sicherung - eine 0644-Datei
    # aus 1.2.22 bliebe sonst 0644. Erst NACH dem Kopieren (chmod vor cp -p ist
    # wirkungslos).
    chmod 600 "$PCONFIG/apc_ups_ng.cfg" 2>/dev/null
    chown -R loxberry:loxberry "$PCONFIG" 2>/dev/null
    FEHLT=$( { cd "$SICHER" && find . -type f | while IFS= read -r f; do
                 cmp -s "$f" "$PCONFIG/$f" || printf '%s ' "${f#./}"
             done; } 2>/dev/null || echo "(Sicherung nicht lesbar)" )
    if [ "$CP_RC" -eq 0 ] && [ -z "$FEHLT" ]; then
        rm -rf "$SICHER"
        echo "<OK> Konfiguration zurueckgestellt."
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig zurueckstellen"
        echo "<WARNING> (cp Rueckgabewert $CP_RC; abweichend: ${FEHLT:-keine})."
        echo "<WARNING> Die Sicherung bleibt liegen und kann von Hand zurueckkopiert"
        echo "<WARNING> werden: $SICHER -> $PCONFIG"
    fi
else
    echo "<INFO> Keine Sicherung vorhanden - offenbar eine Erstinstallation."
fi

# ---------- E5 (1.2.23): endpunkt_ein=1 fuer bestehende Anlagen ----------
# Der Lese-Endpunkt (webfrontend/html/index.php) steht seit 1.2.23 hinter dem
# Schalter endpunkt_ein (Neuinstallation 0). Eine bestehende Anlage hatte ihn
# immer offen: fehlt der Schluessel in der zurueckgespielten Konfiguration, wird
# endpunkt_ein=1 unmittelbar vor formtoken= eingefuegt - in cfg UND Zweitschrift,
# mit Gegenprobe (eine Zeile mehr, formtoken gleich), 0600. Scheitert das, gilt die
# Lese-Regel (Schluessel fehlt in vorhandener cfg = 1): kein Unterschied im
# Verhalten. Hier und nicht in postinstall.sh, weil erst dieses Skript die
# Konfiguration zurueckspielt (Reihenfolge plugininstall.pl).
ap_endpunkt_ergaenzen() {
    f=$1
    [ -f "$f" ] || return 2
    grep -q '^endpunkt_ein=' "$f" 2>/dev/null && return 1
    apc_cfg_hat_inhalt "$f" || return 2
    n=$(wc -l < "$f")
    rm -f "$f.neu" 2>/dev/null
    ( umask 077; awk '/^formtoken=/ && !x { print "endpunkt_ein=1"; x = 1 } { print }' "$f" > "$f.neu" ) 2>/dev/null \
        || { rm -f "$f.neu" 2>/dev/null; return 3; }
    m=$(wc -l < "$f.neu")
    if [ "$m" -eq $((n + 1)) ] && grep -qx 'endpunkt_ein=1' "$f.neu" \
       && [ "$(grep '^formtoken=' "$f")" = "$(grep '^formtoken=' "$f.neu")" ]; then
        chmod 600 "$f.neu" 2>/dev/null
        mv -f "$f.neu" "$f" 2>/dev/null && return 0
    fi
    rm -f "$f.neu" 2>/dev/null
    return 3
}
AP_ZWEIT="$APC_BASE/config/plugins/${3:-apc_ups_ng}.backup.apc_ups_ng.cfg"
ap_endpunkt_ergaenzen "$PCONFIG/apc_ups_ng.cfg"; AP_EP=$?
if [ "$AP_EP" = "0" ]; then
    echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 (bestehende Anlage - der XML-Endpunkt fuer Loxone bleibt erreichbar; abschalten im Reiter Einbindung in Loxone)."
    if [ -f "$AP_ZWEIT" ]; then
        ap_endpunkt_ergaenzen "$AP_ZWEIT"; AP_EPZ=$?
        [ "$AP_EPZ" = "0" ] && echo "<INFO> Zweitschrift ebenso ergaenzt."
        [ "$AP_EPZ" = "3" ] && echo "<WARNING> Die Zweitschrift liess sich nicht ergaenzen - beim naechsten Speichern wird sie neu geschrieben."
    fi
elif [ "$AP_EP" = "3" ]; then
    echo "<WARNING> endpunkt_ein liess sich nicht in die Konfiguration schreiben - es gilt die Lese-Regel (fehlt der Schluessel, bleibt der Endpunkt offen)."
fi

# --- APC-UPS NG --------------------------------------------------------------
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# apcupsd muss laufen, sonst antwortet apcaccess nicht.
# E2 (1.2.23): ohne root wirkungslos - LoxBerry fuehrt dieses Skript als
# loxberry aus (plugininstall.pl, sudo -n -u loxberry). Der Installer
# installiert apcupsd ueber dpkg/apt, und die Unit startet von selbst. Die
# Zeilen bleiben fuer den Aufruf als root von Hand; Fehler gehen stumm weg.
if command -v systemctl >/dev/null 2>&1; then
    systemctl enable apcupsd >/dev/null 2>&1
    systemctl start  apcupsd >/dev/null 2>&1
fi

# ISCONFIGURED in /etc/default/apcupsd.
#
# ZWEI DINGE, beide am 06.09.2026 an einem laufenden LoxBerry gemessen:
#
# 1. Das sed SCHEITERT hier, und die Meldung darunter behauptete trotzdem
#    Erfolg. Im Installationsprotokoll stand woertlich:
#        sed: couldn't open temporary file /etc/default/sedWcSlVr: Permission denied
#        <INFO> ISCONFIGURED in /etc/default/apcupsd auf yes gesetzt.
#    Nachgemessen: die Datei trug danach unveraendert ISCONFIGURED=no und
#    das Datum der Paketinstallation. Eine stille Falschaussage - die
#    schlimmste Fehlerart, weil danach niemand mehr nachsieht.
#
# 2. ISCONFIGURED ist auf diesem System GAR NICHT MEHR MASSGEBLICH. Die
#    systemd-Unit (/usr/lib/systemd/system/apcupsd.service) liest
#    /etc/default/apcupsd nicht - sie hat keine EnvironmentFile-Zeile und
#    ruft prestart und apcupsd unmittelbar auf. Gemessen: ISCONFIGURED=no
#    UND "systemctl is-active apcupsd" = active. Nur das alte init-Skript
#    wertet den Schalter noch aus.
#
# Deshalb wird hier nichts mehr behauptet: es wird versucht, das Ergebnis
# GELESEN und der Zustand des Dienstes dazugesagt.
if [ -f /etc/default/apcupsd ]; then
    if grep -q "^ISCONFIGURED=no" /etc/default/apcupsd; then
        sed -i "s/^ISCONFIGURED=no/ISCONFIGURED=yes/" /etc/default/apcupsd 2>/dev/null
        if grep -q "^ISCONFIGURED=yes" /etc/default/apcupsd; then
            echo "<INFO> ISCONFIGURED in /etc/default/apcupsd auf yes gesetzt."
            systemctl restart apcupsd >/dev/null 2>&1
        else
            echo "<INFO> ISCONFIGURED in /etc/default/apcupsd steht auf no und liess"
            echo "<INFO> sich hier nicht aendern (keine Schreibrechte auf /etc/default)."
            echo "<INFO> Das ist auf einem System mit systemd ohne Bedeutung: die Unit"
            echo "<INFO> apcupsd.service liest diese Datei nicht. Nur wer apcupsd ueber"
            echo "<INFO> das alte init-Skript startet, muss den Wert von Hand setzen."
        fi
    else
        echo "<OK> /etc/default/apcupsd ist bereits eingerichtet."
    fi
fi

# Pruefen, ob die Bausteine da sind.
if command -v apcaccess >/dev/null 2>&1; then
    echo "<OK> apcaccess gefunden: $(command -v apcaccess)"
else
    # apcaccess ist das Herzstueck - ohne es liefert das Plugin nichts.
    # Trotzdem kein exit 1: apcupsd steht in dpkg/apt und wird von LoxBerry
    # vor diesem Skript installiert. Schlaegt das einmal fehl (Paketquelle
    # kurz nicht erreichbar), waere ein Abbruch der Installation die
    # unbequemere Antwort als ein Hinweis - nachinstallieren geht jederzeit,
    # eine zurueckgerollte Installation muss der Nutzer wiederholen.
    echo "<FAIL> apcaccess fehlt - das Plugin kann ohne apcupsd nichts liefern."
    echo "<FAIL> Nachinstallieren mit:  sudo apt-get install -y apcupsd"
    echo "<FAIL> Danach im Reiter Test auf 'Jetzt abfragen' druecken."
fi
if python3 -c "import paho.mqtt.client" >/dev/null 2>&1; then
    echo "<OK> Python-Modul paho.mqtt vorhanden."
else
    echo "<WARNING> paho-mqtt fehlt. Nachinstallieren: sudo apt-get install -y python3-paho-mqtt"
fi
# Antwortet die USV WIRKLICH?
#
# Hier stand nur "apcaccess status >/dev/null" - und das endet auch dann mit
# 0, wenn apcupsd die USV gar nicht erreicht: der Dienst antwortet dann
# bereitwillig mit STATUS: COMMLOST. Am 06.09.2026 meldete die Installation
# um 19:53:52 "<OK> Die USV antwortet.", waehrend apcupsd ab 19:54:52
# durchgehend "Communications with UPS lost" protokollierte und lsusb kein
# APC-Geraet zeigte. Die zweite stille Falschaussage desselben Skripts.
#
# Gemessen wird deshalb der STATUS, nicht der Rueckgabewert.
#
# Der Pfad wird aufgeloest, nicht angenommen: in einer Shell ohne /sbin und
# /usr/sbin endet ein blankes "apcaccess" mit Rueckgabewert 127, und der
# Zweig "kein STATUS" greift - eine richtige Meldung aus dem falschen Grund.
# Dieselbe Suche wie in bin/apc_common.py.
APCPROG=$(command -v apcaccess 2>/dev/null)
if [ -z "$APCPROG" ]; then
    for k in /sbin/apcaccess /usr/sbin/apcaccess /usr/bin/apcaccess; do
        [ -x "$k" ] && APCPROG="$k" && break
    done
fi
APCSTATUS=$("$APCPROG" status 2>/dev/null | sed -n 's/^STATUS *: *//p' | head -1 | sed 's/[[:space:]]*$//')
if [ -z "$APCPROG" ]; then
    echo "<INFO> apcaccess ist nicht auffindbar - der Zustand der USV bleibt offen."
elif [ -z "$APCSTATUS" ]; then
    echo "<INFO> apcaccess liefert keinen STATUS. Laeuft apcupsd?"
    echo "<INFO> Der Reiter Test zeigt, woran es liegt."
elif echo "$APCSTATUS" | grep -qE "COMMLOST|NOCOMM|COMMFAULT"; then
    echo "<INFO> apcupsd laeuft, erreicht die USV aber nicht (STATUS: $APCSTATUS)."
    echo "<INFO> Zu pruefen: USB-Kabel und 'lsusb'; in /etc/apcupsd/apcupsd.conf"
    echo "<INFO> gehoert bei UPSTYPE usb ein LEERES DEVICE (Debian liefert dort"
    echo "<INFO> /dev/ttyS0 vor). Der Reiter Test zeigt beides."
else
    echo "<OK> Die USV antwortet (STATUS: $APCSTATUS)."
fi

echo "<INFO> Naechster Schritt: Reiter Test -> Jetzt abfragen."

# ---------- Ende der Installation: Marke abraeumen, Dienst starten ----------
# preupgrade.sh hat die Marke "Aktualisierung laeuft" gelegt, damit der
# Waechter in der Luecke vor postinstall.sh nichts startet. Dieses Skript
# laeuft beim Upgrade als letztes (plugininstall.pl: postinstall, dann
# postupgrade) und hat die Konfiguration oben zurueckgestellt.
#
# Gestartet wird hier, weil sonst bis zu fuenf Minuten lang KEIN Dienst
# laeuft: in WSL gemessen (Pruefung-Upgradeluecke-2026-09-17, Fall ohne
# Takt) stand nach dem Upgrade kein Dienst, erst der naechste Takt startete
# ihn. Nach Regeln/06 laeuft nach einem Update wieder, was lief, und ein
# bewusst angehaltener Dienst bleibt angehalten. In diesem Plugin haelt der
# Anwender den Dienst ueber enabled=0 an; nur dann laesst der Waechter ihn
# stehen. Gestartet wird deshalb unter genau der Bedingung des Waechters -
# durch den Waechter selbst: er startet als loxberry, prueft die Wirkung und
# startet keinen zweiten. APC_BASE ist die Wurzel vom Anfang dieses Skripts.
APC_PDIR="${3:-apc_ups_ng}"
APC_NAME="${2:-apc_ups_ng}"
MARKE="$APC_BASE/data/plugins/$APC_PDIR.upgrade_laeuft"
APC_DIENST="$APC_BASE/bin/plugins/$APC_PDIR/apc_service.py"
WAECHTER="$APC_BASE/system/cron/cron.05min/$APC_NAME"

# Argumentweise (Regeln/03): argv[0] ein Python, argv[1] genau das Skript.
apc_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline" | {
        IFS= read -r a0 || exit 1
        IFS= read -r a1 || exit 1
        case "${a0##*/}" in python|python3|python3.*) ;; *) exit 1 ;; esac
        [ "$a1" = "$2" ]
    }
}
apc_dienste_suchen() {
    for d in /proc/[0-9]*; do
        [ "$(stat -c %u "$d" 2>/dev/null)" = "$2" ] || continue
        apc_ist_dienst "${d#/proc/}" "$1" && echo "${d#/proc/}"
    done
    return 0
}
APC_UID=$(id -u loxberry 2>/dev/null || id -u)

if [ -f "$MARKE" ]; then
    # Solange die Marke liegt, stammt jeder eigene Dienst aus der Zeit vor
    # oder waehrend der Installation - etwa ein Takt, der die Marke um
    # Sekunden verpasst hat - und laeuft mit dem alten Code und womoeglich
    # ohne PID-Datei. Er wird beendet, damit danach genau einer laeuft.
    ALT=$(apc_dienste_suchen "$APC_DIENST" "$APC_UID")
    if [ -n "$ALT" ]; then
        kill $ALT 2>/dev/null
        i=0
        while [ $i -lt 10 ] && [ -n "$(apc_dienste_suchen "$APC_DIENST" "$APC_UID")" ]; do
            sleep 1
            i=$((i + 1))
        done
        REST=$(apc_dienste_suchen "$APC_DIENST" "$APC_UID")
        [ -n "$REST" ] && kill -9 $REST 2>/dev/null
        echo "<INFO> Ein Dienst aus der Zeit der Installation lief noch und wurde beendet (PID $(echo $ALT))."
    fi
fi
rm -f "$MARKE"
if [ -e "$MARKE" ]; then
    echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen."
    echo "<WARNING> Der Waechter startet den Dienst erst, wenn sie eine Stunde alt ist."
fi

if grep -q '^enabled=0' "$PCONFIG/apc_ups_ng.cfg" 2>/dev/null; then
    echo "<INFO> Das Plugin ist ausgeschaltet (enabled=0) - der Dienst wird nicht gestartet."
elif [ -f "$APC_BASE/data/plugins/$APC_PDIR.angehalten" ]; then
    # Der Anhaltemerker liegt neben dem Datenordner und hat das Update
    # ueberstanden (Pruefung-APC-UPS-1.2.13, Fall S3).
    echo "<INFO> Der Dienst war mit dem Knopf Dienst anhalten angehalten und bleibt es."
    echo "<INFO> Dienst neu starten im Reiter Test startet ihn wieder."
elif [ -x "$WAECHTER" ]; then
    WAECHTER_AUS=$("$WAECHTER" 2>&1)
    [ -n "$WAECHTER_AUS" ] && echo "$WAECHTER_AUS" | sed 's/^/<INFO> /'
    # A14 (1.2.23): Name aus dem Ordner (fuer apc_ups_ng byte-gleich).
    PIDF="/run/shm/$APC_PDIR.pid"
    [ -f "$PIDF" ] || PIDF="$APC_BASE/log/plugins/$APC_PDIR/$APC_PDIR.pid"
    NEU=$(cat "$PIDF" 2>/dev/null)
    if [ -n "$NEU" ] && kill -0 "$NEU" 2>/dev/null && apc_ist_dienst "$NEU" "$APC_DIENST"; then
        echo "<OK> Der Dienst laeuft (PID $NEU)."
    else
        echo "<WARNING> Der Dienst laeuft nicht. Der Waechter versucht es alle fuenf Minuten;"
        echo "<WARNING> die Ursache steht im Reiter Logdateien."
    fi
else
    echo "<INFO> Der Waechter liegt nicht unter $WAECHTER - der Dienst wird nicht gestartet."
    echo "<INFO> Starten im Reiter Test."
fi

exit 0
