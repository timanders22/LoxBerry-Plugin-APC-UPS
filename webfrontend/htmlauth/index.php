<?php
/**
 * APC-UPS NG - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * ==== Was sich mit 1.2.0 geaendert hat ====
 *
 * 1. **Der Reiter MQTT fehlte.** Der Haken lag in Einstellungen, das
 *    Themenpraefix ebenfalls, die Themen-Tabelle in "Einbindung in Loxone",
 *    der Broker-Zustand auch dort. Der Schluessel REITER.MQTT stand seit
 *    jeher in beiden Sprachdateien und wurde nirgends benutzt.
 * 2. **Das einzutragende Abo stand nirgends.** Eine Suche ueber das ganze
 *    Plugin nach dem Thema mit Platzhalter ergab null Treffer. Ohne diesen
 *    Eintrag im MQTT-Gateway kommt am Miniserver nichts an - das ist die
 *    haeufigste Fehlerursache ueberhaupt.
 * 3. **Die Baustein-Liste fehlte.** Der Reiter sagte, welche Themen
 *    ankommen, aber nicht, was man in Loxone daraus baut.
 * 4. **Die Reiter wurden nur vom JavaScript geschaltet.** sm-active kam an
 *    keiner der acht Stellen vom Server, und Links gab es nicht. Fiel das
 *    Skript aus, war die Seite leer - alle Bereiche stehen auf display:none.
 * 5. **Eine CSS-Eigenschaft steckte in einem Sprachschluessel.** Der erste
 *    Wortteil der Eigenschaft text-shadow war als uebersetzbarer Wert
 *    abgelegt und wurde im Stilblock wieder davorgesetzt. Wer ihn
 *    uebersetzt, schaltet den Schattenschutz des Hausstandards ab.
 *    Der Schluesselname steht hier bewusst NICHT: ein Kommentar, der die
 *    gesuchte Form woertlich enthaelt, macht jede Suche danach blind.
 * 6. **Zwei Sprachwerte begannen mit "> ".** Das war das schliessende
 *    Zeichen des <input>-Tags. Wer es beim Uebersetzen wegnimmt - und das
 *    tut jeder, der den Wert fuer einen Satz haelt -, erzeugt unschliessbares
 *    HTML.
 * 7. **Der Speichern-Knopf war gruen** und trug gar keine Farbklasse.
 *    Speichern veraendert etwas und ist deshalb orange.
 * 8. **Die Beanstandung zur E-Mail-Adresse erreichte den Anwender nie.**
 *    Sie stand in $ap_hinweis, und derselbe Name wurde beim erfolgreichen
 *    Speichern unbedingt ueberschrieben. Beanstandungen werden jetzt
 *    gesammelt.
 * 9. **data_valid wurde nicht angezeigt.** Bei abgezogenem USB-Kabel
 *    lieferte apcaccess einen vollstaendig aussehenden Datensatz mit
 *    Nullen, und die Oberflaeche zeigte ihn ohne Vorbehalt.
 * 10. **Der Downloadname trug keine Anfuehrungszeichen** und kein VI_.
 * 11. **Die Ueberschrift im Reiter Logdateien hiess "Protokoll"**,
 *    englisch "Log". Der Beschluss vom 14.08.2026 sagt "Logdateien" bzw.
 *    "Log files".
 * 12. **Die Formulare trugen kein Merkmal gegen fremde Absender.** Eine
 *    fremde Seite konnte im angemeldeten Browser "Dienst anhalten"
 *    ausloesen.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/ap_lib.php';

$ap_p = ap_paths();
if ($ap_p['home']) {
    $ap_sdk = $ap_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($ap_sdk)) {
        require_once $ap_sdk;
        require_once $ap_p['home'] . '/libs/phplib/loxberry_web.php';
        // loxberry_system.php ueberschreibt beim Einbinden Globale des
        // Aufrufers. Deshalb heisst hier alles ap_... und die Pfade werden
        // danach neu geholt.
        $ap_p = ap_paths();
    }
}

/* ================= Reiter =================
 *
 * Die Namen stehen an drei Stellen: hier als Positivliste, unten als Leiste
 * und als id am Bereich. Alle drei sind AUSGESCHRIEBEN - und das ist eine
 * bewusste Entscheidung gegen eine erzeugte Liste:
 *
 *   hausstandard_pruefen.py sucht die Positivliste als Literal (entweder
 *   ^tab-(a|b|c) oder array('tab-a', ...)) und die Leiste als
 *   data-ziel="tab-…". Wird eines davon erzeugt, findet das Werkzeug es
 *   nicht, meldet die Spalte als "trifft nicht zu" - und das sieht aus wie
 *   in Ordnung. Dieser Fehler steht in REGELN_1 dreimal.
 *
 * Der Preis dafuer ist, dass die drei Stellen auseinanderlaufen koennen.
 * Dagegen steht keine Hoffnung, sondern eine Pruefzeile: der Reiter Test
 * haelt Positivliste, Leiste und Bereichs-ids gegeneinander und wird rot,
 * sobald eine der drei abweicht. */
$ap_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');

$tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $ap_reiter, true)) {
    $tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $ap_reiter, true)) {
    $tab = 'tab-' . (string) $_GET['form'];
}

/** ' sm-active', wenn dieser Bereich der offene ist. */
function ap_aktiv($id)
{
    global $tab;
    return $tab === $id ? ' sm-active' : '';
}

$ap_saved     = false;
$ap_fehler    = array();   // harte Beanstandungen
$ap_hinweise  = array();   // Meldungen, die das Speichern nicht verhindern

list($ap_cfg, $ap_altformat) = ap_config_read();

