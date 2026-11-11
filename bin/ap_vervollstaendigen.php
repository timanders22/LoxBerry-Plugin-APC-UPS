<?php
/**
 * APC-UPS NG - Konfiguration vervollstaendigen, Kommandozeile (seit 1.2.31).
 *
 * Aufgerufen vom Dienst beim Start (bin/apc_service.py main(), vor dem ersten
 * Lesen der Konfiguration). Geschrieben wird ausschliesslich ueber
 * ap_config_vervollstaendigen() in ap_lib.php - der Dienst schreibt die Datei
 * nicht selbst (ein Schreiber fuer ein Format; Bauart BLE-Scanner 1.3.40).
 *
 * Aufruf: php ap_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 bei ruht, ohne_datei, ohne_merkwort, vollstaendig, ergaenzt; sonst 1.
 * Die Zeile nennt nur Schluesselnamen, nie einen Wert.
 *
 * Die Wurzel wie in bin/ap_ansage.php: LBHOMEDIR, wenn es config/plugins und
 * data/plugins traegt, sonst vom eigenen Ablageort aufwaerts (config/plugins,
 * data/plugins UND config/system/general.json) - der Dienst startet ueber su
 * und hat keine LoxBerry-Umgebung.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$ap_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $ap_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$ap_h = rtrim((string) getenv('LBHOMEDIR'), '/');
if ($ap_h === '' || !is_dir($ap_h . '/config/plugins') || !is_dir($ap_h . '/data/plugins')) {
    $ap_h = '';
    $ap_d = __DIR__;
    for ($ap_i = 0; $ap_i < 8; $ap_i++) {
        if (is_dir($ap_d . '/config/plugins') && is_dir($ap_d . '/data/plugins')
            && is_file($ap_d . '/config/system/general.json')) {
            $ap_h = $ap_d;
            break;
        }
        $ap_e = dirname($ap_d);
        if ($ap_e === $ap_d) {
            break;
        }
        $ap_d = $ap_e;
    }
}
if ($ap_h === '') {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
    exit(1);
}
$ap_lib = $ap_h . '/webfrontend/htmlauth/plugins/' . $ap_o . '/ap_lib.php';
if (!is_file($ap_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
// Wurzel und Ordner ausdruecklich nennen (ap_paths(): "ausdruecklich").
putenv('LBHOMEDIR=' . $ap_h);
putenv('LBPPLUGINDIR=' . $ap_o);
require_once $ap_lib;
$ap_e = ap_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $ap_e['stand'] . ';SCHLUESSEL=' . implode(',', $ap_e['schluessel']) . "\n";
exit(in_array($ap_e['stand'], array('ruht', 'ohne_datei', 'ohne_merkwort', 'vollstaendig', 'ergaenzt'), true) ? 0 : 1);
