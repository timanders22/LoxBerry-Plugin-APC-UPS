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

/* V1 (1.2.23): die Abschrift der gemeinsamen Sprachausgabe (Stammfassung 1.1.3, byte-gleich; seit 1.2.24 Stamm 1.2.1
 * mit weiteren Ausgabezielen, ap_mit_zielen(), Welle Mehrfach-Ziele) liegt neben dieser Datei. Sie hat einen Schutz gegen doppeltes Laden und antwortet nur als Einstieg
 * mit 403. Die Linie braucht sie fuer den Formularblock, die Pruefung der Schluessel tts_*, den
 * Webport (A2) und die Bruecke bin/ap_ansage.php. */
if (is_file(__DIR__ . '/sprachausgabe.php')) {
    require_once __DIR__ . '/sprachausgabe.php';
}

if (!function_exists('ap_e')) {
    function ap_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis config/plugins,
 * data/plugins UND config/system/general.json traegt. Findet die Suche
 * nichts, ist die Antwort '' - einen Rueckfall danach gibt es nicht; jeder
 * Aufrufer faengt das ab.
 *
 * Bis 1.2.12 genuegten config/plugins und webfrontend - genau diese Ordner
 * hinterlaesst ein Pruefstand auf einem Arbeitsrechner (Regeln/06). In WSL
 * gemessen (Pruefung-APC-UPS-1.2.13, Fall O1): in einem fremden Baum ohne
 * general.json nahm diese Bibliothek den Baum als Wurzel.
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

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und danach nichts mehr. Ein gesetztes LBHOMEDIR gilt mit
 * config/plugins UND data/plugins darunter; general.json wird hier nicht
 * verlangt, damit die Attrappe der Pruefwerkzeuge (Werkzeuge/lb) weiter
 * traegt. Rueckgabe '' heisst "keine Wurzel". Bauart tb_lbhome() in
 * Spotpreis-Tibber 0.9.19. */
function ap_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

function ap_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = ap_lbhome();
    /* Der Ordnername: LBPPLUGINDIR (nur sein letzter Teil, und nur, wenn er
     * ein Pluginordner sein KANN), sonst der eigene Ablageort - installiert
     * liegt diese Datei unter webfrontend/htmlauth/plugins/<ordner>.
     *
     * Bis 1.2.12 stand hier basename(dirname(dirname(__DIR__))): installiert
     * ergab das 'htmlauth', und die Suche fiel auf den festen Namen
     * apc_ups_ng zurueck - auch bei einer Zweitinstallation apc_ups_ng01,
     * die damit Konfiguration und Dienst der ersten verwaltete (in WSL
     * gemessen, Pruefung-APC-UPS-1.2.13, Fall O6; Regeln/06, "Ein Rueckfall
     * auf den vorgesehenen Ordnernamen"). */
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $kein_ordner = array('', '.', '/', 'html', 'htmlauth', 'bin', 'plugins');
    $lbp_gilt = !in_array($lbp, $kein_ordner, true);
    $dir = $lbp_gilt ? $lbp : basename(__DIR__);
    /* Archivmodus. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/htmlauth/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt (LBHOMEDIR und LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit
     * ihrer Attrappe). Sonst ist das ein ausgepacktes Archiv oder ein
     * Pruefordner: alles bleibt in dessen eigenem Ordner, und Dienst- und
     * Meldeknoepfe verweigern (ap_dienst(), Reiter Test "melden").
     *
     * Bis 1.2.12 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel
     * und den festen Namen apc_ups_ng - Konfiguration, Dienst und
     * Benachrichtigung der Anlage. In WSL gemessen (Pruefung-APC-UPS-1.2.13,
     * Faelle O2 bis O4, O11): "Dienst anhalten" aus dem Archiv beendete den
     * Dienst der Anlage. Bauart tb_paths() in Spotpreis-Tibber 0.9.19. */
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home === '' && !$lbp_gilt) {
        // Nur eine Beschriftung (Vorlage, Selbstaufruf) - im Archiv heisst
        // der Ablageort htmlauth, und das ist kein Pluginordner.
        $dir = 'apc_ups_ng';
    }
    // A14 (1.2.23): der Name traegt den Ordner - fuer apc_ups_ng byte-gleich wie bisher.
    $status = is_dir('/run/shm') ? '/run/shm/' . $dir . '_status.json'
                                 : '/tmp/' . $dir . '_status.json';

    // Was der Installer gesetzt hat, gilt - selbst ausrechnen ist der
    // zweite Weg, nicht der erste. Bis 1.1.6 wurden LBPCONFIGDIR, LBPBINDIR
    // und LBPLOGDIR uebergangen und die Pfade aus $home zusammengesetzt.
    // Im Archivmodus gelten sie nicht: sie zeigen in die Anlage.
    $base = dirname(dirname(__DIR__));
    $cfgdir = $home !== '' ? getenv('LBPCONFIGDIR') : false;
    $bindir = $home !== '' ? getenv('LBPBINDIR') : false;
    $logdir = $home !== '' ? getenv('LBPLOGDIR') : false;
    $datadir = $home !== '' ? getenv('LBPDATADIR') : false;
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => ($cfgdir ? $cfgdir : $home . '/config/plugins/' . $dir)
                        . '/apc_ups_ng.cfg',
            'ereignisse' => ($cfgdir ? $cfgdir : $home . '/config/plugins/' . $dir)
                        . '/apc_ereignisse.json',
            'bindir' => $bindir ? $bindir : $home . '/bin/plugins/' . $dir,
            // Datenordner (Einmalmeldung nach dem POST, U1 in 1.2.14).
            'data'   => $datadir ? rtrim($datadir, '/') : $home . '/data/plugins/' . $dir,
            'logdir' => $logdir ? $logdir : $home . '/log/plugins/' . $dir,
            'status' => $status,
            'archiv' => '',
        );
    } else {
        // Keine Wurzel oder ein Archiv: neben dem Plugin arbeiten. Bis 1.2.12
        // stand das Protokollverzeichnis hier auf dem Systemordner fuer
        // Zwischendateien - der Reiter Logdateien zeigte dann die juengste
        // fremde .log-Datei dort.
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/apc_ups_ng.cfg',
            'ereignisse' => $base . '/config/apc_ereignisse.json',
            'bindir' => $base . '/bin',
            'data'   => $base . '/data',
            'logdir' => $base . '/log',
            'status' => $status,
            // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
            // liegt - fuer die Meldung; ohne jede Wurzel leer.
            'archiv' => $gefunden !== '' ? $gefunden : $base,
        );
    }
    return $p;
}

/** Meldung, mit der Dienst- und Meldeknoepfe im Archivmodus verweigern. */
function ap_archiv_text()
{
    return sprintf(ap_t('TEST.ARCHIV_VERWEIGERT'), dirname(dirname(__DIR__)));
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
        // --- neu in 1.2.23 (Gruppe 16) ---
        // E5: Lese-Endpunkt annehmen. Werk 0; fehlt der Schluessel in einer VORHANDENEN cfg, gilt 1
        // (Lese-Regel in ap_config_read(), Bestand bis 1.2.22 hatte den Endpunkt immer offen).
        'endpunkt_ein'     => '0',
        // V1: Block tts der gemeinsamen Sprachausgabe (ansage_vorgaben('aus'), Stammfassung 1.1.3).
        'tts_mode'         => 'musicserver',     // 1.2.25: ab Werk EIN; ap_tts() nimmt Google und Echo dazu, solange nichts eingerichtet ist
        'tts_ip'           => '',
        'tts_port'         => '7091',
        'tts_zones'        => '1',
        'tts_volume'       => '8',
        'tts_lang'         => 'de',
        'tts_template'     => '',
        'tts_alexa_geraet' => '',
        'tts_alexa_token'  => '',
        'tts_alexa_laut'   => '-1',
        'tts_google_geraet' => '',
        'tts_google_token' => '',
        'tts_google_laut'  => '-1',
        'tts_sonos_zone'   => '',
        'tts_sonos_laut'   => '-1',
        // seit 1.2.24 (Modul 1.2.0, Nachtwelle N9): SonoX - dieselben Vorgaben wie ansage_vorgaben('aus')
        'tts_sonox_raum'   => '',
        'tts_sonox_laut'   => '-1',
        'tts_sonox_sprache' => 'de',
        // Welle Mehrfach-Ziele (1.2.24, Sprachmodul 1.2.1): die weiteren Ausgabeziele als JSON-Liste, leer = keine
        // (ab Werk aus). ap_tts() liest sie in den Block, ap_tts_cfg() schreibt sie zurueck.
        'tts_ziele'        => '',
        'ansage_ausfall'     => '1',
        'ansage_rueckkehr'   => '1',
        'ansage_vorwarnung'  => '1',
        'ansage_abschaltung' => '1',
        'ansage_sperre'      => '600',
        // 1.2.25: Wann gesprochen wird (Stamm). ansage_sperre_min leer = aus ansage_sperre; der Vermerk reist nie in einer Sicherung.
        'ansage_ruhe_ein'    => '1',
        'ansage_tafel_ein'   => '1',
        'ansage_tafel_namen' => '',
        'ansage_homepod_ein' => '1',     // 1.2.28: bis hierher zeigte das Formular HomePod, gespeichert wurde es nicht
        'ansage_homepod_geraet' => '',
        'ansage_ruhe_von'    => '22:00',
        'ansage_ruhe_bis'    => '07:00',
        'ansage_sperre_min'  => '',
        'ansage_token_herkunft' => '',
        // V2: haus/usv/stufe an andere Plugins geben (Regeln/07 Nr. 15), ab Werk aus.
        'haus_usv_ein'     => '0',
        // Merkmal gegen fremde Absender. Entsteht beim ersten Aufruf der
        // Oberflaeche und ueberlebt jedes Speichern, weil der Handler den
        // Bestand uebernimmt statt die Konfiguration neu zu bauen.
        'formtoken'        => '',
    );
}

/**
 * Ein Argument fuer die Schale anfuehren, Byte fuer Byte: fuer Themen, Werte und Texte, nicht fuer Pfade.
 * Bis 1.2.24 stand hier escapeshellarg(). Es verwirft unter PHP 7.4 jedes Byte, das im LC_CTYPE kein gueltiges Zeichen ist; Apache startet mit LANG=C, eine
 * Befehlsbruecke ohne LANG: aus "Kueche" mit Umlaut wurde "Kche", ohne Fehler (am Geraet gemessen 01.11.2026). setlocale() fehlt, wo keine UTF-8-Locale
 * erzeugt ist, und aendert basename() und PCRE. Einfache Anfuehrungszeichen sind fuer sh und dash byte-erhaltend; ein Nullbyte kann kein Argument tragen.
 */
