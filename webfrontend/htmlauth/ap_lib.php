<?php
/**
 * APC-UPS NG - gemeinsame Hilfsfunktionen
 *
 * Die Konfiguration liegt im selben Format, das bin/apc_common.py liest und
 * schreibt. Beide Seiten muessen sich hier einig sein.
 *
 * Loest die Perl-CGI-Oberflaeche der Originalfassung ab (index.cgi mit
 * HTML::Template, settings.html und zwei Sprachdateien). Alles auf Deutsch.
 *
 * Eigenes Praefix "ap_", weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('ap_e')) {
    function ap_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function ap_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home && is_dir('/opt/loxberry')) {
        $home = '/opt/loxberry';
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
    if ($home) {
        $p = array('home' => $home, 'plugin' => $dir,
                   'config' => $home . '/config/plugins/' . $dir . '/apc_ups_ng.cfg',
                   'bindir' => $home . '/bin/plugins/' . $dir,
                   'logdir' => $home . '/log/plugins/' . $dir,
                   'status' => $status);
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array('home' => '', 'plugin' => $dir,
                   'config' => $base . '/config/apc_ups_ng.cfg',
                   'bindir' => $base . '/bin',
                   'logdir' => sys_get_temp_dir(),
                   'status' => $status);
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

/** Zustandsthemen. Muessen zu status_themen() in apc_common.py passen. */
function ap_status_themen()
{
    return array(
        'status'                => array('Zustandstext der USV, z.&nbsp;B. ONLINE oder ONBATT', 'text'),
        'on_line'               => array('1 = Netzbetrieb', 'digital'),
        'on_battery'            => array('1 = die USV speist aus dem Akku', 'digital'),
        'battery_charge'        => array('Akkuladung in Prozent', 'analog'),
        'time_left'             => array('verbleibende Laufzeit in Minuten', 'analog'),
        'line_voltage'          => array('Netzspannung in Volt', 'analog'),
        'load_percent'          => array('Auslastung der USV in Prozent', 'analog'),
        'load_watt'             => array('gesch&auml;tzte Last in Watt (Nennleistung &times; Auslastung)', 'analog'),
        'battery_voltage'       => array('Akkuspannung in Volt', 'analog'),
        'replace_battery'       => array('1 = die USV meldet einen f&auml;lligen Akkutausch', 'digital'),
        'time_on_battery'       => array('Sekunden im laufenden Akkubetrieb', 'analog'),
        'cumulative_on_battery' => array('Sekunden im Akkubetrieb insgesamt', 'analog'),
        'transfers'             => array('Anzahl der Umschaltungen auf Akku', 'analog'),
        'last_transfer'         => array('Grund der letzten Umschaltung', 'text'),
        'model'                 => array('Modellbezeichnung', 'text'),
        'serial'                => array('Seriennummer', 'text'),
        'battery_date'          => array('Datum des letzten Akkutauschs', 'text'),
        'valid'                 => array('1 = die letzte Abfrage war brauchbar', 'digital'),
        'data_valid'            => array('1 = die USV antwortet wirklich. <b>0 bedeutet COMMLOST</b> &mdash; apcaccess liefert dann zwar einen vollstaendig aussehenden Datensatz, aber die Zahlen darin sind wertlos.', 'digital'),
        'comm_lost'             => array('1 = die Verbindung zur USV ist abgerissen (USB-Kabel, apcupsd)', 'digital'),
        'service/online'        => array('1 = der Dienst l&auml;uft', 'digital'),
        'last_error'            => array('letzte Fehlermeldung, sonst leer', 'text'),
    );
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

function ap_log_tail($file, $max = 300)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $lines = preg_split('/\R/', (string) @file_get_contents($file));
    $lines = array_values(array_filter($lines, function ($l) { return trim($l) !== ''; }));
    return array_reverse(array_slice($lines, -$max));
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul
 * gibt es nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der
 * Tabulator vor den Kindelementen entsprechen dem Original.
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
    $o .= 'Title="' . ap_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ap_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ap_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ap_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
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
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Vorlage erzeugen. $art ist 'mqtt_in' oder 'xml_in'.
 * Rueckgabe: array(dateiname, inhalt)
 */
function ap_vorlage($cfg, $art)
{
    $praefix = ap_cfg($cfg, 'themenpraefix', 'apcups');
    $fuss = 'Erzeugt vom LoxBerry-Plugin APC-UPS NG (' . date('d.m.Y') . ')';

    if ($art === 'xml_in') {
        // Der alte Weg: Loxone holt die XML-Seite selbst und zieht die Werte
        // per Befehlserkennung heraus. Bleibt fuer bestehende Anlagen erhalten.
        $host = gethostname() ?: 'loxberry';
        $cmds = array();
        foreach (array('STATUS'   => 'Zustandstext',
                       'BCHARGE'  => 'Akkuladung in Prozent',
                       'TIMELEFT' => 'verbleibende Laufzeit in Minuten',
                       'LINEV'    => 'Netzspannung in Volt',
                       'LOADPCT'  => 'Auslastung in Prozent') as $feld => $bed) {
            $cmds[] = array('title' => 'APCUPS_' . $feld, 'comment' => $bed,
                            'check' => '<' . $feld . '>\v');
        }
        return array('apc_ups_ng_xml.xml', ap_xml_virtual_in_http(array(
            'title'   => 'APC-UPS NG (XML)',
            'address' => 'http://' . $host . '/plugins/' . ap_paths()['plugin'] . '/index.php',
            'polling' => '60',
            'comment' => $fuss,
        ), $cmds));
    }

    $cmds = array();
    foreach (ap_status_themen() as $schluessel => $info) {
        $cmds[] = array(
            'title'   => $praefix . '_' . str_replace('/', '_', $schluessel),
            'comment' => strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')),
            'check'   => ' ',
        );
    }
    return array('apc_ups_ng_eingaenge.xml', ap_xml_virtual_in_http(array(
        'title'   => 'APC-UPS NG',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
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
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
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

function ap_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
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
