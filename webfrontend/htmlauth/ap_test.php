<?php
/**
 * APC-UPS NG - Reiter Test
 *
 * Zwei Teile:
 *   ap_test_selbstpruefung()  die Zeilen mit Haekchen und Kreuz
 *   ap_test_ausfuehren()      die Knoepfe; liefert array(Ueberschrift, Text)
 *
 * Der Text der Knoepfe wird von der Oberflaeche maskiert ausgegeben, hier
 * also bewusst als Klartext erzeugt.
 *
 * ==== Was sich mit 1.2.0 geaendert hat ====
 *
 * 1. **Es gab ueberhaupt keine Selbstpruefzeilen.** Der Reiter war eine
 *    Diagnose-Konsole: man drueckte einen Knopf und las Fliesstext. Keine
 *    einzige Zeile beantwortete eine Frage mit Ja oder Nein.
 * 2. **Der eigene Endpunkt wurde nie aufgerufen.** Ausgerechnet diese Linie
 *    hat einen Endpunkt im unangemeldeten Baum, der laut seinem eigenen
 *    Dateikopf schon einmal unter PHP 8 mit einem fatalen Fehler gestorben
 *    ist - eine Klasse, die nur ein echter Aufruf findet.
 * 3. **"alle retained" war falsch.** Der Satz stand hier und an sechs
 *    weiteren Stellen; gemessen sind es 24 von 38 Themen, und der Quelltext
 *    des Dienstes begruendet ausfuehrlich, warum Messwerte es bewusst NICHT
 *    sind.
 * 4. **Der MQTT-Gateway wurde "ein eigenes Plugin" genannt.** Er ist seit
 *    LoxBerry 3 Bestandteil des Systems.
 * 5. **Die Datei war vollstaendig deutsch fest verdrahtet.** Ein englischer
 *    Anwender bekam englische Reiter und darunter deutschen Text.
 */

require_once __DIR__ . '/ap_lib.php';

function ap_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

/* ==================================================================
 * Teil 1: Die Selbstpruefung
 * ================================================================== */

/**
 * Eine Zeile der Selbstpruefung.
 *
 * $zustand: true = Haken, false = Kreuz, null = Hinweis.
 *
 * Ein Hinweis ist fuer "geht mich nichts an" da, nicht fuer "ich weiss es
 * nicht" - und ein rotes Kreuz, das nichts bedeutet, ist schlimmer als
 * keine Pruefung, weil man dann dort sucht.
 */
function ap_pruefzeile($frage, $zustand, $antwort)
{
    if ($zustand === true) {
        $zeichen = '<span class="sm-an">&#10003;</span>';
    } elseif ($zustand === false) {
        $zeichen = '<span class="sm-aus">&#10007;</span>';
    } else {
        $zeichen = '<span style="color:#777;">&#8226;</span>';
    }
    echo '<tr><td style="width:32px;text-align:center;">' . $zeichen . '</td>'
       . '<td style="width:44%;">' . ap_e($frage) . '</td>'
       . '<td>' . $antwort . '</td></tr>';
}

/**
 * Ruft den eigenen Endpunkt im unangemeldeten Baum wirklich auf.
 *
 * Rueckgabe: array(zustand, text). zustand null heisst "keine Antwort" -
 * das ist ein Hinweis, kein Kreuz: manche Pruefstaende koennen sich nicht
 * selbst aufrufen (der eingebaute PHP-Server ist einlaeufig), und ein Kreuz
 * wuerde dort dauerhaft stehen, ohne etwas ueber den Pruefling zu sagen.
 */
function ap_endpunkt_pruefen()
{
    $ordner = ap_paths()['plugin'];
    $url = 'http://127.0.0.1/plugins/' . rawurlencode($ordner) . '/index.php?selftest=1';
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 3, 'ignore_errors' => true, 'method' => 'GET',
    )));
    // Das @ ist hier kein Deckel, sondern die Antwort auf einen erwarteten
    // Fall: antwortet niemand, meldet file_get_contents eine WARNUNG, und
    // die stuende sonst mitten in der Seite - die Oberflaeche laeuft mit
    // display_errors=1. Der Rueckgabewert wird unmittelbar darunter
    // ausgewertet; unterdrueckt wird die Ausgabe, nicht der Befund.
    $rumpf = @file_get_contents($url, false, $ctx);
    if ($rumpf === false) {
        return array(null, sprintf(ap_t('TEST.EP_KEINE_ANTWORT'), $url));
    }
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    if ($code === 200 && strpos($rumpf, 'SELFTEST;OK=1') !== false) {
        return array(true, ap_e(sprintf(ap_t('TEST.EP_OK'), $url)));
    }
    return array(false, ap_e(sprintf(ap_t('TEST.EP_FALSCH'), $code,
        substr(trim(preg_replace('/\s+/', ' ', $rumpf)), 0, 120))));
}

