<?php
/**
 * APC-UPS NG - XML-Endpunkt fuer Loxone
 *
 * Diese Seite liefert dieselbe XML-Struktur wie die Originalfassung, damit
 * bestehende Loxone-Konfigurationen unveraendert weiterlaufen. Fuer neue
 * Anlagen ist MQTT der bessere Weg.
 *
 * ==== Was hier repariert wurde ====
 *
 * Die Originalfassung stuerzte unter PHP 8 ab, sobald etwas schiefging. In
 * ihrer debug()-Funktion stand viermal
 *
 *     array_push($summary, $message);
 *
 * obwohl $summary nirgends angelegt wurde und auch nicht in der
 * global-Liste der Funktion stand. Unter PHP 7 war das eine Warnung, unter
 * PHP 8 ist es ein fataler TypeError:
 *
 *     array_push(): Argument #1 ($array) must be of type array, null given
 *
 * Erreicht wurde die Stelle nur bei Loglevel 1 bis 4 - also genau dann,
 * wenn ein Fehler gemeldet werden sollte. Faellt die USV-Kommunikation aus,
 * starb damit die Seite, statt die vorgesehene Fehler-XML zu liefern.
 *
 * Zur Dringlichkeit: LoxBerry 3.0.0 bis 4.0 fahren PHP 7.4 - dort ist es noch
 * eine Verwarnung. Zum Fehler wird es mit Debian 13 (Trixie), wo LoxBerry auf
 * PHP 8 wechselt.
 *
 * Ausserdem rief die Originalfassung fest '/sbin/apcaccess' auf. Je nach
 * Debian-Fassung liegt das Programm woanders; jetzt wird gesucht.
 *
 * ==== Was sich mit 1.2.0 geaendert hat ====
 *
 * 1. **?selftest=1.** Bis 1.1.6 gab es keine Moeglichkeit, von der
 *    Oberflaeche aus festzustellen, ob die in Loxone eingetragene Adresse
 *    ueberhaupt noch antwortet. Genau diese Datei ist laut ihrem eigenen
 *    Kopf schon einmal mit einem fatalen Fehler gestorben - eine
 *    Fehlerklasse, die nur ein echter Aufruf findet.
 *    Ein Token gibt es hier bewusst NICHT: der Endpunkt loest nichts aus, er
 *    fragt nur ab. Die Hausregel verlangt ein Token fuer ausloesende
 *    Aufrufe; abfragende bleiben offen.
 * 2. **Die Host-Einstellung wird beachtet.** Bis 1.1.6 rief diese Datei
 *    'apcaccess status' OHNE Host-Argument auf, waehrend der MQTT-Weg den
 *    eingestellten Host benutzte. Wer eine entfernte USV eingestellt hatte,
 *    bekam ueber den alten Weg stillschweigend die Werte der oertlichen.
 * 3. **Die abgeleiteten Werte gibt es jetzt auch hier.** Bis 1.1.6 trug der
 *    XML-Weg nur die Rohfelder, der MQTT-Weg dagegen on_battery, load_watt,
 *    alarm_level und so weiter. Zwei Wege mit verschiedenem Inhalt sind
 *    zwei Wahrheiten. Sie kommen aus der Zustandsdatei des Dienstes, also
 *    aus DERSELBEN Berechnung wie die MQTT-Themen - hier wird nichts ein
 *    zweites Mal ausgerechnet.
 *    Dazu steht ihr Alter in Sekunden dabei: ein Wert aus der Zustandsdatei
 *    ist so frisch wie der letzte Durchgang des Dienstes, und wer das nicht
 *    sieht, haelt einen alten Wert fuer einen neuen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$start = microtime(true);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Steht VOR jedem Aufruf - eine Funktion in einem if-Block wird von PHP
 * nicht vorgezogen. Genau daran ist bin/apc_notify.php bis 1.1.6
 * gescheitert.
 *
 * Diese Datei liegt im UNANGEMELDETEN Baum. Sie darf nichts aus
 * webfrontend/htmlauth/ einbinden: auf dem installierten LoxBerry sind das
 * zwei getrennte Verzeichnisbaeume, und der require ginge ins Leere.
 * Deshalb steht die kleine Pfadsuche hier noch einmal.
 */
if (!function_exists('apc_wurzel')) {
    function apc_wurzel()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/** Ordnername des Plugins - so heisst er auch unter config/plugins/. */
function apc_ordner()
{
    $dir = getenv('LBPPLUGINDIR');
    return $dir ? $dir : basename(__DIR__);
}

/** Die eingestellten Werte. Nur die, die hier gebraucht werden. */
function apc_konfig()
{
    $aus = array('host' => '');
    $home = getenv('LBHOMEDIR');
    if (!$home) {
        $home = apc_wurzel();
    }
    $kandidaten = array();
    if ($home) {
        $kandidaten[] = $home . '/config/plugins/' . apc_ordner() . '/apc_ups_ng.cfg';
        $kandidaten[] = $home . '/config/plugins/apc_ups_ng/apc_ups_ng.cfg';
    }
    foreach ($kandidaten as $datei) {
        if (!is_file($datei)) {
            continue;
        }
        foreach (preg_split('/\R/', (string) @file_get_contents($datei)) as $zeile) {
            $t = trim($zeile);
            if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
                continue;
            }
            $pos = strpos($t, '=');
            if ($pos === false) {
                continue;
            }
            $k = strtolower(trim(substr($t, 0, $pos)));
            if ($k === 'host') {
                $aus['host'] = trim(trim(substr($t, $pos + 1)), "\"'");
            }
        }
        break;
    }
    return $aus;
}