function ap_shell_arg($s)
{
    return "'" . str_replace("'", "'\\''", str_replace("\0", '', (string) $s)) . "'";
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
    $gesehen = array();
    $ap_roh_cfg = (string) @file_get_contents($file);
    foreach (preg_split('/\R/', $ap_roh_cfg) as $zeile) {
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
            $gesehen[$klein] = true;
        } elseif (in_array($klein, array('loglevel', 'sendmail', 'mailto'), true)) {
            $alt = true;
        }
    }
    /* E5 (1.2.23), EINE Lese-Regel (dieselbe im Endpunkt webfrontend/html/index.php): fehlt
     * endpunkt_ein in einer VORHANDENEN Konfiguration, gilt 1 - eine Anlage bis 1.2.22 hatte den
     * Endpunkt immer offen; postupgrade.sh schreibt die Zeile beim Update ausdruecklich. Keine
     * Konfiguration: Werk 0. */
    if (empty($gesehen['endpunkt_ein'])) {
        $werte['endpunkt_ein'] = '1';
    }
    /* 1.2.25: bis 1.2.24 trug die mitgelieferte Vorlage tts_mode=aus. Hat die Oberflaeche die Datei nie geschrieben, hat auch niemand etwas gewaehlt: nie gesetzt = ab Werk EIN. */
    if ($werte['tts_mode'] === 'aus' && strpos($ap_roh_cfg, 'Geschrieben von der Plugin-Oberflaeche') === false) {
        $werte['tts_mode'] = 'musicserver';
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
    // Erst fragen, dann anlegen. Ein @mkdir auf ein VORHANDENES Verzeichnis
    // meldet "File exists"; das @ unterdrueckt nur die Ausgabe, ein
    // gesetzter Fehlerbehandler sieht die Warnung trotzdem - und jeder
    // Pruefstand setzt einen. Gemessen mit rendern.py am 06.09.2026.
    $ordner = dirname($file);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
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
    // A4 (1.2.23): 0600 statt 0644 - die Datei traegt formtoken und die Sprechtoken der
    // Sprachausgabe (Regeln/05, Ultraschall 1.2.6: "drei Stellen gehoeren geprueft").
    @chmod($neben, 0600);
    if (!@rename($neben, $file)) {
        @unlink($neben);
        return false;
    }
    // A4 (1.2.23): die Zweitschrift bei JEDEM Speichern mitschreiben. Bis 1.2.22 schrieb sie nur
    // preupgrade.sh - die Heilung in postinstall.sh spielte dann den Stand des letzten Updates ein.
    $GLOBALS['ap_zweitschrift_ok'] = ap_zweitschrift_schreiben($txt);
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
    $n = ap_paths()['plugin'] . '.pid';
    return is_dir('/run/shm') ? '/run/shm/' . $n : ap_paths()['logdir'] . '/' . $n;
}

/** Der eigene Dienst mit vollstaendigem Pfad, nicht nur mit Dateinamen. */
function ap_dienst_skript()
{
    return ap_paths()['bindir'] . '/apc_service.py';
}

/**
 * Ist die Prozessnummer $pid ein Dienst dieses Plugins?
 *
 * Argumentweise, genau wie preupgrade.sh, postupgrade.sh, cron/cron.05min
 * und uninstall/uninstall es tun (Regeln/03): argv[0] muss ein Python sein,
 * argv[1] genau der eigene Dienstpfad. Ein Editor mit der Datei offen, eine
 * Suche mit dem Pfad im Muster oder ein gleichnamiges Skript aus einem
 * anderen Baum wird damit nie getroffen.
 *
 * Bis 1.2.10 stand hier strpos() ueber die ganze Befehlszeile. Das trifft
 * jeden Prozess, in dem die Zeichenkette irgendwo vorkommt - auch einen
 * fremden Vorgang, der eine wiederverwendete Prozessnummer aus einer
 * liegengebliebenen PID-Datei geerbt hat. In WSL gemessen
 * (Pruefung-APC-UPS-1.2.10, Fall oberflaeche): "Dienst anhalten" beendete
 * einen Prozess "python3 -c ... <Dienstpfad>", der nie unser Dienst war.
 */
function ap_ist_dienst($pid, $skript)
{
    $pid = (int) $pid;
    if ($pid <= 0) {
        return false;
    }
    $roh = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($roh) || $roh === '') {
        return false;
    }
    $teile = explode("\0", $roh);
    if (count($teile) < 2) {
        return false;
    }
    $a0 = basename($teile[0]);
    if ($a0 !== 'python' && $a0 !== 'python3' && strpos($a0, 'python3.') !== 0) {
        return false;
    }
    return $teile[1] === $skript;
}

/**
 * Unter welcher Benutzernummer laeuft der Dienst? -1 heisst "unbekannt".
 *
 * Gestartet wird er als loxberry (cron/cron.05min, daemon/daemon). Laesst
 * sich die Nummer nicht ermitteln - ohne die POSIX-Erweiterung geht es
 * nicht -, wird NICHT gefiltert, und die Erkennung stuetzt sich allein auf
 * die Befehlszeile. Das ist hier die geschlossene Seite: ein uebersehener
 * Dienst bekaeme beim naechsten Speichern einen zweiten danebengestellt,
 * waehrend ein Beenden ueber die Benutzergrenze hinweg ohnehin scheitert.
 */
function ap_dienst_uid()
{
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam('loxberry');
        if (is_array($pw) && isset($pw['uid'])) {
            return (int) $pw['uid'];
        }
    }
    return -1;
}

/**
 * Alle laufenden Dienste dieses Plugins, aufsteigend nach Prozessnummer.
 *
 * Auch die OHNE PID-Datei. Die Dateisperre des Dienstes haengt an dieser
 * Datei: ist sie geloescht, laesst sie den naechsten Start durch, und es
 * laufen zwei Dienste an derselben USV (Befund B5 der Upgrade-Luecke, in
 * WSL gemessen). Die Oberflaeche sah bis 1.2.10 nur die PID-Datei und
 * stellte beim Speichern einen zweiten daneben.
 */
function ap_dienste_suchen()
{
    clearstatcache();
    $skript = ap_dienst_skript();
    $uid = ap_dienst_uid();
    $treffer = array();
    // Erst nachsehen, ob es /proc ueberhaupt gibt. Ohne diese Zeile schrieb
    // ein Lauf auf einem System ohne /proc bei JEDEM Aufruf eine Warnung -
    // und eine Warnung, die immer kommt, liest am Ende niemand mehr.
    if (!@is_dir('/proc')) {
        return $treffer;
    }
    $dh = @opendir('/proc');
    if ($dh === false) {
        return $treffer;
    }
    while (($e = readdir($dh)) !== false) {
        // E3 (1.2.23): preg_match statt ctype_digit (Regeln/03).
        if (preg_match('/^[0-9]+\z/', $e) !== 1) {
            continue;
        }
        if ($uid >= 0) {
            $o = @fileowner('/proc/' . $e);
            if ($o === false || (int) $o !== $uid) {
                continue;
            }
        }
        if (ap_ist_dienst($e, $skript)) {
            $treffer[] = (int) $e;
        }
    }
    closedir($dh);
    sort($treffer);
    return $treffer;
}

/**
 * Prozessnummer des laufenden Dienstes, oder 0.
 *
 * Zuerst die PID-Datei - sie ist die billige und die richtige Antwort,
 * solange sie stimmt -, danach die Suche ueber /proc. Beide Wege pruefen
 * die Befehlszeile argumentweise.
 */
function ap_dienst_pid()
{
    clearstatcache();
    $f = ap_pid_datei();
    if (is_file($f)) {
        $pid = (int) trim((string) @file_get_contents($f));
        if (ap_ist_dienst($pid, ap_dienst_skript())) {
            return $pid;
        }
    }
    $alle = ap_dienste_suchen();
    return $alle ? $alle[0] : 0;
}

/**
 * Der Anhaltemerker des Knopfs "Dienst anhalten" - '' ohne Wurzel.
 *
 * Er liegt NEBEN dem Datenordner (data/plugins/<ordner>.angehalten), damit
 * purge_installation ihn beim Update nicht loescht (Regeln/06, Sollmerker;
 * Bauart Zendure <ordner>.bestand/soll_laufen, Govee <ordner>.soll_laufen).
 * Hier in umgekehrter Richtung: Waechter und daemon/daemon dieser Linie
 * starten ab Werk; ein Sollmerker muesste bei jeder Erstinstallation und
 * jedem Update von 1.2.12 oder frueher erst entstehen, sonst bliebe der
 * Dienst aus. Achten muessen ihn cron/cron.05min, daemon/daemon,
 * postupgrade.sh und das Speichern (ap_dienst('uebernehmen')); uninstall
 * raeumt ihn weg.
 */
function ap_anhalte_merker()
{
    $p = ap_paths();
    return $p['home'] === '' ? ''
        : $p['home'] . '/data/plugins/' . $p['plugin'] . '.angehalten';
}

/** Hat der Anwender den Dienst mit "Dienst anhalten" angehalten? */
function ap_angehalten()
{
    $m = ap_anhalte_merker();
    clearstatcache();
    return $m !== '' && is_file($m);
}

/**
 * Den Dienst steuern.
 *
 *   'stop'        Knopf "Dienst anhalten": beenden UND den Anhaltemerker
 *                 legen - bis 1.2.12 beendete er nur den Prozess, und der
 *                 Waechter startete ihn binnen fuenf Minuten wieder (in WSL
 *                 gemessen, Pruefung-APC-UPS-1.2.13, Fall S1).
 *   'restart'     Knopf "Dienst neu starten": den Merker nehmen, neu starten.
 *   'uebernehmen' Speichern: neu starten, damit die Einstellungen gelten -
 *                 aber nur, wenn der Dienst nicht angehalten ist (Fall S4).
 */