/**
 * Prueft, ob Themenliste, Berechnung und Oberflaeche dasselbe meinen.
 *
 * Ruft dazu bin/apc_lesen.py --themen auf. Bis 1.1.6 gab es zwei Listen,
 * eine je Sprache, und ein Kommentar behauptete ihre Uebereinstimmung -
 * ein Kommentar ist kein Nachweis.
 */
function ap_themen_kongruenz()
{
    $skript = ap_paths()['bindir'] . '/apc_lesen.py';
    if (!is_file($skript)) {
        return array(null, ap_e(sprintf(ap_t('TEST.TH_KEIN_SKRIPT'), $skript)));
    }
    $out = array();
    $rc = 0;
    @exec('timeout 20 python3 ' . escapeshellarg($skript) . ' --themen 2>&1', $out, $rc);
    $j = @json_decode(trim(implode("\n", $out)), true);
    if (!is_array($j) || !isset($j['gleich'])) {
        return array(null, ap_e(sprintf(ap_t('TEST.TH_UNLESBAR'), $rc)));
    }
    $php = array_keys(ap_themen());
    $abw = array_merge(
        array_diff($j['liste'], $php),
        array_diff($php, $j['liste']),
        isset($j['nur_liste']) ? $j['nur_liste'] : array(),
        isset($j['nur_code']) ? $j['nur_code'] : array()
    );
    if ($j['gleich'] && !$abw) {
        return array(true, ap_e(sprintf(ap_t('TEST.TH_OK'), count($php))));
    }
    return array(false, ap_e(sprintf(ap_t('TEST.TH_ABWEICHUNG'),
        implode(', ', array_unique($abw)))));
}

