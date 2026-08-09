<?php
/**
 * APC-UPS NG - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche maskiert ausgegeben, hier also bewusst als Klartext erzeugt.
 */

require_once __DIR__ . '/ap_lib.php';

function ap_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

/** Einmalabfrage ueber bin/apc_lesen.py. */
function ap_einmal_lesen()
{
    $p = ap_paths();
    $skript = $p['bindir'] . '/apc_lesen.py';
    if (!is_file($skript)) {
        return array('fehler' => 'apc_lesen.py nicht gefunden: ' . $skript);
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
            . "Die Abfrage lieferte keine verwertbare Antwort:\n\n"
            . substr($roh, 0, 800));
    }
    return $j;
}

function ap_test_ausfuehren($was)
{
    $p = ap_paths();
    list($cfg, $alt) = ap_config_read();

    switch ($was) {

        case 'status':
            $pid = ap_dienst_pid();
            $alter = ap_status_alter();
            $t  = "Dienst:          " . ($pid ? "laeuft (PID $pid)" : 'laeuft nicht') . "\n";
            $t .= "Eingeschaltet:   " . (ap_cfg($cfg, 'enabled', '1') === '1' ? 'ja' : 'nein') . "\n";
            $t .= "Zustandsdatei:   " . ($alter < 0 ? 'nicht vorhanden' : $alter . ' Sekunden alt') . "\n";
            $t .= "Takt:            alle " . ap_cfg($cfg, 'intervall', '30') . " Sekunden\n";
            $t .= "MQTT:            " . (ap_cfg($cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "Benachrichtigung:" . (ap_cfg($cfg, 'benachrichtigung', '1') === '1' ? ' ein' : ' aus') . "\n";
            $t .= "E-Mail:          " . (ap_cfg($cfg, 'email', '0') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "USV-Host:        " . (ap_roh($cfg, 'host') !== '' ? ap_roh($cfg, 'host') : 'oertlich') . "\n\n";
            if ($alt) {
                $t .= "In der Konfiguration stehen noch Schluessel der Originalfassung.\n"
                    . "Sie werden beim naechsten Speichern entfernt.\n\n";
            }
            if (!$pid) {
                $t .= "Der Dienst laeuft nicht. Die Ursache steht meistens im Protokoll\n"
                    . "(Reiter Logdateien). Mit \"Dienst neu starten\" erneut versuchen.\n\n";
            } elseif ($alter > 3 * (int) ap_cfg($cfg, 'intervall', '30')) {
                $t .= "Der Dienst laeuft, hat aber seit $alter Sekunden nichts mehr\n"
                    . "geschrieben - laenger als drei Takte.\n\n";
            }
            $t .= ap_sh('ps -o pid,etime,rss,args -C python3 2>/dev/null | grep -iE "apc_service|PID"');
            return array('Zustand des Dienstes', trim($t) !== '' ? $t : 'Keine Angaben.');

        case 'werte':
            $s = ap_status();
            if (!$s) {
                return array('Letzte Werte',
                    "Es gibt noch keine Zustandsdatei.\n\n"
                    . "Sie entsteht, sobald der Dienst die USV das erste Mal abgefragt hat.");
            }
            $t = "Stand: vor " . ap_status_alter() . " Sekunden\n\n";
            if (empty($s['werte'])) {
                $t .= "Keine brauchbaren Werte.\n\n" . ($s['fehler'] ?? '');
                return array('Letzte Werte', $t);
            }
            foreach ($s['werte'] as $k => $v) {
                $t .= sprintf("  %-24s %s\n", $k, $v === null ? '-' : $v);
            }
            return array('Letzte Werte', $t);

        case 'abfragen':
            $j = ap_einmal_lesen();
            if (!empty($j['fehler']) && empty($j['werte'])) {
                return array('Jetzt abfragen', $j['fehler'] . "\n\n"
                    . "Was man pruefen kann:\n"
                    . "- Laeuft apcupsd?  systemctl status apcupsd\n"
                    . "- Steht ISCONFIGURED in /etc/default/apcupsd auf yes?\n"
                    . "- Ist die USV per USB angeschlossen und erkannt?  lsusb\n");
            }
            $t = "apcaccess: " . ($j['apcaccess'] ?? 'unbekannt') . "\n\n";
            $t .= "--- ausgewertete Werte ---\n";
            foreach (($j['werte'] ?? array()) as $k => $v) {
                $t .= sprintf("  %-24s %s\n", $k, $v === null ? '-' : $v);
            }
            $t .= "\n--- Rohdaten von apcaccess ---\n";
            foreach (($j['roh'] ?? array()) as $k => $v) {
                $t .= sprintf("  %-12s %s\n", $k, $v);
            }
            return array('Jetzt abfragen', $t);

        case 'apcupsd':
            $t  = "apcaccess:  " . (trim(ap_sh('command -v apcaccess')) ?: 'NICHT GEFUNDEN') . "\n";
            $t .= "apcupsd:    " . (trim(ap_sh('command -v apcupsd')) ?: 'nicht im Pfad') . "\n\n";
            $t .= "--- Dienstzustand ---\n";
            $t .= (ap_sh('systemctl status apcupsd --no-pager 2>&1 | head -14') ?: 'systemctl nicht verfuegbar') . "\n\n";
            $t .= "--- /etc/default/apcupsd ---\n";
            $t .= (is_readable('/etc/default/apcupsd')
                   ? (string) @file_get_contents('/etc/default/apcupsd')
                   : 'nicht lesbar') . "\n";
            $t .= "\nSteht ISCONFIGURED auf no, startet apcupsd nicht. Die Installation\n"
                . "dieses Plugins setzt den Wert auf yes.\n\n";
            $t .= "--- angeschlossene USB-Geraete ---\n";
            $t .= (ap_sh('lsusb 2>&1') ?: 'lsusb nicht vorhanden') . "\n";
            return array('apcupsd pruefen', $t);

        case 'konfig':
            $t = "Datei: " . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= "Die Datei gibt es noch nicht. Sie entsteht beim ersten Speichern.\n\n"
                    . "Bis dahin gelten die Voreinstellungen:\n\n";
                foreach (ap_defaults() as $k => $v) {
                    $t .= sprintf("  %-18s %s\n", $k, $v === '' ? '(leer)' : $v);
                }
            }
            return array('Konfiguration anzeigen', $t);

        case 'umgebung':
            $t  = "Python:      " . trim(ap_sh('python3 --version')) . "\n";
            $t .= "System:      " . trim(ap_sh('. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"')) . "\n";
            $t .= "LoxBerry:    " . ($p['home'] !== '' ? $p['home'] : 'nicht gefunden') . "\n";
            $t .= "Plugin:      " . $p['plugin'] . "\n";
            $t .= "Programme:   " . $p['bindir'] . "\n";
            $t .= "Protokolle:  " . $p['logdir'] . "\n\n";
            $t .= "Python-Module:\n";
            foreach (array('paho.mqtt.client',) as $m) {
                $da = trim(ap_sh('python3 -c "import ' . $m . '" >/dev/null 2>&1 && echo ja || echo nein'));
                $t .= sprintf("  %-20s %s\n", $m, $da);
            }
            $t .= "\nHilfsprogramme:\n";
            foreach (array('apcaccess', 'sendmail', 'pgrep', 'pkill', 'php') as $c) {
                $t .= sprintf("  %-20s %s\n", $c, trim(ap_sh('command -v ' . $c)) ?: 'fehlt');
            }
            return array('Umgebung und Module', $t);

        case 'mqttinfo':
            $broker = ap_mqtt_broker();
            $praefix = ap_cfg($cfg, 'themenpraefix', 'apcups');
            $t = "Broker: " . ($broker !== '' ? $broker : 'kein MQTT-Gateway in general.json gefunden') . "\n";
            $t .= "Themenpraefix: " . $praefix . "\n\n";
            if ($broker === '') {
                $t .= "Ohne MQTT-Gateway kann das Plugin nichts veroeffentlichen.\n"
                    . "Das Gateway ist ein eigenes Plugin und muss eingerichtet sein.\n\n";
            }
            $t .= "Themen, die der Dienst setzt (alle retained):\n\n";
            foreach (ap_status_themen() as $k => $info) {
                $t .= sprintf("  %-30s %s\n", $praefix . '/' . $k,
                    strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
            }
            $t .= sprintf("  %-30s %s\n", $praefix . '/event',
                'Kurztext beim Zustandswechsel, z. B. Stromausfall');
            $t .= "\nRetained heisst: der Broker merkt sich den letzten Wert. Nach einem\n"
                . "Neustart des Miniservers steht der USV-Zustand sofort wieder da.\n";
            return array('MQTT-Gateway', $t);

        case 'melden':
            $skript = $p['bindir'] . '/apc_notify.php';
            if (!is_file($skript)) {
                return array('Testmeldung', 'apc_notify.php nicht gefunden: ' . $skript);
            }
            $out = array();
            @exec('php ' . escapeshellarg($skript) . ' 6 '
                . escapeshellarg('Testmeldung des Plugins APC-UPS NG. Wenn du das liest, funktionieren die Benachrichtigungen.')
                . ' 2>&1', $out, $rc);
            return array('Testmeldung',
                ($rc === 0
                    ? "Die Meldung wurde abgelegt.\nSie erscheint oben in der LoxBerry-Oberflaeche im Glockensymbol."
                    : "Die Meldung konnte nicht abgelegt werden.")
                . "\n\n" . implode("\n", $out));

        case 'restart':
            $aus = ap_dienst('restart');
            $pid = ap_dienst_pid();
            return array('Dienst neu starten',
                ($pid ? "Der Dienst laeuft wieder (PID $pid)."
                      : "Der Dienst laeuft nicht.\nDie Ursache steht im Protokoll, Reiter Logdateien.")
                . ($aus !== '' ? "\n\n" . $aus : ''));

        case 'stop':
            $aus = ap_dienst('stop');
            $pid = ap_dienst_pid();
            return array('Dienst anhalten',
                ($pid ? "Der Dienst laeuft noch (PID $pid)." : 'Der Dienst wurde angehalten.')
                . ($aus !== '' ? "\n\n" . $aus : ''));
    }

    return array('Unbekannt', 'Diese Aktion gibt es nicht: ' . $was);
}
