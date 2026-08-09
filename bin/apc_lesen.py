#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
APC-UPS NG - einmalige Abfrage

Wird vom Reiter Test aufgerufen und gibt das Ergebnis als JSON auf die
Standardausgabe. Laeuft unabhaengig vom Dienst, damit man die USV
ausprobieren kann, ohne den Dienst zu starten.
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import apc_common as gem   # noqa: E402


def main():
    cfg, _alt = gem.konfiguration_lesen()
    try:
        ergebnis = gem.abfragen(cfg)
    except Exception as fehler:  # noqa: BLE001
        # Alles Unerwartete ebenfalls als JSON melden - die Oberflaeche kann
        # mit einem Python-Rueckverfolgungsprotokoll nichts anfangen.
        print(json.dumps({"werte": None, "roh": {},
                          "fehler": "{0}: {1}".format(type(fehler).__name__, fehler)},
                         ensure_ascii=False))
        return
    ergebnis["apcaccess"] = gem.apcaccess_pfad() or ""
    print(json.dumps(ergebnis, ensure_ascii=False))


if __name__ == "__main__":
    main()