function ap_test_selbstpruefung($cfg, $w, $pid, $alter, $broker, $autostart)
{
    $p = ap_paths();
    $themen = ap_themen();
    echo '<h2>' . ap_e(ap_t('TEST.SELBSTPRUEFUNG')) . '</h2>';
    echo '<div class="sm-hilfe">' . ap_t('TEST.SELBSTPRUEFUNG_HILFE') . '</div>';
    echo '<div class="sm-breit"><table class="sm-tbl">';

    $gut = 0;
    $schlecht = 0;
    $zaehle = function ($z) use (&$gut, &$schlecht) {
        if ($z === true) { $gut++; }
        if ($z === false) { $schlecht++; }
        return $z;
    };

    // --- Die Ursache vor der Wirkung: laeuft ueberhaupt, was messen soll? --
    //
    // Die Ausgabe von "command -v" allein reicht NICHT als Beleg: schlaegt
    // der Aufruf fehl, kann trotzdem etwas herauskommen - auf einem
    // Prueffrechner ohne Muschel etwa eine Fehlermeldung. Eine nicht leere
    // Antwort ergab dann einen Haken, und der stand ausgerechnet in der
    // ersten Zeile der Kette. Geprueft wird deshalb, ob der genannte Pfad
    // wirklich eine ausfuehrbare Datei ist.
    $apcaccess = trim(ap_sh('command -v apcaccess'));
    if ($apcaccess !== '' && !@is_executable($apcaccess)) {
        $apcaccess = '';
    }
    if ($apcaccess === '') {
        foreach (array('/sbin/apcaccess', '/usr/sbin/apcaccess', '/usr/bin/apcaccess') as $k) {
            if (@is_file($k) && @is_executable($k)) {
                $apcaccess = $k;
                break;
            }
        }
    }
    $z = $zaehle($apcaccess !== '');
    ap_pruefzeile(ap_t('TEST.F_APCACCESS'), $z,
        $apcaccess !== '' ? '<span class="sm-mono">' . ap_e($apcaccess) . '</span>'
                          : ap_e(ap_t('TEST.A_APCACCESS_FEHLT')));

    // Gibt es systemctl ueberhaupt? Ohne diese Frage wird aus "kein
    // systemctl vorhanden" ein Kreuz, das nichts ueber apcupsd sagt - und
    // ein rotes Kreuz, das nichts bedeutet, ist schlimmer als keine Pruefung.
    $sysctl = trim(ap_sh('command -v systemctl'));
    if ($sysctl !== '' && !@is_executable($sysctl)) {
        $sysctl = '';
    }
    if ($sysctl === '') {
        ap_pruefzeile(ap_t('TEST.F_APCUPSD'), $zaehle(null),
            ap_e(ap_t('TEST.A_KEIN_SYSTEMCTL')));
    } else {
        $dienstlauf = trim(ap_sh(escapeshellarg($sysctl) . ' is-active apcupsd 2>/dev/null'));
        ap_pruefzeile(ap_t('TEST.F_APCUPSD'), $zaehle($dienstlauf === 'active'),
            ap_e($dienstlauf !== '' ? $dienstlauf : ap_t('TEST.A_KEIN_SYSTEMCTL')));
    }

    $isconf = null;
    if (is_readable('/etc/default/apcupsd')) {
        $isconf = strpos((string) @file_get_contents('/etc/default/apcupsd'),
                         'ISCONFIGURED=yes') !== false;
    }
    $jn = $isconf ? ap_t('ALLGEMEIN.JA') : ap_t('ALLGEMEIN.NEIN');
    ap_pruefzeile(ap_t('TEST.F_ISCONFIGURED'), $zaehle($isconf),
        $isconf === null ? ap_e(ap_t('TEST.A_DATEI_FEHLT')) : ap_e($jn));

    // --- Der eigene Dienst -------------------------------------------------
    $z = $zaehle($pid > 0);
    ap_pruefzeile(ap_t('TEST.F_DIENST'), $z,
        $pid > 0 ? 'PID ' . (int) $pid : ap_e(ap_t('TEST.A_DIENST_TOT')));

    $takt = (int) ap_cfg($cfg, 'intervall', '30');
    if ($alter < 0) {
        ap_pruefzeile(ap_t('TEST.F_FRISCH'), $zaehle(false), ap_e(ap_t('TEST.A_KEINE_DATEI')));
    } else {
        $frisch = $alter <= 3 * max(5, $takt);
        ap_pruefzeile(ap_t('TEST.F_FRISCH'), $zaehle($frisch),
            ap_e(sprintf(ap_t('TEST.A_ALTER'), $alter, 3 * max(5, $takt))));
    }

    // --- Die Werte selbst --------------------------------------------------
    // Zuerst pruefen, ob es ueberhaupt Werte gibt: "alle 0 von 0 sind in
    // Ordnung" ist kein Haken.
    if (!$w) {
        ap_pruefzeile(ap_t('TEST.F_VERBINDUNG'), null, ap_e(ap_t('TEST.A_NOCH_KEINE_WERTE')));
        ap_pruefzeile(ap_t('TEST.F_SCHWELLEN'), null, ap_e(ap_t('TEST.A_NOCH_KEINE_WERTE')));
    } else {
        $gueltig = !empty($w['data_valid']);
        ap_pruefzeile(ap_t('TEST.F_VERBINDUNG'), $zaehle($gueltig),
            $gueltig ? ap_e($w['status'])
                     : ap_e(sprintf(ap_t('TEST.A_COMMLOST'), $w['status'])));

        $kennt = (isset($w['shutdown_charge']) && $w['shutdown_charge'] !== null)
              || (isset($w['shutdown_minutes']) && $w['shutdown_minutes'] !== null);
        ap_pruefzeile(ap_t('TEST.F_SCHWELLEN'), $zaehle($kennt ? true : null),
            $kennt ? ap_e(sprintf(ap_t('TEST.A_SCHWELLEN'),
                        isset($w['shutdown_charge']) ? $w['shutdown_charge'] : '?',
                        isset($w['shutdown_minutes']) ? $w['shutdown_minutes'] : '?'))
                   : ap_e(ap_t('TEST.A_SCHWELLEN_FEHLEN')));
    }

    // --- MQTT --------------------------------------------------------------
    $mqtt_an = ap_cfg($cfg, 'mqtt', '1') === '1';
    if (!$mqtt_an) {
        ap_pruefzeile(ap_t('TEST.F_BROKER'), null, ap_e(ap_t('TEST.A_MQTT_AUS')));
        ap_pruefzeile(ap_t('TEST.F_AUTOSTART'), null, ap_e(ap_t('TEST.A_MQTT_AUS')));
    } else {
        ap_pruefzeile(ap_t('TEST.F_BROKER'), $zaehle($broker !== ''),
            $broker !== '' ? '<span class="sm-mono">' . ap_e($broker) . '</span>'
                           : ap_e(ap_t('TEST.A_KEIN_BROKER')));
        $ajn = $autostart ? ap_t('ALLGEMEIN.JA') : ap_t('ALLGEMEIN.NEIN');
        ap_pruefzeile(ap_t('TEST.F_AUTOSTART'), $zaehle($autostart),
            $autostart === null ? ap_e(ap_t('ALLGEMEIN.UNBEKANNT')) : ap_e($ajn));
    }

    $paho = trim(ap_sh('python3 -c "import paho.mqtt.client" >/dev/null 2>&1 && echo ja || echo nein'));
    ap_pruefzeile(ap_t('TEST.F_PAHO'), $zaehle($paho === 'ja'), ap_e($paho));

    // --- Die eigene Bauart -------------------------------------------------
    ap_pruefzeile(ap_t('TEST.F_THEMENDATEI'), $zaehle($themen ? true : false),
        $themen ? ap_e(sprintf(ap_t('TEST.A_THEMENDATEI'), count($themen), ap_themen_datei()))
                : ap_e(sprintf(ap_t('TEST.A_THEMENDATEI_FEHLT'), ap_themen_datei())));

    list($zk, $tk) = ap_themen_kongruenz();
    $zaehle($zk);
    ap_pruefzeile(ap_t('TEST.F_KONGRUENZ'), $zk, $tk);

    list($ze, $te) = ap_endpunkt_pruefen();
    $zaehle($ze);
    ap_pruefzeile(ap_t('TEST.F_ENDPUNKT'), $ze, $te);

    // Die Vorlage: wohlgeformt oder nicht, das ist nicht verhandelbar.
    list($vname, $vinhalt) = ap_vorlage($cfg, 'mqtt_in');
    $vorher = libxml_use_internal_errors(true);
    $ok = simplexml_load_string($vinhalt) !== false;
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    ap_pruefzeile(ap_t('TEST.F_VORLAGE'), $zaehle($ok),
        $ok ? ap_e(sprintf(ap_t('TEST.A_VORLAGE'), $vname,
                  count(ap_vorlage_themen()), count(ap_text_themen())))
            : ap_e(ap_t('TEST.A_VORLAGE_KAPUTT')));

    // Alle DREI Stellen gegeneinander: die Positivliste $ap_reiter, die
    // ausgeschriebene Leiste (data-ziel) und die Bereichs-ids.
    //
    // Die Leiste steht ausgeschrieben, damit hausstandard_pruefen.py sie
    // findet - und genau deshalb kann sie von der Quelle abweichen, ohne
    // dass ein Fehler entsteht. Ein Reiter, der in der Positivliste fehlt,
    // laesst sich anklicken und springt nach jedem Absenden zurueck auf
    // Einstellungen. Diese Zeile ist die Gegenwehr.
    $quelle = (string) @file_get_contents(__DIR__ . '/index.php');
    $namen = array();
    if (preg_match('/\$ap_reiter = array\((.*?)\);/s', $quelle, $m)) {
        preg_match_all("/'(tab-[a-z0-9]+)'/", $m[1], $x);
        $namen = $x[1];
    }
    preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $quelle, $y);
    preg_match_all('/id="(tab-[a-z0-9]+)"/', $quelle, $z2);
    $kongruent = ($namen && $namen === $y[1] && $namen === $z2[1]);
    ap_pruefzeile(ap_t('TEST.F_REITER'), $zaehle($kongruent),
        ap_e(sprintf(ap_t('TEST.A_REITER'), count($namen), count($y[1]), count($z2[1]))));

    // Der Waechter - ohne ihn laeuft ein gestorbener Dienst nicht wieder an.
    $wacht = is_file(dirname(dirname(__DIR__)) . '/cron/cron.05min');
    // Beide Schluessel ausgeschrieben, nicht ueber einen Bedingungsausdruck
    // in ap_t() hinein: der Sprachpruefer findet nur woertliche Aufrufe und
    // meldete sie sonst als unbenutzt.
    $wtext = $wacht ? ap_t('TEST.A_WAECHTER_DA') : ap_t('TEST.A_WAECHTER_UNBEKANNT');
    ap_pruefzeile(ap_t('TEST.F_WAECHTER'), $zaehle($wacht ? true : null), ap_e($wtext));

    echo '</table></div>';

    // Eine Zusammenfassung darf nicht besser aussehen als ihr schlechtester
    // Punkt. Hinweise zaehlen NICHT als bestanden.
    $klasse = $schlecht > 0 ? 'sm-warnung' : 'sm-hinweis';
    echo '<div class="' . $klasse . '">'
       . ap_e(sprintf(ap_t('TEST.BILANZ'), $gut, $gut + $schlecht, $schlecht))
       . '</div>';
}

