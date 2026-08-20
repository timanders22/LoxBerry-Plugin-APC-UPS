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

## Version 1.2.0 — Befunde, Alarmstufe, fünfter Reiter

Diese Fassung entstand aus einer Zeile-für-Zeile-Durchsicht von 1.1.6 gegen die
Hausregeln. **Zwei der Befunde sind schwer**, und beide wurden am Nachbau
gemessen, nicht erschlossen:

**`bin/apc_common.py` rief `time.tzset()` auf, ohne `time` zu importieren.** Der
Aufruf stand in `try: … except AttributeError`; ein `NameError` läuft dort nicht
hinein. Auf jedem System mit `/etc/timezone` und ohne gesetztes `TZ` — also auf
jedem Debian — scheiterte damit schon der **Import** des Moduls, und mit ihm der
Dienst, die Einmalabfrage und der Knopf *Jetzt abfragen*. Gemessen an einem
Nachbau mit umgebogener Zeitzonendatei: 1.1.6 endet mit `NameError` und
Rückgabewert 1, 1.2.0 läuft durch.

**`bin/apc_notify.php` rief `lb_wurzel_ermitteln()` auf, bevor die Funktion
definiert war.** PHP zieht Funktionen aus einem `if`-Block nicht vor. Gemessen
mit leerem `LBHOMEDIR`: 1.1.6 endet mit *Call to undefined function* und
Rückgabewert 255, 1.2.0 mit der vorgesehenen Meldung und Rückgabewert 1.

### Neue Funktionen

- **`alarm_level` 0–3 und `shutdown_pending`.** Das Plugin liest die
  Abschaltschwellen aus apcupsd (`MBATTCHG`, `MINTIMEL`, `MAXTIME`) und meldet
  gestuft, wie eng es wird: 0 Netz, 1 Akku, 2 Vorwarnung, 3 apcupsd fährt
  herunter. Damit kann der Miniserver Verbraucher gestaffelt abwerfen. Liefert
  die USV keine Schwellen, bleibt es bei Stufe 1 — eine erfundene Schwelle wäre
  schlimmer als keine Stufung. Bei abgerissener Verbindung gibt es **keinen**
  Wert; eine 0 hieße dort „alles ruhig".
- **Neue Messgrößen:** `nominal_power`, `output_voltage`, `line_frequency`,
  `internal_temp`, `nominal_battery_volt`, `self_test_result`,
  `self_test_interval`, `status_flag`, `battery_age_months`, `timestamp`.
- **`battery_age_months`** rechnet das Akkualter aus `BATTDATE`. Ist das
  Datumsformat zweideutig (`05/06/19` — Tag oder Monat zuerst?), bleibt der Wert
  **leer**: ein falsches Akkualter stünde als Zahl in Loxone und sähe richtig
  aus.
- **Rohfeld-Durchreicher.** Ein Feld in der Oberfläche nimmt beliebige
  apcaccess-Feldnamen und veröffentlicht sie unter `<Präfix>/raw/<FELD>`. Ab
  Werk leer.
- **Ereignishistorie** mit Zeitstempel, im Reiter *Test* einsehbar.
- **`?selftest=1`** am XML-Endpunkt, und der Reiter *Test* ruft ihn wirklich auf.
- **Wächter** unter `cron/cron.05min`: stirbt der Dienst, lief er bis 1.1.6 bis
  zum nächsten Neustart nicht wieder an.
- **Fünfter Reiter *MQTT*** mit dem einzutragenden Abo. Eine Suche über das
  ganze Plugin 1.1.6 nach dem Abo-Thema ergab null Treffer — ohne diesen Eintrag
  im Gateway kommt am Miniserver nichts an.
- **Baustein-Liste** im Reiter *Einbindung in Loxone*, vierzehn Zeilen zum
  1:1-Nachbauen.
- **Selbstprüfung** im Reiter *Test*: siebzehn Zeilen mit Haken, Kreuz oder
  Punkt. Ein Punkt heißt „hier nicht beantwortbar" und zählt weder als bestanden
  noch als durchgefallen.

### Berichtigt

- **Zwei Themenlisten liefen auseinander.** Die PHP-Fassung kannte `data_valid`
  und `comm_lost`, die Python-Fassung nicht — und die Python-Fassung wurde von
  nichts aufgerufen. Massgeblich ist jetzt `bin/apc_themen.json`, das beide
  Sprachen lesen; der Reiter *Test* hält sie gegeneinander.