function ap_dienst($aktion)
{
    $p = ap_paths();
    // Aus einem Archiv oder ohne Wurzel wird kein Dienst gestartet oder
    // beendet - weder der des Archivs noch der der Anlage (Archivmodus in
    // ap_paths(); in WSL gemessen, Pruefung-APC-UPS-1.2.13, Faelle O3, O4).
    if ($p['home'] === '') {
        return ap_archiv_text();
    }
    $merker = ap_anhalte_merker();
    if ($aktion === 'uebernehmen') {
        if (ap_angehalten()) {
            return '';
        }
        $aktion = 'restart';
    } elseif ($aktion === 'restart' || $aktion === 'start') {
        if (is_file($merker)) {
            @unlink($merker);
        }
    }
    $skript = ap_dienst_skript();
    $meldungen = array();
    if (in_array($aktion, array('stop', 'restart'), true)) {
        // ALLE eigenen Dienste, nicht nur den aus der PID-Datei: sonst
        // bleibt eine Waise stehen, und der neue Dienst teilt sich die USV
        // mit ihr. Gesucht wird argumentweise, beendet wird nur, was diese
        // Suche gefunden hat.
        $ziel = ap_dienste_suchen();
        if ($ziel) {
            foreach ($ziel as $pid) {
                @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            }
            for ($i = 0; $i < 10 && ap_dienste_suchen(); $i++) {
                sleep(1);
            }
            foreach (ap_dienste_suchen() as $pid) {
                @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                sleep(1);
            }
            // Aus der Sprachdatei (U6); bis 1.2.13 standen die Saetze hier fest
            // deutsch und erschienen so auch in der englischen Oberflaeche.
            $meldungen[] = count($ziel) > 1
                ? sprintf(ap_t('TEXT.D_BEENDET_MEHRERE'), implode(', ', $ziel))
                : sprintf(ap_t('TEXT.D_BEENDET_EINER'), $ziel[0]);
        } else {
            $meldungen[] = ap_t('TEXT.D_LIEF_KEINER');
        }
        // Eine PID-Datei ohne lebenden eigenen Dienst dahinter ist ein
        // Ueberbleibsel und wird entfernt - sie gehoerte sonst womoeglich
        // bald einem fremden Vorgang.
        $pf = ap_pid_datei();
        if (is_file($pf) && ap_dienst_pid() === 0) {
            @unlink($pf);
            $meldungen[] = ap_t('TEXT.D_PID_ENTFERNT');
        }
        // Nur der Knopf "Dienst anhalten" legt den Merker; ein Neustart
        // beendet ebenfalls, will aber, dass der Dienst weiterlaeuft.
        // Geprueft wird die Wirkung (ap_angehalten()), nicht der
        // Rueckgabewert - die Meldung dazu schreibt ap_test.php.
        if ($aktion === 'stop') {
            @file_put_contents($merker, time() . "\n");
        }
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return sprintf(ap_t('TEXT.D_NICHT_GEFUNDEN'), $skript);
        }
        // Vorher nachsehen. Ohne diese Pruefung startete jeder Klick auf
        // Speichern eine weitere Fassung - mehrere Dienste fragten dann
        // dieselbe USV ab und ueberholten sich beim Schreiben nach MQTT.
        // Der Dienst selbst haelt zusaetzlich eine Dateisperre; das hier
        // erspart den unnoetigen Start und die irritierende Logzeile.
        $schon = ap_dienst_pid();
        if ($schon > 0) {
            $meldungen[] = sprintf(ap_t('TEXT.D_LAEUFT_BEREITS'), $schon);
            return implode("\n", $meldungen);
        }
        $log = $p['logdir'] . '/apc_ups_ng.log';
        // Die Rueckmeldung der Schale ("gestartet") geht nicht mehr in die
        // Meldung: sie war das einzige Wort darin, das nie uebersetzt wurde.
        $ap_start_aus = array();
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo gestartet', $ap_start_aus);
        sleep(3);
    }
    return implode("\n", $meldungen);
}