/* ==================================================================
 * Teil 2: Die Knoepfe
 * ================================================================== */

/** Einmalabfrage ueber bin/apc_lesen.py. */
function ap_einmal_lesen()
{
    $p = ap_paths();
    $skript = $p['bindir'] . '/apc_lesen.py';
    if (!is_file($skript)) {
        return array('fehler' => sprintf(ap_t('TEST.ABFRAGE_KEIN_SKRIPT'), $skript));
    }
    $out = array();
    $rc = 0;
    @exec('timeout 30 python3 ' . escapeshellarg($skript) . ' 2>&1', $out, $rc);
    $roh = trim(implode("\n", $out));
    // timeout meldet 124, wenn es zugeschlagen hat. Ohne diese Unterscheidung
    // stand dort "keine verwertbare Antwort" - und der Nutzer suchte den
    // Fehler im JSON statt in der haengenden Abfrage.
    if ($rc === 124) {
        return array('fehler' => ap_t('TEST.ABFRAGE_HAENGT'));
    }
    $j = @json_decode($roh, true);
    if (!is_array($j)) {
        return array('fehler' => ($rc !== 0 ? sprintf(ap_t('TEST.ABFRAGE_RC'), $rc) . "\n\n" : '')
            . ap_t('TEST.ABFRAGE_UNLESBAR') . "\n\n" . substr($roh, 0, 800));
    }
    return $j;
}

