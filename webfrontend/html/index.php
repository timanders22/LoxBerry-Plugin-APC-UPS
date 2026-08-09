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
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$start = microtime(true);

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

$result = array();
$retval = 0;
@exec(escapeshellarg($prog) . ' status 2>&1', $result, $retval);

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
echo " <execution>" . round(microtime(true) - $start, 5) . " s</execution>\n";
echo " <status>OK</status>\n";
echo "</root>\n";