/* ============ Merkmal gegen fremde Absender ============
 *
 * Jedes Formular fuehrt es mit, und EINE Pruefung steht vor allen Handlern.
 * Ohne das konnte eine fremde Seite im angemeldeten Browser des Anwenders
 * "Dienst anhalten" ausloesen - der Browser schickt die LoxBerry-Anmeldung
 * mit. Bei einem USV-Plugin heisst das: die Netzausfallmeldung hoert auf,
 * ohne dass es jemand merkt.
 *
 * Das Merkmal entsteht beim ersten Aufruf der Oberflaeche und ueberlebt
 * jedes Speichern, weil der Speicher-Handler den Bestand uebernimmt statt
 * die Konfiguration neu zu bauen.
 */
if (ap_roh($ap_cfg, 'formtoken') === '') {
    $neu = $ap_cfg;
    $neu['formtoken'] = bin2hex(function_exists('random_bytes')
        ? random_bytes(16) : pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()));
    if (ap_config_write($neu)) {
        list($ap_cfg, $ap_altformat) = ap_config_read();
    }
}
$ap_formtoken = ap_roh($ap_cfg, 'formtoken');

$ap_ist_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($ap_ist_post) {
    $mit = isset($_POST['ap_form']) ? (string) $_POST['ap_form'] : '';
    if ($ap_formtoken === '' || !hash_equals($ap_formtoken, $mit)) {
        // Abweisen, nicht zurechtbiegen. Und sagen, was zu tun ist - ein
        // abgelaufenes Merkmal nach einem Neustart sieht sonst aus wie ein
        // Fehler des Plugins.
        $ap_ist_post = false;
        $ap_fehler[] = ap_t('TEXT.FORMULAR_ABGEWIESEN');
    }
}

/* ============ Loxone-Vorlage herunterladen ============ */
if ($ap_ist_post && isset($_POST['download'])) {
    $art = (string) $_POST['download'];
    if ($art !== 'mqtt_in' && $art !== 'xml_in') {
        $art = 'mqtt_in';
    }
    list($name, $inhalt) = ap_vorlage($ap_cfg, $art);
    header('Content-Type: application/x-download');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht: ohne sie
    // bricht jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($inhalt));
    echo $inhalt;
    exit;
}

/* ============ Test-Aktionen ============ */
$ap_test_titel = '';
$ap_test_text = '';
if ($ap_ist_post && isset($_POST['test'])) {
    require_once __DIR__ . '/ap_test.php';
    list($ap_test_titel, $ap_test_text) = ap_test_ausfuehren((string) $_POST['test']);
    $tab = 'tab-test';
}

/* ============ Speichern: Einstellungen ============ */
if ($ap_ist_post && isset($_POST['save'])) {
    $neu = $ap_cfg;
    $saeubern = function ($s) {
        // Nur Steuerzeichen und Anfuehrungszeichen entfernen. Eine
        // Positivliste wuerde eingefuegte Adressen zerstoeren.
        $s = preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s);
        return trim($s);
    };
    $ganz = function ($wert, $vorgabe, $min, $max) {
        if (!is_numeric($wert)) {
            return (string) $vorgabe;
        }
        $n = (int) $wert;
        return ($n >= $min && $n <= $max) ? (string) $n : (string) $vorgabe;
    };

    $neu['enabled']          = isset($_POST['enabled']) ? '1' : '0';
    $neu['benachrichtigung'] = isset($_POST['benachrichtigung']) ? '1' : '0';
    $neu['email']            = isset($_POST['email']) ? '1' : '0';

    $an = $saeubern(isset($_POST['email_an']) ? $_POST['email_an'] : '');
    // Der ganze Domainteil war frueher optional. Damit ging zwar der
    // beabsichtigte oertliche Empfaenger "root" durch - aber genauso jeder
    // Vertipper wie "meine_email", der dann kommentarlos an sendmail wanderte
    // und nirgends ankam.
    if (strpos($an, '@') === false && ap_benutzer_existiert($an)) {
        $neu['email_an'] = $an;                       // oertlicher Benutzer
    } elseif (filter_var($an, FILTER_VALIDATE_EMAIL)) {
        $neu['email_an'] = $an;
    } else {
        $neu['email_an'] = 'root';
        if ($an !== '') {
            $ap_hinweise[] = ap_t('EINST.EMAIL_UNGUELTIG');
        }
    }

    $neu['intervall']      = $ganz(isset($_POST['intervall']) ? $_POST['intervall'] : '', 30, 5, 3600);
    $neu['aktualisierung'] = $ganz(isset($_POST['aktualisierung']) ? $_POST['aktualisierung'] : '', 300, 5, 86400);
    $neu['vorwarn_min']    = $ganz(isset($_POST['vorwarn_min']) ? $_POST['vorwarn_min'] : '', 5, 0, 600);
    $neu['vorwarn_prozent'] = $ganz(isset($_POST['vorwarn_prozent']) ? $_POST['vorwarn_prozent'] : '', 10, 0, 100);
    $neu['log_kb']         = $ganz(isset($_POST['log_kb']) ? $_POST['log_kb'] : '', 512, 32, 20480);

    // Nur Rechnername oder Adresse, notfalls mit Port. Kein Leerzeichen,
    // damit nichts an apcaccess durchgereicht wird, was dort nicht hingehoert.
    $host = $saeubern(isset($_POST['host']) ? $_POST['host'] : '');
    $neu['host'] = preg_match('/^[A-Za-z0-9._-]+(:[0-9]{1,5})?$/', $host) ? $host : '';
    if ($host !== '' && $neu['host'] === '') {
        $ap_hinweise[] = ap_t('TEXT.HOST_UNGUELTIG');
    }

    if (ap_config_write($neu)) {
        $ap_saved = true;
        ap_dienst('restart');
        $ap_hinweise[] = ap_dienst_pid()
            ? ap_t('TEXT.DIENST_NEU_GESTARTET')
            : ap_t('TEXT.DIENST_LAEUFT_NICHT');
        list($ap_cfg, $ap_altformat) = ap_config_read();
    } else {
        $ap_fehler[] = ap_t('TEXT.SCHREIBFEHLER') . ' ' . $ap_p['config'];
    }
}