- **„Alle Themen sind retained" stand an sieben Stellen und war falsch.**
  Gemessen sind es 24 von 38. Für Messwerte ist Retain schädlich: wer sich nach
  einer Stunde neu verbindet, bekäme eine stundenalte Restlaufzeit serviert.
- **Der Kommentar einer Vorlage wird in Loxone zum Anzeigenamen.**
  `apcups_data_valid` trug dort einen Satz von 161 Zeichen. Die Beschriftungen
  stehen jetzt in den Sprachdateien und sind kurz.
- **Die Vorlage war der geerbte Stand von vor den Ergänzungen:** ohne
  `<Info templateType>`, ohne `HintText`, ohne `Unit`, mit `MinVal`/`MaxVal`
  pauschal auf ±2147483647. Sechs Textthemen bekamen einen Analogeingang, der
  dauerhaft 0 zeigt — sie bleiben jetzt draußen.
- **Der XML-Endpunkt überging die Host-Einstellung.** Wer eine entfernte USV
  eingestellt hatte, bekam über den alten Weg die Werte der örtlichen.
- **Jede Protokollzeile stand doppelt in der Datei**, und gekappt wurde nie —
  auf einer Ramdisk.
- **MQTT versuchte genau einmal zu verbinden.** Beim Hochfahren, wenn der Broker
  noch nicht antwortet, blieb es bis zum nächsten Speichern aus.
- **Das erste Ereignis nach einem Dienstneustart fiel unter den Tisch.**
- **Die Reiter wurden nur vom JavaScript geschaltet** — ohne Skript war die
  Seite leer, nicht etwa untereinander aufgeklappt.
- **Die Beanstandung zur E-Mail-Adresse erreichte den Anwender nie**, weil sie
  beim erfolgreichen Speichern unbedingt überschrieben wurde.
- **`data_valid` wurde in der Oberfläche nicht ausgewertet.** Bei abgezogenem
  USB-Kabel zeigte sie eine vollständig aussehende Werte-Tabelle.
- **Eine CSS-Eigenschaft steckte in einem Sprachschlüssel**, und zwei
  Sprachwerte begannen mit dem schließenden `>` eines `<input>`-Tags.
- **Die Formulare trugen kein Merkmal gegen fremde Absender.**
- **Der MQTT-Gateway wurde „ein eigenes Plugin" genannt** — er ist seit
  LoxBerry 3 Bestandteil des Systems.
- **Der Downloadname trug keine Anführungszeichen** und kein `VI_`.
- Der Speichern-Knopf war grün statt orange; die Überschrift im Reiter
  *Logdateien* hieß „Protokoll".

### Aktualisierungsfall

Die neuen Konfigurationsschlüssel fehlen in jeder bestehenden Anlage. Beide
Leser starten von den Vorgaben, ein fehlender Schlüssel ist also der
Vorgabewert; `rohfelder` ist ab Werk leer. Gemessen an einer alten
Konfiguration ohne die neuen Schlüssel: alte Werte bleiben, neue bekommen ihre
Vorgabe.

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

**Meldewege** — **MQTT** als Regelweg. (Die Zahl der Themen und welche davon retained sind, steht in der Tabelle weiter unten; sie wird aus `bin/apc_themen.json` erzeugt.) Beim Wechsel
zwischen Netz- und Akkubetrieb zusätzlich eine LoxBerry-Benachrichtigung und auf
Wunsch eine E-Mail. Die Ereigniserkennung sitzt jetzt im Dienst und nicht mehr in
Ereignisskripten, die apcupsd aufrufen musste.

**Der alte XML-Weg bleibt.** Die Seite unter `webfrontend/html/index.php` liefert
dieselbe Struktur wie bisher — wer sie schon in Loxone eingebunden hat, muss
nichts ändern. Sie ist nur PHP-8-fest gemacht und erzeugt kein ungültiges XML
mehr, wenn `apcaccess` eine unerwartete Zeile liefert.

**Oberfläche** — neu als `webfrontend/htmlauth/index.php` im Hausstandard mit
Reitern. Seit 1.2.0 sind es fünf: *Einstellungen*, *MQTT*,
*Einbindung in Loxone*, *Test*, *Logdateien*.
Zweisprachig, Deutsch und Englisch. Die Perl-CGI-Oberfläche mit `HTML::Template` und zwei
Sprachdateien ist entfallen. Der Reiter *Test* prüft apcupsd, zeigt die Rohdaten
und kann eine Testbenachrichtigung ablegen.

