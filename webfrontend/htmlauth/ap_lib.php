<?php
/**
 * APC-UPS NG - gemeinsame Hilfsfunktionen
 *
 * Die Konfiguration liegt im selben Format, das bin/apc_common.py liest und
 * schreibt. Beide Seiten muessen sich hier einig sein.
 *
 * Loest die Perl-CGI-Oberflaeche der Originalfassung ab (index.cgi mit
 * HTML::Template, settings.html und zwei Sprachdateien).
 *
 * Eigenes Praefix "ap_", weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * ==== Was sich mit 1.2.0 geaendert hat ====
 *
 * 1. **Die Themenliste stand zweimal da** - einmal hier, einmal in
 *    bin/apc_common.py - und sie waren auseinandergelaufen: hier gab es
 *    data_valid und comm_lost, dort nicht. Die Python-Fassung wurde
 *    ausserdem von nichts aufgerufen. Beide sind entfallen; massgeblich ist
 *    jetzt bin/apc_themen.json, das beide Sprachen lesen.
 * 2. **Die Beschriftungen wanderten als ganze Saetze in die
 *    Loxone-Vorlage.** Der Comment einer Importvorlage wird in Loxone zum
 *    ANZEIGENAMEN der Kachel; apcups_data_valid trug dort einen Satz von
 *    161 Zeichen. Die Beschriftungen stehen jetzt in den Sprachdateien
 *    unter [THEMA] und sind kurz gehalten; die ausfuehrliche Erklaerung
 *    steht unter [THEMA_LANG] und bleibt in der Oberflaeche.
 * 3. **Die Vorlage war der geerbte Stand von vor den Ergaenzungen**: ohne
 *    <Info templateType>, ohne HintText, ohne Unit, und mit MinVal/MaxVal
 *    pauschal auf +-2147483647. Loxone zieht daraus Reglergrenzen und
 *    Plausibilitaetspruefung - wer alles offen laesst, verschenkt beides.
 * 4. **Textthemen kamen als Analogwert in die Vorlage.** Sechs von ihnen;
 *    das nachgebaute Format ist nur fuer Zahlenwerte belegt.
 * 5. **ap_log_tail() las die ganze Datei ein**, um 300 Zeilen zu zeigen.
 *    Jetzt wird vom Ende her gelesen.
 * 6. **ap_paths() ueberging die Umgebungsvariablen des Installers.** Es
 *    rechnete die Pfade selbst aus, obwohl LoxBerry sie setzt.
 */

