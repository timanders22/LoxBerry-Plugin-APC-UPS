#!/bin/sh

# To use important variables from command line use the following code:
PDIR=$3       # Third argument is Plugin installation folder
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PCONFIG=$LBPCONFIG/$PDIR

echo "<INFO> Copy back existing config files"
# -p erhaelt Eigentuemer, Rechte und Zeitstempel. Ohne das gehoeren die
# zurueckgespielten Dateien danach root - LoxBerry fuehrt dieses Skript als
# root aus -, und die Weboberflaeche laeuft als loxberry. Der Nutzer koennte
# nach dem ersten Update keine Einstellungen mehr speichern und faende dafuer
# keine Erklaerung. Das chown danach faengt auch den Fall ab, dass die
# Sicherung selbst schon falsche Eigentuemer trug.
cp -p -v -r /tmp/${PDIR}.SAVE/* $PCONFIG/ 2>/dev/null
chown -R loxberry:loxberry "$PCONFIG" 2>/dev/null
rm -rf /tmp/${PDIR}.SAVE

# --- APC-UPS NG --------------------------------------------------------------
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# apcupsd muss laufen, sonst antwortet apcaccess nicht.
if command -v systemctl >/dev/null 2>&1; then
    systemctl enable apcupsd >/dev/null 2>&1
    systemctl start  apcupsd >/dev/null 2>&1
fi

# ISCONFIGURED steht bei Debian nach der Installation auf "no" - solange
# startet apcupsd nicht. Das ist die einzige Systemdatei, die dieses Plugin
# anfasst, und sie muss angefasst werden.
if [ -f /etc/default/apcupsd ]; then
    if grep -q "^ISCONFIGURED=no" /etc/default/apcupsd; then
        sed -i "s/^ISCONFIGURED=no/ISCONFIGURED=yes/" /etc/default/apcupsd
        echo "<INFO> ISCONFIGURED in /etc/default/apcupsd auf yes gesetzt."
        systemctl restart apcupsd >/dev/null 2>&1
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
if apcaccess status >/dev/null 2>&1; then
    echo "<OK> Die USV antwortet."
else
    echo "<INFO> Die USV antwortet noch nicht. Ist sie per USB angeschlossen?"
    echo "<INFO> Der Reiter Test zeigt, woran es liegt."
fi

echo "<INFO> Naechster Schritt: Reiter Test -> Jetzt abfragen."

exit 0
