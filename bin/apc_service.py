#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
APC-UPS NG - Ueberwachungsdienst

Fragt in einstellbarem Takt apcaccess ab und veroeffentlicht die Werte per
MQTT. Wechselt die USV zwischen Netz- und Akkubetrieb, legt der Dienst
zusaetzlich eine Meldung in den LoxBerry-Benachrichtigungsbereich und
verschickt auf Wunsch eine E-Mail.

Grundlage ist das Plugin von Christian Woerstenfeld (Apache-Lizenz 2.0).
Neu geschrieben fuer LoxBerry 4:

  * Durchlaufender Dienst mit MQTT statt einer Seite, die auf Zuruf
    apcaccess aufruft.
  * apcaccess wird gesucht statt fest unter /sbin erwartet.
  * Ereignisse (Akkubetrieb, Netz zurueck, Kommunikationsverlust) werden im
    Dienst erkannt, nicht mehr ueber Ereignisskripte von apcupsd.

==== Was sich mit 1.2.0 geaendert hat ====

1. **Jede Protokollzeile stand doppelt in der Datei.** Der FileHandler
   schrieb nach ``log/plugins/<ordner>/apc_ups_ng.log``, ein StreamHandler
   zusaetzlich auf stdout - und sowohl ``daemon/daemon`` als auch
   ``ap_dienst()`` leiten stdout mit ``>>`` in genau dieselbe Datei. Der
   StreamHandler ist entfallen; wer das Skript von Hand aufruft, bekommt
   die Ausgabe weiterhin, weil das Startskript umleitet.
2. **Die Protokolldatei wurde nie gekappt.** ``log/plugins`` liegt auf einer
   Ramdisk, und bei 30 Sekunden Takt entstehen rund 2900 Zeilen am Tag.
3. **MQTT versuchte es genau einmal.** Schlug ``connect()`` beim Start fehl -
   der Normalfall beim Hochfahren, wenn der Broker noch nicht antwortet -,
   blieb MQTT bis zum naechsten Klick auf "Speichern" aus. Jetzt wird die
   Verbindung im laufenden Betrieb nachgeholt.
4. **Das erste Ereignis nach einem Dienstneustart fiel unter den Tisch.**
   ``ereignis()`` kehrte bei ``alt is None`` sofort zurueck. Wer den Dienst
   waehrend eines Stromausfalls neu startete, bekam keine Meldung.
5. **Es gab keine Ereignishistorie.** Jetzt fuehrt der Dienst eine Liste
   neben der Konfiguration.
6. **Ein Zeitstempel geht mit hinaus.** MQTT ist ein Push-Weg: dort gibt es
   kein "Alter", sondern einen Zeitstempel. Ohne ihn kann der Miniserver
   einen retained Wert nicht von einem frischen unterscheiden.