function ap_mqtt_broker()
{
    // Ohne Wurzel gibt es keinen Broker - bis 1.2.12 wurde hier dann
    // /config/system/general.json ab der Laufwerkswurzel gelesen (in WSL
    // gemessen, Pruefung-APC-UPS-1.2.13, Fall O8).
    $home = ap_paths()['home'];
    if ($home === '') {
        return '';
    }
    $f = $home . '/config/system/general.json';
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
 * Die Themen, die nach Regeln/07 NIE retained sein duerfen.
 *
 * 1. Das Lebenszeichen (Hausstandard 03.09.2026, bekraeftigt 17.09.2026):
 *    zurueckbehalten zeigte es immer "lebt" - service/online und timestamp.
 *    Der Last Will ist Teil davon und geht ebenfalls fluechtig hinaus; er
 *    steht nur in bin/apc_service.py, nicht in der Themenliste.
 * 2. Was der DIENST ueber sich selbst sagt (Entscheidung 19.09.2026): valid
 *    (hat die EIGENE apcaccess-Abfrage geklappt?) und last_error. Bis 1.2.12
 *    stand hier, valid sei ein Zustand der Quelle wie comm_lost und darum
 *    retained - das ist durch die Entscheidung ueberholt. comm_lost und
 *    data_valid bleiben retained: das stellt apcupsd ueber die USV fest.
 * 3. Ein Alter (Entscheidungen 18. und 24.09.2026): battery_age_months wird
 *    allein durch die Uhr falsch.
 */
function ap_nie_retained_themen()
{
    return array('service/online', 'timestamp', 'valid', 'last_error', 'battery_age_months',
                 'status/zaehler', 'status/ok', 'status/ts');
}

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
            'art'      => isset($e['art']) ? $e['art'] : 'text',
            'einheit'  => isset($e['einheit']) ? (string) $e['einheit'] : '',
            'min'      => array_key_exists('min', $e) ? $e['min'] : null,
            'max'      => array_key_exists('max', $e) ? $e['max'] : null,
            'retain'   => !empty($e['retain']),
            'fehlwert' => (array_key_exists('fehlwert', $e) && $e['fehlwert'] !== null) ? (string) $e['fehlwert'] : null,
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

/* 1.2.23 (E6): ap_vorlage_themen() und ap_text_themen() sind mit der VirtualInHttp-Vorlage des MQTT-Wegs
 * entfallen - die Bauform je Thema nennt jetzt die Namenstabelle (ap_mqtt_namen()). */

/**
 * Name, unter dem das MQTT-Gateway ein Thema in Loxone fuehrt: Praefix_Thema, jeder Schraegstrich
 * und jedes % zu _ (Regeln/07, "Umbenennungsregel des MQTT-Gateways: nur / und % werden zu _,
 * Punkte bleiben"; sbin/mqttgateway.pl im HTTP-Betrieb). Dieselbe Funktion fuer die Titel der
 * MQTT-Vorlage und fuer jeden Namen, den der Reiter "Einbindung in Loxone" nennt.
 *
 * 1.2.21: bis 1.2.20 ersetzte ap_vorlage() den Schraegstrich nur im Thema, nicht im Praefix. Bei
 * einem Praefix mit Ebenen (haus/apcups, von ap_praefix_gueltig() erlaubt) hiess der Eingang der
 * Vorlage haus/apcups_on_battery, das Gateway lieferte an haus_apcups_on_battery - der Eingang der
 * Vorlage bekam keinen Wert. Beim Vorgabe-Praefix apcups ist das Ergebnis dasselbe wie vorher.
 * Ein % kann weder im Praefix noch in einem Thema stehen; es steht hier, damit die Regel
 * vollstaendig ist.
 */
function ap_gateway_name($praefix, $thema)
{
    return str_replace(array('/', '%'), '_', $praefix . '_' . $thema);
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
    if ($art !== 'xml_in') {
        return array('', '');
    }
    // 1.2.20 (Sprachregel 13.10.2026): Titel fest im Code, Kommentare und HintText aus der Sprachdatei.
    $fuss = sprintf(ap_t('LOXONE.VORLAGE_FUSS'), date('d.m.Y'));
    // Der alte Weg: Loxone holt die XML-Seite selbst und zieht die Werte per Befehlserkennung
    // heraus. Nur Zahlenfelder (ein Zustandstext ergaebe im Analogeingang dauerhaft 0).
    $felder = array(
        'BCHARGE'  => array('battery_charge', '%', 0, 100),
        'TIMELEFT' => array('time_left', 'min', 0, 9999),
        'LINEV'    => array('line_voltage', 'V', 0, 300),
        'LOADPCT'  => array('load_percent', '%', 0, 100),
        'NUMXFERS' => array('transfers', '', 0, 999999),
    );
    $cmds = array();
    foreach ($felder as $feld => $t) {
        $cmds[] = array('title' => 'APCUPS_' . $feld, 'comment' => ap_thema_text($t[0]),
                        'einheit' => $t[1], 'min' => $t[2], 'max' => $t[3],
                        'check' => '<' . $feld . '>\v');
    }
    // A2 (1.2.23): LAN-Adresse und Webserver-Port statt gethostname() ohne Port.
    return array('VI_APC-UPS-NG_XML.xml', ap_xml_virtual_in_http(array(
        'title'   => 'APC-UPS NG (XML)',
        'address' => ap_endpunkt_adresse(),
        'polling' => '60',
        'comment' => $fuss,
        'hint'    => ap_t('LOXONE.VORLAGE_HINWEIS_XML'),
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
        // <home>/templates/plugins/<ordner>/lang/. Wurzel und Ordner kommen
        // aus ap_paths() - ohne Wurzel oder im Archiv wird der installierte
        // Ort gar nicht erst gefragt. Bis 1.2.12 lautete er dann
        // /templates/plugins/htmlauth/lang ab der Laufwerkswurzel (in WSL
        // gemessen, Pruefung-APC-UPS-1.2.13, Fall O7).
        $ap_p = ap_paths();
        $pfad = '';
        if ($ap_p['home'] !== '') {
            $pfad = $ap_p['home'] . '/templates/plugins/' . $ap_p['plugin'] . '/lang';
        }
        if ($pfad === '' || !is_dir($pfad)) {
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


/* ---------------- Gateway V2: Selbsttest der Haken (Nachtwelle N9, 1.2.24) ---------------- */

/**
 * Die Abos des MQTT-Gateways V2 aus config/system/subscriptions.json (Subscriptions[].Id je Thema, Noncached):
 * array(Thema => Noncached ja/nein), null = nicht lesbar. Form wie MGiSmart 1.1.28 mg_v2_abos(), Govee 0.9.30
 * gv_mqtt_v2_haken_lage() und ACTiKamera 1.9.34 (Nachtwelle N4); die Datei liest ap_v2_haken_lage(). Rein.
 */
function ap_v2_abos($daten)
{
    if (!is_array($daten)) {
        return null;
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = trim($e[$k]);
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k]) && is_scalar($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
                break;
            }
        }
        $abo[$id] = !empty($abo[$id]) || $nc;
    }
    return $abo;
}

/**
 * Soll gegen Ist, rein (liest nichts): array(1|0, fehlt[], noncached_fehlt[], noncached_zu_viel[], angehakt, soll).
 * Soll je Zeile array('thema' => volles Thema, 'name' => Gateway-Name, 'noncached' => bool). Noncached wird in
 * beide Richtungen verglichen: fehlt es, reicht das Gateway einen gleichen Wert nach einem Neustart des Miniservers
 * nicht weiter; steht es zu viel, geht jeder Wert erneut an den Miniserver.
 */
function ap_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc_fehlt = array();
    $nc_zuviel = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ($e['noncached'] && !$abo[$e['thema']]) {
            $nc_fehlt[] = $e['name'];
        } elseif (!$e['noncached'] && $abo[$e['thema']]) {
            $nc_zuviel[] = $e['name'];
        }
    }
    return array(($fehlt || $nc_fehlt || $nc_zuviel) ? 0 : 1, $fehlt, $nc_fehlt, $nc_zuviel, $da, count($soll));
}

/**
 * Das Soll fuer den Selbsttest: ap_v2_haken() - DIESELBE Liste wie die Haken im Reiter Einbindung in Loxone, Schritt 2,
 * und die Spalte V2 der Namenstabelle (Regeln/07; 36 Haken der zwei Musterseiten, die Textthemen darunter mit ihren
 * Virtuellen Texteingaengen #33-#35 und Seite 2). Noncached wie dort: status/ok und service/online.
 */
function ap_v2_haken_soll($cfg)
{
    $p = ap_praefix($cfg);
    $aus = array();
    foreach (ap_v2_haken() as $k => $nc) {
        $aus[] = array('thema' => $p . '/' . $k, 'name' => ap_gateway_name($p, $k), 'noncached' => (bool) $nc);
    }
    return $aus;
}

/**
 * Selbsttestzeile "Gateway V2: sind alle Datenpunkte der Hakenliste angehakt?" (Nachtwelle N9, Vorschrift Punkt 4):
 * array(1|0|2, Text ohne Markup). 1 alle Haken da und Noncached wie in der Hakenliste; 0 es fehlt ein Haken oder
 * Noncached stimmt nicht (mit Namen); 2 trifft nicht zu (MQTT dieses Plugins aus, Gateway V1) oder nicht
 * feststellbar (Fassung 0, keine Wurzel, subscriptions.json nicht lesbar). Liest nur.
 */
function ap_v2_haken_lage($cfg = null)
{
    if (!is_array($cfg)) {
        list($cfg) = ap_config_read();
    }
    if (ap_cfg($cfg, 'mqtt', '1') !== '1') {
        return array(2, ap_t('TEST.V2_AUS'));
    }
    $fassung = (int) ap_gateway_fassung();
    $home = (string) ap_paths()['home'];
    if ($fassung === 0 || $home === '') {
        return array(2, ap_t('TEST.V2_UNKLAR'));
    }
    if ($fassung < 2) {
        return array(2, ap_t('TEST.V2_V1'));
    }
    $abo = ap_v2_abos(@json_decode((string) @file_get_contents($home . '/config/system/subscriptions.json'), true));
    if ($abo === null) {
        return array(2, ap_t('TEST.V2_UNKLAR'));
    }
    $liste = ap_v2_haken_soll($cfg);
    if (!$liste) {
        return array(2, ap_t('TEST.V2_LEER'));   // leere Hakenliste: kein Urteil (Hausform wie N7)
    }
    list($ok, $fehlt, $nc_fehlt, $nc_zuviel, $da, $soll) = ap_v2_haken_abgleich($liste, $abo);
    $txt = sprintf(ap_t('TEST.V2_ZAHL'), $da, $soll);
    if ($fehlt) {
        $txt .= ' ' . sprintf(ap_t('TEST.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' …' : ''));
    }
    if ($nc_fehlt) {
        $txt .= ' ' . sprintf(ap_t('TEST.V2_NC_FEHLT'), implode(', ', $nc_fehlt));
    }
    if ($nc_zuviel) {
        $txt .= ' ' . sprintf(ap_t('TEST.V2_NC_ZUVIEL'), implode(', ', $nc_zuviel));
    }
    return array($ok, $txt);
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
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 * beanstandete Schluessel[]). Den vierten Wert braucht "Sichern" (X-3), um
 * die betroffenen Einstellungen zu nennen, ohne ihre Werte zu wiederholen.
 */
function ap_sicherung_lesen($roh, $bisher = null)
{
    $mangel = array();
    $falsch = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ap_t('EINST.SICH_KEIN_JSON')), 0, array(), array());
    }
    if (!is_array($bisher)) {
        list($bisher, $unbenutzt) = ap_config_read();
    }
    $erlaubt = ap_sicherung_schluessel();
    $pflicht = ap_sicherung_pflicht();
    $geheim = array('tts_alexa_token', 'tts_google_token');
    $werte = array();
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if ($k === 'formtoken' || $k === 'ansage_token_herkunft') {     // 1.2.25: der Vermerk kommt nie aus einer Datei
            continue;
        }
        if (in_array($k, $geheim, true)) {
            if ($w !== '') {
                $mangel[] = sprintf(ap_t('EINST.SICH_TOKEN'), $k);
                $falsch[] = $k;
            }
            continue;
        }
        if (!in_array($k, $erlaubt, true)) {
            $mangel[] = sprintf(ap_t('EINST.SICH_FREMD'), $k);
            $falsch[] = $k;
            continue;
        }
        $werte[$k] = $w;
    }
    if (!$werte) {
        $mangel[] = ap_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Pflichtschluessel sind eine Beanstandung, kein stiller Rueckfall (VolkswagenID
     * 0.9.11, 03.09.2026). Fehlende wahlfreie (neu in 1.2.23) behalten den jetzigen Stand. */
    $fehlend = array_values(array_diff($pflicht, array_keys($werte)));
    if ($fehlend) {
        $mangel[] = sprintf(ap_t('EINST.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
    }
    $unveraendert = array_values(array_diff($erlaubt, $pflicht, array_keys($werte)));
    list($gut, $pruef, $pruef_falsch) = ap_werte_pruefen($werte);
    $mangel = array_merge($mangel, $pruef);
    if ($mangel) {
        return array(null, $mangel, count($werte),
                     array_values(array_unique(array_merge($falsch, $fehlend, $pruef_falsch))), array());
    }
    $neu = $bisher;
    foreach ($gut as $k => $v) {
        $neu[$k] = $v;
    }
    return array($neu, array(), count($gut), array(), $unveraendert);
}

/** Die Schluessel einer Sicherung: alle Einstellungen ausser dem Formularmerkmal. */
function ap_sicherung_schluessel()
{
    return array_values(array_diff(array_keys(ap_defaults()),
                                   array('formtoken', 'tts_alexa_token', 'tts_google_token', 'ansage_token_herkunft')));
}

/** Die Fassung aus der Plugindatenbank von LoxBerry, '' wenn nicht feststellbar. */
function ap_fassung()
{
    $p = ap_paths();
    if ($p['home'] === '') {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($p['home'] . '/data/system/plugindatabase.json'), true);
    if (!is_array($j)) {
        return '';
    }
    $liste = (isset($j['plugins']) && is_array($j['plugins'])) ? $j['plugins'] : $j;
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $ordner = isset($e['folder']) ? $e['folder']
                : (isset($e['PLUGINDB_FOLDER']) ? $e['PLUGINDB_FOLDER'] : '');
        if ((string) $ordner === $p['plugin']) {
            return (string) (isset($e['version']) ? $e['version']
                : (isset($e['PLUGINDB_VERSION']) ? $e['PLUGINDB_VERSION'] : ''));
        }
    }
    return '';
}

/**
 * Die Sicherungsdatei (C2): aus ap_config_read(), ohne formtoken, mit
 * lesbarem _-Kopf (Plugin, Stand, Hinweis). Genau diese Datei nimmt
 * ap_sicherung_lesen() wieder an.
 */
function ap_sicherung_json($cfg)
{
    $f = ap_fassung();
    $aus = array(
        '_plugin'  => 'APC-UPS NG (' . ap_paths()['plugin'] . ')',
        '_stand'   => sprintf(ap_t('EINST.SICH_STAND'), $f !== '' ? $f : '?', date('Y-m-d H:i:s')),
        '_hinweis' => ap_t('EINST.SICH_KOPF_HINWEIS'),
    );
    // X-3 (30.09.2026): Wuerde das eigene Zurueckspielen diese Datei
    // abweisen, sagt es der Kopf - nur die Schluesselnamen, nie die Werte.
    // Geliefert wird trotzdem: eine Sicherung, die man nicht bekommt, hilft
    // beim Umzug noch weniger als eine, die man vorher berichtigen muss.
    $maengel = ap_sicherung_eigene_maengel($cfg);
    if ($maengel) {
        $aus['_warnung'] = sprintf(ap_t('EINST.SICH_KOPF_WARNUNG'), implode(', ', $maengel));
    }
    foreach (ap_sicherung_werte($cfg) as $k => $v) {
        $aus[$k] = $v;
    }
    return json_encode($aus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Die Werte einer Sicherung ohne Kopf - genau das, was ap_sicherung_json() schreibt. */
function ap_sicherung_werte($cfg)
{
    $aus = array();
    foreach (ap_sicherung_schluessel() as $k) {
        $aus[$k] = ap_roh($cfg, $k);
    }
    return $aus;
}

/**
 * X-3 (30.09.2026, Bauform KODI-NG U3): Bestuende die eigene Sicherung das
 * Zurueckspielen? Geprueft wird mit ap_sicherung_lesen() - DERSELBEN Funktion
 * wie beim Zurueckspielen, nicht mit einer zweiten Regel, die auseinanderlaufen
 * koennte. Rueckgabe: die beanstandeten Schluessel, leer = besteht.
 * Anlass: eine von Hand geaenderte Konfiguration (etwa intervall=4) oder ein
 * Altwert aus 1.2.13 (Praefix apcups/) wurde gesichert, und erst der Umzug
 * zeigte, dass die Datei nicht zurueckgeht.
 */
function ap_sicherung_eigene_maengel($cfg)
{
    $js = json_encode(ap_sicherung_werte($cfg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return array('?');
    }
    $r = array_pad(ap_sicherung_lesen($js, $cfg), 4, array());
    if ($r[0] !== null) {
        return array();
    }
    return $r[3] ? array_values($r[3]) : array('?');
}

/** Grenzen der Zahlenfelder - EINE Stelle fuer Formular und Sicherung. */
function ap_grenzen()
{
    return array(
        'intervall'       => array(5, 3600),
        'aktualisierung'  => array(5, 86400),
        'vorwarn_min'     => array(0, 600),
        'vorwarn_prozent' => array(0, 100),
        'log_kb'          => array(32, 20480),
        'ansage_sperre'   => array(60, 3600),
    );
}

/** Beschriftung eines Feldes fuer eine Beanstandung: "Anzeigename (schluessel)". */
function ap_feld_name($k)
{
    $namen = array(
        'enabled'          => 'EINST.PLUGIN_EINGESCHALTET',
        'intervall'        => 'EINST.INTERVALL',
        'aktualisierung'   => 'EINST.AKTUALISIERUNG',
        'themenpraefix'    => 'MQTT.PRAEFIX',
        'mqtt'             => 'MQTT.EINSCHALTEN',
        'benachrichtigung' => 'EINST.BENACHRICHTIGUNG',
        'email'            => 'EINST.EMAIL',
        'email_an'         => 'EINST.EMAIL_AN',
        'host'             => 'EINST.HOST',
        'vorwarn_min'      => 'EINST.VORWARN_MIN',
        'vorwarn_prozent'  => 'EINST.VORWARN_PROZENT',
        'rohfelder'        => 'MQTT.ROHFELDER',
        'log_kb'           => 'EINST.LOG_KB',
        'endpunkt_ein'     => 'LOXONE.ENDPUNKT_EIN',
        'ansage_ausfall'     => 'EINST.ANSAGE_AUSFALL',
        'ansage_rueckkehr'   => 'EINST.ANSAGE_RUECKKEHR',
        'ansage_vorwarnung'  => 'EINST.ANSAGE_VORWARNUNG',
        'ansage_abschaltung' => 'EINST.ANSAGE_ABSCHALTUNG',
        'ansage_sperre'      => 'EINST.ANSAGE_SPERRE',
        'haus_usv_ein'     => 'EINST.HAUS_USV_EIN',
    );
    if (strpos((string) $k, 'tts_') === 0) {
        return ap_t('EINST.ANSAGE_UEBERSCHRIFT') . ' (' . $k . ')';
    }
    return isset($namen[$k]) ? ap_t($namen[$k]) . ' (' . $k . ')' : $k;
}

/**
 * Ist das ein zulaessiges Themenpraefix? (M5)
 *
 * Buchstaben, Ziffern, _ und -, Ebenen durch EINEN Schraegstrich getrennt,
 * kein Schraegstrich am Rand, kein # und kein +, hoechstens 64 Zeichen.
 * Dieselbe Regel steht in bin/apc_common.py (praefix_gueltig), nach der der
 * Dienst sendet oder nicht. Bis 1.2.13 nahm die Oberflaeche "apcups/" und
 * "/apcups" an; der Dienst sendete dann apcups//... bzw. /apcups/..., und die
 * Deinstallation fand nichts (gemessen 29.09.2026, Befund M5).
 */
function ap_praefix_gueltig($p)
{
    return is_string($p) && strlen($p) <= 64
        && preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*\z#', $p) === 1;
}

/** Das Praefix, unter dem Dienst und Leeren senden: der gespeicherte Wert,
 *  leer heisst apcups - wie apc_common.mqtt_praefix(). */
function ap_praefix($cfg)
{
    return ap_cfg($cfg, 'themenpraefix', 'apcups');
}

/**
 * Einen Wert pruefen - fuer das Formular UND das Zurueckspielen (C3, C7).
 *
 * Rueckgabe: array(ok, Wert als Zeichenkette, Beanstandung). Nichts wird
 * zurechtgebogen: kein Runden, kein Entfernen von Zeichen, kein Rueckfall auf
 * die Vorgabe. Der Aufrufer schneidet hoechstens Leerraum am Rand ab (nur
 * das Formular). Eine ganze Zahl aus einer JSON-Datei gilt bei Zahlenfeldern
 * als ihre Ziffernfolge; true/false, Listen und Kommazahlen sind die falsche
 * Form.
 */
function ap_wert_pruefen($k, $v)
{
    $name = ap_feld_name($k);
    $g = ap_grenzen();
    if (is_int($v) && isset($g[$k])) {
        $v = (string) $v;
    }
    /* Welle Mehrfach-Ziele (1.2.24): tts_ziele traegt die Liste der weiteren Ziele als JSON (leer = keine). Geprueft
     * vom Modul mit der Modusliste dieser Linie (ansage_wert_pruefen, Schluessel ziele) - im Formularweg und beim
     * Zurueckspielen; uebernommen in fester Form. Eine zuerst geladene aeltere Abschrift kennt die Liste nicht. */
    if ($k === 'tts_ziele') {
        if (!is_string($v)) {
            return array(false, '', sprintf(ap_t('EINST.W_TYP'), $name, gettype($v)));
        }
        $v = trim($v);
        if ($v === '') {
            return array(true, '', '');
        }
        $zl = json_decode($v, true);
        $tg = 'TTS_ZIELE';
        $tp = (is_array($zl) && ap_mit_zielen()) ? ansage_wert_pruefen(array('ziele' => $zl), $tg, ap_ansage_modi()) : null;
        if ($tp === null) {
            return array(false, '', sprintf(ap_t('EINST.W_ANSAGE'), $name, ansage_kennung_text($tg, ap_ansage_k())));
        }
        return array(true, $tp['ziele'] ? (string) json_encode($tp['ziele'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '', '');
    }
    /* 1.2.25: die Regeln (flach). */
    if (in_array((string) $k, array('ansage_tafel_ein', 'ansage_tafel_namen', 'ansage_homepod_ein', 'ansage_homepod_geraet', 'ansage_ruhe_ein', 'ansage_ruhe_von', 'ansage_ruhe_bis', 'ansage_sperre_min', 'ansage_token_herkunft'), true)) {
        $ap_rg = function ($k, $w) {
        if ($k === 'ansage_token_herkunft') { return $w === '' || is_array(json_decode($w, true)); }
        if ($k === 'ansage_ruhe_ein') { return $w === '0' || $w === '1'; }
        if ($k === 'ansage_tafel_ein') { return $w === '0' || $w === '1'; }
        if ($k === 'ansage_tafel_namen') { return ansage_tafel_namen_ok($w); }
        if ($k === 'ansage_homepod_ein') { return $w === '0' || $w === '1'; }
        if ($k === 'ansage_homepod_geraet') { return ansage_geraet_ok($w); }
        if ($k === 'ansage_ruhe_von' || $k === 'ansage_ruhe_bis') { return is_string($w) && (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $w); }
        if ($k === 'ansage_sperre_min') { return is_string($w) && ($w === '' || ((bool) preg_match('/^[0-9]{1,3}\z/', $w) && (int) $w >= 5 && (int) $w <= 240)); }
            return false;
        };
        if (is_string($v) && $ap_rg((string) $k, trim($v))) { return array(true, trim($v), ''); }
        return array(false, '', sprintf(ap_t('EINST.W_ANSAGE'), (string) $k, (string) $k));
    }
    /* V1 (1.2.23): die Schluessel des Blocks tts prueft die Stammfassung (ansage_wert_pruefen) -
     * dieselbe Pruefung wie das Formular (ansage_formular_lesen) und das Zurueckspielen. */
    if (strpos((string) $k, 'tts_') === 0 && function_exists('ansage_vorgaben')
        && array_key_exists(substr((string) $k, 4), ansage_vorgaben('aus'))) {
        $ts = substr((string) $k, 4);
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            return array(false, '', sprintf(ap_t('EINST.W_TYP'), $name, gettype($v)));
        }
        $tg = '';
        $tp = ansage_wert_pruefen(array($ts => $v), $tg, ap_ansage_modi());
        if ($tp === null) {
            return array(false, '', sprintf(ap_t('EINST.W_ANSAGE'), $name,
                                            ansage_kennung_text($tg, ap_ansage_k())));
        }
        return array(true, (string) $tp[$ts], '');
    }
    if (!is_string($v)) {
        return array(false, '', sprintf(ap_t('EINST.W_TYP'), $name, gettype($v)));
    }
    switch ($k) {
        case 'enabled':
        case 'mqtt':
        case 'benachrichtigung':
        case 'email':
        case 'endpunkt_ein':
        case 'ansage_ausfall':
        case 'ansage_rueckkehr':
        case 'ansage_vorwarnung':
        case 'ansage_abschaltung':
        case 'haus_usv_ein':
            return ($v === '0' || $v === '1') ? array(true, $v, '')
                : array(false, '', sprintf(ap_t('EINST.W_SCHALTER'), $name, $v));
        case 'host':
            // Leer heisst: die oertliche USV.
            return ($v === '' || preg_match('/^[A-Za-z0-9._-]+(:[0-9]{1,5})?\z/', $v) === 1)
                ? array(true, $v, '')
                : array(false, '', sprintf(ap_t('TEXT.HOST_UNGUELTIG'), $v));
        case 'email_an':
            // Ein oertlicher Benutzer (root) oder eine Adresse. Leerraum,
            // Steuerzeichen und Anfuehrungszeichen werden abgewiesen, nicht
            // entfernt; es faellt nichts auf root zurueck.
            $ok = preg_match('/[\x00-\x20\x7F"\']/', $v) !== 1
                && ((strpos($v, '@') === false && ap_benutzer_existiert($v))
                    || filter_var($v, FILTER_VALIDATE_EMAIL) !== false);
            return $ok ? array(true, $v, '')
                : array(false, '', sprintf(ap_t('EINST.EMAIL_UNGUELTIG'), $v));
        case 'themenpraefix':
            return ap_praefix_gueltig($v) ? array(true, $v, '')
                : array(false, '', sprintf(ap_t('MQTT.PRAEFIX_UNGUELTIG'), $v));
        case 'rohfelder':
            // Die Form, die das Formular speichert: Grossbuchstaben, Komma,
            // jeder Name einmal. Ein doppelter Name wird beanstandet, nicht
            // still gestrichen (Nr. 19, 01.10.2026) - wie im Formular.
            if ($v !== '' && preg_match('/^[A-Z][A-Z0-9_]{0,31}(,[A-Z][A-Z0-9_]{0,31})*\z/', $v) !== 1) {
                return array(false, '', sprintf(ap_t('EINST.W_ROHFELDER'), $name, $v));
            }
            $liste = ($v === '') ? array() : explode(',', $v);
            $doppelt = array_values(array_unique(array_diff_assoc($liste, array_unique($liste))));
            return $doppelt
                ? array(false, '', sprintf(ap_t('MQTT.ROHFELD_DOPPELT'), $name, implode(', ', $doppelt)))
                : array(true, $v, '');
    }
    if (isset($g[$k])) {
        list($min, $max) = $g[$k];
        // E3 (1.2.23): preg_match statt ctype_digit (Regeln/03).
        if ($v !== '' && strlen($v) <= 9 && preg_match('/^[0-9]+\z/', $v) === 1
            && (int) $v >= $min && (int) $v <= $max) {
            return array(true, (string) (int) $v, '');
        }
        return array(false, '', sprintf(ap_t('EINST.W_ZAHL'), $name, $v, $min, $max));
    }
    return array(false, '', sprintf(ap_t('EINST.SICH_FREMD'), $k));
}

/** Mehrere Werte pruefen. Rueckgabe: array(gute Werte, Beanstandungen,
 *  beanstandete Schluessel). Den dritten Wert braucht die Markierung des
 *  Feldes nach einer Beanstandung (X-2). */
function ap_werte_pruefen($werte)
{
    $gut = array();
    $mangel = array();
    $falsch = array();
    foreach ($werte as $k => $v) {
        list($ok, $w, $text) = ap_wert_pruefen((string) $k, $v);
        if ($ok) {
            $gut[$k] = $w;
        } else {
            $mangel[] = $text;
            $falsch[] = (string) $k;
        }
    }
    return array($gut, $mangel, $falsch);
}

/**
 * Das Feld "Rohfelder" aus dem Formular lesen (Nr. 19, 01.10.2026).
 *
 * Rueckgabe: array(ok, gespeicherte Form "A,B", Beanstandung). Getrennt wird an
 * Komma, Semikolon und Leerraum; Kleinbuchstaben werden zu Grossbuchstaben.
 * Beides aendert keinen Namen: apcaccess schreibt jeden Feldnamen gross. Alles
 * andere wird beanstandet, statt still verworfen zu werden: ein Stueck, das
 * kein Feldname ist, ein doppelter Name und ein Wert, der keine Zeichenkette
 * ist. Bis 1.2.16 fielen alle drei still weg (bzw. wurden zu ARRAY), und der
 * Rest wurde gespeichert. Die gespeicherte Form prueft ap_wert_pruefen().
 */
function ap_rohfelder_lesen($v)
{
    $name = ap_feld_name('rohfelder');
    if (!is_string($v)) {
        return array(false, '', sprintf(ap_t('EINST.W_TYP'), $name, gettype($v)));
    }
    $gut = array();
    $schlecht = array();
    $doppelt = array();
    foreach (preg_split('/[,;\s]+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $stueck) {
        $s = strtoupper($stueck);
        if (preg_match('/^[A-Z][A-Z0-9_]{0,31}\z/', $s) !== 1) {
            $schlecht[] = $stueck;
        } elseif (in_array($s, $gut, true)) {
            if (!in_array($s, $doppelt, true)) {
                $doppelt[] = $s;
            }
        } else {
            $gut[] = $s;
        }
    }
    $text = array();
    if ($schlecht) {
        $text[] = sprintf(ap_t('MQTT.ROHFELD_BEANSTANDET'), $name, implode(', ', $schlecht));
    }
    if ($doppelt) {
        $text[] = sprintf(ap_t('MQTT.ROHFELD_DOPPELT'), $name, implode(', ', $doppelt));
    }
    if ($text) {
        return array(false, '', implode(' ', $text));
    }
    return array(true, implode(',', $gut), '');
}

/** Hinweis nach einem Speichern, das das Praefix wechselt oder MQTT abschaltet (M4). */
function ap_praefix_wechsel_hinweis($alt, $neu)
{
    $ap = ap_praefix($alt);
    $alt_an = ap_cfg($alt, 'mqtt', '1') === '1';
    $neu_an = ap_cfg($neu, 'mqtt', '1') === '1';
    if ($alt_an && (!$neu_an || ap_praefix($neu) !== $ap)) {
        return sprintf(ap_t('MQTT.ALT_RAEUMEN'), $ap);
    }
    return '';
}

/**
 * Eine Datei ganz schreiben: daneben, Rechte VOR dem Inhalt, Laenge und
 * Ruecklesen pruefen, dann umbenennen (Regeln/03). false heisst: am alten
 * Stand hat sich nichts geaendert.
 */
function ap_datei_schreiben($pfad, $inhalt, $modus)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    $neben = $pfad . '.neu' . getmypid();
    $fh = @fopen($neben, 'wb');
    if ($fh === false) {
        return false;
    }
    @chmod($neben, $modus);
    $n = @fwrite($fh, $inhalt);
    $ok = ($n === strlen($inhalt)) && @fflush($fh);
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        $ok = (@file_get_contents($neben) === $inhalt);
    }
    if (!$ok || !@rename($neben, $pfad)) {
        @unlink($neben);
        return false;
    }
    return true;
}

/* ---------------- Einmalmeldung nach dem POST (U1) ----------------
 * Regeln/04: jeder POST endet mit 303, das Ergebnis reist als Datei im
 * Datenordner (0600, 120 s gueltig, beim naechsten GET gelesen und geloescht).
 * Das Formularmerkmal steht darin nur als *** (Regeln/04, Nachtrag Raumklima
 * 17.09.2026: die Einmalmeldung traegt kein Token). */
function ap_meldung_datei()
{
    return ap_paths()['data'] . '/einmalmeldung.json';
}

function ap_meldung_ablegen($daten, $geheim)
{
    $daten['zeit'] = time();
    $geheim = (string) $geheim;
    if ($geheim !== '') {
        array_walk_recursive($daten, function (&$w) use ($geheim) {
            if (is_string($w)) {
                $w = str_replace($geheim, '***', $w);
            }
        });
    }
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                              | JSON_INVALID_UTF8_SUBSTITUTE);
    return $js !== false && ap_datei_schreiben(ap_meldung_datei(), $js, 0600);
}

function ap_meldung_abholen()
{
    $f = ap_meldung_datei();
    clearstatcache();
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $liste = function ($s) use ($d) {
        return (isset($d[$s]) && is_array($d[$s]))
            ? array_values(array_filter($d[$s], 'is_string')) : array();
    };
    $text = function ($s) use ($d) {
        return (isset($d[$s]) && is_string($d[$s])) ? $d[$s] : '';
    };
    return array(
        'saved'       => !empty($d['saved']),
        'fehler'      => $liste('fehler'),
        'fehler_html' => $liste('fehler_html'),
        'hinweise'    => $liste('hinweise'),
        'test_titel'  => $text('test_titel'),
        'test_text'   => $text('test_text'),
        'eingaben'    => ap_eingaben_lesen(isset($d['eingaben']) ? $d['eingaben'] : null),
    );
}

/* ---------------- Eingaben nach einer Beanstandung (X-2) ----------------
 * Regeln/04, "Nach einer Beanstandung stehen die eingetippten Werte wieder im
 * Formular" (Hausregel seit 30.09.2026, Anlass APC-UPS 1.2.14 gemessen): Mit
 * der Einmalmeldung reisen die Eingaben des EINEN beanstandeten Formulars,
 * nur seine Felder, und die beanstandeten Schluessel. Rechte, Ort und Alter
 * sind die der Einmalmeldung (0600, Datenordner, 120 s, beim GET geloescht).
 * Geheimnisse gibt es in beiden Formularen keine; das Formularmerkmal ist
 * kein Formularfeld und wird von ap_meldung_ablegen() ohnehin ersetzt. */
function ap_eingaben_felder($formular)
{
    $felder = array(
        'settings' => array_merge(array('enabled', 'intervall', 'aktualisierung', 'host',
                            'vorwarn_min', 'vorwarn_prozent', 'log_kb',
                            'benachrichtigung', 'email', 'email_an', 'haus_usv_ein',
                            'ansage_ausfall', 'ansage_rueckkehr', 'ansage_vorwarnung',
                            'ansage_abschaltung'),
                            function_exists('ansage_x2_felder') ? ansage_x2_felder(ap_ansage_opt()) : array(),
                            function_exists('ansage_regeln_x2_felder') ? ansage_regeln_x2_felder() : array(),
                            array('ansage_tafel_ein', 'ansage_tafel_namen', 'ansage_homepod_ein', 'ansage_homepod_geraet'),     // 1.2.28: Tafel und HomePod dazu: ansage_regeln_formular() beanstandet sie, ohne Eintrag hier ging die Beanstandung samt Eingaben verloren.
                            ap_ziele_x2_felder()),
        'mqtt'     => array('mqtt', 'themenpraefix', 'rohfelder'),
        'endpunkt' => array('endpunkt_ein'),
    );
    return isset($felder[$formular]) ? $felder[$formular] : array();
}

/** Die Eingaben eines beanstandeten Formulars fuer die Einmalmeldung. Ein Wert,
 *  der keine Zeichenkette ist (etwa host[]=x), reist als leeres Feld. */
function ap_eingaben_merken($formular, $werte, $falsch)
{
    $felder = ap_eingaben_felder($formular);
    $aus = array();
    foreach ($felder as $k) {
        if (array_key_exists($k, $werte)) {
            $aus[$k] = is_string($werte[$k]) ? $werte[$k] : '';
        }
    }
    return array('formular' => $formular, 'werte' => $aus,
                 'falsch'   => array_values(array_intersect($felder, (array) $falsch)));
}

/** Die Eingaben aus der Einmalmeldung - nur bekannte Formulare und Felder,
 *  nur Zeichenketten. null, wenn nichts Brauchbares darin steht. */
function ap_eingaben_lesen($e)
{
    if (!is_array($e) || !isset($e['formular']) || !is_string($e['formular'])) {
        return null;
    }
    $felder = ap_eingaben_felder($e['formular']);
    if (!$felder) {
        return null;
    }
    $werte = array();
    if (isset($e['werte']) && is_array($e['werte'])) {
        foreach ($felder as $k) {
            if (isset($e['werte'][$k]) && is_string($e['werte'][$k])) {
                $werte[$k] = $e['werte'][$k];
            }
        }
    }
    $falsch = (isset($e['falsch']) && is_array($e['falsch']))
        ? array_values(array_intersect($felder, array_filter($e['falsch'], 'is_string')))
        : array();
    if (!$werte) {
        return null;
    }
    return array('formular' => $e['formular'], 'werte' => $werte, 'falsch' => $falsch);
}

/**
 * Die Abo-Datei des MQTT-Gateways (M9): config/plugins/<ordner>/mqtt_subscriptions.cfg
 * mit <praefix>/#. Das Gateway liest sie selbst (am Geraet belegt am
 * 13.09.2026 an Midea2Lox; Bauform eb_abo_datei(), Einspeisebremse 0.9.28).
 * Geschrieben wird nur ein gueltiges Praefix und nur, wenn die Datei
 * abweicht. Rueckgabe: array(Pfad, traegt das Abo).
 */
function ap_abo_datei($praefix, $schreiben = false)
{
    $pfad = dirname(ap_paths()['config']) . '/mqtt_subscriptions.cfg';
    $soll = $praefix . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && ap_praefix_gueltig($praefix) && $roh !== $soll . "\n"
        && is_dir(dirname($pfad))) {
        if (ap_datei_schreiben($pfad, $soll . "\n", 0644)) {
            $da = true;
        }
    }
    return array($pfad, $da);
}

/**
 * Eine Adresse abrufen und den HTTP-Code aus den Kopfzeilen lesen (C8).
 * Rueckgabe: array(Inhalt oder false, Code; 0 = kein Code erkennbar).
 *
 * Ueber fopen() und stream_get_meta_data() statt ueber die alte
 * Kopfzeilen-Variable von PHP: 8.5 meldet sie schon beim Uebersetzen als
 * ueberholt, und PHP 9 soll sie abschaffen - dann hiesse jeder Code 0 und die
 * Pruefzeile "Endpunkt" stuende dauerhaft auf Kreuz. Bauform eb_http_abruf()
 * (Einspeisebremse 0.9.26).
 */
function ap_http_abruf($url, $ctx)
{
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(false, 0);
    }
    $meta = @stream_get_meta_data($fp);
    $t = @stream_get_contents($fp);
    @fclose($fp);
    $code = 0;
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    return array($t, $code);
}

/* ==================================================================
 * Seit 1.2.23 (Gruppe 16): Zweitschrift, Webport, Endpunkt, Sprachausgabe (V1),
 * Haus-Thema (V2), Namenstabelle und V2-Hakenliste
 * ================================================================== */

/** Die Sicherung der Pflichtschluessel: die 13 Einstellungen bis 1.2.22 (N5). */
function ap_sicherung_pflicht()
{
    return array('enabled', 'intervall', 'aktualisierung', 'themenpraefix', 'mqtt', 'benachrichtigung',
                 'email', 'email_an', 'host', 'vorwarn_min', 'vorwarn_prozent', 'rohfelder', 'log_kb');
}

/**
 * A4 (1.2.23): die Zweitschrift <home>/config/plugins/<ordner>.backup.apc_ups_ng.cfg bei jedem
 * Speichern mitschreiben - nur mit Wurzel (Archivmodus nichts) und nur, wenn der Inhalt einen
 * vollstaendigen Stand traegt (dieselbe Regel wie apc_cfg_hat_inhalt in den Hakenskripten: Kopf
 * [apc_ups_ng] und formtoken mit 32 Hexzeichen). Geschrieben ueber GENAU "<ziel>.neu" (den Namen
 * raeumt uninstall ab), Rechte 0600 vor dem Inhalt, Ruecklesen, rename (Regeln/05, Bauart
 * sp_zweitschrift_ziehen). Rueckgabe true geschrieben, false gescheitert, null nicht anwendbar.
 */
function ap_zweitschrift_schreiben($txt)
{
    $p = ap_paths();
    if ($p['home'] === '') {
        return null;
    }
    if (preg_match('/^\[apc_ups_ng\][ \t]*$/m', $txt) !== 1
        || preg_match('/^formtoken=[0-9a-fA-F]{32}[ \t]*$/m', $txt) !== 1) {
        return null;
    }
    $ziel = $p['home'] . '/config/plugins/' . $p['plugin'] . '.backup.apc_ups_ng.cfg';
    $neben = $ziel . '.neu';
    // Erst fragen, dann loeschen: ein gesetzter Fehlerbehandler saehe die Warnung trotz @ (rendern.py, 06.09.2026).
    if (is_file($neben)) {
        @unlink($neben);
    }
    $fh = @fopen($neben, 'xb');
    if ($fh === false) {
        return false;
    }
    @chmod($neben, 0600);
    $ok = (@fwrite($fh, $txt) === strlen($txt)) && @fflush($fh);
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        $ok = (@file_get_contents($neben) === $txt);
    }
    if (!$ok || !@rename($neben, $ziel)) {
        @unlink($neben);
        return false;
    }
    return true;
}

