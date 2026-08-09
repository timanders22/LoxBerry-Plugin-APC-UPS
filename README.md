# LoxBerry-Plugin APC-UPS NG

Überwacht eine APC-USV über `apcupsd` und meldet Zustand, Akkuladung,
Restlaufzeit und Last per MQTT an den Loxone Miniserver. Bei Stromausfall und
Netzrückkehr gibt es zusätzlich eine Benachrichtigung.

## Herkunft und Pflege

Grundlage ist das Plugin **APC-UPS** von **Christian Woerstenfeld**, Stand
2022.01.29, Apache-Lizenz 2.0
([Woersty/LoxBerry-Plugin-APC-UPS](https://github.com/Woersty/LoxBerry-Plugin-APC-UPS) ·
[LoxBerry-Wiki des Originals](https://wiki.loxberry.de/plugins/apc_ups/start)).
Die dort beschriebene Bedienung gilt für das Original, nicht für diese Fassung.

**Die urheberrechtliche Nennung bleibt bei ihm** und steht dort, wo die
Apache-Lizenz sie verlangt: in `NOTICE` (samt Liste der Änderungen nach
Abschnitt 4 b), hier im README, in der Hilfeseite und im Kopf beider
Python-Dateien.

**Nicht mehr in `plugin.cfg`** — und das ist Absicht. LoxBerry benutzt `[AUTHOR]
NAME` und `EMAIL` zusammen mit `[PLUGIN] NAME` als **Kennung** des Plugins, nicht
als Urhebervermerk. Bis 1.0.0 trug diese Fortführung deshalb die Kennung eines
fremden Plugins, zeigte mit `AUTOMATIC_UPDATES` aber auf ein anderes
Repository — eine Mischform, die LoxBerry nicht sauber auflösen kann. Und
Fehlerberichte wären beim Originalautor gelandet, der mit dieser Fassung nichts
zu tun hat.

**Maintainer dieser Fortführung:** [timanders22](https://github.com/timanders22).
Das Original ist seit dem 22.01.2022 ohne Commit — nach den Merkmalen des
LoxBerry-Wikis (Punkt „Schritt 1") gilt es als verwaist. Diese Fassung wird
hier gepflegt; Fehlermeldungen und Wünsche bitte als Issue in **diesem**
Repository, nicht beim ursprünglichen Autor.

## Version 1.1.0 — eigene Kennung, eigener Name

Diese Fassung heißt **APC-UPS NG** und trägt eine **eigene Kennung**. Ordner und
interne Namen lauten `apc_ups_ng`, der Anzeigename ist *APC-UPS NG*.

**Warum überhaupt:** siehe oben unter *Herkunft und Pflege* — die alte Kennung
gehörte einem fremden Plugin.

**Warum zusätzlich ein neuer Ordner:** Kennung allein hätte gereicht, damit
LoxBerry beide auseinanderhält. Aber mehrere Dateien liegen in einem von allen
Plugins geteilten Bereich:

| Datei | vorher | jetzt |
|---|---|---|
| Zwischenstand | `/run/shm/apc_ups_status.json` | `/run/shm/apc_ups_ng_status.json` |
| Prozesskennung | `/run/shm/apc_ups.pid` | `/run/shm/apc_ups_ng.pid` |

Wären die geblieben, hätten sich Original und Fortführung bei paralleler
Installation **gegenseitig überschrieben** — der Dienst des einen hätte die
Prozesskennung des anderen gelesen. Die Umbenennung ist erst damit vollständig.
Mitgezogen wurden aus demselben Grund `apc_ups_ng.cfg`, `apc_ups_ng.log` und die
Namen der Loxone-Vorlagen.

**Was das für Sie bedeutet:**

* Wer **1.0.0 aus diesem Repository** hat: LoxBerry sieht 1.1.0 als neues
  Plugin. Einmal von Hand installieren, danach läuft das Auto-Update wieder von
  selbst. Die alte Installation lässt sich anschließend entfernen.
* Wer das **Original** hat: unverändert — beide können nebeneinander laufen,
  ohne sich zu stören. Genau dafür ist die Trennung da.
* **Loxone-Adressen ändern sich**, weil der Ordner im Pfad steht:
  aus `/plugins/apc_ups/…` wird `/plugins/apc_ups_ng/…`. Der Reiter *Einbindung
  in Loxone* zeigt die neuen Adressen und erzeugt die Vorlage passend dazu.

## Version 1.0.0 — LoxBerry 4 und Hausstandard

**Zur Versionsnummer:** Das Original zählte nach Datum. Hier beginnt die Zählung
neu bei `1.0.0`. Für `LoxBerry::System::plugin_version_compare` ist das
rechnerisch **älter** als `2021.05.14`, weil der Hauptstand als Zahl verglichen
wird und 1 kleiner ist als 2021.

Praktische Folge: Wer die Originalfassung installiert hat, bekommt diese hier
**nicht** als Update angeboten und muss sie einmal von Hand einspielen. Ab dann
greift das Auto-Update normal.

### Der Fehler, der auf LoxBerry 4 zuschlägt

Anders als bei anderen alten Plugins ist es hier nicht die Schnittstelle — die
stand schon auf 2.0. Es ist ein **fataler PHP-8-Fehler an der schlechtesten
denkbaren Stelle**.

In `webfrontend/html/index.php`, also genau der Seite, die Loxone abfragt, steht
in der Funktion `debug()` viermal:

```php
array_push($summary, $message);
```

`$summary` wird nirgends angelegt und steht auch nicht in der `global`-Liste der
Funktion. Unter PHP 7 war das eine Warnung. Unter PHP 8 ist es ein **fataler
TypeError**:

```
array_push(): Argument #1 ($array) must be of type array, null given
```

Nachgemessen unter PHP 8.1 mit dem isolierten Originalcode. Erreicht wird die
Stelle nur bei Loglevel 1 bis 4 — also **genau dann, wenn ein Fehler gemeldet
werden soll**. Solange alles gutgeht, läuft die Seite; fällt die
USV-Kommunikation aus, stirbt sie, statt die vorgesehene Fehler-XML zu liefern.

Zur Dringlichkeit: LoxBerry 3.0.0 bis 4.0 fahren PHP **7.4** - dort ist es noch
eine Verwarnung. Zum Fehler wird es mit Debian 13 („Trixie“), wo LoxBerry auf
PHP 8 wechselt. Der Fehler ist also nicht von heute, sondern von uebermorgen -
behoben ist er trotzdem, weil er sonst genau dann zuschlaegt, wenn niemand mehr
daran denkt.

### Weitere Befunde

- **Zwei von drei Abhängigkeiten sind tot.** `dpkg/apt` verlangte
  `apcupsd sendemail policykit-1`. `sendemail` wird nirgends benutzt — die Mails
  gehen über `/usr/sbin/sendmail`, ein anderes Programm. `policykit-1` kommt im
  ganzen Plugin nicht vor und ist auf neueren Debian-Fassungen durch `polkitd`
  abgelöst. Beide sind entfallen.
- **Alle drei standen auf einer Zeile**, obwohl die Datei ein Paket je Zeile
  erwartet.
- **`plugin.cfg` sagte `VERSION=2021.05.14`**, obwohl der letzte Tag
  `2022.01.29` heißt — die Datei wurde beim Release nicht mitgezogen.
- **Der Daemon griff tief ins System:** er löschte Dateien in `/etc/apcupsd` und
  ersetzte sie durch Symlinks in den Plugin-Ordner, schrieb mit `sed` in
  `/etc/init.d/apcupsd` und `/etc/default/apcupsd` und rief
  `/etc/init.d/apcupsd restart` auf. Auf einem systemd-System ist das weder nötig
  noch harmlos: geht die Deinstallation schief, bleibt apcupsd mit toten Symlinks
  zurück.
- **`uninstall` rief `apt-get remove` ohne `-y` auf** und hätte auf eine
  Rückfrage gewartet, die niemand beantworten kann.
- **`/sbin/apcaccess` war fest verdrahtet.** Je nach Debian-Fassung liegt das
  Programm woanders.

### Was diese Fassung anders macht

**Messung** — ein durchlaufender Dienst in Python 3 fragt `apcaccess` im
einstellbaren Takt ab, statt bei jedem Seitenaufruf. `apcaccess` wird gesucht
statt an einem festen Pfad erwartet. Aus Auslastung und Nennleistung wird
zusätzlich die geschätzte Last in Watt berechnet.

**Meldewege** — **MQTT retained** als Regelweg mit 20 Themen. Beim Wechsel
zwischen Netz- und Akkubetrieb zusätzlich eine LoxBerry-Benachrichtigung und auf
Wunsch eine E-Mail. Die Ereigniserkennung sitzt jetzt im Dienst und nicht mehr in
Ereignisskripten, die apcupsd aufrufen musste.

**Der alte XML-Weg bleibt.** Die Seite unter `webfrontend/html/index.php` liefert
dieselbe Struktur wie bisher — wer sie schon in Loxone eingebunden hat, muss
nichts ändern. Sie ist nur PHP-8-fest gemacht und erzeugt kein ungültiges XML
mehr, wenn `apcaccess` eine unerwartete Zeile liefert.

**Oberfläche** — neu als `webfrontend/htmlauth/index.php` im Hausstandard mit
vier Reitern: *Einstellungen*, *Einbindung in Loxone*, *Test*, *Logdateien*.
Vollständig auf Deutsch. Die Perl-CGI-Oberfläche mit `HTML::Template` und zwei
Sprachdateien ist entfallen. Der Reiter *Test* prüft apcupsd, zeigt die Rohdaten
und kann eine Testbenachrichtigung ablegen.

**Installation** — `dpkg/apt` nur noch mit `apcupsd` und `python3-paho-mqtt`.
Die einzige Systemdatei, die noch angefasst wird, ist `/etc/default/apcupsd`:
dort steht `ISCONFIGURED=no`, solange das nicht geändert wird startet apcupsd
nicht. Der Dienst läuft als `loxberry`, nicht als `root`.

## MQTT-Themen

| Thema | Bedeutung |
|---|---|
| `<Präfix>/status` | Zustandstext, z. B. `ONLINE` oder `ONBATT` |
| `<Präfix>/on_line` | 1 = Netzbetrieb |
| `<Präfix>/on_battery` | 1 = die USV speist aus dem Akku |
| `<Präfix>/battery_charge` | Akkuladung in Prozent |
| `<Präfix>/time_left` | verbleibende Laufzeit in Minuten |
| `<Präfix>/line_voltage` | Netzspannung in Volt |
| `<Präfix>/load_percent` | Auslastung in Prozent |
| `<Präfix>/load_watt` | geschätzte Last in Watt |
| `<Präfix>/battery_voltage` | Akkuspannung in Volt |
| `<Präfix>/replace_battery` | 1 = Akkutausch fällig |
| `<Präfix>/time_on_battery` | Sekunden im laufenden Akkubetrieb |
| `<Präfix>/cumulative_on_battery` | Sekunden im Akkubetrieb insgesamt |
| `<Präfix>/transfers` | Anzahl der Umschaltungen |
| `<Präfix>/last_transfer` | Grund der letzten Umschaltung |
| `<Präfix>/model`, `/serial`, `/battery_date` | Gerätedaten |
| `<Präfix>/valid` | 1 = die letzte Abfrage war brauchbar |
| `<Präfix>/service/online` | 1 = der Dienst läuft |
| `<Präfix>/last_error` | letzte Fehlermeldung, sonst leer |
| `<Präfix>/event` | Kurztext beim Zustandswechsel |

Voreingestelltes Präfix: `apcups`. Alle Themen sind **retained**.

## Notabschaltung

Ob und wann der LoxBerry bei leerem Akku heruntergefahren wird, entscheidet
**apcupsd**, nicht dieses Plugin. Die Schwellen stehen in
`/etc/apcupsd/apcupsd.conf` unter `BATTERYLEVEL`, `MINUTES` und `TIMEOUT`. Das
ist bewusst nicht in die Oberfläche geholt: es ist eine Systemdatei, die apcupsd
selbst verwaltet.

## Stand der Prüfung

Geprüft wurden: Syntax aller Python- und PHP-Dateien (PHP 8.1, Python 3), ein
vollständiger Dienstlauf gegen eine `apcaccess`-Attrappe mit den Übergängen
Netz → Akku → Netz → Ausfall (Ereigniserkennung, MQTT retained, Zustandsdatei,
Bremse gegen wiederholte Fehlermeldungen), der reparierte XML-Endpunkt im
Normal- **und** im Fehlerfall auf Wohlgeformtheit, das Rendern der Oberfläche
und die beiden Loxone-Vorlagen auf CRLF, Tabulatoren und Attributreihenfolge.

Der PHP-8-Fehler der Originalfassung wurde durch Ausführen des isolierten
Originalcodes unter PHP 8.1 belegt, nicht nur durch Lesen.

**Nicht geprüft: der Betrieb an einer echten USV.** Es stand keine zur
Verfügung.

## Installation

Über *Plugin-Verwaltung → Plugin installieren* das ZIP oder die Release-Adresse
angeben. Danach im Reiter *Test* mit *Jetzt abfragen* prüfen, ob die USV
antwortet. Kommt dort nichts, hilft *apcupsd prüfen* weiter — meistens steht
`ISCONFIGURED` noch auf `no` oder die USV ist nicht per USB erkannt.

## Aufgeräumt

- **`bin/__pycache__` mit drei `.pyc`-Dateien** aus einem Python-3.10-Lauf lagen
  im Paket. Auf dem Zielsystem passen sie zur dort vorhandenen Python-Fassung
  nicht zwangsläufig, und gebraucht werden sie ohnehin nicht — Python legt sie
  bei Bedarf selbst an. Entfernt; eine `.gitignore` verhindert die Wiederkehr.
- **Tote Vorlagenvariablen.** `postinstall.sh`, `preupgrade.sh` und
  `postupgrade.sh` wiesen zusammen **31** Variablen der LoxBerry-Vorlage zu, die
  nirgends gelesen wurden (`COMMAND`, `PTEMPDIR`, `PVERSION`, `PCGI`, `PHTML`,
  `PTEMPL`, `PDATA`, `PSBIN`, `PBIN` …). Entfernt; danach gegengeprüft, dass
  keine benutzte Variable ohne Zuweisung übrig ist.

### Ein Fehler im Deinstallationsskript

`uninstall/uninstall` suchte die PID-Datei an zwei Orten:

```
for PIDF in /run/shm/apc_ups_ng.pid "$LBPLOG/$PDIR/apc_ups_ng.pid"; do
```

`$PDIR` wurde in diesem Skript aber **nie zugewiesen** — der zweite Pfad wurde
damit zu `$LBPLOG//apc_ups_ng.pid` und traf nie etwas. Aufgefallen ist das nicht,
weil der erste Pfad im Normalfall zutrifft: `apc_service.py` legt die PID-Datei
unter `/run/shm` ab, solange es dieses Verzeichnis gibt. Nur wenn es das *nicht*
gibt — und genau dafür war der zweite Pfad gedacht — lief das Beenden ins Leere.
`$PDIR` wird jetzt aus dem dritten Argument gesetzt.

Beim harten Beenden wird zusätzlich **argumentweise** gegengeprüft statt per
Teilzeichenkette: Prozessnummern werden wiederverwendet, und ein `kill -9` an
den Falschen ist nicht rückgängig zu machen.

### Rückstände einer früheren Textumstellung

Elf deutsche Texte standen fest in `index.php`, obwohl es Sprachschlüssel dafür
gab oder geben sollte — auf Englisch erschienen sie deutsch: `Netzausfall`,
`noch rund … Minuten`, `Last … W`, `zuletzt:`, `Austausch fällig`,
`Jetzt abfragen` (zweimal), `· neueste Zeile zuerst`, der Satz über die fehlende
Protokolldatei sowie drei Meldungstexte beim Speichern.

Zwei Schlüssel waren dabei aus einem maschinellen Durchlauf **zusammengeklebt**:

- `STAND_VOR_2 = "&nbsp;% · Stand vor"` — das Prozentzeichen des vorangehenden
  Satzes klebte am Anfang; der saubere `STAND_VOR` existierte daneben,
- `NEUESTE_ZEILE_ZUERST_NOCH_KEINE_PR` enthielt **zwei** unabhängige Sätze in
  einem Wert.

Beide sind durch sauber getrennte Schlüssel ersetzt und alle elf Stellen
angeschlossen. 144 Schlüssel je Sprachdatei, deckungsgleich, keiner fehlt.

*Nicht* angetastet: `ALLGEMEIN.JA/NEIN/SPEICHERN` und `REITER.MQTT` sind
tatsächlich unbenutzt, kosten aber nichts und bleiben als Reserve stehen.

