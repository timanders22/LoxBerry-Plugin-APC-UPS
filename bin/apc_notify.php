<?php
/**
 * APC-UPS NG - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php apc_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Der Pluginordner wird als drittes Argument uebergeben, weil der Dienst
 * ueber  su loxberry -c ...  gestartet wird und dabei die
 * LoxBerry-Umgebungsvariablen verlorengehen. Ohne ihn fiele dieses Skript
 * auf den fest eingetragenen Namen zurueck - wer das Plugin in einen
 * anderen Ordner installiert hat, faende seine Warnung dann unter einem
 * Paketnamen, den es nicht gibt, und damit gar nicht.
 *
 * Der Messdienst ist in Python geschrieben; fuer Benachrichtigungen gibt es
 * dort keine LoxBerry-Schnittstelle. Deshalb dieses Zwischenstueck, das
 * dieselbe Funktion notify_ext() aufruft wie die Originalfassung des Plugins.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * DIESER BLOCK STAND BIS 1.1.6 AM DATEIENDE - also HINTER seinem eigenen
 * Aufruf. PHP zieht Funktionen, die in einem if-Block stehen, nicht vor:
 * sie entstehen erst, wenn die Zeile ausgefuehrt wird. Der Aufruf weiter
 * unten endete deshalb mit "Call to undefined function" und Rueckgabewert
 * 255, sobald LBHOMEDIR leer war - und genau davon geht der Dienst aus, der
 * dieses Skript ueber "su loxberry -c" startet.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
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

$home = getenv('LBHOMEDIR');
if (!$home) {
    $home = lb_wurzel_ermitteln();
}
$sdk = $home . '/libs/phplib/loxberry_log.php';
if (!$home || !file_exists($sdk)) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $sdk . "\n");
    exit(1);
}
require_once $home . '/libs/phplib/loxberry_system.php';
require_once $sdk;

$schwere = isset($argv[1]) && preg_match('/^[0-9]+$/', (string) $argv[1]) ? (int) $argv[1] : 4;
$text    = isset($argv[2]) ? (string) $argv[2] : '';
if (trim($text) === '') {
    fwrite(STDERR, "Kein Text angegeben.\n");
    exit(1);
}

// Reihenfolge: was der Dienst mitgibt, dann die Umgebung, dann der feste
// Name. Das dritte Argument ist der verlaessliche Weg - siehe Kopf.
$paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($paket === '') {
    $paket = (string) getenv('LBPPLUGINDIR');
}
if (!$paket) {
    $paket = 'apc_ups_ng';
}

if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}

notify_ext(array(
    'PACKAGE'  => $paket,
    'NAME'     => 'APC-UPS NG',
    'MESSAGE'  => $text,
    'SEVERITY' => $schwere,
));

exit(0);