/** Die Zustandsdatei des Dienstes - dort stehen die abgeleiteten Werte. */
function apc_zustand()
{
    $f = is_dir('/run/shm') ? '/run/shm/apc_ups_ng_status.json'
                            : '/tmp/apc_ups_ng_status.json';
    if (!is_file($f)) {
        return null;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/** apcaccess suchen statt einen festen Pfad annehmen. */
function apc_programm()
{
    $out = array();
    @exec('command -v apcaccess 2>/dev/null', $out);
    if (!empty($out[0]) && is_executable(trim($out[0]))) {
        return trim($out[0]);
    }
    foreach (array('/sbin/apcaccess', '/usr/sbin/apcaccess', '/usr/bin/apcaccess') as $k) {
        if (is_file($k) && is_executable($k)) {
            return $k;
        }
    }
    return '';
}

function apc_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* ================== Selbsttest ==================
 *
 * Beantwortet genau eine Frage: antwortet diese Adresse noch? Kein
 * Geraetekontakt, kein Schreibzugriff, kein Aufruf von apcaccess - sonst
 * wuerde die Pruefung selbst zum Eingriff.
 */
if (isset($_GET['selftest'])) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "SELFTEST;OK=1;QUELLE=apc_ups_ng\n";
    exit;
}

header('Content-Type: text/xml; charset=UTF-8');
echo "<?xml version='1.0' encoding='UTF-8'?>\n";
echo "<root>\n";
echo " <timestamp>" . time() . "</timestamp>\n";
echo " <date_RFC822>" . date(DATE_RFC822) . "</date_RFC822>\n";

$prog = apc_programm();
if ($prog === '') {
    echo " <error>apcaccess wurde nicht gefunden</error>\n";
    echo " <errorcode>127</errorcode>\n";
    echo " <errorinfo>Ist apcupsd installiert? sudo apt-get install -y apcupsd</errorinfo>\n";
    echo " <execution>" . round(microtime(true) - $start, 5) . " s</execution>\n";
    echo " <status>ERROR</status>\n";
    echo "</root>\n";
    exit;
}

// Denselben Host benutzen wie der MQTT-Weg. Das Muster ist eng: Rechnername
// oder Adresse, notfalls mit Port - was nicht passt, wird NICHT
// durchgereicht, sondern weggelassen, und dann fragt apcaccess wie bisher
// die oertliche USV.
$cfg = apc_konfig();
$befehl = escapeshellarg($prog) . ' status';
if ($cfg['host'] !== '' && preg_match('/^[A-Za-z0-9._-]+(:[0-9]{1,5})?$/', $cfg['host'])) {
    $befehl .= ' ' . escapeshellarg($cfg['host']);
}

$result = array();
$retval = 0;
@exec($befehl . ' 2>&1', $result, $retval);

if ($retval != 0) {
    echo " <error>Die USV antwortet nicht</error>\n";
    echo " <errorcode>" . (int) $retval . "</errorcode>\n";
    echo " <errorinfo>" . apc_x(implode("\n", $result)) . "</errorinfo>\n";
    echo " <execution>" . round(microtime(true) - $start, 5) . " s</execution>\n";
    echo " <status>ERROR</status>\n";
    echo "</root>\n";
    exit;
}

echo " <UPS>\n";
foreach ($result as $zeile) {
    if (strpos($zeile, ':') === false) {
        continue;
    }
    $pos = strpos($zeile, ':');
    $name = strtoupper(str_replace(' ', '_', trim(substr($zeile, 0, $pos))));
    $wert = trim(substr($zeile, $pos + 1));
    // Nur Namen zulassen, die als XML-Element gueltig sind. Die
    // Originalfassung schrieb ungeprueft, was apcaccess lieferte - eine
    // unerwartete Zeile konnte damit ungueltiges XML erzeugen.
    if ($name === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
        continue;
    }
    echo "   <$name>" . apc_x($wert) . "</$name>\n";
}
echo " </UPS>\n";

/* Die abgeleiteten Werte - dieselben, die ueber MQTT hinausgehen.
 *
 * Sie stammen aus der Zustandsdatei des Dienstes, nicht aus einer zweiten
 * Rechnung an dieser Stelle: zwei Stellen, die dasselbe ausrechnen, laufen
 * auseinander. Laeuft der Dienst nicht, fehlt der Block - und der Grund
 * steht dabei, statt dass jemand eine leere Zahl fuer eine gemessene haelt.
 */
$z = apc_zustand();
if (is_array($z) && !empty($z['werte']) && is_array($z['werte'])) {
    $alter = max(0, time() - (int) (isset($z['zeit']) ? $z['zeit'] : 0));
    echo " <CALC alter=\"" . (int) $alter . "\">\n";
    foreach ($z['werte'] as $k => $v) {
        $name = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string) $k));
        if ($name === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            continue;
        }
        echo "   <$name>" . apc_x($v === null ? '' : $v) . "</$name>\n";
    }
    echo " </CALC>\n";
} else {
    echo " <CALC_HINWEIS>Der Dienst hat noch keine Zustandsdatei geschrieben."
        . " Die abgeleiteten Werte (on_battery, load_watt, alarm_level ...)"
        . " stehen deshalb nicht zur Verfuegung; die Rohfelder oben sind"
        . " davon unberuehrt.</CALC_HINWEIS>\n";
}

echo " <execution>" . round(microtime(true) - $start, 5) . " s</execution>\n";
echo " <status>OK</status>\n";
echo "</root>\n";
