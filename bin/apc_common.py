#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
APC-UPS NG - gemeinsame Grundlagen

Pfade, Konfiguration, Auswertung von apcaccess und die Umrechnung in
MQTT-Themen liegen hier, damit Dienst, Einmalabfrage und der alte
XML-Endpunkt dieselbe Sicht haben.

Grundlage ist das Plugin von Christian Woerstenfeld (Apache-Lizenz 2.0).
Der Messteil wurde fuer LoxBerry 4 neu geschrieben.

==== Was sich mit 1.2.0 geaendert hat ====

1. ``import time`` fehlte, und ``_zeitzone_setzen()`` rief ``time.tzset()``.
   Der Aufruf stand in ``try: ... except AttributeError`` - ein ``NameError``
   laeuft dort NICHT hinein. Auf jedem System mit ``/etc/timezone`` und ohne
   gesetztes ``TZ`` - also auf jedem Debian - ist damit schon der IMPORT
   dieses Moduls gescheitert, und mit ihm der Dienst, die Einmalabfrage und
   der Knopf "Jetzt abfragen".
2. ``status_themen()`` ist entfallen. Die Funktion wurde von nichts
   aufgerufen und war gegenueber ihrer PHP-Zwillingsschwester veraltet
   (ihr fehlten ``data_valid`` und ``comm_lost``). Massgeblich ist jetzt
   ``apc_themen.json`` - EINE Datei, die beide Sprachen lesen.
3. ``EINHEITEN`` ist entfallen: die Konstante wurde nirgends benutzt. Die
   Einheiten stehen jetzt dort, wo sie hingehoeren - in ``apc_themen.json``,
   von wo sie als ``Unit`` in die Loxone-Vorlage wandern.