**Installation** — `dpkg/apt` nur noch mit `apcupsd` und `python3-paho-mqtt`.
Die einzige Systemdatei, die noch angefasst wird, ist `/etc/default/apcupsd`:
dort steht `ISCONFIGURED=no`, solange das nicht geändert wird startet apcupsd
nicht. Der Dienst läuft als `loxberry`, nicht als `root`.

## MQTT-Themen

Diese Tabelle ist aus `bin/apc_themen.json` erzeugt - derselben Datei,
aus der Dienst und Oberfläche lesen. Eine von Hand geführte zweite Liste
lief bis 1.1.6 auseinander.

| Thema | Art | Einheit | retained | Bedeutung |
|---|---|---|---|---|
| `<Präfix>/status` | text | — | ja | Zustandstext der USV |
| `<Präfix>/data_valid` | digital | — | ja | Werte brauchbar |
| `<Präfix>/comm_lost` | digital | — | ja | Verbindung abgerissen |
| `<Präfix>/on_line` | digital | — | ja | Netzbetrieb |
| `<Präfix>/on_battery` | digital | — | ja | Akkubetrieb |
| `<Präfix>/alarm_level` | analog | — | ja | Alarmstufe 0-3 |
| `<Präfix>/shutdown_pending` | digital | — | ja | Abschaltung steht bevor |
| `<Präfix>/battery_charge` | analog | % | nein | Akkuladung |
| `<Präfix>/time_left` | analog | min | nein | Restlaufzeit |
| `<Präfix>/line_voltage` | analog | V | nein | Netzspannung |
| `<Präfix>/output_voltage` | analog | V | nein | Ausgangsspannung |
| `<Präfix>/line_frequency` | analog | Hz | nein | Netzfrequenz |
| `<Präfix>/load_percent` | analog | % | nein | Auslastung |
| `<Präfix>/load_watt` | analog | W | nein | Last geschätzt |
| `<Präfix>/nominal_power` | analog | W | ja | Nennleistung |
| `<Präfix>/battery_voltage` | analog | V | nein | Akkuspannung |
| `<Präfix>/nominal_battery_volt` | analog | V | ja | Akku-Nennspannung |
| `<Präfix>/internal_temp` | analog | °C | nein | Innentemperatur |
| `<Präfix>/replace_battery` | digital | — | ja | Akkutausch fällig |
| `<Präfix>/battery_age_months` | analog | Mon | ja | Akkualter |
| `<Präfix>/self_test_result` | text | — | ja | Letzter Selbsttest |
| `<Präfix>/self_test_interval` | text | — | ja | Selbsttest-Abstand |
| `<Präfix>/time_on_battery` | analog | s | nein | Zeit im Akkubetrieb |
| `<Präfix>/cumulative_on_battery` | analog | s | nein | Akkubetrieb insgesamt |
| `<Präfix>/transfers` | analog | — | nein | Umschaltungen |
| `<Präfix>/last_transfer` | text | — | nein | Grund der letzten Umschaltung |
| `<Präfix>/shutdown_charge` | analog | % | ja | Abschaltschwelle Ladung |
| `<Präfix>/shutdown_minutes` | analog | min | ja | Abschaltschwelle Restzeit |
| `<Präfix>/shutdown_timeout` | analog | s | ja | Abschaltschwelle Zeitlimit |
| `<Präfix>/status_flag` | text | — | ja | Statusbits roh |
| `<Präfix>/model` | text | — | ja | Modell |
| `<Präfix>/serial` | text | — | ja | Seriennummer |
| `<Präfix>/battery_date` | text | — | ja | Akku eingebaut am |
| `<Präfix>/timestamp` | analog | s | ja | Zeitpunkt der Messung |
| `<Präfix>/valid` | digital | — | ja | Letzte Abfrage brauchbar |
| `<Präfix>/service/online` | digital | — | ja | Dienst läuft |
| `<Präfix>/event` | text | — | ja | Letztes Ereignis |
| `<Präfix>/last_error` | text | — | nein | Letzte Fehlermeldung |