if (!function_exists('ap_e')) {
    function ap_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
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

function ap_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home) {
        $home = lb_wurzel_ermitteln();
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(dirname(dirname(__DIR__)));
    }
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(basename(dirname(__DIR__)), 'apc_ups_ng') as $cand) {
            if (is_dir($home . '/config/plugins/' . $cand)) {
                $dir = $cand;
                break;
            }
        }
    }
    $status = is_dir('/run/shm') ? '/run/shm/apc_ups_ng_status.json'
                                 : '/tmp/apc_ups_ng_status.json';

    // Was der Installer gesetzt hat, gilt - selbst ausrechnen ist der
    // zweite Weg, nicht der erste. Bis 1.1.6 wurden LBPCONFIGDIR, LBPBINDIR
    // und LBPLOGDIR uebergangen und die Pfade aus $home zusammengesetzt.
    $base = dirname(dirname(__DIR__));
    $cfgdir = getenv('LBPCONFIGDIR');
    $bindir = getenv('LBPBINDIR');
    $logdir = getenv('LBPLOGDIR');
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => ($cfgdir ? $cfgdir : $home . '/config/plugins/' . $dir)
                        . '/apc_ups_ng.cfg',
            'ereignisse' => ($cfgdir ? $cfgdir : $home . '/config/plugins/' . $dir)
                        . '/apc_ereignisse.json',
            'bindir' => $bindir ? $bindir : $home . '/bin/plugins/' . $dir,
            'logdir' => $logdir ? $logdir : $home . '/log/plugins/' . $dir,
            'status' => $status,
        );
    } else {
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/apc_ups_ng.cfg',
            'ereignisse' => $base . '/config/apc_ereignisse.json',
            'bindir' => $base . '/bin',
            'logdir' => sys_get_temp_dir(),
            'status' => $status,
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in apc_common.py passen. */
function ap_defaults()
{
    return array(
        'enabled'          => '1',
        'intervall'        => '30',
        'aktualisierung'   => '300',
        'themenpraefix'    => 'apcups',
        'mqtt'             => '1',
        'benachrichtigung' => '1',
        'email'            => '0',
        'email_an'         => 'root',
        'host'             => '',
        'vorwarn_min'      => '5',
        'vorwarn_prozent'  => '10',
        'rohfelder'        => '',
        'log_kb'           => '512',
        // Merkmal gegen fremde Absender. Entsteht beim ersten Aufruf der
        // Oberflaeche und ueberlebt jedes Speichern, weil der Handler den
        // Bestand uebernimmt statt die Konfiguration neu zu bauen.
        'formtoken'        => '',
    );
}

/** Rueckgabe: array($werte, $altesFormat) */
function ap_config_read()
{
    $werte = ap_defaults();
    $alt = false;
    $file = ap_paths()['config'];
    if (!is_file($file)) {
        return array($werte, $alt);
    }
    foreach (preg_split('/\R/', (string) @file_get_contents($file)) as $zeile) {
        $t = trim($zeile);
        if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $klein = strtolower(preg_replace('/^apc[_-]?ups\./i', '', trim(substr($t, 0, $pos))));
        $wert = trim(trim(substr($t, $pos + 1)), "\"'");
        if (array_key_exists($klein, $werte)) {
            $werte[$klein] = $wert;
        } elseif (in_array($klein, array('loglevel', 'sendmail', 'mailto'), true)) {
            $alt = true;
        }
    }
    return array($werte, $alt);
}

function ap_cfg($cfg, $key, $default = '')
{
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
}

function ap_roh($cfg, $key)
{
    return isset($cfg[$key]) ? (string) $cfg[$key] : '';
}

function ap_config_write($werte)
{
    $file = ap_paths()['config'];
    @mkdir(dirname($file), 0775, true);
    $txt = "; APC-UPS NG\n; Geschrieben von der Plugin-Oberflaeche.\n\n[apc_ups_ng]\n";
    foreach (ap_defaults() as $k => $vorgabe) {
        $v = array_key_exists($k, $werte) ? $werte[$k] : $vorgabe;
        $v = str_replace(array("\r", "\n"), array('', ' '), (string) $v);
        $txt .= $k . '=' . trim($v) . "\n";
    }
    // Erst daneben schreiben, dann umbenennen.
    //
    // Ausgerechnet bei einem USV-Plugin ist der Stromausfall waehrend des
    // Schreibens kein theoretischer Fall - er ist der Anlass, aus dem es das
    // Plugin gibt. file_put_contents kuerzt die Datei sofort auf null und
    // fuellt sie erst danach; faellt der Strom dazwischen, ist die
    // Konfiguration weg. rename() ist auf demselben Dateisystem unteilbar.
    $neben = $file . '.neu';
    if (@file_put_contents($neben, $txt) !== strlen($txt)) {
        @unlink($neben);
        return false;
    }
    @chmod($neben, 0644);
    if (!@rename($neben, $file)) {
        @unlink($neben);
        return false;
    }
    return true;
}

function ap_status()
{
    $f = ap_paths()['status'];
    if (!is_file($f)) {
        return null;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function ap_status_alter()
{
    $s = ap_status();
    if (!$s || !isset($s['zeit'])) {
        return -1;
    }
    return max(0, time() - (int) $s['zeit']);
}

/** Die abgelegten Ereignisse, neuestes zuerst. */
function ap_ereignisse($max = 40)
{
    $f = ap_paths()['ereignisse'];
    if (!is_file($f)) {
        return array();
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return array();
    }
    return array_slice($j, 0, max(1, (int) $max));
}

/** Pfad der PID-Datei, die der Dienst selbst schreibt und sperrt. */
function ap_pid_datei()
{
    return is_dir('/run/shm') ? '/run/shm/apc_ups_ng.pid'
                              : ap_paths()['logdir'] . '/apc_ups_ng.pid';
}

/**
 * Prozessnummer des laufenden Dienstes, oder 0.
 *
 * Frueher stand hier  pgrep -o -f apc_service.py.  Das trifft jeden Prozess,
 * in dessen Befehlszeile die Zeichenkette vorkommt - auch einen Editor, der
 * die Datei geoeffnet hat, oder ein Sicherungsskript, das den Ordner
 * durchsucht. Zum Nachsehen war das ungenau, zum Beenden gefaehrlich.
 */
function ap_dienst_pid()
{
    $f = ap_pid_datei();
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0) {
        return 0;
    }
    // Lebt der Prozess, und ist er wirklich unserer? Prozessnummern werden
    // wiederverwendet.
    if (!@file_exists('/proc/' . $pid)) {
        return 0;
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'apc_service.py') !== false ? $pid : 0;
}

function ap_dienst($aktion)
{
    $p = ap_paths();
    $skript = $p['bindir'] . '/apc_service.py';
    $meldungen = array();
    if (in_array($aktion, array('stop', 'restart'), true)) {
        $pid = ap_dienst_pid();
        if ($pid > 0) {
            // Punktgenau beenden, nicht ueber die Befehlszeile suchen.
            @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            for ($i = 0; $i < 10 && ap_dienst_pid() === $pid; $i++) {
                sleep(1);
            }
            if (ap_dienst_pid() === $pid) {
                @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                sleep(1);
            }
            $meldungen[] = 'Dienst ' . $pid . ' beendet.';
        } else {
            $meldungen[] = 'Es lief kein Dienst.';
        }
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return 'Dienst nicht gefunden: ' . $skript;
        }
        // Vorher nachsehen. Ohne diese Pruefung startete jeder Klick auf
        // Speichern eine weitere Fassung - mehrere Dienste fragten dann
        // dieselbe USV ab und ueberholten sich beim Schreiben nach MQTT.
        // Der Dienst selbst haelt zusaetzlich eine Dateisperre; das hier
        // erspart den unnoetigen Start und die irritierende Logzeile.
        $schon = ap_dienst_pid();
        if ($schon > 0) {
            $meldungen[] = 'Dienst laeuft bereits (PID ' . $schon . ') - kein zweiter Start.';
            return implode("\n", $meldungen);
        }
        $log = $p['logdir'] . '/apc_ups_ng.log';
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo gestartet', $meldungen);
        sleep(3);
    }
    return implode("\n", $meldungen);
}

function ap_mqtt_broker()
{
    $f = ap_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return '';
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        foreach (array('Brokerhost', 'brokerhost') as $h) {
            if (!empty($j[$a][$h])) {
                $port = 1883;
                foreach (array('Brokerport', 'brokerport') as $pk) {
                    if (!empty($j[$a][$pk])) {
                        $port = (int) $j[$a][$pk];
                    }
                }
                return $j[$a][$h] . ':' . $port;
            }
        }
    }
    return '';
}