/** A2 (1.2.23): der Webserver-Port des LoxBerry (general.json Webserver.Port, Rueckfall 80) - ueber
 *  ansage_webport() der Stammfassung (die Oberflaeche laedt sie wegen V1 ohnehin). */
function ap_webport()
{
    $home = ap_paths()['home'];
    if ($home === '' || !is_file($home . '/config/system/general.json')) {
        return 80;
    }
    if (function_exists('ansage_webport')) {
        return (int) ansage_webport($home . '/config/system/general.json', true);
    }
    $j = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
    foreach (array('Webserver', 'WEBSERVER', 'webserver') as $a) {
        if (isset($j[$a]['Port']) && preg_match('/^[0-9]{1,5}\z/', trim((string) $j[$a]['Port']))
            && (int) $j[$a]['Port'] >= 1 && (int) $j[$a]['Port'] <= 65535) {
            return (int) $j[$a]['Port'];
        }
    }
    return 80;
}

/** A2: die LAN-Adresse des LoxBerry (LBSystem::get_localip(), sonst der Rechnername, sonst loxberry). */
function ap_lan_adresse()
{
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip')) {
        $ip = trim((string) @LBSystem::get_localip());
        if ($ip !== '') {
            return $ip;
        }
    }
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

/** A2: die Adresse des Lese-Endpunkts aus Sicht des Miniservers (":port" nur, wenn nicht 80). */
function ap_endpunkt_adresse()
{
    $port = ap_webport();
    return 'http://' . ap_lan_adresse() . ($port !== 80 ? ':' . $port : '')
         . '/plugins/' . ap_paths()['plugin'] . '/index.php';
}

