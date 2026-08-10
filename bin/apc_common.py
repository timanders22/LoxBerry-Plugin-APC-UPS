#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
APC-UPS NG - gemeinsame Grundlagen

Pfade, Konfiguration, Auswertung von apcaccess und die Umrechnung in
MQTT-Themen liegen hier, damit Dienst, Einmalabfrage und der alte
XML-Endpunkt dieselbe Sicht haben.

Grundlage ist das Plugin von Christian Woerstenfeld (Apache-Lizenz 2.0).
Der Messteil wurde fuer LoxBerry 4 neu geschrieben.
"""

import json
import os
import re
import shutil
import subprocess


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "apc_ups_ng"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = lb_wurzel_ermitteln() + "/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = lb_wurzel_ermitteln() + "/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
CONFIG_FILE = os.path.join(CONFIG_DIR, "apc_ups_ng.cfg")
def _zeitzone_setzen():
    """Zeitzone des Systems uebernehmen.

    Ohne sie protokolliert Python in UTC, waehrend die Oberflaeche Ortszeit
    zeigt - beim Vergleich zweier Zeitstempel sucht man den Fehler dann in
    der USV. Genommen wird, was eingestellt ist, nicht fest Europe/Berlin.
    """
    if os.environ.get("TZ"):
        return os.environ["TZ"]
    tz = ""
    try:
        with open("/etc/timezone", encoding="utf-8") as fh:
            tz = fh.read().strip()
    except OSError:
        tz = ""
    if tz:
        os.environ["TZ"] = tz
        try:
            time.tzset()
        except AttributeError:
            pass
    return tz


ZEITZONE = _zeitzone_setzen()

STATUS_FILE = "/run/shm/apc_ups_ng_status.json"
if not os.path.isdir("/run/shm"):
    STATUS_FILE = "/tmp/apc_ups_ng_status.json"

def version():
    """Fassung aus der Plugindatenbank von LoxBerry.

    Bewusst nicht fest eingetragen: eine Versionsnummer im Quelltext bleibt
    beim naechsten Release stehen und weicht dann von der plugin.cfg ab.
    Massgeblich ist, was LoxBerry bei der Installation uebernommen hat.

    Gibt "" zurueck, wenn sich die Fassung nicht ermitteln laesst - dann steht
    im Protokoll keine Nummer, was besser ist als eine falsche.
    """
    datei = os.path.join(HOME_DIR, "data", "system", "plugindatabase.json")
    try:
        with open(datei, "r", encoding="utf-8") as f:
            inhalt = json.load(f)
    except (OSError, ValueError):
        return ""
    liste = inhalt.get("plugins", inhalt) if isinstance(inhalt, dict) else inhalt
    if isinstance(liste, dict):
        liste = list(liste.values())
    if not isinstance(liste, list):
        return ""
    for eintrag in liste:
        if not isinstance(eintrag, dict):
            continue
        if eintrag.get("folder") == PLUGIN_NAME or eintrag.get("PLUGINDB_FOLDER") == PLUGIN_NAME:
            return str(eintrag.get("version")
                       or eintrag.get("PLUGINDB_VERSION") or "").strip()
    return ""

VORGABEN = {
    "enabled":        "1",
    "intervall":      "30",
    "aktualisierung": "300",
    "themenpraefix":  "apcups",
    "mqtt":           "1",
    "benachrichtigung": "1",
    "email":          "0",
    "email_an":       "root",
    "host":           "",      # leer = oertliche USV
}


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------

def konfiguration_lesen(pfad=None):
    """Konfiguration lesen. Rueckgabe: (werte, altesFormat)."""
    pfad = pfad or CONFIG_FILE
    werte = dict(VORGABEN)
    alt = False
    try:
        with open(pfad, "r", encoding="utf-8", errors="replace") as fh:
            zeilen = fh.read().splitlines()
    except OSError:
        return werte, alt

    for zeile in zeilen:
        t = zeile.strip()
        if not t or t[0] in ";#[":
            continue
        if "=" not in t:
            continue
        schluessel, wert = t.split("=", 1)
        klein = re.sub(r"^apc[_-]?ups\.", "", schluessel.strip(), flags=re.I).lower()
        wert = wert.strip().strip('"').strip("'")
        if klein in VORGABEN:
            werte[klein] = wert
        elif klein in ("loglevel", "sendmail", "mailto"):
            # Schluessel der Originalfassung - werden erkannt, damit die
            # Umstellung nicht stillschweigend etwas verliert.
            alt = True
    return werte, alt


def konfiguration_schreiben(werte, pfad=None):
    pfad = pfad or CONFIG_FILE
    try:
        os.makedirs(os.path.dirname(pfad), exist_ok=True)
    except OSError:
        pass
    zeilen = ["; APC-UPS NG", "; Geschrieben von der Plugin-Oberflaeche.", "", "[apc_ups_ng]"]
    for schluessel, vorgabe in VORGABEN.items():
        zeilen.append("{0}={1}".format(schluessel, werte.get(schluessel, vorgabe)))
    # Erst daneben schreiben, dann umbenennen.
    #
    # Ausgerechnet bei einem USV-Plugin ist der Stromausfall waehrend des
    # Schreibens kein theoretischer Fall - es ist der Fall, fuer den es das
    # Plugin gibt. Ein direktes open(..., "w") kuerzt die Datei sofort auf
    # null und fuellt sie erst danach; faellt der Strom dazwischen, ist die
    # Konfiguration weg. rename() ist auf demselben Dateisystem unteilbar:
    # der Leser sieht entweder die alte oder die neue Fassung.
    neben = pfad + ".neu"
    inhalt = "\n".join(zeilen) + "\n"
    try:
        with open(neben, "w", encoding="utf-8") as fh:
            fh.write(inhalt)
            fh.flush()
            os.fsync(fh.fileno())   # ohne fsync liegt der Inhalt nur im Puffer
        os.chmod(neben, 0o644)
        os.replace(neben, pfad)
        return True
    except OSError:
        try:
            os.unlink(neben)
        except OSError:
            pass
        return False


def zahl(werte, schluessel, vorgabe, typ=float):
    try:
        wert = werte.get(schluessel, "")
        if wert is None or str(wert).strip() == "":
            return typ(vorgabe)
        return typ(str(wert).strip())
    except (TypeError, ValueError):
        return typ(vorgabe)


# ---------------------------------------------------------------------------
# apcaccess
# ---------------------------------------------------------------------------

class UsvFehler(Exception):
    """apcaccess fehlt oder der Daemon antwortet nicht."""


def apcaccess_pfad():
    """Wo liegt apcaccess?

    Die Originalfassung rief fest '/sbin/apcaccess' auf. Je nach Debian-Fassung
    liegt das Programm aber unter /usr/sbin oder /usr/bin; ein fester Pfad geht
    dort ins Leere. Deshalb wird gesucht.
    """
    gefunden = shutil.which("apcaccess")
    if gefunden:
        return gefunden
    for kandidat in ("/sbin/apcaccess", "/usr/sbin/apcaccess", "/usr/bin/apcaccess"):
        if os.path.isfile(kandidat) and os.access(kandidat, os.X_OK):
            return kandidat
    return None


def rohdaten(host="", zeitgrenze=10):
    """apcaccess aufrufen und die Rohausgabe zurueckgeben."""
    prog = apcaccess_pfad()
    if not prog:
        raise UsvFehler(
            "apcaccess wurde nicht gefunden. Ist apcupsd installiert? "
            "Nachinstallieren mit: sudo apt-get install -y apcupsd")
    befehl = [prog, "status"]
    if host.strip():
        befehl.append(host.strip())
    try:
        lauf = subprocess.run(befehl, capture_output=True, text=True,
                              timeout=zeitgrenze)
    except subprocess.TimeoutExpired as fehler:
        raise UsvFehler(
            "apcaccess antwortete nicht innerhalb von {0} Sekunden.".format(
                zeitgrenze)) from fehler
    except OSError as fehler:
        raise UsvFehler("apcaccess liess sich nicht starten: {0}".format(fehler)) from fehler

    if lauf.returncode != 0:
        meldung = (lauf.stderr or lauf.stdout or "").strip()
        raise UsvFehler(
            "apcaccess meldet einen Fehler ({0}): {1}".format(
                lauf.returncode, meldung[:300] or "keine Ausgabe") +
            "\nLaeuft der Dienst apcupsd? Pruefen mit: systemctl status apcupsd")
    return lauf.stdout


def auswerten(text):
    """apcaccess-Ausgabe in ein Woerterbuch umwandeln.

    Format: SCHLUESSEL<Leerzeichen>: Wert. Werte tragen haeufig eine Einheit
    ('230.0 Volts'), die hier abgetrennt wird.
    """
    roh = {}
    for zeile in (text or "").splitlines():
        if ":" not in zeile:
            continue
        schluessel, _, wert = zeile.partition(":")
        schluessel = schluessel.strip().upper().replace(" ", "_")
        if schluessel:
            roh[schluessel] = wert.strip()
    return roh


EINHEITEN = ("Volts", "Percent", "Minutes", "Seconds", "Watts", "Hz", "C")


def wert_zahl(roh, schluessel):
    """Zahlenwert ohne Einheit. None, wenn nicht vorhanden oder keine Zahl."""
    wert = roh.get(schluessel)
    if wert is None:
        return None
    teil = str(wert).split()
    if not teil:
        return None
    try:
        return float(teil[0])
    except ValueError:
        return None


def wert_text(roh, schluessel, vorgabe=""):
    wert = roh.get(schluessel)
    return vorgabe if wert is None else str(wert).strip()


# Themen, die der Dienst veroeffentlicht.
# Aufbau: Thema -> (Beschreibung, Art)
def status_themen():
    return {
        "status":                ("Zustandstext der USV, z.&nbsp;B. ONLINE oder ONBATT", "text"),
        "on_line":               ("1 = Netzbetrieb", "digital"),
        "on_battery":            ("1 = die USV speist aus dem Akku", "digital"),
        "battery_charge":        ("Akkuladung in Prozent", "analog"),
        "time_left":             ("verbleibende Laufzeit in Minuten", "analog"),
        "line_voltage":          ("Netzspannung in Volt", "analog"),
        "load_percent":          ("Auslastung der USV in Prozent", "analog"),
        "load_watt":             ("gesch&auml;tzte Last in Watt (Nennleistung &times; Auslastung)", "analog"),
        "battery_voltage":       ("Akkuspannung in Volt", "analog"),
        "replace_battery":       ("1 = die USV meldet einen f&auml;lligen Akkutausch", "digital"),
        "time_on_battery":       ("Sekunden im laufenden Akkubetrieb", "analog"),
        "cumulative_on_battery": ("Sekunden im Akkubetrieb insgesamt", "analog"),
        "transfers":             ("Anzahl der Umschaltungen auf Akku", "analog"),
        "last_transfer":         ("Grund der letzten Umschaltung", "text"),
        "model":                 ("Modellbezeichnung", "text"),
        "serial":                ("Seriennummer", "text"),
        "battery_date":          ("Datum des letzten Akkutauschs", "text"),
        "valid":                 ("1 = die letzte Abfrage war brauchbar", "digital"),
        "service/online":        ("1 = der Dienst l&auml;uft", "digital"),
        "last_error":            ("letzte Fehlermeldung, sonst leer", "text"),
    }


# Zustaende, bei denen die USV nicht am Netz haengt
AKKU_ZUSTAENDE = ("ONBATT", "LOWBATT", "SHUTTING")


def messwerte(roh):
    """Aus den Rohdaten die Themenwerte berechnen."""
    status = wert_text(roh, "STATUS", "UNBEKANNT").upper()
    akku = any(z in status for z in AKKU_ZUSTAENDE)
    netz = "ONLINE" in status or "TRIM" in status or "BOOST" in status

    last = wert_zahl(roh, "LOADPCT")
    nenn = wert_zahl(roh, "NOMPOWER")
    watt = round(nenn * last / 100.0, 1) if (last is not None and nenn) else None

    # Ist die Verbindung zur USV tot, sind die Zahlen daneben wertlos.
    #
    # apcaccess liefert auch dann Rueckgabewert 0 und einen vollstaendig
    # aussehenden Datensatz, wenn das USB-Kabel gezogen ist - der Status heisst
    # dann COMMLOST, und Ladung, Last und Restlaufzeit stehen auf den zuletzt
    # bekannten oder auf 0. Wer nur den Rueckgabewert prueft, meldet Loxone
    # eine erreichbare USV mit 0 Prozent Last. Deshalb ein eigenes Feld:
    # der Miniserver soll unterscheiden koennen zwischen "die USV sagt 0" und
    # "wir wissen es nicht".
    verbindung_weg = any(z in status for z in ("COMMLOST", "NOCOMM", "COMMFAULT"))

    return {
        "status":                status,
        "data_valid":            0 if verbindung_weg else 1,
        "comm_lost":             1 if verbindung_weg else 0,
        "on_line":               1 if netz else 0,
        "on_battery":            1 if akku else 0,
        "battery_charge":        wert_zahl(roh, "BCHARGE"),
        "time_left":             wert_zahl(roh, "TIMELEFT"),
        "line_voltage":          wert_zahl(roh, "LINEV"),
        "load_percent":          last,
        "load_watt":             watt,
        "battery_voltage":       wert_zahl(roh, "BATTV"),
        "replace_battery":       1 if "REPLACEBATT" in status else 0,
        "time_on_battery":       wert_zahl(roh, "TONBATT"),
        "cumulative_on_battery": wert_zahl(roh, "CUMONBATT"),
        "transfers":             wert_zahl(roh, "NUMXFERS"),
        "last_transfer":         wert_text(roh, "LASTXFER"),
        "model":                 wert_text(roh, "MODEL"),
        "serial":                wert_text(roh, "SERIALNO"),
        "battery_date":          wert_text(roh, "BATTDATE"),
    }


def abfragen(cfg):
    """Einmal abfragen und auswerten.

    Rueckgabe: dict mit werte, roh, fehler.
    """
    try:
        text = rohdaten(cfg.get("host", ""))
    except UsvFehler as fehler:
        return {"werte": None, "roh": {}, "fehler": str(fehler)}
    roh = auswerten(text)
    if not roh:
        return {"werte": None, "roh": {},
                "fehler": "apcaccess lieferte keine auswertbaren Zeilen."}
    return {"werte": messwerte(roh), "roh": roh, "fehler": ""}


# ---------------------------------------------------------------------------
# LoxBerry-Umgebung
# ---------------------------------------------------------------------------

def mqtt_zugangsdaten():
    pfad = os.path.join(HOME_DIR, "config", "system", "general.json")
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return None
    for abschnitt in ("Mqtt", "mqtt"):
        block = daten.get(abschnitt)
        if not isinstance(block, dict):
            continue

        def hole(*namen):
            for n in namen:
                if block.get(n):
                    return block[n]
            return None

        host = hole("Brokerhost", "brokerhost")
        if not host:
            continue
        return {"host": str(host),
                "port": int(hole("Brokerport", "brokerport") or 1883),
                "user": hole("Brokeruser", "brokeruser"),
                "pass": hole("Brokerpass", "brokerpass")}
    return None


def benachrichtigen(text, schwere="warning"):
    """Meldung im LoxBerry-Benachrichtigungsbereich ablegen.

    Fuer Python gibt es keine LoxBerry-Schnittstelle dafuer. Deshalb wird das
    kleine Hilfsprogramm bin/apc_notify.php aufgerufen, das dieselbe Funktion
    notify_ext() benutzt wie die Originalfassung. Schlaegt es fehl, ist das
    kein Grund, den Dienst anzuhalten - die Werte gehen ohnehin per MQTT
    hinaus.
    """
    helfer = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                          "apc_notify.php")
    if not os.path.isfile(helfer):
        return False
    stufe = {"error": "3", "warning": "4", "info": "6"}.get(schwere, "4")
    try:
        # Den Pluginordner ausdruecklich mitgeben. Der Dienst wird ueber
        # su loxberry -c gestartet, und das raeumt die LoxBerry-Umgebung ab -
        # getenv('LBPPLUGINDIR') im Helfer waere leer. Der Rueckfall dort
        # traegt den festen Namen apc_ups_ng; wer das Plugin in einen anderen
        # Ordner installiert hat, faende seine Warnung dann unter einem
        # Paketnamen, den LoxBerry nicht kennt, und damit gar nicht.
        lauf = subprocess.run(["php", helfer, stufe, text, PLUGIN_NAME],
                              capture_output=True, text=True, timeout=15)
        return lauf.returncode == 0
    except (OSError, subprocess.SubprocessError):
        return False
