#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
APC-UPS NG - einmalige Abfrage

Wird vom Reiter Test aufgerufen und gibt das Ergebnis als JSON auf die
Standardausgabe. Laeuft unabhaengig vom Dienst, damit man die USV
ausprobieren kann, ohne den Dienst zu starten.

Aufrufformen:

    apc_lesen.py              einmal abfragen, Ergebnis als JSON
    apc_lesen.py --themen     die Themenliste und die tatsaechlich erzeugten
                              Schluessel als JSON - damit der Reiter Test
                              gegenpruefen kann, ob apc_themen.json, die
                              Berechnung und die Oberflaeche dasselbe meinen
    apc_lesen.py --mqtt-leeren
                              die behaltenen Themen dieser Linie im Broker
                              loeschen und nachlesen - fuer uninstall/uninstall
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import apc_common as gem   # noqa: E402


def themenbericht():
    """Was steht in der Liste, und was erzeugt die Berechnung wirklich?

    Bis 1.1.6 gab es zwei Listen - eine je Sprache -, und sie waren
    auseinandergelaufen, ohne dass es jemand bemerkte. Diese Ausgabe macht
    die Frage messbar, statt sie einem Kommentar zu ueberlassen.
    """
    liste = gem.themen_schluessel()
    erzeugt = sorted(set(gem.messwerte({}, {})) | set(gem.DIENST_THEMEN))
    return {
        "datei":    gem.themen_datei(),
        "liste":    liste,
        "erzeugt":  erzeugt,
        "nur_liste": sorted(set(liste) - set(erzeugt)),
        "nur_code":  sorted(set(erzeugt) - set(liste)),
        "gleich":   sorted(set(liste)) == erzeugt,
    }


def mqtt_leeren():
    """Behaltene Themen der Linie im Broker leeren - fuer die Deinstallation.

    Wer das Plugin entfernt, soll keine behaltenen Werte zuruecklassen
    (Regeln/07; Muster 6 der Nachlese). Bis 1.2.12 raeumte uninstall nichts
    ab: Zustand, Modell, Seriennummer und das letzte Ereignis standen danach
    fuer immer im Broker, und nach jedem Neustart von Broker oder Gateway
    bekam der Miniserver sie als frische Werte (in WSL gemessen,
    Pruefung-APC-UPS-1.2.13, Fall U1).

    Geleert wird NUR, was diese Linie sendet: das Praefix aus der
    Konfiguration und darunter genau die Namen aus apc_themen.json samt den
    frueher behaltenen (apc_common.ALTLAST). Ein fremdes Thema unter
    demselben Praefix bleibt stehen. Am Broker mit Nachlesen; CONNACK
    ungleich 0 und SUBACK 0x80 heissen "nicht zu fragen", nie "nichts da".
    Rueckgabe: 0 geleert oder nichts behalten, 1 es blieb etwas stehen,
    2 nicht zu fragen oder nicht zulaessig.
    """
    if gem.ARCHIVMODUS:
        sys.stdout.write("<WARNING> " + gem.archiv_meldung("apc_lesen.py --mqtt-leeren"))
        return 2
    cfg, _alt = gem.konfiguration_lesen()
    praefix = str(cfg.get("themenpraefix") or "apcups").strip("/") or "apcups"
    eigene = set(gem.themen_schluessel()) | set(gem.ALTLAST)

    def auswahl(thema):
        return thema.startswith(praefix + "/") and thema[len(praefix) + 1:] in eigene

    erg = gem.broker_leeren(praefix, auswahl, warten=3.0)
    if erg["rc"] == 2:
        print("<WARNING> MQTT: die behaltenen Themen unter {0}/ wurden nicht geleert - "
              "{1}.".format(praefix, erg["grund"]))
        return 2
    if erg["rc"] == 1:
        print("<WARNING> MQTT: {0} von {1} behaltenen Themen unter {2}/ stehen noch im "
              "Broker, zum Beispiel {3}.".format(len(erg["rest"]), len(erg["geleert"]),
                                                 praefix, erg["rest"][0]))
        return 1
    if erg["geleert"]:
        print("<OK> MQTT: {0} behaltene Themen unter {1}/ geleert und nachgelesen.".format(
            len(erg["geleert"]), praefix))
    else:
        print("<INFO> MQTT: unter {0}/ lag nichts behalten - nichts zu leeren.".format(praefix))
    return 0


def main():
    if "--themen" in sys.argv[1:]:
        print(json.dumps(themenbericht(), ensure_ascii=False))
        return
    if "--mqtt-leeren" in sys.argv[1:]:
        sys.exit(mqtt_leeren())

    cfg, _alt = gem.konfiguration_lesen()
    try:
        ergebnis = gem.abfragen(cfg)
    except Exception as fehler:  # noqa: BLE001
        # Alles Unerwartete ebenfalls als JSON melden - die Oberflaeche kann
        # mit einem Python-Rueckverfolgungsprotokoll nichts anfangen.
        print(json.dumps({"werte": None, "roh": {}, "zusatz": {},
                          "fehler": "{0}: {1}".format(type(fehler).__name__, fehler)},
                         ensure_ascii=False))
        return
    ergebnis["apcaccess"] = gem.apcaccess_pfad() or ""
    _gut, schlecht = gem.rohfelder_liste(cfg)
    ergebnis["rohfelder_abgewiesen"] = schlecht
    ergebnis["schwellen_bekannt"] = gem.schwellen_bekannt(ergebnis.get("werte"))
    ergebnis["statflag_streit"] = gem.statflag_widerspruch(
        ergebnis.get("roh") or {}, ergebnis.get("werte") or {})
    print(json.dumps(ergebnis, ensure_ascii=False))


if __name__ == "__main__":
    main()