/** E5: ist der Lese-Endpunkt eingeschaltet? (Lese-Regel in ap_config_read()) */
function ap_endpunkt_ein($cfg)
{
    return ap_roh($cfg, 'endpunkt_ein') === '1';
}

/* ---------------- V1: Sprachausgabe (Stammfassung sprachausgabe.php, seit 1.2.24 Stamm 1.2.1 mit weiteren Ausgabezielen,
 * ap_mit_zielen(), Welle Mehrfach-Ziele) ---------------- */

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' - der Original-Audioserver hat keine
 *  HTTP-Schnittstelle (Kopf der Stammfassung); dort sagt Loxone ueber die Benachrichtigungen an.
 *  Seit 1.2.24 (Nachtwelle N9, Modul 1.2.0, Pflegewelle 22) dazu SonoX - nur ab Modul 1.1.4, damit eine zuerst
 *  geladene aeltere Abschrift nichts anbietet, was sie nicht kann (ungemessen, kein Sonos im Haus). Weitere
 *  Ausgabeziele (Tabelle, 'ziele') seit 1.2.24 mit Stamm 1.2.1 - ap_mit_zielen() unten; ohne Tabelle wie 1.1.4. */
function ap_ansage_modi()
{
    $m = array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox', 'sonos4lox');
    if (defined('ANSAGE_FASSUNG') && version_compare(ANSAGE_FASSUNG, '1.1.4', '>=')) {
        $m[] = 'sonox';
    }
    return $m;
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1): bietet diese Linie die Tabelle der weiteren Ausgabeziele an? Nur, wenn die
 *  geladene Abschrift sie kann - laden zwei Abschriften im selben Prozess, gilt die zuerst geladene. */