/* ============ Speichern: MQTT ============
 * Eigenes Formular, eigener Handler - die MQTT-Belange wohnen vollstaendig
 * im Reiter MQTT. Zwei Formulare mit demselben Knopfnamen brauchen ein
 * Formularkennzeichen; deshalb heisst dieser Knopf save_mqtt. */
if ($ap_ist_post && isset($_POST['save_mqtt'])) {
    $neu = $ap_cfg;
    $neu['mqtt'] = isset($_POST['mqtt']) ? '1' : '0';

    $roh = trim((string) (isset($_POST['themenpraefix']) ? $_POST['themenpraefix'] : ''));
    $praefix = preg_replace('/[^A-Za-z0-9_\/-]+/', '', $roh);
    if ($roh !== '' && $praefix !== $roh) {
        $ap_hinweise[] = ap_t('MQTT.PRAEFIX_GEAENDERT');
    }
    $neu['themenpraefix'] = $praefix !== '' ? $praefix : 'apcups';

    // Rohfelder: abweisen und MELDEN, nicht stillschweigend wegschneiden.
    $rf_roh = trim((string) (isset($_POST['rohfelder']) ? $_POST['rohfelder'] : ''));
    $gut = array();
    $schlecht = array();
    foreach (preg_split('/[,;\s]+/', $rf_roh) as $stueck) {
        $s = strtoupper(trim($stueck));
        if ($s === '') {
            continue;
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $s)) {
            if (!in_array($s, $gut, true)) {
                $gut[] = $s;
            }
        } else {
            $schlecht[] = trim($stueck);
        }
    }
    if ($schlecht) {
        // Melden, aber das Speichern nicht verhindern: sonst laesst sich ein
        // zweites Feld nicht eintragen, bevor das erste stimmt.
        $ap_hinweise[] = sprintf(ap_t('MQTT.ROHFELD_ABGEWIESEN'), implode(', ', $schlecht));
    }
    $neu['rohfelder'] = implode(',', $gut);

    if (ap_config_write($neu)) {
        $ap_saved = true;
        ap_dienst('restart');
        $ap_hinweise[] = ap_dienst_pid()
            ? ap_t('TEXT.DIENST_NEU_GESTARTET')
            : ap_t('TEXT.DIENST_LAEUFT_NICHT');
        list($ap_cfg, $ap_altformat) = ap_config_read();
    } else {
        $ap_fehler[] = ap_t('TEXT.SCHREIBFEHLER') . ' ' . $ap_p['config'];
    }
}

$ap_praefix = ap_cfg($ap_cfg, 'themenpraefix', 'apcups');
$ap_pid     = ap_dienst_pid();
$ap_status  = ap_status();
$ap_alter   = ap_status_alter();
$ap_broker  = ap_mqtt_broker();
$ap_autost  = ap_gateway_autostart();
$ap_log     = ap_log_file();
$ap_zeilen  = ap_log_tail($ap_log);
$ap_w       = ($ap_status && !empty($ap_status['werte'])) ? $ap_status['werte'] : null;
$ap_themen  = ap_themen();

/** Kurzform fuer "der Wert ist da". */
function ap_hat($w, $k)
{
    return is_array($w) && isset($w[$k]) && $w[$k] !== null && $w[$k] !== '';
}

/** Zahl oder Gedankenstrich. */
function ap_zahl($w, $k, $einheit = '')
{
    if (!ap_hat($w, $k)) {
        return '&ndash;';
    }
    return ap_e($w[$k]) . ($einheit !== '' ? '&nbsp;' . ap_e($einheit) : '');
}

