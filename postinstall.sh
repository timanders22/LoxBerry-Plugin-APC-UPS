#!/bin/sh

# To use important variables from command line use the following code:
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PLOG=$LBPLOG/$PDIR

# -p: der Ordner kann schon da sein (Upgrade, oder log/plugins wurde
# aus den Skeletten vorbelegt). Ohne -p schreibt der Installer bei
# JEDEM Upgrade 'mkdir: cannot create directory ... File exists' ins
# Protokoll - am 06.09.2026 im Installationsprotokoll gesehen.
mkdir -p $PLOG
touch $PLOG/$PSHNAME.log
chown loxberry:loxberry $PLOG/$PSHNAME.log

# --- APC-UPS NG --------------------------------------------------------------
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# apcupsd muss laufen, sonst antwortet apcaccess nicht.
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


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-apc_ups_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "apc_ups_ng.cfg" "db6b2b24b51a7b599d77f56ea00e03a765b25fad706bb220f2f21a3a20efeda2"

exit 0
