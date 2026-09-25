<?php
/**
 * APC-UPS NG - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php apc_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Der Pluginordner wird als drittes Argument uebergeben, weil der Dienst
 * ueber  su loxberry -c ...  gestartet wird und dabei die
 * LoxBerry-Umgebungsvariablen verlorengehen. Ohne ihn nimmt dieses Skript
 * den Namen seines eigenen Ablageorts (installiert bin/plugins/<ordner>);
 * bis 1.2.12 war es der fest eingetragene Name - wer das Plugin in einen
 * anderen Ordner installiert hatte, faende seine Warnung dann unter einem
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
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis config/plugins,
 * data/plugins UND config/system/general.json traegt (Regeln/06). Bis 1.2.12
 * genuegten config/plugins und webfrontend: in einem fremden Baum ohne
 * general.json wurde dessen libs/phplib/loxberry_log.php eingebunden und
 * ausgefuehrt (in WSL gemessen, Pruefung-APC-UPS-1.2.13, Fall O10).
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
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$umg = rtrim((string) getenv('LBHOMEDIR'), '/');
if ($umg !== '' && !(is_dir($umg . '/config/plugins') && is_dir($umg . '/data/plugins'))) {
    $umg = '';
}
$home = $umg !== '' ? $umg : lb_wurzel_ermitteln();
if ($home === '') {
    fwrite(STDERR, "Es wurde keine LoxBerry-Wurzel gefunden - es wird nichts gemeldet.\n");
    exit(1);
}
/* Archivmodus: gemeldet wird nur aus der Installation (<Wurzel>/bin/plugins/
 * <ordner>, physisch verglichen) oder mit LBHOMEDIR UND LBPPLUGINDIR. Bis
 * 1.2.12 legte ein apc_notify.php aus einem Archiv unter einer echten Wurzel
 * seine Meldung in den Benachrichtigungsbereich der Anlage. */
$soll = @realpath($home . '/bin/plugins/' . basename(__DIR__));
$ist = @realpath(__DIR__);
$installiert = ($soll !== false && $ist !== false && $soll === $ist);
$ausdruecklich = ($umg !== '' && basename(rtrim((string) getenv('LBPPLUGINDIR'), '/')) !== '');
if (!$installiert && !$ausdruecklich) {
    fwrite(STDERR, "Diese Datei liegt nicht in der Installation unter " . $home
        . " (ausgepacktes Archiv oder Pruefordner) - es wird nichts gemeldet.\n");
    exit(1);
}
$sdk = $home . '/libs/phplib/loxberry_log.php';
if (!file_exists($sdk)) {
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

// Reihenfolge: was der Dienst mitgibt, dann die Umgebung, dann der eigene
// Ablageort (installiert bin/plugins/<ordner>). Das dritte Argument ist der
// verlaessliche Weg - siehe Kopf. Bis 1.2.12 stand am Ende der feste Name
// apc_ups_ng; eine Zweitinstallation meldete damit unter fremdem Namen.
$paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($paket === '') {
    $paket = preg_replace('/[^A-Za-z0-9_\-]/', '', basename((string) getenv('LBPPLUGINDIR')));
}
if ($paket === '') {
    $paket = basename(__DIR__);
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