/**
 * Startet der MQTT-Gateway von selbst?
 *
 * Rueckgabe: true, false, oder null wenn es sich nicht feststellen laesst.
 * Der Schluessel heisst 'Gatewayautostart' - nicht 'Autostart'. Und: eine
 * gesetzte Brokeradresse beantwortet die Frage NICHT, die steht ab Werk
 * immer da.
 *
 * Stand bis 1.1.6 als eingebettete Funktion mitten im HTML des Reiters und
 * riet den LoxBerry-Pfad mit einem fest verdrahteten '/opt/loxberry'.
 */
function ap_gateway_autostart()
{
    $home = ap_paths()['home'];
    if (!$home) {
        return null;
    }
    $g = $home . '/config/system/general.json';
    if (!is_file($g)) {
        return null;
    }
    $j = @json_decode((string) @file_get_contents($g), true);
    if (!is_array($j)) {
        return null;
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        if (isset($j[$a]) && is_array($j[$a])) {
            foreach (array('Gatewayautostart', 'gatewayautostart') as $k) {
                if (array_key_exists($k, $j[$a])) {
                    return !empty($j[$a][$k]);
                }
            }
            return null;
        }
    }
    return null;
}

/* ==================================================================
 * Themenliste - EINE Quelle, gelesen aus bin/apc_themen.json
 * ================================================================== */