"""

import atexit
import fcntl
import json
import logging
import os
import signal
import socket
import subprocess
import sys
import threading
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import apc_common as gem   # noqa: E402

# Nur in die Datei protokollieren.
#
# Ein zweiter Kanal auf stdout schriebe jede Zeile ein zweites Mal in
# dieselbe Datei, weil das Startskript stdout dorthin umleitet. Scheitert
# das Anlegen der Datei, bleibt stderr - dort landet die Zeile dann ueber
# die Umleitung des Startskripts trotzdem im Protokoll.
_handlers = []
try:
    os.makedirs(gem.LOG_DIR, exist_ok=True)
    _handlers.append(logging.FileHandler(os.path.join(gem.LOG_DIR, "apc_ups_ng.log")))
except OSError:
    _handlers.append(logging.StreamHandler(sys.stderr))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=_handlers,
)
log = logging.getLogger("apc_ups_ng")

LOG_DATEI = os.path.join(gem.LOG_DIR, "apc_ups_ng.log")

PID_DATEI = os.path.join(gem.LOG_DIR if os.path.isdir("/run/shm") is False else "/run/shm",
                         "apc_ups_ng.pid")
_sperre_fh = None


def einmal_starten():
    """Nur eine Fassung des Dienstes zulassen.

    Ohne Sperre konnte die Oberflaeche beliebig viele starten: ap_dienst()
    rief nohup auf, ohne vorher nachzusehen. Zwei schnelle Klicks auf
    Speichern - jeder loest einen Neustart aus - und es liefen zwei Dienste,
    die dieselbe USV abfragten und sich beim Schreiben nach MQTT gegenseitig
    ueberholten.

    Die Sperre haengt an der offenen Datei, nicht an ihrem Inhalt: stirbt der
    Prozess, gibt das Betriebssystem sie frei. Eine liegengebliebene
    PID-Datei blockiert also nichts.
    """
    global _sperre_fh
    try:
        os.makedirs(os.path.dirname(PID_DATEI), exist_ok=True)
        _sperre_fh = open(PID_DATEI, "a+", encoding="utf-8")
        # BlockingIOError ist eine Unterklasse von OSError - ein eigener
        # except-Zweig dafuer stand hier und war unerreichbar.
        fcntl.flock(_sperre_fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return False
    _sperre_fh.seek(0)
    _sperre_fh.truncate()
    _sperre_fh.write(str(os.getpid()))
    _sperre_fh.flush()
    atexit.register(_sperre_freigeben)
    return True


def _sperre_freigeben():
    global _sperre_fh
    if _sperre_fh is None:
        return
    try:
        fcntl.flock(_sperre_fh, fcntl.LOCK_UN)
        _sperre_fh.close()
    except OSError:
        pass
    try:
        os.unlink(PID_DATEI)
    except OSError:
        pass
    _sperre_fh = None


class Mqtt:
    """Duenne Huelle um paho-mqtt.

    Faellt still aus, wenn Bibliothek oder Gateway fehlen - der Dienst
    protokolliert dann weiter. Eine gescheiterte Verbindung wird aber im
    laufenden Betrieb nachgeholt: bis 1.1.6 wurde genau einmal versucht,
    und beim Hochfahren des Rechners ist der Broker haeufig noch nicht so
    weit. MQTT blieb dann bis zum naechsten Speichern aus.
    """

    # Erst nach einer Minute wieder versuchen, dann immer seltener, hoechstens
    # alle fuenf Minuten. Ein Dienst, der im Sekundentakt gegen einen toten
    # Broker laeuft, fuellt nur das Protokoll.
    WARTE_MIN = 60
    WARTE_MAX = 300

    def __init__(self, praefix):
        self.praefix = praefix
        self.client = None
        self.verbunden = False
        self.naechster_versuch = 0
        self.warte = self.WARTE_MIN
        self.retain = gem.retain_themen()

    def start(self, leise=False):
        """Verbindung aufbauen. Rueckgabe: True, wenn sie steht."""
        try:
            import paho.mqtt.client as mqtt
        except ImportError:
            if not leise:
                log.error("paho-mqtt fehlt - MQTT bleibt aus. "
                          "Paket python3-paho-mqtt nachinstallieren.")
            return False
        zugang = gem.mqtt_zugangsdaten()
        if not zugang:
            if not leise:
                log.warning("Kein MQTT-Broker in general.json gefunden")
            return False
        try:
            client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)
        except (AttributeError, TypeError):
            client = mqtt.Client()      # paho-mqtt 1.x
        if zugang["user"]:
            client.username_pw_set(zugang["user"], zugang["pass"] or "")
        client.will_set(self.praefix + "/service/online", "0", retain=True)
        try:
            client.connect(zugang["host"], zugang["port"], keepalive=60)
        except OSError as fehler:
            if not leise:
                log.error("MQTT-Broker %s:%s nicht erreichbar: %s",
                          zugang["host"], zugang["port"], fehler)
            return False
        self.client = client
        self.client.loop_start()
        self.verbunden = True
        self.warte = self.WARTE_MIN
        log.info("MQTT verbunden mit %s:%s, Themenpraefix %s",
                 zugang["host"], zugang["port"], self.praefix)
        self.senden("service/online", "1")
        return True

    def nachfassen(self):
        """Steht die Verbindung nicht, es spaeter noch einmal versuchen.

        Rueckgabe: True, wenn in diesem Anlauf eine Verbindung entstanden
        ist - dann muss der Aufrufer alle Werte neu senden, weil der Broker
        nichts von ihnen weiss.
        """
        if self.verbunden:
            return False
        jetzt = time.time()
        if jetzt < self.naechster_versuch:
            return False
        # Erst den Zeitpunkt setzen, dann versuchen: schlaegt der Versuch mit
        # einer Ausnahme fehl, wird trotzdem nicht sofort wieder gerannt.
        self.naechster_versuch = jetzt + self.warte
        self.warte = min(self.WARTE_MAX, self.warte * 2)
        if self.start(leise=True):
            log.info("MQTT-Verbindung nachgeholt.")
            return True
        return False

    def senden(self, unterthema, wert):
        if not self.client:
            return
        try:
            self.client.publish(self.praefix + "/" + unterthema,
                                "" if wert is None else str(wert),
                                qos=0, retain=(unterthema in self.retain))
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veroeffentlichung fehlgeschlagen: %s", fehler)
            self.verbunden = False

    def stop(self):
        if not self.client:
            return
        try:
            self.senden("service/online", "0")
            self.client.loop_stop()
            self.client.disconnect()
        except Exception:  # noqa: BLE001
            pass
        self.verbunden = False


def email_senden(betreff, text, empfaenger="root"):
    """E-Mail ueber den oertlichen sendmail verschicken.

    Der Weg der Originalfassung. Ist kein Mailversand eingerichtet, schlaegt
    das still fehl - deshalb die Rueckmeldung, damit es im Protokoll steht.
    """
    for prog in ("/usr/sbin/sendmail", "/usr/lib/sendmail"):
        if os.path.isfile(prog):
            break
    else:
        return False, "sendmail ist auf diesem System nicht vorhanden"
    empfaenger = (empfaenger or "root").strip() or "root"
    # Ohne From-Kopfzeile weisen die meisten Mailserver und Spamfilter die
    # Nachricht rundheraus ab. Der Rechnername reicht als Absender.
    absender = "loxberry@" + (socket.gethostname() or "loxberry")
    nachricht = ("From: {0}\nTo: {1}\nSubject: {2}\n"
                 "Content-Type: text/plain; charset=UTF-8\n\n{3}\n"
                 .format(absender, empfaenger, betreff, text))
    try:
        # Zehn statt dreissig Sekunden: der Aufruf laeuft zwar in einem
        # Nebenlauf, aber ein haengender Mailserver soll nicht minutenlang
        # einen Faden binden.
        lauf = subprocess.run([prog, "-t"], input=nachricht, text=True,
                              capture_output=True, timeout=10)
    except (OSError, subprocess.SubprocessError) as fehler:
        return False, str(fehler)
    if lauf.returncode != 0:
        return False, (lauf.stderr or "").strip()[:200]
    return True, empfaenger


def email_im_hintergrund(betreff, text, empfaenger="root"):
    """E-Mail verschicken, ohne die Hauptschleife anzuhalten.

    sendmail lief bisher im Hauptfaden. Haengt der Mailserver, stand der
    ganze Dienst - und weil in dieser Zeit auch die MQTT-Bibliothek nicht
    zum Zug kommt, riss der Broker die Verbindung wegen ausbleibendem
    Lebenszeichen ab. Ausgerechnet bei einem Stromausfall, also genau dann,
    wenn die Meldung wichtig ist.
    """
    def arbeit():
        ok, info = email_senden(betreff, text, empfaenger)
        if ok:
            log.info("E-Mail an %s verschickt.", info)
        else:
            log.warning("E-Mail nicht verschickt: %s", info)

    threading.Thread(target=arbeit, name="apc-mail", daemon=True).start()


class Dienst:
    def __init__(self):
        self.cfg, alt = gem.konfiguration_lesen()
        if alt:
            log.info("Konfiguration im alten Format erkannt - wird uebernommen "
                     "und beim naechsten Speichern neu geschrieben")
        self.praefix = self.cfg.get("themenpraefix") or "apcups"
        self.mqtt = Mqtt(self.praefix)
        self.laeuft = True
        self.letzter_stand = {}
        self.letzter_status = None
        self.erster_durchgang = True
        self.config_mtime = self._mtime()
        self._gemeldet = {}
        self._letzte_kappung = 0

    def _mtime(self):
        try:
            return os.path.getmtime(gem.CONFIG_FILE)
        except OSError:
            return 0

    def _einmal(self, schluessel, text, stufe="error", wieder_nach=3600):
        """Dieselbe Meldung nicht bei jedem Durchgang wiederholen.

        Eine fehlende USV bleibt fehlend. Ohne diese Bremse schriebe der
        Dienst bei 30 Sekunden Takt 2880 gleichlautende Zeilen am Tag.
        """
        jetzt = time.time()
        alt = self._gemeldet.get(schluessel)
        if alt and alt[0] == text and (jetzt - alt[1]) < wieder_nach:
            return False
        self._gemeldet[schluessel] = (text, jetzt)
        getattr(log, stufe)("%s", text)
        return True

    def _senden(self, thema, wert, erzwingen=False):
        wert = "" if wert is None else str(wert)
        if not erzwingen and self.letzter_stand.get(thema) == wert:
            return False
        self.letzter_stand[thema] = wert
        self.mqtt.senden(thema, wert)
        return True

    def zustand_schreiben(self, daten):
        try:
            temp = gem.STATUS_FILE + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(daten, fh, ensure_ascii=False)
            os.replace(temp, gem.STATUS_FILE)
            os.chmod(gem.STATUS_FILE, 0o644)
        except OSError as fehler:
            log.warning("Zustandsdatei nicht schreibbar: %s", fehler)

    def _melden(self, titel, text, schwere):
        """Ereignis protokollieren, ablegen, senden und weiterreichen."""
        log.warning("%s - %s", titel, text)
        gem.ereignis_anhaengen(time.time(), titel, text)
        self.mqtt.senden("event", titel)

        if self.cfg.get("benachrichtigung", "1") == "1":
            if not gem.benachrichtigen("{0}: {1}".format(titel, text), schwere):
                log.info("LoxBerry-Benachrichtigung nicht moeglich")
        if self.cfg.get("email", "0") == "1":
            # Im Nebenlauf: ein haengender Mailserver darf die Hauptschleife
            # nicht anhalten - sonst reisst waehrend eines Stromausfalls die
            # MQTT-Verbindung ab, weil das Lebenszeichen ausbleibt.
            email_im_hintergrund("APC-UPS NG: " + titel, text,
                                 self.cfg.get("email_an", "root"))

    @staticmethod
    def _zusatz(werte):
        ladung = werte.get("battery_charge")
        rest = werte.get("time_left")
        zusatz = ""
        if ladung is not None:
            zusatz += "  Akku {0:.0f} %".format(ladung)
        if rest is not None:
            zusatz += "  noch {0:.0f} min".format(rest)
        return zusatz

    def erstmeldung(self, werte):
        """Der Zustand beim Start des Dienstes, wenn er nicht ruhig ist.

        Bis 1.1.6 kehrte ereignis() bei ``alt is None`` sofort zurueck. Wer
        den Dienst waehrend eines Stromausfalls neu startete - oder wessen
        LoxBerry waehrend eines Ausfalls hochfuhr -, bekam keine Meldung,
        weil es keinen WECHSEL gab. Gemeldet wird deshalb einmalig, wenn der
        erste gemessene Zustand nicht in Ordnung ist.
        """
        if werte.get("comm_lost"):
            self._melden("Verbindung zur USV verloren",
                         "Beim Start des Dienstes erreicht apcupsd die USV nicht.",
                         "error")
        elif werte.get("on_battery"):
            self._melden("Stromausfall",
                         "Beim Start des Dienstes speist die USV bereits aus dem Akku."
                         + self._zusatz(werte), "error")
        elif werte.get("replace_battery"):
            self._melden("Akkutausch faellig",
                         "Die USV meldet beim Start des Dienstes einen faelligen "
                         "Akkutausch.", "warning")

    def ereignis(self, alt, neu, werte):
        """Zustandswechsel melden - einmal je Wechsel, nicht je Durchgang."""
        if alt is None or alt == neu:
            return
        zusatz = self._zusatz(werte)

        if werte.get("on_battery"):
            titel = "Stromausfall"
            text = "Die USV speist aus dem Akku." + zusatz
            schwere = "error"
        # Enthaltensein, nicht Gleichheit: apcupsd meldet zusammengesetzte
        # Zustaende wie "ONBATT LOWBATT". Bis 1.1.6 stand hier
        # "alt in (...)", also ein Vergleich auf Gleichheit - der traf genau
        # dann nicht, wenn es ernst geworden war. Aus "Netz wieder da" wurde
        # dann die nichtssagende Meldung "Zustand geaendert". Gemessen in
        # einem Dienstlauf ueber die Folge Netz - Akku - knapp - Abschaltung
        # - Netz.
        elif any(z in alt for z in gem.AKKU_ZUSTAENDE):
            titel = "Netz wieder da"
            text = "Die USV ist zurueck im Netzbetrieb." + zusatz
            schwere = "info"
        elif "COMMLOST" in neu:
            titel = "Verbindung zur USV verloren"
            text = "apcupsd erreicht die USV nicht mehr."
            schwere = "error"
        else:
            titel = "Zustand geaendert"
            text = "Die USV meldet jetzt {0} (vorher {1}).".format(neu, alt)
            schwere = "warning"

        self._melden(titel, text, schwere)

    def durchgang(self, erzwingen=False):
        ergebnis = gem.abfragen(self.cfg)
        werte = ergebnis["werte"]
        jetzt = int(time.time())

        if werte is None:
            self._einmal("abfrage", ergebnis["fehler"], "warning")
            self._senden("valid", "0", erzwingen)
            self._senden("last_error", ergebnis["fehler"].splitlines()[0], erzwingen)
            self._senden("timestamp", jetzt, True)
            self.zustand_schreiben({"zeit": jetzt, "version": gem.version(),
                                    "werte": None, "roh": {}, "zusatz": {},
                                    "fehler": ergebnis["fehler"]})
            self.erster_durchgang = False
            return

        self._gemeldet.pop("abfrage", None)
        neu = werte["status"]
        if self.erster_durchgang:
            self.erstmeldung(werte)
        else:
            self.ereignis(self.letzter_status, neu, werte)
        self.letzter_status = neu
        self.erster_durchgang = False

        # Sagen Statustext und Statusbits dasselbe? Nur melden, nicht
        # korrigieren - die Bittabelle ist nicht an dieser Anlage gemessen.
        streit = gem.statflag_widerspruch(ergebnis["roh"], werte)
        if streit:
            self._einmal("statflag",
                         "Statustext und STATFLAG widersprechen sich bei: "
                         + ", ".join(streit)
                         + ". Massgeblich bleibt der Statustext.", "warning")

        log.info("%s  Akku %s %%  Rest %s min  Netz %s V  Last %s %%  Stufe %s",
                 neu, werte["battery_charge"], werte["time_left"],
                 werte["line_voltage"], werte["load_percent"],
                 werte["alarm_level"])

        self._senden("valid", "1", erzwingen)
        self._senden("last_error", "", erzwingen)
        for thema, wert in werte.items():
            self._senden(thema, wert, erzwingen)
        for feld, wert in ergebnis["zusatz"].items():
            self._senden("raw/" + feld, wert, erzwingen)
        # Der Zeitstempel geht IMMER hinaus, auch wenn sich sonst nichts
        # geaendert hat: er ist das Mittel, mit dem der Miniserver einen
        # frischen Wert von einem retained liegengebliebenen unterscheidet.
        self._senden("timestamp", jetzt, True)

        self.zustand_schreiben({
            "zeit": jetzt, "version": gem.version(),
            "werte": werte, "roh": ergebnis["roh"],
            "zusatz": ergebnis["zusatz"], "fehler": "",
        })

    def _kappen(self):
        """Hoechstens einmal je Stunde nachsehen, ob das Protokoll zu gross ist."""
        jetzt = time.time()
        if jetzt - self._letzte_kappung < 3600:
            return
        self._letzte_kappung = jetzt
        if gem.log_kappen(LOG_DATEI, gem.zahl(self.cfg, "log_kb", 512, int)):
            log.info("Protokolldatei gekappt.")

    def start(self):
        log.info("APC-UPS NG %s startet", gem.version())
        log.info("Konfiguration: %s", gem.CONFIG_FILE)
        log.info("Themenliste: %s (%d Themen)", gem.themen_datei(),
                 len(gem.themen_schluessel()))
        if not gem.themen_schluessel():
            log.error("apc_themen.json fehlt oder ist unlesbar - es kann nicht "
                      "entschieden werden, welche Themen retained gehoeren.")
        prog = gem.apcaccess_pfad()
        log.info("apcaccess: %s", prog or "NICHT GEFUNDEN")

        if self.cfg.get("enabled", "1") != "1":
            log.warning("Das Plugin ist ausgeschaltet. Im Reiter Einstellungen "
                        "einschalten und speichern.")

        if self.cfg.get("mqtt", "1") == "1":
            self.mqtt.start()
        else:
            log.info("MQTT ist ausgeschaltet")

        intervall = max(5, gem.zahl(self.cfg, "intervall", 30, int))
        vollmeldung_alle = max(intervall, gem.zahl(self.cfg, "aktualisierung", 300, int))
        letzte_vollmeldung = 0

        while self.laeuft:
            # Steht die MQTT-Verbindung nicht, hier nachfassen. Kommt sie
            # zustande, muessen alle Werte neu hinaus: der Broker kennt sie
            # nicht, und der Doppelt-senden-Filter wuerde sie zurueckhalten.
            if self.cfg.get("mqtt", "1") == "1" and self.mqtt.nachfassen():
                self.letzter_stand.clear()
                letzte_vollmeldung = 0

            if self.cfg.get("enabled", "1") == "1":
                erzwingen = (time.time() - letzte_vollmeldung) >= vollmeldung_alle
                self.durchgang(erzwingen=erzwingen)
                if erzwingen:
                    letzte_vollmeldung = time.time()

            self._kappen()

            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geaendert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                self.cfg, _ = gem.konfiguration_lesen()
                self.letzter_stand.clear()
                intervall = max(5, gem.zahl(self.cfg, "intervall", 30, int))
                vollmeldung_alle = max(intervall,
                                       gem.zahl(self.cfg, "aktualisierung", 300, int))

            ende = time.time() + intervall
            while self.laeuft and time.time() < ende:
                time.sleep(min(1.0, max(0.05, ende - time.time())))

    def stop(self):
        self.laeuft = False
        self.mqtt.stop()


def main():
    if not einmal_starten():
        log.warning("Es laeuft bereits ein APC-UPS NG-Dienst (%s) - dieser Start wird "
                    "beendet. Das ist kein Fehler: die Oberflaeche startet den "
                    "Dienst bei jedem Speichern neu.", PID_DATEI)
        return 0

    dienst = Dienst()

    def beenden(signum, rahmen):   # noqa: ARG001
        log.info("Signal %s empfangen - beende", signum)
        dienst.laeuft = False

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    try:
        dienst.start()
    except KeyboardInterrupt:
        pass
    except Exception:  # noqa: BLE001
        # Ohne diesen Zweig endet der Dienst bei einem unerwarteten Fehler
        # STILL: das Protokoll bekaeme nichts zu sehen, weil stdout in eine
        # Datei umgeleitet ist, die niemand liest. Der Waechter unter
        # cron/ startet ihn danach wieder - aber nur, wenn jemand die
        # Ursache findet, wird es auch besser.
        log.exception("Unerwarteter Fehler - der Dienst beendet sich.")
        return 1
    finally:
        dienst.stop()
        _sperre_freigeben()
        log.info("Beendet")
    return 0


if __name__ == "__main__":
    sys.exit(main())