function ap_test_ausfuehren($was)
{
    $p = ap_paths();
    list($cfg, $alt) = ap_config_read();
    $ja = ap_t('ALLGEMEIN.JA');
    $nein = ap_t('ALLGEMEIN.NEIN');

    switch ($was) {

        case 'status':
            $pid = ap_dienst_pid();
            $alter = ap_status_alter();
            $t  = sprintf("%-22s %s\n", ap_t('ALLGEMEIN.DIENST') . ':',
                  $pid ? ap_t('ALLGEMEIN.LAEUFT') . ' (PID ' . $pid . ')' : ap_t('ALLGEMEIN.LAEUFT_NICHT'));
            $t .= sprintf("%-22s %s\n", ap_t('ALLGEMEIN.PLUGIN') . ':',
                  ap_cfg($cfg, 'enabled', '1') === '1' ? $ja : $nein);
            $t .= sprintf("%-22s %s\n", ap_t('TEST.Z_ZUSTANDSDATEI') . ':',
                  $alter < 0 ? ap_t('TEST.Z_NICHT_VORHANDEN') : sprintf(ap_t('TEST.Z_SEKUNDEN_ALT'), $alter));
            $t .= sprintf("%-22s %s s\n", ap_t('TEST.Z_TAKT') . ':', ap_cfg($cfg, 'intervall', '30'));
            $t .= sprintf("%-22s %s\n", 'MQTT:', ap_cfg($cfg, 'mqtt', '1') === '1' ? $ja : $nein);
            $t .= sprintf("%-22s %s\n", ap_t('EINST.BENACHRICHTIGUNG') . ':',
                  ap_cfg($cfg, 'benachrichtigung', '1') === '1' ? $ja : $nein);
            $t .= sprintf("%-22s %s\n", 'E-Mail:', ap_cfg($cfg, 'email', '0') === '1' ? $ja : $nein);
            $t .= sprintf("%-22s %s\n", ap_t('EINST.HOST') . ':',
                  ap_roh($cfg, 'host') !== '' ? ap_roh($cfg, 'host') : ap_t('TEST.Z_OERTLICH'));
            $t .= "\n";
            if ($alt) {
                $t .= ap_t('TEST.Z_ALTES_FORMAT') . "\n\n";
            }
            if (!$pid) {
                $t .= ap_t('TEST.Z_DIENST_TOT') . "\n\n";
            } elseif ($alter > 3 * (int) ap_cfg($cfg, 'intervall', '30')) {
                $t .= sprintf(ap_t('TEST.Z_DIENST_STUMM'), $alter) . "\n\n";
            }
            $t .= ap_sh('ps -o pid,etime,rss,args -C python3 2>/dev/null | grep -iE "apc_service|PID"');
            return array(ap_t('TEST.K_STATUS'), trim($t) !== '' ? $t : ap_t('TEST.Z_KEINE_ANGABEN'));

        case 'werte':
            $s = ap_status();
            if (!$s) {
                return array(ap_t('TEST.K_WERTE'), ap_t('TEST.W_KEINE_DATEI'));
            }
            $t = sprintf(ap_t('TEST.W_STAND'), ap_status_alter()) . "\n\n";
            if (empty($s['werte'])) {
                $t .= ap_t('TEST.W_KEINE_WERTE') . "\n\n" . (isset($s['fehler']) ? $s['fehler'] : '');
                return array(ap_t('TEST.K_WERTE'), $t);
            }
            foreach ($s['werte'] as $k => $v) {
                $t .= sprintf("  %-24s %s\n", $k, ($v === null || $v === '') ? '-' : $v);
            }
            if (!empty($s['zusatz'])) {
                $t .= "\n" . ap_t('TEST.W_ROHFELDER') . "\n";
                foreach ($s['zusatz'] as $k => $v) {
                    $t .= sprintf("  %-24s %s\n", $k, $v);
                }
            }
            return array(ap_t('TEST.K_WERTE'), $t);

        case 'ereignisse':
            $liste = ap_ereignisse(40);
            if (!$liste) {
                // Eine wahre Aussage ueber eine leere Menge ist kein Haken -
                // deshalb steht hier, WARUM die Liste leer ist.
                return array(ap_t('TEST.K_EREIGNISSE'), ap_t('TEST.E_LEER'));
            }
            $t = sprintf(ap_t('TEST.E_ANZAHL'), count($liste)) . "\n\n";
            foreach ($liste as $e) {
                $t .= sprintf("  %s  %-28s %s\n",
                    date('d.m.Y H:i:s', (int) (isset($e['zeit']) ? $e['zeit'] : 0)),
                    isset($e['titel']) ? $e['titel'] : '?',
                    isset($e['text']) ? $e['text'] : '');
            }
            return array(ap_t('TEST.K_EREIGNISSE'), $t);

        case 'abfragen':
            $j = ap_einmal_lesen();
            if (!empty($j['fehler']) && empty($j['werte'])) {
                // Vier Schluessel statt eines mit eingebauten Zeilenumbruechen:
                // ein Wert einer .ini kann nicht ueber mehrere Zeilen gehen.
                $rat = ap_t('TEST.AB_WAS_PRUEFEN') . "\n"
                     . ap_t('TEST.AB_PRUEF_1') . "\n"
                     . ap_t('TEST.AB_PRUEF_2') . "\n"
                     . ap_t('TEST.AB_PRUEF_3');
                return array(ap_t('TEST.K_ABFRAGEN'), $j['fehler'] . "\n\n" . $rat);
            }
            $t = 'apcaccess: ' . (isset($j['apcaccess']) ? $j['apcaccess'] : '?') . "\n\n";
            $t .= ap_t('TEST.AB_AUSGEWERTET') . "\n";
            foreach ((isset($j['werte']) && is_array($j['werte']) ? $j['werte'] : array()) as $k => $v) {
                $t .= sprintf("  %-24s %s\n", $k, ($v === null || $v === '') ? '-' : $v);
            }
            if (!empty($j['statflag_streit'])) {
                $t .= "\n" . sprintf(ap_t('TEST.AB_STATFLAG_STREIT'),
                       implode(', ', $j['statflag_streit'])) . "\n";
            }
            if (!empty($j['rohfelder_abgewiesen'])) {
                $t .= "\n" . sprintf(ap_t('MQTT.ROHFELD_ABGEWIESEN'),
                       implode(', ', $j['rohfelder_abgewiesen'])) . "\n";
            }
            $t .= "\n" . ap_t('TEST.AB_ROHDATEN') . "\n";
            foreach ((isset($j['roh']) && is_array($j['roh']) ? $j['roh'] : array()) as $k => $v) {
                $t .= sprintf("  %-14s %s\n", $k, $v);
            }
            return array(ap_t('TEST.K_ABFRAGEN'), $t);

        case 'apcupsd':
            $t  = sprintf("%-12s %s\n", 'apcaccess:',
                  trim(ap_sh('command -v apcaccess')) ? trim(ap_sh('command -v apcaccess'))
                                                      : ap_t('TEST.A_APCACCESS_FEHLT'));
            $t .= sprintf("%-12s %s\n\n", 'apcupsd:',
                  trim(ap_sh('command -v apcupsd')) ? trim(ap_sh('command -v apcupsd'))
                                                    : ap_t('TEST.AP_NICHT_IM_PFAD'));
            $t .= '--- ' . ap_t('TEST.AP_DIENSTZUSTAND') . " ---\n";
            $t .= (ap_sh('systemctl status apcupsd --no-pager 2>&1 | head -14')
                   ?: ap_t('TEST.A_KEIN_SYSTEMCTL')) . "\n\n";
            $t .= "--- /etc/default/apcupsd ---\n";
            $t .= (is_readable('/etc/default/apcupsd')
                   ? (string) @file_get_contents('/etc/default/apcupsd')
                   : ap_t('TEST.AP_NICHT_LESBAR')) . "\n\n";
            $t .= ap_t('TEST.AP_ISCONFIGURED') . "\n\n";
            $t .= '--- ' . ap_t('TEST.AP_USB') . " ---\n";
            $t .= (ap_sh('lsusb 2>&1') ?: ap_t('TEST.AP_KEIN_LSUSB')) . "\n";
            return array(ap_t('TEST.K_APCUPSD'), $t);

        case 'konfig':
            $t = ap_t('TEST.KO_DATEI') . ' ' . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= ap_t('TEST.KO_FEHLT') . "\n\n";
                foreach (ap_defaults() as $k => $v) {
                    $t .= sprintf("  %-18s %s\n", $k, $v === '' ? '(leer)' : $v);
                }
            }
            return array(ap_t('TEST.K_KONFIG'), $t);

        case 'umgebung':
            $t  = sprintf("%-14s %s\n", 'Python:', trim(ap_sh('python3 --version')));
            $t .= sprintf("%-14s %s\n", 'PHP:', PHP_VERSION);
            $t .= sprintf("%-14s %s\n", 'System:',
                  trim(ap_sh('. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"')));
            $t .= sprintf("%-14s %s\n", 'LoxBerry:',
                  $p['home'] !== '' ? $p['home'] : ap_t('TEST.U_NICHT_GEFUNDEN'));
            $t .= sprintf("%-14s %s\n", 'Plugin:', $p['plugin']);
            $t .= sprintf("%-14s %s\n", ap_t('TEST.U_PROGRAMME') . ':', $p['bindir']);
            $t .= sprintf("%-14s %s\n", ap_t('TEST.U_PROTOKOLLE') . ':', $p['logdir']);
            $t .= sprintf("%-14s %s\n\n", ap_t('TEST.U_THEMENDATEI') . ':', ap_themen_datei());
            $t .= ap_t('TEST.U_MODULE') . "\n";
            foreach (array('paho.mqtt.client') as $m) {
                $da = trim(ap_sh('python3 -c "import ' . $m . '" >/dev/null 2>&1 && echo ja || echo nein'));
                $t .= sprintf("  %-20s %s\n", $m, $da === 'ja' ? $ja : $nein);
            }
            $t .= "\n" . ap_t('TEST.U_HILFSPROGRAMME') . "\n";
            foreach (array('apcaccess', 'sendmail', 'python3', 'php', 'timeout') as $c) {
                $t .= sprintf("  %-20s %s\n", $c,
                      trim(ap_sh('command -v ' . $c)) ?: ap_t('TEST.U_FEHLT'));
            }
            return array(ap_t('TEST.K_UMGEBUNG'), $t);

        case 'vorlage':
            $t = '';
            foreach (array('mqtt_in', 'xml_in') as $art) {
                list($name, $inhalt) = ap_vorlage($cfg, $art);
                $vorher = libxml_use_internal_errors(true);
                $x = simplexml_load_string($inhalt);
                $meldungen = array();
                foreach (libxml_get_errors() as $e) {
                    $meldungen[] = trim($e->message);
                }
                libxml_clear_errors();
                libxml_use_internal_errors($vorher);
                $t .= '=== ' . $name . " ===\n";
                $t .= sprintf("  %-22s %s\n", ap_t('TEST.V_WOHLGEFORMT') . ':',
                      $x === false ? $nein . ' - ' . implode('; ', $meldungen) : $ja);
                $t .= sprintf("  %-22s %d\n", ap_t('TEST.V_EINTRAEGE') . ':',
                      substr_count($inhalt, '<VirtualInHttpCmd'));
                $t .= sprintf("  %-22s %d CRLF, %d LF\n", ap_t('TEST.V_ZEILENENDEN') . ':',
                      substr_count($inhalt, "\r\n"),
                      substr_count($inhalt, "\n") - substr_count($inhalt, "\r\n"));
                $t .= sprintf("  %-22s %s\n", 'Info templateType:',
                      strpos($inhalt, '<Info templateType="2"') !== false ? $ja : $nein);
                $t .= sprintf("  %-22s %s\n", 'Unit:',
                      strpos($inhalt, 'Unit="') !== false ? $ja : $nein);
                $t .= sprintf("  %-22s %s\n\n", 'HintText:',
                      strpos($inhalt, 'HintText=') !== false ? $ja : $nein);
            }
            $t .= sprintf(ap_t('TEST.V_TEXTTHEMEN'),
                  implode(', ', array_keys(ap_text_themen()))) . "\n";
            return array(ap_t('TEST.K_VORLAGE'), $t);

        case 'mqttinfo':
            $broker = ap_mqtt_broker();
            $praefix = ap_cfg($cfg, 'themenpraefix', 'apcups');
            $themen = ap_themen();
            $t = ap_t('MQTT.BROKER') . ': ' . ($broker !== '' ? $broker : ap_t('MQTT.KEIN_BROKER')) . "\n";
            $t .= ap_t('MQTT.PRAEFIX') . ': ' . $praefix . "\n";
            $t .= ap_t('MQTT.ABO_SATZ') . ' ' . $praefix . "/#\n\n";
            if ($broker === '') {
                // Der MQTT-Gateway ist seit LoxBerry 3 Bestandteil des
                // Systems, kein Plugin. Bis 1.1.6 stand hier das Gegenteil.
                $t .= ap_t('MQTT.KEIN_BROKER_LANG') . "\n\n";
            }
            if (!$themen) {
                $t .= sprintf(ap_t('TEST.A_THEMENDATEI_FEHLT'), ap_themen_datei()) . "\n";
                return array(ap_t('TEST.K_MQTT'), $t);
            }
            $retained = 0;
            foreach ($themen as $info) {
                if ($info['retain']) { $retained++; }
            }
            // Gezaehlt, nicht behauptet: bis 1.1.6 stand an sieben Stellen
            // "alle Themen sind retained", und es waren 11 von 23.
            $t .= sprintf(ap_t('TEST.M_UEBERSCHRIFT'), count($themen), $retained) . "\n\n";
            foreach ($themen as $k => $info) {
                $t .= sprintf("  %-28s %-8s %-3s %s\n", $praefix . '/' . $k,
                      $info['art'], $info['retain'] ? 'R' : '-', ap_thema_text($k));
            }
            $t .= "\n" . ap_t('MQTT.RETAIN_KURZ') . "\n";
            return array(ap_t('TEST.K_MQTT'), $t);

        case 'melden':
            $skript = $p['bindir'] . '/apc_notify.php';
            if (!is_file($skript)) {
                return array(ap_t('TEST.K_MELDEN'),
                    sprintf(ap_t('TEST.ME_KEIN_SKRIPT'), $skript));
            }
            $out = array();
            $rc = 0;
            @exec('php ' . escapeshellarg($skript) . ' 6 '
                . escapeshellarg(ap_t('TEST.ME_TEXT'))
                . ' 2>&1', $out, $rc);
            return array(ap_t('TEST.K_MELDEN'),
                ($rc === 0 ? ap_t('TEST.ME_OK') : ap_t('TEST.ME_FEHL'))
                . "\n\n" . implode("\n", $out));

        case 'restart':
            $aus = ap_dienst('restart');
            $pid = ap_dienst_pid();
            return array(ap_t('TEST.K_RESTART'),
                ($pid ? sprintf(ap_t('TEST.R_LAEUFT'), $pid) : ap_t('TEST.R_LAEUFT_NICHT'))
                . ($aus !== '' ? "\n\n" . $aus : ''));

        case 'stop':
            $aus = ap_dienst('stop');
            $pid = ap_dienst_pid();
            return array(ap_t('TEST.K_STOP'),
                ($pid ? sprintf(ap_t('TEST.S_LAEUFT_NOCH'), $pid) : ap_t('TEST.S_ANGEHALTEN'))
                . ($aus !== '' ? "\n\n" . $aus : ''));
    }

    return array(ap_t('TEST.UNBEKANNT'), sprintf(ap_t('TEST.UNBEKANNT_LANG'), $was));
}