/**
 * Die Themen als Feld: Schluessel => array(art, einheit, min, max, retain).
 *
 * Fehlt die Datei, ist das Feld leer - und der Reiter Test sagt es. Eine
 * Ersatzliste zu erfinden waere die schlechtere Antwort: dann zeigte die
 * Oberflaeche andere Themen an, als der Dienst sendet.
 */
function ap_themen()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = array();
    $datei = ap_paths()['bindir'] . '/apc_themen.json';
    if (!is_file($datei)) {
        // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
        $datei = dirname(dirname(__DIR__)) . '/bin/apc_themen.json';
    }
    if (!is_file($datei)) {
        return $t;
    }
    $j = @json_decode((string) @file_get_contents($datei), true);
    if (!is_array($j) || !isset($j['themen']) || !is_array($j['themen'])) {
        return $t;
    }
    foreach ($j['themen'] as $e) {
        if (!is_array($e) || empty($e['schluessel'])) {
            continue;
        }
        $t[$e['schluessel']] = array(
            'art'     => isset($e['art']) ? $e['art'] : 'text',
            'einheit' => isset($e['einheit']) ? (string) $e['einheit'] : '',
            'min'     => array_key_exists('min', $e) ? $e['min'] : null,
            'max'     => array_key_exists('max', $e) ? $e['max'] : null,
            'retain'  => !empty($e['retain']),
        );
    }
    return $t;
}

/** Pfad der Themendatei - fuer die Pruefzeile im Reiter Test. */
function ap_themen_datei()
{
    $datei = ap_paths()['bindir'] . '/apc_themen.json';
    if (!is_file($datei)) {
        $zweit = dirname(dirname(__DIR__)) . '/bin/apc_themen.json';
        if (is_file($zweit)) {
            return $zweit;
        }
    }
    return $datei;
}

/**
 * Sprachschluessel eines Themas.
 *
 * 'service/online' wird zu THEMA.SERVICE_ONLINE - der Schraegstrich ist in
 * einem INI-Schluessel nicht zu gebrauchen.
 */
function ap_thema_schluessel($k)
{
    return 'THEMA.' . strtoupper(str_replace(array('/', '-'), '_', $k));
}

/**
 * Kurze Beschriftung eines Themas.
 *
 * Sie wandert als Comment in die Loxone-Importvorlage und wird dort zum
 * ANZEIGENAMEN der Kachel. Deshalb eine Beschriftung, kein Satz - in 1.1.6
 * trug apcups_data_valid einen Satz von 161 Zeichen.
 */
function ap_thema_text($k)
{
    $s = ap_thema_schluessel($k);
    $t = ap_t($s);
    return $t === $s ? $k : $t;
}

/** Ausfuehrliche Erklaerung fuer die Oberflaeche. Leer, wenn es keine gibt. */
function ap_thema_lang($k)
{
    $s = 'THEMA_LANG.' . strtoupper(str_replace(array('/', '-'), '_', $k));
    $t = ap_t($s);
    return $t === $s ? '' : $t;
}

function ap_log_file()
{
    $c = glob(ap_paths()['logdir'] . '/*.log');
    if (!$c) {
        return '';
    }
    usort($c, function ($a, $b) { return filemtime($b) - filemtime($a); });
    return $c[0];
}

/**
 * Die letzten Zeilen einer Protokolldatei, neueste zuerst.
 *
 * Gelesen wird VOM ENDE HER. Bis 1.1.6 stand hier file_get_contents auf die
 * ganze Datei, um 300 Zeilen zu zeigen - bei einem Dienst, der im
 * 30-Sekunden-Takt schreibt, wandert so die Arbeit vieler Tage durch den
 * Speicher der Oberflaeche.
 */
