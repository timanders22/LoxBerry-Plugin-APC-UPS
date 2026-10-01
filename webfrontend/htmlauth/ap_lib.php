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
    $status = is_dir('/run/shm') ? '/run/shm/apc_ups_ng_status.json'
                                 : '/tmp/apc_ups_ng_status.json';

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
        if (!ctype_digit($e)) {
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
    return array('service/online', 'timestamp', 'valid', 'last_error', 'battery_age_months');
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
    /* Bauart E (Befunde B3/B4 des Durchgangs 29.09.2026): bis 1.2.13 wurde
     * hier jeder bekannte Schluessel UNGEPRUEFT uebernommen - formtoken als
     * Liste wurde zur Zeichenfolge "Array", enabled=2 hiess fuer den Dienst
     * "aus" und fuer den Waechter "an", ein Praefix mit # legte jede
     * Veroeffentlichung lahm. Und eine nach Hausstandard gebaute eigene
     * Sicherung (ohne formtoken, mit _-Kopf) wurde abgewiesen.
     *
     * Jetzt:
     *   - jeder Wert laeuft durch ap_wert_pruefen() - DIESELBE Pruefung wie
     *     beim Speichern im Formular;
     *   - formtoken wird weder verlangt noch uebernommen: das Merkmal gehoert
     *     diesem LoxBerry, nicht der Datei;
     *   - Schluessel mit _ am Anfang sind der lesbare Kopf und werden
     *     ueberlesen;
     *   - die Beanstandungen sind Klartext. Maskiert wird EINMAL, bei der
     *     Ausgabe (bis 1.2.13 hier und dort, also doppelt). */
    $mangel = array();
    $falsch = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ap_t('EINST.SICH_KEIN_JSON')), 0, array());
    }
    if (!is_array($bisher)) {
        list($bisher, $unbenutzt) = ap_config_read();
    }
    $pflicht = ap_sicherung_schluessel();
    $werte = array();
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if ($k === 'formtoken') {
            continue;
        }
        if (!in_array($k, $pflicht, true)) {
            $mangel[] = sprintf(ap_t('EINST.SICH_FREMD'), $k);
            $falsch[] = $k;
            continue;
        }
        $werte[$k] = $w;
    }
    if (!$werte) {
        $mangel[] = ap_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026: eine Datei mit einem
     * einzigen Schluessel lief durch, und alle uebrigen Einstellungen fielen
     * auf Werk zurueck. Der Hausstandard sagt: eine halb gueltige Datei
     * aendert gar nichts. */
    $fehlend = array_values(array_diff($pflicht, array_keys($werte)));
    if ($fehlend) {
        $mangel[] = sprintf(ap_t('EINST.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
    }
    list($gut, $pruef, $pruef_falsch) = ap_werte_pruefen($werte);
    $mangel = array_merge($mangel, $pruef);
    if ($mangel) {
        return array(null, $mangel, count($werte),
                     array_values(array_unique(array_merge($falsch, $fehlend, $pruef_falsch))));
    }
    $neu = $bisher;
    foreach ($gut as $k => $v) {
        $neu[$k] = $v;
    }
    return array($neu, array(), count($gut), array());
}

/** Die Schluessel einer Sicherung: alle Einstellungen ausser dem Formularmerkmal. */
function ap_sicherung_schluessel()
{
    return array_values(array_diff(array_keys(ap_defaults()), array('formtoken')));
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
    );
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
    if (!is_string($v)) {
        return array(false, '', sprintf(ap_t('EINST.W_TYP'), $name, gettype($v)));
    }
    switch ($k) {
        case 'enabled':
        case 'mqtt':
        case 'benachrichtigung':
        case 'email':
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
        if ($v !== '' && strlen($v) <= 9 && ctype_digit($v)
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
        'settings' => array('enabled', 'intervall', 'aktualisierung', 'host',
                            'vorwarn_min', 'vorwarn_prozent', 'log_kb',
                            'benachrichtigung', 'email', 'email_an'),
        'mqtt'     => array('mqtt', 'themenpraefix', 'rohfelder'),
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