"""

import json
import os
import re
import shutil
import subprocess
import time


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

    ``time.tzset()`` gibt es nur auf Unix. Auf Windows fehlt das Attribut -
    deshalb die Abfrage mit hasattr davor. Bis 1.1.6 war ``time`` gar nicht
    importiert, und der ``NameError`` hat das ganze Modul mitgerissen; die
    Einzelheiten stehen im Dateikopf.
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
        if hasattr(time, "tzset"):
            time.tzset()
    return tz


ZEITZONE = _zeitzone_setzen()

STATUS_FILE = "/run/shm/apc_ups_ng_status.json"
if not os.path.isdir("/run/shm"):
    STATUS_FILE = "/tmp/apc_ups_ng_status.json"

# Die Ereignisliste liegt neben der Konfiguration, nicht in der Ramdisk unter
# log/: sie soll einen Neustart des Rechners ueberleben. Genau dann will
# jemand wissen, wann der Strom weg war.
EREIGNIS_FILE = os.path.join(CONFIG_DIR, "apc_ereignisse.json")
EREIGNIS_MAX = 200


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
    "enabled":          "1",
    "intervall":        "30",
    "aktualisierung":   "300",
    "themenpraefix":    "apcups",
    "mqtt":             "1",
    "benachrichtigung": "1",
    "email":            "0",
    "email_an":         "root",
    "host":             "",      # leer = oertliche USV
    # --- neu in 1.2.0 ---------------------------------------------------
    # Vorwarnung fuer alarm_level. Beide Werte wirken NUR auf die neue
    # Stufe 2; an den bisherigen Themen aendert sich dadurch nichts.
    "vorwarn_min":      "5",     # Minuten ueber MINTIMEL
    "vorwarn_prozent":  "10",    # Prozentpunkte ueber MBATTCHG
    # Zusaetzliche Rohfelder von apcaccess, kommagetrennt. Ab Werk LEER:
    # eine neue Funktion schaltet sich nicht selbst ein.
    "rohfelder":        "",
    # Obergrenze der Protokolldatei in Kilobyte. log/plugins liegt auf einer
    # Ramdisk - eine Datei, die niemand kappt, frisst dort Arbeitsspeicher.
    "log_kb":           "512",
    # Merkmal, das jedes Formular der Oberflaeche mitfuehrt. Es entsteht
    # dort beim ersten Aufruf. Hier steht es, damit es beim Schreiben aus
    # Python nicht verlorengeht - ein Handler, der die Konfiguration neu
    # baut, muss es ausdruecklich uebernehmen.
    "formtoken":        "",
}


# ---------------------------------------------------------------------------
# Themenliste - EINE Quelle fuer Python und PHP
# ---------------------------------------------------------------------------

def themen_datei():
    return os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        "apc_themen.json")


def themen():
    """Die Themenliste aus apc_themen.json.

    Fehlt die Datei, wird eine leere Liste zurueckgegeben - und der Aufrufer
    muss das merken, deshalb hat der Reiter Test dafuer eine eigene
    Pruefzeile. Stillschweigend eine Ersatzliste zu erfinden waere die
    schlechtere Antwort: dann liefe der Dienst mit anderen Themen als die
    Oberflaeche anzeigt, und niemand saehe es.
    """
    zwischen = getattr(themen, "_zwischenspeicher", None)
    if zwischen is not None:
        return zwischen
    liste = []
    try:
        with open(themen_datei(), "r", encoding="utf-8") as fh:
            daten = json.load(fh)
        roh = daten.get("themen")
        if isinstance(roh, list):
            liste = [t for t in roh if isinstance(t, dict) and t.get("schluessel")]
    except (OSError, ValueError):
        liste = []
    themen._zwischenspeicher = liste
    return liste


def themen_schluessel():
    return [t["schluessel"] for t in themen()]


def retain_themen():
    """Welche Themen dauerhaft im Broker liegen bleiben sollen.

    Retain ist fuer Zustaende gedacht, die auch ohne neue Nachricht gelten:
    Modell, Seriennummer, ob die USV am Netz haengt. Ein neu verbundener
    Abnehmer soll die sofort kennen und nicht erst auf den naechsten Takt
    warten.

    Fuer Messwerte ist Retain dagegen schaedlich: Restlaufzeit, Last und
    Akkuspannung aendern sich staendig, blieben aber als "letzter Wille" im
    Broker liegen. Verbindet sich ein Abnehmer nach einer Stunde neu, bekaeme
    er eine stundenalte Restlaufzeit serviert und hielte sie fuer aktuell -
    bei einer USV ist das die eine Zahl, auf die es ankommt.
    """
    return frozenset(t["schluessel"] for t in themen() if t.get("retain"))


# Themen, die nicht aus messwerte() kommen, sondern der Dienst selbst setzt.
DIENST_THEMEN = ("timestamp", "valid", "service/online", "event", "last_error")


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


def rohfelder_liste(cfg):
    """Die vom Nutzer gewuenschten Zusatzfelder. Rueckgabe: (gut, schlecht).

    Abweisen statt zurechtbiegen: was nicht wie ein apcaccess-Feldname
    aussieht, wird weggelassen UND zurueckgemeldet, damit die Oberflaeche es
    beanstanden kann. Stillschweigend Zeichen zu entfernen waere der Fehler,
    der in den Hausregeln unter "Eingaben nie hart filtern" steht.
    """
    roh = str(cfg.get("rohfelder", "") or "")
    gut, schlecht = [], []
    for stueck in re.split(r"[,;\s]+", roh):
        s = stueck.strip().upper()
        if not s:
            continue
        if re.match(r"^[A-Z][A-Z0-9_]{0,31}$", s):
            if s not in gut:
                gut.append(s)
        else:
            schlecht.append(stueck.strip())
    return gut, schlecht


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
    ('230.0 Volts'), die beim Lesen der Zahl abgetrennt wird.
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


# Zustaende, bei denen die USV nicht am Netz haengt
AKKU_ZUSTAENDE = ("ONBATT", "LOWBATT", "SHUTTING")


# Die Bits von STATFLAG.
#
# quelle: Quelltext von apcupsd (include/apc_defines.h)
# stand:  20.08.2026
# gemessen an dieser Anlage: NEIN
#
# Deshalb werden sie AUSSCHLIESSLICH zum Anzeigen und zum Gegenpruefen
# benutzt, nie um einen gemessenen Wert zu ueberschreiben. Waere die Tabelle
# falsch, faelschte sie sonst genau die Groessen, auf die es ankommt - und
# eine Zahl, die niemand gemessen hat, darf nicht aussehen wie eine, die
# jemand gemessen hat. Die Oberflaeche kennzeichnet sie als unbelegt.
STATFLAG_BITS = (
    (0x00000008, "TRIM"),
    (0x00000010, "BOOST"),
    (0x00000020, "ONLINE"),
    (0x00000040, "ONBATT"),
    (0x00000080, "OVERLOAD"),
    (0x00000100, "BATTLOW"),
    (0x00000200, "REPLACEBATT"),
    (0x00000400, "COMMLOST"),
    (0x00000800, "SHUTDOWN"),
    (0x00001000, "SLAVE"),
    (0x00002000, "SLAVEDOWN"),
    (0x00080000, "SHUT_BTIME"),
    (0x00100000, "SHUT_LTIME"),
    (0x00200000, "SHUT_EMERG"),
    (0x00800000, "PLUGGED"),
    (0x01000000, "BATTPRESENT"),
)


def statflag_bits(wert):
    """STATFLAG in seine Namen zerlegen. Leere Liste, wenn unlesbar."""
    text = str(wert or "").strip().split()
    if not text:
        return []
    stueck = text[0]
    if stueck[:2].lower() == "0x":
        stueck = stueck[2:]
    try:
        roh = int(stueck, 16)
    except ValueError:
        return []
    return [name for maske, name in STATFLAG_BITS if roh & maske]


def statflag_widerspruch(roh, werte):
    """Sagen Statustext und Statusbits dasselbe?

    Gibt eine Liste der Groessen zurueck, bei denen sie sich widersprechen -
    leer, wenn alles zusammenpasst oder wenn STATFLAG fehlt.

    Bewusst nur eine MELDUNG, keine Korrektur: die Bittabelle oben ist nicht
    an diesem Geraet gemessen. Wer den gemessenen Statustext durch eine
    ungemessene Bitmaske ersetzt, tauscht einen belegten Wert gegen einen
    geratenen.
    """
    bits = statflag_bits(roh.get("STATFLAG"))
    if not bits:
        return []
    aus = []
    paare = (
        ("on_battery", "ONBATT"),
        ("replace_battery", "REPLACEBATT"),
        ("comm_lost", "COMMLOST"),
    )
    for schluessel, name in paare:
        aus_text = 1 if werte.get(schluessel) else 0
        aus_bit = 1 if name in bits else 0
        if aus_text != aus_bit:
            aus.append(schluessel)
    return aus


def batteriealter_monate(text, jetzt=None):
    """Alter des Akkus in Monaten aus BATTDATE.

    apcupsd schreibt das Datum in dem Format, das in apcupsd.conf unter DATE
    eingestellt ist - das ist NICHT einheitlich. Geraten wird hier nichts:

      2019-03-15   eindeutig, wird genommen
      15/03/19     erstes Feld > 12, also Tag zuerst
      03/15/19     zweites Feld > 12, also Monat zuerst
      05/06/19     zweideutig - es wird KEIN Wert gebildet

    Ein falsches Akkualter ist schlimmer als keines: es stuende dann als Zahl
    in Loxone und saehe richtig aus.
    """
    text = str(text or "").strip()
    if not text or text.upper() in ("N/A", "NA", "NONE", "UNKNOWN"):
        return None
    jetzt = time.localtime(jetzt) if jetzt is not None else time.localtime()

    m = re.match(r"^(\d{4})-(\d{1,2})-(\d{1,2})$", text)
    if m:
        jahr, monat = int(m.group(1)), int(m.group(2))
    else:
        m = re.match(r"^(\d{1,2})[/.](\d{1,2})[/.](\d{2,4})$", text)
        if not m:
            return None
        a, b, c = int(m.group(1)), int(m.group(2)), int(m.group(3))
        if a > 12 and b <= 12:
            monat = b                      # Tag zuerst
        elif b > 12 and a <= 12:
            monat = a                      # Monat zuerst
        else:
            return None                    # zweideutig - kein Wert
        jahr = c if c > 100 else (2000 + c if c < 70 else 1900 + c)

    if not 1 <= monat <= 12 or not 1970 <= jahr <= jetzt.tm_year + 1:
        return None
    monate = (jetzt.tm_year - jahr) * 12 + (jetzt.tm_mon - monat)
    return monate if monate >= 0 else None


def _alarmstufe(cfg, verbindung_weg, akku, netz, ladung, rest,
                ab_ladung, ab_minuten):
    """Gestufte Warnung fuer Loxone.

    Rueckgabe: (stufe, abschaltung_steht_bevor)

      0  Netzbetrieb, alles ruhig
      1  Akkubetrieb, aber noch weit von der Abschaltung entfernt
      2  Vorwarnung - die Abschaltschwelle ist in Sicht
      3  apcupsd faehrt jetzt herunter

    Die Schwellen kommen aus apcupsd selbst (MBATTCHG, MINTIMEL) und werden
    NICHT geraten. Liefert die USV sie nicht, kann keine der beiden oberen
    Stufen entstehen und es bleibt bei 1 - eine erfundene Schwelle waere
    schlimmer als keine Stufung, weil Loxone dann zu frueh oder gar nicht
    abwirft. Damit das niemand fuer "alles in Ordnung" haelt, beantwortet der
    Reiter Test die Frage ausdruecklich, und ``schwellen_bekannt()`` sagt es
    der Oberflaeche.

    Bei abgerissener Verbindung gibt es KEINEN Wert. Eine 0 hiesse dort
    "alles ruhig", und genau das weiss niemand.
    """
    # Reines "COMMLOST" faengt schon der dritte Zweig ab (weder Netz noch
    # Akku erkennbar). Diese erste Sperre traegt den zusammengesetzten Fall,
    # etwa "ONLINE COMMLOST": dort waere netz wahr, und ohne sie kaeme
    # Stufe 0 heraus - "alles ruhig" bei toter Verbindung. Ob apcupsd diese
    # Kombination wirklich schreibt, ist an dieser Anlage NICHT gemessen;
    # die Sperre kostet nichts und der Schaden waere eine stille
    # Falschaussage. Geeicht wird sie mit genau diesem Statustext.
    if verbindung_weg:
        return None, None
    if not akku and netz:
        return 0, 0
    if not akku:
        # Weder eindeutig Netz noch eindeutig Akku - kein Urteil.
        return None, None

    vor_min = zahl(cfg, "vorwarn_min", 5, float)
    vor_pro = zahl(cfg, "vorwarn_prozent", 10, float)

    # Kein eigener Zweig fuer "Schwelle unbekannt": sind beide Paare nicht
    # zu haben, bleiben erreicht und nahe auf False, und das Ergebnis ist
    # ohnehin Stufe 1. Ein zusaetzliches "if not kennt_schwelle: return 1, 0"
    # stand hier zunaechst - die Gegenprobe hat gezeigt, dass sein Wegfall
    # das Verhalten nicht aendert. Ein Zweig, den kein Fall erreicht, ist
    # eine falsche Faehrte fuer den naechsten Umbau.
    erreicht = False
    nahe = False

    if ab_ladung is not None and ladung is not None:
        if ladung <= ab_ladung:
            erreicht = True
        elif ladung <= ab_ladung + vor_pro:
            nahe = True
    if ab_minuten is not None and rest is not None:
        if rest <= ab_minuten:
            erreicht = True
        elif rest <= ab_minuten + vor_min:
            nahe = True

    if erreicht:
        return 3, 1
    return (2, 0) if nahe else (1, 0)


def schwellen_bekannt(werte):
    """Kennt das Plugin die Abschaltschwellen der USV?

    Solange nicht mindestens ein Paar vollstaendig ist, kann alarm_level nie
    ueber 1 hinausgehen. Das muss sichtbar sein, sonst haelt jemand die
    dauerhafte 1 fuer "es wird schon nicht eng werden".
    """
    if not isinstance(werte, dict):
        return False
    ladung = werte.get("shutdown_charge") is not None and werte.get("battery_charge") is not None
    zeit = werte.get("shutdown_minutes") is not None and werte.get("time_left") is not None
    return bool(ladung or zeit)


def messwerte(roh, cfg=None):
    """Aus den Rohdaten die Themenwerte berechnen."""
    cfg = cfg or {}
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

    ladung = wert_zahl(roh, "BCHARGE")
    rest = wert_zahl(roh, "TIMELEFT")
    ab_ladung = wert_zahl(roh, "MBATTCHG")
    ab_minuten = wert_zahl(roh, "MINTIMEL")

    stufe, bevor = _alarmstufe(cfg, verbindung_weg, akku, netz,
                               ladung, rest, ab_ladung, ab_minuten)

    return {
        "status":                status,
        "data_valid":            0 if verbindung_weg else 1,
        "comm_lost":             1 if verbindung_weg else 0,
        "on_line":               1 if netz else 0,
        "on_battery":            1 if akku else 0,
        "alarm_level":           stufe,
        "shutdown_pending":      bevor,
        "battery_charge":        ladung,
        "time_left":             rest,
        "line_voltage":          wert_zahl(roh, "LINEV"),
        "output_voltage":        wert_zahl(roh, "OUTPUTV"),
        "line_frequency":        wert_zahl(roh, "LINEFREQ"),
        "load_percent":          last,
        "load_watt":             watt,
        "nominal_power":         nenn,
        "battery_voltage":       wert_zahl(roh, "BATTV"),
        "nominal_battery_volt":  wert_zahl(roh, "NOMBATTV"),
        "internal_temp":         wert_zahl(roh, "ITEMP"),
        "replace_battery":       1 if "REPLACEBATT" in status else 0,
        "battery_age_months":    batteriealter_monate(wert_text(roh, "BATTDATE")),
        "self_test_result":      wert_text(roh, "SELFTEST"),
        "self_test_interval":    wert_text(roh, "STESTI"),
        "time_on_battery":       wert_zahl(roh, "TONBATT"),
        "cumulative_on_battery": wert_zahl(roh, "CUMONBATT"),
        "transfers":             wert_zahl(roh, "NUMXFERS"),
        "last_transfer":         wert_text(roh, "LASTXFER"),
        "shutdown_charge":       ab_ladung,
        "shutdown_minutes":      ab_minuten,
        "shutdown_timeout":      wert_zahl(roh, "MAXTIME"),
        "status_flag":           wert_text(roh, "STATFLAG"),
        "model":                 wert_text(roh, "MODEL"),
        "serial":                wert_text(roh, "SERIALNO"),
        "battery_date":          wert_text(roh, "BATTDATE"),
    }


def abfragen(cfg):
    """Einmal abfragen und auswerten.

    Rueckgabe: dict mit werte, roh, zusatz, fehler.
    """
    cfg = cfg or {}
    try:
        text = rohdaten(cfg.get("host", ""))
    except UsvFehler as fehler:
        return {"werte": None, "roh": {}, "zusatz": {}, "fehler": str(fehler)}
    roh = auswerten(text)
    if not roh:
        return {"werte": None, "roh": {}, "zusatz": {},
                "fehler": "apcaccess lieferte keine auswertbaren Zeilen."}
    gewuenscht, _ = rohfelder_liste(cfg)
    zusatz = {f: roh[f] for f in gewuenscht if f in roh}
    return {"werte": messwerte(roh, cfg), "roh": roh, "zusatz": zusatz,
            "fehler": ""}


# ---------------------------------------------------------------------------
# Ereignisliste
# ---------------------------------------------------------------------------

def ereignisse_lesen(pfad=None):
    pfad = pfad or EREIGNIS_FILE
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return []
    return daten if isinstance(daten, list) else []


def ereignis_anhaengen(zeit, titel, text, pfad=None):
    """Ein Ereignis vorne anfuegen und die Liste kappen.

    Bis 1.1.6 gab es gar keine Historie: der Dienst schrieb eine Logzeile,
    schickte einen Kurztext auf 'event' und vergass ihn. Nach einem Neustart
    des Miniservers war nicht mehr feststellbar, WANN der letzte Ausfall war.
    """
    pfad = pfad or EREIGNIS_FILE
    liste = ereignisse_lesen(pfad)
    liste.insert(0, {"zeit": int(zeit), "titel": titel, "text": text})
    liste = liste[:EREIGNIS_MAX]
    neben = pfad + ".neu"
    try:
        os.makedirs(os.path.dirname(pfad), exist_ok=True)
        with open(neben, "w", encoding="utf-8") as fh:
            json.dump(liste, fh, ensure_ascii=False)
        os.replace(neben, pfad)
        return True
    except OSError:
        try:
            os.unlink(neben)
        except OSError:
            pass
        return False


# ---------------------------------------------------------------------------
# Protokoll kappen
# ---------------------------------------------------------------------------

def log_kappen(pfad, grenze_kb=512):
    """Die Protokolldatei auf die halbe Grenze zurueckschneiden.

    log/plugins liegt auf einer Ramdisk. Eine Datei, die niemand kappt,
    frisst dort Arbeitsspeicher - und der Dienst schreibt bei 30 Sekunden
    Takt rund 2900 Zeilen am Tag.

    Geschnitten wird an einer Zeilengrenze und ueber eine Nebendatei, damit
    ein Stromausfall mitten im Kappen keine halbe Zeile hinterlaesst.
    """
    try:
        grenze = max(32, int(grenze_kb)) * 1024
        if not os.path.isfile(pfad) or os.path.getsize(pfad) <= grenze:
            return False
        with open(pfad, "rb") as fh:
            fh.seek(-(grenze // 2), os.SEEK_END)
            rest = fh.read()
        schnitt = rest.find(b"\n")
        if schnitt >= 0:
            rest = rest[schnitt + 1:]
        neben = pfad + ".neu"
        with open(neben, "wb") as fh:
            fh.write(b"--- gekappt, aeltere Zeilen entfernt ---\n")
            fh.write(rest)
        os.replace(neben, pfad)
        return True
    except OSError:
        return False


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