function ap_log_tail($file, $max = 300)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return array();
    }
    // Grosszuegig geschaetzt: 200 Byte je Zeile, hoechstens 512 kB.
    $wunsch = min(512 * 1024, max(8192, $max * 200));
    $groesse = (int) filesize($file);
    $von = max(0, $groesse - $wunsch);
    if ($von > 0) {
        fseek($fh, $von);
        fgets($fh);          // angeschnittene erste Zeile verwerfen
    }
    $lines = array();
    while (($z = fgets($fh)) !== false) {
        $z = rtrim($z, "\r\n");
        if (trim($z) !== '') {
            $lines[] = $z;
        }
    }
    fclose($fh);
    return array_reverse(array_slice($lines, -$max));
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul
 * gibt es nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der
 * Tabulator vor den Kindelementen entsprechen dem Original.
 *
 * Ergaenzt in 1.2.0 gegen die massgeblichen Ausfuhren aus Loxone Config:
 * HintText am Wurzelelement, <Info templateType="2" minVersion="17010727"/>
 * als erstes Kindelement, Unit und HintText je Eintrag, dazu echte
 * MinVal/MaxVal statt +-2147483647.
 * ================================================================== */

function ap_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ap_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="' . ap_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . ap_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ap_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ap_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ap_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $min = isset($c['min']) && $c['min'] !== null ? $c['min'] : 0;
        $max = isset($c['max']) && $c['max'] !== null ? $c['max'] : 100;
        $einheit = isset($c['einheit']) ? trim((string) $c['einheit']) : '';
        $unit = $einheit === '' ? '<v.1>' : '<v.1> ' . $einheit;
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . ap_x($c['title']) . '" ';
        $o .= 'Comment="' . ap_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . ap_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . ap_x($min) . '" ';
        $o .= 'MaxVal="' . ap_x($max) . '" ';
        $o .= 'Unit="' . ap_x($unit) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Welche Themen bekommen einen virtuellen Eingang?
 *
 * Textthemen NICHT: das nachgebaute Vorlagenformat ist nur fuer Zahlenwerte
 * belegt, und ein Analogeingang auf einem Text zeigt dauerhaft 0. Bis 1.1.6
 * legte die Vorlage fuer sechs Textthemen genau solche Eingaenge an.
 *
 * Sie gehen deshalb nicht verloren: das MQTT-Gateway legt beim ersten
 * Empfang selbst einen passenden Eingang an. Die Oberflaeche sagt das.
 */
function ap_vorlage_themen()
{
    $aus = array();
    foreach (ap_themen() as $k => $info) {
        if ($info['art'] !== 'text') {
            $aus[$k] = $info;
        }
    }
    return $aus;
}

function ap_text_themen()
{
    $aus = array();
    foreach (ap_themen() as $k => $info) {
        if ($info['art'] === 'text') {
            $aus[$k] = $info;
        }
    }
    return $aus;
}

/**
 * Vorlage erzeugen. $art ist 'mqtt_in' oder 'xml_in'.
 * Rueckgabe: array(dateiname, inhalt)
 *
 * Der Dateiname traegt die Bauform vorne (VI_ fuer Eingaenge), keine
 * Leerzeichen und keine Umlaute.
 */