function ap_mit_zielen() {
    return version_compare(ANSAGE_FASSUNG, '1.2.0', '>=') && function_exists('ansage_ziele_html')
        && function_exists('ansage_ziele_pruefen') && function_exists('ansage_testknoepfe_html');
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1), X-2 mit flachen Schluesseln (name.N): die Felder der Tabelle heissen im
 *  Formular name[N] (N = 2..6, ansage_ziel_feldnamen()). ap_ziele_x2_name() macht daraus name.N (Beanstandung und
 *  Markierung), ap_ziele_x2_post() aus den gesammelten Eingaben wieder das POST der Tabelle (ziele_post). */
function ap_ziele_x2_name($n) {
    return is_string($n) ? (string) preg_replace('/^(tts_ziel_[a-z]+)\[([2-9])\]\z/', '$1.$2', $n) : $n;
}
function ap_ziele_x2_post(array $werte) {
    $p = array();
    foreach ($werte as $k => $v) {
        if (is_string($v) && preg_match('/^(tts_ziel_[a-z]+)\.([2-9])\z/', (string) $k, $m)) { $p[$m[1]][(int) $m[2]] = $v; }
    }
    return isset($p['tts_ziel_art']) ? $p : null;
}

/** Welle Mehrfach-Ziele (1.2.24): die Felder der Tabelle in der Einmalmeldung (X-2), flach name.N fuer N = 2..6 -
 *  nur, wenn die geladene Abschrift die Tabelle kann. */
function ap_ziele_x2_felder()
{
    $f = array();
    if (ap_mit_zielen()) {
        for ($i = 2; $i <= 6; $i++) {
            foreach (array('tts_ziel_art', 'tts_ziel_wo', 'tts_ziel_laut', 'tts_ziel_sprache', 'tts_ziel_ein',
                           'tts_ziel_loeschen') as $n) {
                $f[] = $n . '.' . $i;
            }
        }
    }
    return $f;
}

function ap_ansage_opt()
{
    return array('modi' => ap_ansage_modi());
}

/** Der Block tts aus den Schluesseln tts_* der Konfiguration, vervollstaendigt (ab Werk 'aus'). */
function ap_tts($cfg)
{
    $tts = array();
    if (function_exists('ansage_vorgaben')) {
        foreach (ansage_vorgaben('aus') as $s => $vorgabe) {
            $w = isset($cfg['tts_' . $s]) ? (string) $cfg['tts_' . $s] : (string) $vorgabe;
            $tts[$s] = is_int($vorgabe) && preg_match('/^-?[0-9]{1,6}\z/', $w) ? (int) $w : $w;
        }
        /* Welle Mehrfach-Ziele (1.2.24): die weiteren Ziele stehen als JSON-Liste in tts_ziele (leer = keine). Eine
         * Liste, die kein JSON ist, bleibt draussen - die Sicherung nennt sie dann im Kopf (X-3). */
        $zr = isset($cfg['tts_ziele']) ? trim((string) $cfg['tts_ziele']) : '';
        $zl = ($zr !== '') ? json_decode($zr, true) : null;
        if (is_array($zl) && $zl !== array()) {
            $tts['ziele'] = $zl;
        }
        list($tts) = ansage_vervollstaendigen($tts, 'aus');
        /* 1.2.25: Music Server ohne Adresse und ohne weitere Ziele = nie eingerichtet: ab Werk mit Google und Echo. Eine eingetragene Adresse oder Tabelle bleibt. */
        if ($tts['mode'] === ansage_werk_modus() && (string) $tts['ip'] === '' && empty($tts['ziele'])) {
            $ap_w = ansage_werk_block();
            $tts['ziele'] = $ap_w['ziele'];
        }
    }
    return $tts;
}

/** Der Block tts zurueck in die Schluessel tts_* (Zeichenketten) fuer ap_config_write(). Seit 1.2.24 (Welle
 *  Mehrfach-Ziele) die Liste der weiteren Ziele als JSON in tts_ziele - leer, wenn der Block keine traegt (eine
 *  geleerte Tabelle loescht die gespeicherte Liste). */
