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


def main():
    if "--themen" in sys.argv[1:]:
        print(json.dumps(themenbericht(), ensure_ascii=False))
        return

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
