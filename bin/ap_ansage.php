<?php
/**
 * APC-UPS NG - Bruecke des Dienstes zur gemeinsamen Sprachausgabe (seit 1.2.23, V1)
 *
 * Aufruf:  php ap_ansage.php <Pluginordner>   (Auftrag als JSON auf der Standardeingabe)
 *
 *   {"anlass": "ausfall"|"rueckkehr"|"vorwarnung"|"abschaltung", "ladung": <Zahl oder -1>,
 *    "rest": <Zahl oder -1>}
 *
 * Der Dienst (bin/apc_service.py) ist in Python geschrieben; die gemeinsame Sprachausgabe
 * des Hauses (sprachausgabe.php, Stammfassung 1.2.1 seit 1.2.24, Nr. 36 b) gibt es nur in PHP. Diese
 * Datei laedt die Bibliothek der Linie (ap_lib.php) und die Abschrift der Sprachausgabe aus
 * <Wurzel>/webfrontend/htmlauth/plugins/<ordner>/, baut den Block tts (ap_tts()), den
 * Kontext (ap_ansage_k()) und den Satz (ap_ansage_text(), aus den Sprachdateien) und ruft
 * ansage_cli(). Der Text geht nur ueber stdin hinein und nie hinaus: auf stdout steht genau
 * die ASCII-Zeile von ansage_cli() (ANSAGE;STAND=..;ART=..;KENNUNG=..;HTTP=..;ZEICHEN=..).
 * Rueckgabe wie ansage_cli(): 0 gesendet, 1 gescheitert, 3 nichts gesendet ohne Fehler,
 * 2 Aufruf falsch. Dringend (Alexa NG uebergeht "Bitte nicht stoeren") sind Vorwarnung
 * und Abschaltung (Entscheidung 4: alarm_level >= 2). Kein HTTP auf sich selbst.
 *
 * Der Pluginordner wird mitgegeben wie bei apc_notify.php: der Dienst startet ueber su und
 * hat keine LoxBerry-Umgebung, und eine Zweitinstallation heisst apc_ups_ng_<md5[0:3]>.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "ANSAGE;OK=0;GRUND=KEIN_ENDPUNKT\n";
    exit;
}

/* Die Wurzel wie in apc_wurzel_suchen() der Hakenskripte: config/plugins, data/plugins UND
 * config/system/general.json, vom eigenen Ablageort aufwaerts. */
function ap_ansage_wurzel()
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

$home = rtrim((string) getenv('LBHOMEDIR'), '/');
if ($home === '' || !is_dir($home . '/config/plugins') || !is_dir($home . '/data/plugins')) {
    $home = ap_ansage_wurzel();
}
$ordner = isset($argv[1]) ? (string) $argv[1] : '';
if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $ordner) !== 1) {
    fwrite(STDERR, "Aufruf: php ap_ansage.php <Pluginordner> (Auftrag auf stdin)\n");
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(2);
}
$ap_dir = $home . '/webfrontend/htmlauth/plugins/' . $ordner;
if ($home === '' || !is_file($ap_dir . '/ap_lib.php') || !is_file($ap_dir . '/sprachausgabe.php')) {
    fwrite(STDERR, "Bibliothek oder Sprachausgabe nicht gefunden unter " . $ap_dir . "\n");
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
/* Wurzel und Ordner ausdruecklich nennen (ap_paths(): "ausdruecklich"), die Sprache aus
 * general.json, wenn LBLANG fehlt (ap_sprache()). */
putenv('LBHOMEDIR=' . $home);
putenv('LBPPLUGINDIR=' . $ordner);
if ((string) getenv('LBLANG') === '') {
    $ap_g = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
    $ap_lang = '';
    if (is_array($ap_g)) {
        foreach (array('Base', 'base', 'BASE') as $ap_ab) {
            if (isset($ap_g[$ap_ab]) && is_array($ap_g[$ap_ab])) {
                foreach (array('Lang', 'lang', 'LANG') as $ap_s) {
                    if (isset($ap_g[$ap_ab][$ap_s]) && is_string($ap_g[$ap_ab][$ap_s])) {
                        $ap_lang = $ap_g[$ap_ab][$ap_s];
                    }
                }
            }
        }
    }
    if (preg_match('/^[a-z]{2}/i', $ap_lang) === 1) {
        putenv('LBLANG=' . strtolower(substr($ap_lang, 0, 2)));
    }
}
require_once $ap_dir . '/ap_lib.php';
require_once $ap_dir . '/sprachausgabe.php';

$roh = stream_get_contents(STDIN);
$auftrag = is_string($roh) ? json_decode($roh, true) : null;
$anlaesse = array('ausfall', 'rueckkehr', 'vorwarnung', 'abschaltung');
if (!is_array($auftrag) || !isset($auftrag['anlass']) || !in_array($auftrag['anlass'], $anlaesse, true)) {
    fwrite(STDERR, "Auftrag fehlt, ist kein JSON oder nennt keinen bekannten Anlass.\n");
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(2);
}
$ladung = (isset($auftrag['ladung']) && is_int($auftrag['ladung'])) ? $auftrag['ladung'] : -1;
$rest = (isset($auftrag['rest']) && is_int($auftrag['rest'])) ? $auftrag['rest'] : -1;
/* bis 1.2.24 hiess dieses Feld $cfg: das LoxBerry-SDK ueberschreibt ein globales $cfg beim ersten Lesen von general.json (Ausfall MGiSmart 01.11.2026). */
list($ap_lauf_cfg) = ap_config_read();
$ap_lauf_tts = ap_tts($ap_lauf_cfg);
if ($ap_lauf_tts['mode'] === 'aus') {
    echo "ANSAGE;STAND=-1;KENNUNG=AUS\n";
    exit(3);
}
/* 1.2.25: Nachtruhe, Sperre und Schalter stehen im Stamm, gesprochen wird in einem eigenen Vorgang. Stromausfall, Vorwarnung und Abschaltung sprechen auch nachts;
 * "Netz wieder da" nur, wenn der Ausfall gesprochen hat, und nicht in der Nachtruhe. */
$ap_e = array('schluessel' => $auftrag['anlass'], 'text' => ap_ansage_text($auftrag['anlass'], $ladung, $rest), 'dringend' => $auftrag['anlass'] !== 'rueckkehr');
if ($auftrag['anlass'] === 'rueckkehr') { $ap_e['nur_nach'] = 'ausfall'; }
ap_ansage_token_pflegen($ap_lauf_cfg);
ap_ansage_legen(array($ap_e));
echo "ANSAGE;STAND=1;KENNUNG=GELEGT\n";
exit(0);