$ap_frame = class_exists('LBWeb', false);
if ($ap_frame) {
    // Der Verweis zeigt auf DIESES Repository, nicht auf die Wiki-Seite des
    // Originalplugins: die beschreibt eine andere Fassung mit anderer
    // Bedienung, und Rueckfragen dazu gehoeren nicht zum Originalautor.
    LBWeb::lbheader('APC-UPS NG', 'https://github.com/timanders22/LoxBerry-Plugin-APC-UPS', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss.
   Wortgetreu aus VORLAGE_hausstandard.css.html uebernommen. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Tabelle mit vielen Spalten in einen Rollbehaelter - sonst steht die
   letzte Spalte auf schmalem Bildschirm ausserhalb und ist unerreichbar. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen - die Rahmen-CSS des
   LoxBerry nimmt den Pfeil weg. Die Raute im SVG wird als %23 geschrieben:
   eine rohe Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Nur fuer dieses Plugin: Ladebalken, grosse Zustandszeile, Protokollkasten. */
.sm-akku { height: 16px; background: #eceff1; border-radius: 4px; overflow: hidden; margin: 6px 0 2px; }
.sm-akku i { display: block; height: 100%; background: #6dac20; }
.sm-akku.sm-voll i { background: #e0620d; }
.sm-gross { font-size: 1.6em; font-weight: 700; color: #4f7d17; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
.sm-zeile { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-zeile > div { flex: 1; min-width: 190px; }
</style>
<div class="sm-wrap">

<?php if ($ap_saved) { ?>
<div class="sm-hinweis"><b><?php echo ap_e(ap_t('TEXT.GESPEICHERT')); ?></b></div>
<?php } ?>

<?php foreach ($ap_fehler as $f) { ?>
<div class="sm-warnung"><b><?php echo ap_e(ap_t('TEXT.HINWEIS')); ?></b> <?php echo ap_e($f); ?></div>
<?php } ?>
<?php foreach ($ap_hinweise as $h) { ?>
<div class="sm-hinweis"><?php echo ap_e($h); ?></div>
<?php } ?>

<?php if ($ap_altformat) { ?>
<div class="sm-hinweis"><?php echo ap_t('TEXT.ALTES_FORMAT'); ?></div>
<?php } ?>

<?php if (!$ap_themen) { ?>
<div class="sm-warnung"><b><?php echo ap_e(ap_t('TEXT.HINWEIS')); ?></b>
<?php echo ap_e(sprintf(ap_t('TEXT.THEMENDATEI_FEHLT'), ap_themen_datei())); ?></div>
<?php } ?>

<?php
// Die Zustandszeile. Bis 1.1.6 stand hier eine Kette von Sprachfragmenten;
// jetzt ist es ein Satz je Aussage.
$ap_kachel = function ($titel, $wert, $klasse = '') {
    echo '<div class="sm-kachel"><b class="' . $klasse . '">' . $wert . '</b>'
       . ap_e($titel) . '</div>';
};
?>
<div class="sm-kacheln">
<?php
$ap_kachel(ap_t('ALLGEMEIN.DIENST'),
    $ap_pid ? ap_e(ap_t('ALLGEMEIN.LAEUFT')) . ' (PID ' . (int) $ap_pid . ')'
            : '<span class="sm-aus">' . ap_e(ap_t('ALLGEMEIN.LAEUFT_NICHT')) . '</span>');
$ap_kachel(ap_t('ALLGEMEIN.PLUGIN'),
    ap_cfg($ap_cfg, 'enabled', '1') === '1'
        ? '<span class="sm-an">' . ap_e(ap_t('ALLGEMEIN.EIN')) . '</span>'
        : '<span class="sm-aus">' . ap_e(ap_t('ALLGEMEIN.AUS')) . '</span>');
$ap_kachel('MQTT',
    ap_cfg($ap_cfg, 'mqtt', '1') === '1'
        ? '<span class="sm-an">' . ap_e(ap_t('ALLGEMEIN.EIN')) . '</span>'
        : '<span class="sm-aus">' . ap_e(ap_t('ALLGEMEIN.AUS')) . '</span>');
if ($ap_w) {
    $ap_kachel(ap_t('ALLGEMEIN.USV'), ap_e($ap_w['status']));
    $ap_kachel(ap_t('ALLGEMEIN.STAND_SEKUNDEN'), (int) $ap_alter);
}
?>
</div>

<?php
/* Die Warnung, die bis 1.1.6 fehlte: apcaccess liefert bei abgezogenem
   USB-Kabel einen vollstaendig aussehenden Datensatz mit Nullen. Wer die
   Tabelle darunter ohne diesen Kasten liest, glaubt einer 0-Prozent-Last. */
if ($ap_w && isset($ap_w['data_valid']) && (int) $ap_w['data_valid'] === 0) { ?>
<div class="sm-warnung"><b><?php echo ap_e(ap_t('ALLGEMEIN.KEINE_VERBINDUNG')); ?></b>
<?php echo ap_t('ALLGEMEIN.KEINE_VERBINDUNG_LANG'); ?></div>
<?php } ?>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN, nicht als Schleife.
     Eine erzeugte Leiste macht hausstandard_pruefen.py blind: es sucht
     data-ziel="tab-…" als Literal, findet bei einer Schleife nichts und
     meldet die Spalte als "trifft nicht zu" - das sieht aus wie in Ordnung.
     Genau dieser Fehler steht in REGELN_1 schon zweimal.
     Die Positivliste steht als $ap_reiter am Dateikopf, und der Reiter Test
     haelt alle DREI Stellen gegeneinander: Liste, Leiste und Bereichs-ids. -->
<div class="sm-tabs">
	<a class="sm-tab<?php echo ap_aktiv('tab-settings'); ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?php echo ap_e(ap_t('REITER.EINSTELLUNGEN')); ?></a>
	<a class="sm-tab<?php echo ap_aktiv('tab-mqtt'); ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?php echo ap_aktiv('tab-loxone'); ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?php echo ap_e(ap_t('REITER.LOXONE')); ?></a>
	<a class="sm-tab<?php echo ap_aktiv('tab-test'); ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?php echo ap_e(ap_t('REITER.TEST')); ?></a>
	<a class="sm-tab<?php echo ap_aktiv('tab-log'); ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?php echo ap_e(ap_t('REITER.LOG')); ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?php echo ap_aktiv('tab-settings'); ?>" id="tab-settings">

<?php if ($ap_w) {
    $lad = ap_hat($ap_w, 'battery_charge') ? (float) $ap_w['battery_charge'] : null;
    $akku = !empty($ap_w['on_battery']); ?>
<h2><?php echo ap_e(ap_t('EINST.AKTUELLER_ZUSTAND')); ?></h2>
<div class="sm-gross"><?php echo ap_e($ap_w['status']);
    echo $akku ? ' &mdash; ' . ap_e(ap_t('EINST.NETZAUSFALL')) : ''; ?></div>
<?php if ($lad !== null) { ?>
<div class="sm-akku<?php echo ($lad < 50 || $akku) ? ' sm-voll' : ''; ?>"><i style="width: <?php echo (float) $lad; ?>%;"></i></div>
<?php } ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34%;"><?php echo ap_e(ap_thema_text('model')); ?></th><td><?php echo ap_zahl($ap_w, 'model'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('alarm_level')); ?></th><td><?php
    echo ap_zahl($ap_w, 'alarm_level');
    $stufen = array(0 => 'EINST.STUFE_0', 1 => 'EINST.STUFE_1', 2 => 'EINST.STUFE_2', 3 => 'EINST.STUFE_3');
    if (ap_hat($ap_w, 'alarm_level') && isset($stufen[(int) $ap_w['alarm_level']])) {
        echo ' &middot; ' . ap_e(ap_t($stufen[(int) $ap_w['alarm_level']]));
    }
?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('battery_charge')); ?></th><td><?php echo ap_zahl($ap_w, 'battery_charge', '%'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('time_left')); ?></th><td><?php echo ap_zahl($ap_w, 'time_left', 'min'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('line_voltage')); ?></th><td><?php echo ap_zahl($ap_w, 'line_voltage', 'V'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('output_voltage')); ?></th><td><?php echo ap_zahl($ap_w, 'output_voltage', 'V'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('line_frequency')); ?></th><td><?php echo ap_zahl($ap_w, 'line_frequency', 'Hz'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('load_percent')); ?></th><td><?php echo ap_zahl($ap_w, 'load_percent', '%');
    if (ap_hat($ap_w, 'load_watt')) { echo ' &middot; ' . ap_zahl($ap_w, 'load_watt', 'W'); }
    if (ap_hat($ap_w, 'nominal_power')) { echo ' ' . ap_e(sprintf(ap_t('EINST.VON_NENNLEISTUNG'), $ap_w['nominal_power'])); } ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('battery_voltage')); ?></th><td><?php echo ap_zahl($ap_w, 'battery_voltage', 'V');
    if (ap_hat($ap_w, 'nominal_battery_volt')) { echo ' ' . ap_e(sprintf(ap_t('EINST.VON_NENNSPANNUNG'), $ap_w['nominal_battery_volt'])); } ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('internal_temp')); ?></th><td><?php echo ap_zahl($ap_w, 'internal_temp', '&deg;C'); ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('battery_date')); ?></th><td><?php echo ap_zahl($ap_w, 'battery_date');
    if (ap_hat($ap_w, 'battery_age_months')) {
        echo ' &middot; ' . ap_e(sprintf(ap_t('EINST.AKKU_ALTER'), $ap_w['battery_age_months']));
    } else {
        echo ' &middot; ' . ap_e(ap_t('EINST.AKKU_ALTER_UNKLAR'));
    }
    if (!empty($ap_w['replace_battery'])) { echo ' &mdash; <b>' . ap_e(ap_t('EINST.AUSTAUSCH_FAELLIG')) . '</b>'; } ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('self_test_result')); ?></th><td><?php echo ap_zahl($ap_w, 'self_test_result');
    if (ap_hat($ap_w, 'self_test_interval')) { echo ' &middot; ' . ap_zahl($ap_w, 'self_test_interval'); } ?></td></tr>
<tr><th><?php echo ap_e(ap_thema_text('transfers')); ?></th><td><?php echo ap_zahl($ap_w, 'transfers');
    if (ap_hat($ap_w, 'last_transfer')) { echo ' &middot; ' . ap_zahl($ap_w, 'last_transfer'); } ?></td></tr>
<tr><th><?php echo ap_e(ap_t('EINST.ABSCHALTSCHWELLEN')); ?></th><td><?php
    if (ap_hat($ap_w, 'shutdown_charge') || ap_hat($ap_w, 'shutdown_minutes')) {
        echo ap_zahl($ap_w, 'shutdown_charge', '%') . ' &middot; ' . ap_zahl($ap_w, 'shutdown_minutes', 'min');
    } else {
        echo ap_e(ap_t('EINST.SCHWELLEN_UNBEKANNT'));
    } ?></td></tr>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?php echo ap_t('EINST.NOCH_KEINE_WERTE'); ?></div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="ap_form" value="<?php echo ap_e($ap_formtoken); ?>">

<h2><?php echo ap_e(ap_t('EINST.BETRIEB')); ?></h2>
<div class="sm-feld">
<label><input data-role="none" type="checkbox" name="enabled" value="1"<?php
    echo ap_cfg($ap_cfg, 'enabled', '1') === '1' ? ' checked' : ''; ?>>
<?php echo ap_e(ap_t('EINST.PLUGIN_EINGESCHALTET')); ?></label>
<div class="sm-hilfe"><?php echo ap_t('EINST.PLUGIN_EINGESCHALTET_HILFE'); ?></div>
</div>

<div class="sm-zeile">
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.INTERVALL')); ?></label>
<input data-role="none" type="number" name="intervall" min="5" max="3600" value="<?php echo ap_e(ap_cfg($ap_cfg, 'intervall', '30')); ?>">
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.AKTUALISIERUNG')); ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?php echo ap_e(ap_cfg($ap_cfg, 'aktualisierung', '300')); ?>">
<div class="sm-hilfe"><?php echo ap_t('EINST.AKTUALISIERUNG_HILFE'); ?></div>
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.HOST')); ?></label>
<input data-role="none" type="text" name="host" value="<?php echo ap_e(ap_roh($ap_cfg, 'host')); ?>">
<div class="sm-hilfe"><?php echo ap_t('EINST.HOST_HILFE'); ?></div>
</div>
</div>

<h2><?php echo ap_e(ap_t('EINST.VORWARNUNG')); ?></h2>
<div class="sm-hilfe"><?php echo ap_t('EINST.VORWARNUNG_HILFE'); ?></div>
<div class="sm-zeile">
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.VORWARN_MIN')); ?></label>
<input data-role="none" type="number" name="vorwarn_min" min="0" max="600" value="<?php echo ap_e(ap_cfg($ap_cfg, 'vorwarn_min', '5')); ?>">
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.VORWARN_PROZENT')); ?></label>
<input data-role="none" type="number" name="vorwarn_prozent" min="0" max="100" value="<?php echo ap_e(ap_cfg($ap_cfg, 'vorwarn_prozent', '10')); ?>">
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.LOG_KB')); ?></label>
<input data-role="none" type="number" name="log_kb" min="32" max="20480" value="<?php echo ap_e(ap_cfg($ap_cfg, 'log_kb', '512')); ?>">
<div class="sm-hilfe"><?php echo ap_t('EINST.LOG_KB_HILFE'); ?></div>
</div>
</div>

<h2><?php echo ap_e(ap_t('EINST.MELDEWEGE')); ?></h2>
<div class="sm-feld">
<label><input data-role="none" type="checkbox" name="benachrichtigung" value="1"<?php
    echo ap_cfg($ap_cfg, 'benachrichtigung', '1') === '1' ? ' checked' : ''; ?>>
<?php echo ap_e(ap_t('EINST.BENACHRICHTIGUNG')); ?></label>
<div class="sm-hilfe"><?php echo ap_t('EINST.BENACHRICHTIGUNG_HILFE'); ?></div>
</div>
<div class="sm-feld">
<label><input data-role="none" type="checkbox" name="email" value="1"<?php
    echo ap_cfg($ap_cfg, 'email', '0') === '1' ? ' checked' : ''; ?>>
<?php echo ap_e(ap_t('EINST.EMAIL')); ?></label>
<div class="sm-hilfe"><?php echo ap_t('EINST.EMAIL_HILFE'); ?></div>
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('EINST.EMAIL_AN')); ?></label>
<input data-role="none" type="text" name="email_an" value="<?php echo ap_e(ap_cfg($ap_cfg, 'email_an', 'root')); ?>">
<div class="sm-hilfe"><?php echo ap_t('EINST.EMAIL_AN_HILFE'); ?></div>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ap_e(ap_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?php echo ap_e(ap_t('ALLGEMEIN.SPEICHERN')); ?></button>
</div>
<div class="sm-hilfe"><?php echo ap_t('EINST.SPEICHERN_HILFE'); ?></div>
</form>

<h2><?php echo ap_e(ap_t('EINST.NOTABSCHALTUNG')); ?></h2>
<div class="sm-hilfe"><?php echo ap_t('EINST.NOTABSCHALTUNG_HILFE'); ?></div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?php echo ap_aktiv('tab-mqtt'); ?>" id="tab-mqtt">

<h2><?php echo ap_e(ap_t('MQTT.ZUSTAND')); ?></h2>
<div class="sm-kacheln">
<?php
$ap_kachel(ap_t('MQTT.BROKER'), $ap_broker !== '' ? ap_e($ap_broker)
    : '<span class="sm-aus">' . ap_e(ap_t('MQTT.KEIN_BROKER')) . '</span>');
$ap_kachel(ap_t('MQTT.AUTOSTART'),
    $ap_autost === null ? ap_e(ap_t('ALLGEMEIN.UNBEKANNT'))
    : ($ap_autost ? '<span class="sm-an">' . ap_e(ap_t('ALLGEMEIN.EIN')) . '</span>'
                  : '<span class="sm-aus">' . ap_e(ap_t('ALLGEMEIN.AUS')) . '</span>'));
$ap_kachel(ap_t('MQTT.PRAEFIX'), ap_e($ap_praefix));
?>
</div>
<?php if ($ap_autost === false) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo ap_t('MQTT.W_AUTOSTART'); ?></div>
<?php } ?>
<?php if (ap_cfg($ap_cfg, 'mqtt', '1') !== '1') { ?>
<div class="sm-warnung"><?php echo ap_t('MQTT.W_AUSGESCHALTET'); ?></div>
<?php } ?>

<h2><?php echo ap_e(ap_t('MQTT.ABO_UEBERSCHRIFT')); ?></h2>
<div class="sm-step">
<p><b><?php echo ap_e(ap_t('MQTT.ABO_SATZ')); ?></b></p>
<p class="sm-pre"><?php echo ap_e($ap_praefix); ?>/#</p>
<p><?php echo ap_t('MQTT.ABO_WEG'); ?></p>
<p><b><?php echo ap_e(ap_t('MQTT.ABO_OHNE')); ?></b></p>
</div>

<h2><?php echo ap_e(ap_t('MQTT.EINSTELLUNGEN')); ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="ap_form" value="<?php echo ap_e($ap_formtoken); ?>">
<div class="sm-feld">
<label><input data-role="none" type="checkbox" name="mqtt" value="1"<?php
    echo ap_cfg($ap_cfg, 'mqtt', '1') === '1' ? ' checked' : ''; ?>>
<?php echo ap_e(ap_t('MQTT.EINSCHALTEN')); ?></label>
<div class="sm-hilfe"><?php echo ap_t('MQTT.EINSCHALTEN_HILFE'); ?></div>
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('MQTT.PRAEFIX')); ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?php echo ap_e($ap_praefix); ?>">
<div class="sm-hilfe"><?php echo ap_t('MQTT.PRAEFIX_HILFE'); ?></div>
</div>
<div class="sm-feld">
<label><?php echo ap_e(ap_t('MQTT.ROHFELDER')); ?></label>
<input data-role="none" type="text" name="rohfelder" value="<?php echo ap_e(ap_roh($ap_cfg, 'rohfelder')); ?>" placeholder="LINEFREQ, HITRANS, LOTRANS">
<div class="sm-hilfe"><?php echo ap_t('MQTT.ROHFELDER_HILFE'); ?></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ap_e(ap_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_mqtt" value="1"><?php echo ap_e(ap_t('ALLGEMEIN.SPEICHERN')); ?></button>
</div>
</form>

<h2><?php echo ap_e(ap_t('MQTT.THEMEN')); ?></h2>
<div class="sm-hilfe"><?php echo ap_t('MQTT.THEMEN_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:30%;"><?php echo ap_e(ap_t('MQTT.SP_THEMA')); ?></th>
<th style="width:9%;"><?php echo ap_e(ap_t('MQTT.SP_ART')); ?></th>
<th style="width:8%;"><?php echo ap_e(ap_t('MQTT.SP_EINHEIT')); ?></th>
<th style="width:10%;"><?php echo ap_e(ap_t('MQTT.SP_RETAIN')); ?></th>
<th><?php echo ap_e(ap_t('MQTT.SP_BEDEUTUNG')); ?></th></tr>
<?php foreach ($ap_themen as $k => $info) { ?>
<tr><td><span class="sm-mono"><?php echo ap_e($ap_praefix . '/' . $k); ?></span></td>
<td><?php echo ap_e($info['art']); ?></td>
<td><?php echo ap_e($info['einheit']); ?></td>
<td><?php echo $info['retain'] ? ap_e(ap_t('ALLGEMEIN.JA')) : ap_e(ap_t('ALLGEMEIN.NEIN')); ?></td>
<td><?php echo ap_e(ap_thema_text($k));
    $lang = ap_thema_lang($k);
    if ($lang !== '') { echo '<div class="sm-hilfe">' . $lang . '</div>'; } ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?php echo ap_t('MQTT.RETAIN_ERKLAERUNG'); ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?php echo ap_aktiv('tab-loxone'); ?>" id="tab-loxone">

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT1')); ?></h2>
<div class="sm-step"><?php echo ap_t('LOXONE.SCHRITT1_TEXT'); ?></div>

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT2')); ?></h2>
<div class="sm-step">
<p><?php echo ap_t('LOXONE.SCHRITT2_TEXT'); ?></p>
<p class="sm-pre"><?php echo ap_e($ap_praefix); ?>/#</p>
<p><b><?php echo ap_e(ap_t('MQTT.ABO_OHNE')); ?></b></p>
</div>

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT3')); ?></h2>
<div class="sm-step">
<p><?php echo ap_t('LOXONE.SCHRITT3_TEXT'); ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo ap_e(ap_t('LEGENDE.TECHNIK_DATEI')); ?></span>
</div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="ap_form" value="<?php echo ap_e($ap_formtoken); ?>">
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_in"><?php echo ap_e(ap_t('LOXONE.KNOPF_MQTT')); ?></button>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="xml_in"><?php echo ap_e(ap_t('LOXONE.KNOPF_XML')); ?></button>
</div>
</form>
<p class="sm-hilfe"><?php echo ap_e(sprintf(ap_t('LOXONE.VORLAGE_ZAHL'),
    count(ap_vorlage_themen()), count(ap_text_themen()))); ?></p>
<p><b><?php echo ap_e(ap_t('LOXONE.IMPORT_DOPPELT')); ?></b></p>
</div>

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT4')); ?></h2>
<div class="sm-step"><?php echo ap_t('LOXONE.SCHRITT4_TEXT'); ?></div>

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT5')); ?></h2>
<div class="sm-step"><?php echo ap_t('LOXONE.SCHRITT5_TEXT'); ?></div>

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT6')); ?></h2>
<p class="sm-hilfe"><?php echo ap_t('LOXONE.BAUSTEINE_VORTEXT'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo ap_e(ap_t('LOXONE.SP_BAUSTEIN')); ?></th>
<th><?php echo ap_e(ap_t('LOXONE.SP_NAME')); ?></th>
<th><?php echo ap_e(ap_t('LOXONE.SP_PARAMETER')); ?></th>
<th><?php echo ap_e(ap_t('LOXONE.SP_EINGAENGE')); ?></th></tr>
<tr><td>1</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_on_battery</td><td>digital</td><td><?php echo ap_e(ap_t('LOXONE.B_VOM_GATEWAY')); ?></td></tr>
<tr><td>2</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_alarm_level</td><td>analog 0..3</td><td>&mdash;</td></tr>
<tr><td>3</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_data_valid</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>4</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_replace_battery</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>5</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_time_left</td><td><?php echo ap_e(ap_t('LOXONE.B_ANALOG_MIN')); ?></td><td>&mdash;</td></tr>
<tr><td>6</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_timestamp</td><td><?php echo ap_e(ap_t('LOXONE.B_ANALOG_S')); ?></td><td>&mdash;</td></tr>
<tr><td>7</td><td><?php echo ap_e(ap_t('LOXONE.B_VE')); ?></td><td class="sm-mono"><?php echo ap_e($ap_praefix); ?>_service_online</td><td>digital</td><td>&mdash;</td></tr>
<tr><td>8</td><td><?php echo ap_e(ap_t('LOXONE.B_EINVERZ')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_ENTPRELLT')); ?></td><td>10&nbsp;s</td><td><?php echo ap_e(ap_t('LOXONE.E_EINGANG')); ?> #1</td></tr>
<tr><td>9</td><td><?php echo ap_e(ap_t('LOXONE.B_NICHT')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_KEINE_DATEN')); ?></td><td>&mdash;</td><td><?php echo ap_e(ap_t('LOXONE.E_EINGANG')); ?> #3</td></tr>
<tr><td>10</td><td><?php echo ap_e(ap_t('LOXONE.B_ODER')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_SAMMEL')); ?></td><td>&mdash;</td><td>#8, #4, #9</td></tr>
<tr><td>11</td><td><?php echo ap_e(ap_t('LOXONE.B_BENACHR')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_MELDUNG')); ?></td><td><?php echo ap_e(ap_t('LOXONE.P_MELDETEXT')); ?></td><td><?php echo ap_e(ap_t('LOXONE.E_EINGANG')); ?> #10</td></tr>
<tr><td>12</td><td><?php echo ap_e(ap_t('LOXONE.B_SCHWELL')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_LASTABWURF')); ?></td><td><?php echo ap_e(ap_t('LOXONE.P_SCHWELL')); ?></td><td><?php echo ap_e(ap_t('LOXONE.E_EINGANG')); ?> #2</td></tr>
<tr><td>13</td><td><?php echo ap_e(ap_t('LOXONE.B_MERKER')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_STROMSPAREN')); ?></td><td><?php echo ap_e(ap_t('LOXONE.P_VISU')); ?></td><td><?php echo ap_e(ap_t('LOXONE.E_EINGANG')); ?> #12</td></tr>
<tr><td>14</td><td><?php echo ap_e(ap_t('LOXONE.B_STATUS')); ?></td><td><?php echo ap_e(ap_t('LOXONE.N_STATUS')); ?></td><td><?php echo ap_e(ap_t('LOXONE.P_STATUS')); ?></td><td>v1 = #2, v2 = #5</td></tr>
</table>
</div>
<div class="sm-hinweis">
<b>Zu #10:</b> <?php echo ap_t('LOXONE.ZU_10'); ?><br>
<b>Zu #8:</b> <?php echo ap_t('LOXONE.ZU_8'); ?><br>
<b>Zu #12:</b> <?php echo ap_t('LOXONE.ZU_12'); ?><br>
<b>Zu #6:</b> <?php echo ap_t('LOXONE.ZU_6'); ?>
</div>

<h2><?php echo ap_e(ap_t('LOXONE.SCHRITT7')); ?></h2>
<div class="sm-step"><?php echo ap_t('LOXONE.SCHRITT7_TEXT'); ?></div>

<h2><?php echo ap_e(ap_t('LOXONE.ALTER_WEG')); ?></h2>
<div class="sm-hilfe">
<?php echo ap_t('LOXONE.ALTER_WEG_TEXT'); ?>
<p class="sm-pre">http://<?php echo ap_e(gethostname() ? gethostname() : 'loxberry'); ?>/plugins/<?php echo ap_e($ap_p['plugin']); ?>/index.php</p>
<p><?php echo ap_t('LOXONE.ALTER_WEG_ADRESSE'); ?></p>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?php echo ap_aktiv('tab-test'); ?>" id="tab-test">
<?php
/* Die Selbstpruefung laeuft NUR, wenn dieser Reiter wirklich der offene ist.
 *
 * Sie ruft systemctl auf, startet python3 zweimal und fragt den eigenen
 * Endpunkt ueber HTTP ab. Auf jedem Seitenaufbau waere das eine Pruefung,
 * die etwas kostet, ohne dass jemand hinsieht - und die Zeitschranke des
 * HTTP-Aufrufs laege bei jedem Klick auf "Speichern" im Weg.
 *
 * Damit das mit einem Klick erreichbar bleibt, laedt der Reiter Test als
 * einziger die Seite neu (siehe das Skript am Ende der Datei). Die uebrigen
 * Reiter schaltet das JavaScript weiterhin ohne Neuladen um.
 */
if ($tab === 'tab-test') {
    require_once __DIR__ . '/ap_test.php';
    ap_test_selbstpruefung($ap_cfg, $ap_w, $ap_pid, $ap_alter, $ap_broker, $ap_autost);
} else { ?>
<h2><?php echo ap_e(ap_t('TEST.SELBSTPRUEFUNG')); ?></h2>
<div class="sm-hinweis"><?php echo ap_t('TEST.SELBSTPRUEFUNG_LADEN'); ?></div>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="index.php?form=test"><?php
    echo ap_e(ap_t('TEST.SELBSTPRUEFUNG_KNOPF')); ?></a>
</div>
<?php } ?>

<h2><?php echo ap_e(ap_t('TEST.KNOEPFE')); ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo ap_e(ap_t('LEGENDE.LESEN')); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo ap_e(ap_t('LEGENDE.TECHNIK')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ap_e(ap_t('LEGENDE.AKTION')); ?></span>
</div>

<!-- Die Knoepfe stehen AUSGESCHRIEBEN, nicht aus einer Schleife.
     hausstandard_pruefen.py vergleicht je Reiter die Farben der Legende mit
     den Farben der Knoepfe darin - und findet nur literale Klassenattribute.
     Eine PHP-Funktion, die class="sm-btn " . $klasse zusammensetzt, macht die
     Pruefung blind, und die Legende bekaeme unbemerkt Farben, die es im
     Reiter gar nicht gibt. Der Vorlauf steht deshalb in $ap_ftok. -->
<?php $ap_ftok = '<input data-role="none" type="hidden" name="activetab" value="tab-test">'
    . '<input data-role="none" type="hidden" name="ap_form" value="' . ap_e($ap_formtoken) . '">'; ?>

<h3><?php echo ap_e(ap_t('TEST.G_ANSEHEN')); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo ap_e(ap_t('TEST.K_STATUS')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="werte"><?php echo ap_e(ap_t('TEST.K_WERTE')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="ereignisse"><?php echo ap_e(ap_t('TEST.K_EREIGNISSE')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="mqttinfo"><?php echo ap_e(ap_t('TEST.K_MQTT')); ?></button></form>
</div>

<h3><?php echo ap_e(ap_t('TEST.G_TECHNIK')); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="apcupsd"><?php echo ap_e(ap_t('TEST.K_APCUPSD')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo ap_e(ap_t('TEST.K_KONFIG')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo ap_e(ap_t('TEST.K_UMGEBUNG')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="vorlage"><?php echo ap_e(ap_t('TEST.K_VORLAGE')); ?></button></form>
</div>

<h3><?php echo ap_e(ap_t('TEST.G_AKTION')); ?></h3>
<div class="sm-hilfe"><?php echo ap_t('TEST.G_AKTION_HILFE'); ?></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abfragen"><?php echo ap_e(ap_t('TEST.K_ABFRAGEN')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="melden"><?php echo ap_e(ap_t('TEST.K_MELDEN')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo ap_e(ap_t('TEST.K_RESTART')); ?></button></form>
<form method="post" action="index.php"><?php echo $ap_ftok; ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo ap_e(ap_t('TEST.K_STOP')); ?></button></form>
</div>

<?php if ($ap_test_titel !== '') { ?>
<h2><?php echo ap_e($ap_test_titel); ?></h2>
<div class="sm-log"><?php echo ap_e($ap_test_text); ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?php echo ap_t('TEST.NOCH_NICHTS'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?php echo ap_aktiv('tab-log'); ?>" id="tab-log">
<h2><?php echo ap_e(ap_t('REITER.LOG')); ?></h2>
<div class="sm-hilfe">
<?php if ($ap_log !== '') { ?>
<?php echo ap_e(ap_t('LOG.DATEI')); ?> <span class="sm-mono"><?php echo ap_e($ap_log); ?></span>
<?php echo ap_e(ap_t('LOG.NEUESTE_OBEN')); ?>
<?php } else { ?>
<?php echo ap_e(ap_t('LOG.LEER')); ?>
<?php } ?>
</div>
<?php if ($ap_zeilen) { ?>
<div class="sm-log"><?php foreach ($ap_zeilen as $z) { echo ap_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) {
			// Der Reiter Test laedt als einziger neu: seine Selbstpruefung
			// laeuft serverseitig und kostet einen HTTP-Aufruf und zwei
			// Python-Starts. Sie bei jedem Seitenaufbau mitlaufen zu lassen,
			// waere eine Pruefung, fuer die niemand da ist.
			if (r.dataset.ziel === 'tab-test') { return; }
			e.preventDefault();
			zeige(r.dataset.ziel);
		});
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?php echo json_encode($tab); ?>);
})();
</script>
<?php
if ($ap_frame) {
    LBWeb::lbfooter();
}