function ap_tts_cfg($tts)
{
    $aus = array();
    foreach ((array) $tts as $s => $w) {
        if ($s === 'ziele') {
            continue;
        }
        $aus['tts_' . $s] = (string) $w;
    }
    $aus['tts_ziele'] = (is_array($tts) && isset($tts['ziele']) && is_array($tts['ziele']) && $tts['ziele'] !== array())
        ? (string) json_encode(array_values($tts['ziele']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    return $aus;
}

/** Der Kontext des Moduls: Webport, https-Port, Wurzel, Kopfzeile, Ordner fuer <art>_letzte.json
 *  (der Datenordner, wenn er da ist - das Modul legt keine Ordner an), Texte aus [ANSAGE]. */
function ap_ansage_k()
{
    $p = ap_paths();
    $g = $p['home'] !== '' ? $p['home'] . '/config/system/general.json' : '';
    return array(
        'werk' => ansage_werk_modus(),
        'port'     => ap_webport(),
        'sslport'  => ($g !== '' && function_exists('ansage_sslport')) ? ansage_sslport($g) : 0,
        'home'     => $p['home'],
        'kopf'     => array('User-Agent: LoxBerry APC-UPS NG'),
        'ordner'   => @is_dir($p['data']) ? $p['data'] : '',
        't'        => function ($s) { return ap_t($s); },
        'absender' => 'apc_ups_ng',
    );
}

/** Die Konfiguration fuer die Sprachansagen. */
function ap_ansage_conf()
{
    list($c) = ap_config_read();
    return $c;
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (1.2.25, Welle S2, Stamm sprachausgabe.php 1.3.2) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('AP_ANSAGE_HOECHSTALTER')) { define('AP_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function ap_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = ap_ansage_conf(); }
    $g = '';
    $r = ansage_regeln_vorgaben();
    /* Die Konfiguration ist flach (nur Zeichenketten). */
    if (isset($cfg['ansage_ruhe_von'], $cfg['ansage_ruhe_bis'])) {
        $r = array('nachtruhe' => (string) (isset($cfg['ansage_ruhe_ein']) ? $cfg['ansage_ruhe_ein'] : '1') === '0' ? 0 : 1, 'von' => (string) $cfg['ansage_ruhe_von'],
                   'bis' => (string) $cfg['ansage_ruhe_bis'], 'sperre_min' => (isset($cfg['ansage_sperre_min']) && (string) $cfg['ansage_sperre_min'] !== '') ? (int) $cfg['ansage_sperre_min'] : ((function ($s) { $s = (int) $s; return ($s <= 0 || $s === 600) ? 30 : max(5, min(240, (int) round($s / 60))); })(isset($cfg['ansage_sperre']) ? $cfg['ansage_sperre'] : 600)));
        // Stamm 1.4.0: die Tafel. Fehlt der Schluessel, gilt die Vorgabe des Stamms (an).
        if (isset($cfg['ansage_tafel_ein']) && (string) $cfg['ansage_tafel_ein'] === '0') { $r['tafel'] = 0; }
        if (isset($cfg['ansage_tafel_namen'])) { $r['tafel_namen'] = (string) $cfg['ansage_tafel_namen']; }
        if (isset($cfg['ansage_homepod_ein']) && (string) $cfg['ansage_homepod_ein'] === '0') { $r['homepod'] = 0; }     // 1.2.28
        if (isset($cfg['ansage_homepod_geraet'])) { $r['homepod_geraet'] = (string) $cfg['ansage_homepod_geraet']; }
    }
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function ap_ansage_warte($fn)
{
    $d = ap_paths()['data'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function ap_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    ap_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; ap_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function ap_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = ap_ansage_conf(); }
    list($neu, $her2, $g) = ansage_token_abgleich(ap_tts($cfg), ap_ansage_her($cfg), ap_ansage_k());
    if (!$g) { return false; }
    $cfg = ap_ansage_conf();
    $cfg = array_merge($cfg, ap_tts_cfg($neu));
    $cfg['ansage_token_herkunft'] = (string) json_encode($her2);
    if (!ap_config_write($cfg)) { return false; }
    ap_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function ap_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = ap_ansage_conf(); }
    return ansage_tafel_einreihen(ansage_token_fuer_lauf(ap_tts($cfg), ap_ansage_her($cfg), ap_ansage_k()), ap_ansage_regeln($cfg), ap_ansage_k(), true);     // Stamm 1.4.0: die Tafel, wenn sie bereitsteht
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function ap_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = ap_ansage_conf(); }
    $t = ap_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(ap_ansage_vor_ort($cfg), ap_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function ap_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = ap_ansage_conf(); }
    $k = ap_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(ap_tts($cfg), ap_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function ap_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function ap_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = ap_ansage_conf();
    $k = ap_ansage_k();
    $t = $tts === null ? ap_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, ap_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function ap_ansage_senden($jetzt = null)
{
    $d = ap_paths()['data'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = ap_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= AP_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = ap_ansage_conf();
        ap_ansage_token_pflegen($conf);
        $conf = ap_ansage_conf();
        $a = ansage_regel_sprechen($frisch, ap_tts($conf), ap_ansage_regeln($conf), ap_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { ap_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            ap_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/** Der Vermerk der uebernommenen Sprechtoken (flach als JSON). */
function ap_ansage_her($cfg)
{
    $h = (is_array($cfg) && isset($cfg['ansage_token_herkunft'])) ? json_decode((string) $cfg['ansage_token_herkunft'], true) : null;
    return is_array($h) ? $h : array();
}

/** Der Ansagetext eines Anlasses aus [ANSAGE_TEXT] (de/en ueber den Erzeuger); ohne Werte, wenn
 *  Ladung oder Restzeit -1 (keine Aussage) sind. */
function ap_ansage_text($anlass, $ladung = -1, $rest = -1)
{
    $ladung = (int) $ladung;
    $rest = (int) $rest;
    switch ($anlass) {
        case 'ausfall':
            return ($ladung >= 0 && $rest >= 0)
                ? sprintf(ap_t('ANSAGE_TEXT.AUSFALL'), $ladung, $rest) : ap_t('ANSAGE_TEXT.AUSFALL_OHNE');
        case 'rueckkehr':
            return ap_t('ANSAGE_TEXT.RUECKKEHR');
        case 'vorwarnung':
            return $rest >= 0 ? sprintf(ap_t('ANSAGE_TEXT.VORWARNUNG'), $rest) : ap_t('ANSAGE_TEXT.VORWARNUNG_OHNE');
        case 'abschaltung':
            return ap_t('ANSAGE_TEXT.ABSCHALTUNG');
    }
    return '';
}

/** Welle Mehrfach-Ziele (1.2.24): eine Zeile der Oberflaeche ins Protokoll des Dienstes (apc_ups_ng.log) - die
 *  Testansage nennt dort ihr Ergebnis wie in den anderen Linien, nur die Kurzform ohne Text und Token (Nr. 18).
 *  Legt keinen Ordner an (Archivmodus, Pruefstand ohne Protokollordner: nichts). */
function ap_log($text)
{
    $d = ap_paths()['logdir'];
    if (is_dir($d)) {
        @file_put_contents($d . '/apc_ups_ng.log', date('Y-m-d H:i:s') . ' [INFO] Oberflaeche: ' . $text . "\n",
                           FILE_APPEND | LOCK_EX);
    }
}

/** Die letzte Ansage des Dienstes (data/<ordner>/ansage_dienst.json) oder null. */
function ap_ansage_dienst()
{
    $f = ap_paths()['data'] . '/ansage_dienst.json';
    if (!is_file($f)) {
        return null;
    }
    $d = @json_decode((string) @file_get_contents($f), true);
    return (is_array($d) && isset($d['zeit'], $d['anlass'])) ? $d : null;
}

/* ---------------- V2: haus/usv/stufe ---------------- */

/** Liegt der Merker "haus/usv/stufe gesendet" (config/plugins/<ordner>/haus_usv_gesendet)? */
function ap_haus_usv_merker()
{
    return is_file(dirname(ap_paths()['config']) . '/haus_usv_gesendet');
}

/* ---------------- Namenstabelle und V2-Hakenliste (Entwurf 2.4) ---------------- */

/** Die 36 Haken der Musterseiten unter Gateway V2, in der Reihenfolge der Seiten; true = Noncached. */
function ap_v2_haken()
{
    return array(
        'status/zaehler' => false, 'status/ok' => true, 'service/online' => true, 'on_battery' => false,
        'alarm_level' => false, 'data_valid' => false, 'replace_battery' => false, 'time_left' => false,
        'battery_charge' => false, 'time_on_battery' => false, 'shutdown_pending' => false,
        'status' => false, 'event' => false, 'last_error' => false,
        'load_percent' => false, 'load_watt' => false, 'nominal_power' => false, 'line_voltage' => false,
        'output_voltage' => false, 'line_frequency' => false, 'battery_voltage' => false,
        'nominal_battery_volt' => false, 'internal_temp' => false, 'battery_age_months' => false,
        'transfers' => false, 'cumulative_on_battery' => false, 'shutdown_charge' => false,
        'shutdown_minutes' => false, 'shutdown_timeout' => false, 'on_line' => false, 'model' => false,
        'serial' => false, 'battery_date' => false, 'self_test_result' => false,
        'self_test_interval' => false, 'last_transfer' => false,
    );
}

/** Wo das Thema im Musterprojekt steht: Seite 1 "APC-UPS NG", Seite 2 "... Akku und Netz". */
function ap_musterprojekt_stellen()
{
    return array(
        'on_battery' => '1 #1', 'alarm_level' => '1 #2', 'data_valid' => '1 #3',
        'replace_battery' => '1 #4, 2 #12', 'time_left' => '1 #5', 'status/zaehler' => '1 #6',
        'service/online' => '1 #7', 'battery_charge' => '1 #16, 2 #1', 'status/ok' => '1 #17',
        'time_on_battery' => '1 #26', 'shutdown_pending' => '1 #31', 'status' => '1 #33',
        'event' => '1 #34', 'last_error' => '1 #35', 'load_percent' => '2 #2', 'load_watt' => '2 #3',
        'nominal_power' => '2 #4', 'line_voltage' => '2 #5', 'output_voltage' => '2 #6',
        'line_frequency' => '2 #7', 'battery_voltage' => '2 #8', 'nominal_battery_volt' => '2 #9',
        'internal_temp' => '2 #10', 'battery_age_months' => '2 #11', 'transfers' => '2 #13',
        'cumulative_on_battery' => '2 #14', 'shutdown_charge' => '2 #15', 'shutdown_minutes' => '2 #16',
        'shutdown_timeout' => '2 #17', 'on_line' => '2 #18', 'model' => '2 #19', 'serial' => '2 #20',
        'battery_date' => '2 #21', 'self_test_result' => '2 #22', 'self_test_interval' => '2 #23',
        'last_transfer' => '2 #24',
    );
}

/**
 * Die Namenstabelle: je Thema aus apc_themen.json der Name, unter dem das MQTT-Gateway es in Loxone
 * fuehrt (ap_gateway_name(), EINE Stelle), die Bauform (Virtueller Eingang / Virtueller
 * Texteingang), Einheit, Bereich (mit Fehlwert), retained, V2 ('' / 'H' Haken / 'N' Haken und
 * Noncached), Fehlwert, Stelle im Musterprojekt, Bedeutung und der Vermerk "Bestand".
 */
function ap_mqtt_namen($cfg)
{
    $praefix = ap_praefix($cfg);
    $haken = ap_v2_haken();
    $stellen = ap_musterprojekt_stellen();
    $aus = array();
    foreach (ap_themen() as $k => $info) {
        $aus[] = array(
            'thema'    => $k,
            'name'     => ap_gateway_name($praefix, $k),
            'text'     => $info['art'] === 'text',
            'einheit'  => $info['einheit'],
            'min'      => $info['min'],
            'max'      => $info['max'],
            'retain'   => $info['retain'],
            'v2'       => isset($haken[$k]) ? ($haken[$k] ? 'N' : 'H') : '',
            'fehlwert' => $info['fehlwert'],
            'stelle'   => isset($stellen[$k]) ? $stellen[$k] : '',
            'bestand'  => in_array($k, array('timestamp', 'valid'), true),
        );
    }
    return $aus;
}

/**
 * Pruefzeile Namenstabelle: jeder Name entsteht aus einem Thema der Liste nach der Regel des
 * Gateways, jeder V2-Haken und jede Stelle im Musterprojekt gehoert zu einem gesendeten Thema, die
 * Namen sind eindeutig. Rueckgabe: array(ok, Zahl der Namen, Haken, Noncached, Abweichungen[]).
 */
function ap_namen_probe($cfg)
{
    $zeilen = ap_mqtt_namen($cfg);
    $themen = ap_themen();
    $praefix = ap_praefix($cfg);
    $abw = array();
    $namen = array();
    foreach ($zeilen as $z) {
        if (!isset($themen[$z['thema']]) || $z['name'] !== str_replace(array('/', '%'), '_', $praefix . '_' . $z['thema'])) {
            $abw[] = $z['name'];
        }
        $namen[] = $z['name'];
    }
    if (count(array_unique($namen)) !== count($namen)) {
        $abw[] = 'doppelt';
    }
    $nc = 0;
    foreach (ap_v2_haken() as $k => $n) {
        if (!isset($themen[$k])) {
            $abw[] = $k;
        }
        if ($n) {
            $nc++;
        }
    }
    foreach (ap_musterprojekt_stellen() as $k => $s) {
        if (!isset($themen[$k])) {
            $abw[] = $k;
        }
    }
    return array((!$abw && $zeilen) ? true : false, count($zeilen), count(ap_v2_haken()), $nc, array_values(array_unique($abw)));
}