Voreingestelltes Präfix: `apcups`. **Nicht alle Themen sind retained** — 24 von 38 sind es. Zustände bleiben im Broker liegen,
Messwerte nicht: wer sich nach einer Stunde neu verbindet, bekäme sonst
eine stundenalte Restlaufzeit serviert und hielte sie für aktuell. Wie
frisch ein Wert ist, sagt `<Präfix>/timestamp`.

## Notabschaltung

Ob und wann der LoxBerry bei leerem Akku heruntergefahren wird, entscheidet
**apcupsd**, nicht dieses Plugin. Die Schwellen stehen in
`/etc/apcupsd/apcupsd.conf` unter `BATTERYLEVEL`, `MINUTES` und `TIMEOUT`. Das
ist bewusst nicht in die Oberfläche geholt: es ist eine Systemdatei, die apcupsd
selbst verwaltet.

## Stand der Prüfung

**132 Messungen und 3 Gegenproben**, alle Zahlen aus den Ausgaben gezählt.
Die Prüfstände liegen unter `Pruefung-APC-UPS-1.2.0/`; ihre README nennt den
Aufruf und die Grenzen jedes einzelnen.

| Prüfstand | Fälle | Was er misst |
|---|---|---|
| `pruefe_common.py` | 58 | Themenliste gegen die Berechnung, Batteriealter samt zweideutigem Fall, jede Alarmstufe einzeln, die neuen Messgrößen, STATFLAG, Rohfelder, Aktualisierungsfall, Log-Kappung, Ereignisliste |
| `gegenprobe.py` | 3 | baut je eine Korrektur zurück und prüft, dass es **rot** wird |
| `seitenlauf.py` | 13 | jeder Reiter serverseitig geöffnet, unter PHP 7.4 **und** 8.4, dazu ein erfundener `form`-Wert |
| `postlauf.py` | 34 | beide Speicher-Handler gegeneinander, ungültige Eingaben, das Formularmerkmal, die Vorlagen, die lesenden Testknöpfe |
| `dienstlauf.py` | 27 | ein Dienstlauf über Netz → Akku → knapp → Abschaltung → Netz → Verbindung weg |

Dazu die Hauswerkzeuge: `hausstandard_pruefen.py` (alle elf Spalten),
`sprachschluessel_pruefen.py`, `umschrift_pruefen.py`, `ini_pruefen.py`
(alle drei Lesemodi liefern dasselbe), `formularpruefung.py`,
`sprachdatei_lesbar.py`, `installationslage_pruefen.py`,
`zeilenenden_vergleichen.py` gegen das Archiv von 1.1.6 (0 Dateien
angeglichen — kein Zeilenende hat sich verschoben), und `php -l` gegen
**beide** Fassungen 7.4 und 8.4.

Beide schweren Befunde sind am Nachbau gemessen, nicht erschlossen: die alte
Fassung wird rot, die neue grün, unter derselben Bedingung.

**Der Dienstlauf hat dabei einen dritten Fehler gefunden**, der beim Lesen
unauffällig war: `ereignis()` verglich den vorherigen Zustand auf *Gleichheit*
mit `ONBATT`, `LOWBATT`, `SHUTTING`. apcupsd meldet aber zusammengesetzte
Zustände wie `ONBATT LOWBATT` — genau dann, wenn es ernst wird. Aus „Netz
wieder da" wurde dadurch die nichtssagende Meldung „Zustand geändert".

### Was ausdrücklich NICHT geprüft ist

- **Der Betrieb an einer echten USV.** Es stand keine zur Verfügung; alle
  Messungen laufen gegen eine Attrappe im Format von `apcaccess`.
- **Die Einmal-Sperre des Dienstes.** `fcntl` gibt es auf dem Prüfrechner
  nicht.
- **Der Endpunkt über einen Webserver.** Die Prüfzeile im Reiter *Test* meldet
  hier „keine Antwort" — ein Hinweis, kein Kreuz.
- **Ob Loxone Config die erzeugten Vorlagen annimmt.** Gemessen sind
  Wohlgeformtheit, Attributreihenfolge, Zeilenenden, Tabulatoren, `Unit`,
  `Info`-Element und die Grenzen.
- **Ob `TZ` auf einem konkreten LoxBerry gesetzt ist.** Davon hängt ab, ob der
  Zeitzonen-Befund dort schon zugeschlagen hat; `env | grep ^TZ` beantwortet es
  in einer Sekunde.

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