function ap_vorlage($cfg, $art)
{
    $praefix = ap_cfg($cfg, 'themenpraefix', 'apcups');
    $fuss = 'Erzeugt vom LoxBerry-Plugin APC-UPS NG (' . date('d.m.Y') . ')';

    if ($art === 'xml_in') {
        // Der alte Weg: Loxone holt die XML-Seite selbst und zieht die Werte
        // per Befehlserkennung heraus. Bleibt fuer bestehende Anlagen erhalten.
        $host = gethostname() ? gethostname() : 'loxberry';
        // Nur Zahlenfelder. STATUS stand hier bis 1.1.6 mit dabei und bekam
        // einen Eingang mit Analog="true" - ein Zustandstext wie ONLINE
        // ergibt darin dauerhaft 0. Dieselbe Ueberlegung wie bei den zehn
        // Textthemen des MQTT-Wegs: das nachgebaute Vorlagenformat ist nur
        // fuer Zahlenwerte belegt.
        //
        // Ersatz fuer den Netzausfall auf diesem Weg: BCHARGE und LINEV
        // fallen bei einem Ausfall sichtbar ab, und wer den Zustandstext
        // wirklich braucht, nimmt den MQTT-Weg.
        $felder = array(
            'BCHARGE'  => array('Akkuladung', '%', 0, 100),
            'TIMELEFT' => array('Restlaufzeit', 'min', 0, 9999),
            'LINEV'    => array('Netzspannung', 'V', 0, 300),
            'LOADPCT'  => array('Auslastung', '%', 0, 100),
            'NUMXFERS' => array('Umschaltungen', '', 0, 999999),
        );
        $cmds = array();
        foreach ($felder as $feld => $t) {
            $cmds[] = array('title' => 'APCUPS_' . $feld, 'comment' => $t[0],
                            'einheit' => $t[1], 'min' => $t[2], 'max' => $t[3],
                            'check' => '<' . $feld . '>\v');
        }
        return array('VI_APC-UPS-NG_XML.xml', ap_xml_virtual_in_http(array(
            'title'   => 'APC-UPS NG (XML)',
            'address' => 'http://' . $host . '/plugins/' . ap_paths()['plugin'] . '/index.php',
            'polling' => '60',
            'comment' => $fuss,
            'hint'    => 'Die Adresse ist ein Vorschlag - bitte pruefen, unter '
                       . 'welchem Namen der Miniserver den LoxBerry erreicht.',
        ), $cmds));
    }

    $cmds = array();
    foreach (ap_vorlage_themen() as $schluessel => $info) {
        $cmds[] = array(
            'title'   => $praefix . '_' . str_replace('/', '_', $schluessel),
            'comment' => ap_thema_text($schluessel),
            'einheit' => $info['einheit'],
            'min'     => $info['min'],
            'max'     => $info['max'],
            'check'   => ' ',
        );
    }
    return array('VI_APC-UPS-NG_MQTT.xml', ap_xml_virtual_in_http(array(
        'title'   => 'APC-UPS NG',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
        'hint'    => 'Die Werte kommen vom MQTT-Gateway, nicht von dieser Adresse.',
    ), $cmds));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function ap_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Gibt es diesen Benutzer auf dem Geraet?
 *
 * Fuer den Mailempfaenger reicht "sieht aus wie ein Benutzername" nicht:
 * "meine_email" sieht genauso aus wie "root", ist aber ein Vertipper. Eine
 * Warnung bei Stromausfall an einen Benutzer zu schicken, den es nicht gibt,
 * ist dasselbe wie sie nicht zu schicken - nur merkt es niemand.
 *
 * Geprueft wird gegen die Benutzerdatenbank, nicht gegen /etc/passwd allein:
 * posix_getpwnam kennt auch Benutzer aus LDAP oder aehnlichem. Fehlt die
 * Erweiterung, wird /etc/passwd gelesen.
 */
function ap_benutzer_existiert($name)
{
    $name = trim((string) $name);
    if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9._-]{0,31}$/', $name)) {
        return false;
    }
    if (function_exists('posix_getpwnam')) {
        return @posix_getpwnam($name) !== false;
    }
    $passwd = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$passwd) {
        // Ohne Auskunft lieber durchlassen als eine gueltige Eingabe abweisen.
        return true;
    }
    foreach ($passwd as $zeile) {
        if (strpos($zeile, $name . ':') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function ap_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            $home = lb_wurzel_ermitteln();
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ap_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function ap_gateway_fassung()
{
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function ap_abo_text()
{
    $f = ap_gateway_fassung();
    if ($f <= 0) {
        return ap_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(ap_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return ap_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_OHNE') . $gemessen;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function ap_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ap_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = ap_defaults();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ap_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ap_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
