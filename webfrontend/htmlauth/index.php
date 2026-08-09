<?php
/**
 * APC-UPS NG - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die Perl-CGI-Oberflaeche der Originalfassung ab (index.cgi mit
 * HTML::Template, settings.html und je einer Sprachdatei fuer Deutsch und
 * Englisch). Alles auf Deutsch.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
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
    }
}

$ap_saved = false;
$ap_error = '';
$ap_hinweis = '';
$ap_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

list($ap_cfg, $ap_altformat) = ap_config_read();

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    list($name, $inhalt) = ap_vorlage($ap_cfg, (string) $_POST['download']);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename=' . $name);
    header('Content-Length: ' . strlen($inhalt));
    echo $inhalt;
    exit;
}

/* ============ Test-Aktionen ============ */
$ap_test_titel = '';
$ap_test_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    require_once __DIR__ . '/ap_test.php';
    list($ap_test_titel, $ap_test_text) = ap_test_ausfuehren((string) $_POST['test']);
    $ap_tab = 'tab-test';
}

/* ============ Speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = $ap_cfg;
    $saeubern = function ($s) {
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
    $neu['mqtt']             = isset($_POST['mqtt']) ? '1' : '0';
    $neu['benachrichtigung'] = isset($_POST['benachrichtigung']) ? '1' : '0';
    $neu['email']            = isset($_POST['email']) ? '1' : '0';
    $an = $saeubern($_POST['email_an'] ?? '');
    // Der ganze Domainteil war frueher optional (das Fragezeichen am Ende
    // des Ausdrucks). Damit ging zwar der beabsichtigte oertliche Empfaenger
    // "root" durch - aber genauso jeder Vertipper wie "meine_email", der
    // dann kommentarlos an sendmail wanderte und nirgends ankam.
    // Jetzt: entweder ein oertlicher Benutzername, oder eine Adresse, die
    // PHP selbst fuer gueltig haelt.
    if (strpos($an, '@') === false && ap_benutzer_existiert($an)) {
        $neu['email_an'] = $an;                       // oertlicher Benutzer, z. B. root
    } elseif (filter_var($an, FILTER_VALIDATE_EMAIL)) {
        $neu['email_an'] = $an;
    } else {
        $neu['email_an'] = 'root';
        if ($an !== '') {
            $ap_hinweis = ap_t('EINST.EMAIL_UNGUELTIG');
        }
    }

    $praefix = preg_replace('/[^A-Za-z0-9_\/-]+/', '', $saeubern($_POST['themenpraefix'] ?? ''));
    $neu['themenpraefix'] = $praefix !== '' ? $praefix : 'apcups';

    $neu['intervall']      = $ganz($_POST['intervall'] ?? '', 30, 5, 3600);
    $neu['aktualisierung'] = $ganz($_POST['aktualisierung'] ?? '', 300, 5, 86400);

    // Nur Rechnername oder Adresse, notfalls mit Port. Kein Leerzeichen,
    // damit nichts an apcaccess durchgereicht wird, was dort nicht hingehoert.
    $host = $saeubern($_POST['host'] ?? '');
    $neu['host'] = preg_match('/^[A-Za-z0-9._-]+(:[0-9]{1,5})?$/', $host) ? $host : '';
    if ($host !== '' && $neu['host'] === '') {
        $ap_error = ap_t('TEXT.HOST_UNGUELTIG');
    }

    if (ap_config_write($neu)) {
        $ap_saved = true;
        require_once __DIR__ . '/ap_test.php';
        ap_dienst('restart');
        $ap_hinweis = ap_dienst_pid()
            ? ap_t('TEXT.DIENST_NEU_GESTARTET')
            : ap_t('TEXT.DIENST_LAEUFT_NICHT');
        list($ap_cfg, $ap_altformat) = ap_config_read();
    } else {
        $ap_error = ap_t('TEXT.SCHREIBFEHLER') . ' ' . ap_e($ap_p['config']);
    }
}

$ap_praefix = ap_cfg($ap_cfg, 'themenpraefix', 'apcups');
$ap_pid     = ap_dienst_pid();
$ap_status  = ap_status();
$ap_alter   = ap_status_alter();
$ap_broker  = ap_mqtt_broker();
$ap_log     = ap_log_file();
$ap_zeilen  = ap_log_tail($ap_log);
$ap_w       = ($ap_status && !empty($ap_status['werte'])) ? $ap_status['werte'] : null;

$ap_frame = class_exists('LBWeb', false);
if ($ap_frame) {
    // Der Verweis zeigt auf DIESES Repository, nicht auf die Wiki-Seite des
    // Originalplugins: die beschreibt eine andere Fassung mit anderer
    // Bedienung, und Rueckfragen dazu gehoeren nicht zum Originalautor.
    LBWeb::lbheader('APC-UPS NG', 'https://github.com/timanders22/LoxBerry-Plugin-APC-UPS', 'help.html');
}
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { <?php echo ap_t('TEXT.TEXT_2'); ?>-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 180px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { box-shadow: none !important; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.sm-tbl th { background: #f0f0f0; }
.sm-gross { font-size: 1.6em; font-weight: 700; color: #4f7d17; }
.sm-akku { height: 16px; background: #eceff1; border-radius: 4px; overflow: hidden; margin: 6px 0 2px; }
.sm-akku i { display: block; height: 100%; background: #6dac20; }
.sm-akku.sm-warn i { background: #e0620d; }
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($ap_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo ap_t('TEXT.GESPEICHERT'); ?></b> <?= $ap_hinweis ?></div>
<?php } ?>
<?php if ($ap_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo ap_t('TEXT.HINWEIS'); ?></b> <?= $ap_error ?></div><?php } ?>
<?php if ($ap_altformat) { ?>
<div class="sm-alert sm-info"><?php echo ap_t('TEXT.IN_DER_KONFIGURATION_STEHEN_NOCH_S'); ?></div>
<?php } ?>

<div class="sm-alert sm-info">
<?php echo ap_t('TEXT.DIENST'); ?> <b><?= $ap_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $ap_pid ? ' (PID ' . $ap_pid . ')' : '' ?>
<?php echo ap_t('TEXT.PLUGIN'); ?> <b><?= ap_cfg($ap_cfg, 'enabled', '1') === '1' ? 'eingeschaltet' : 'ausgeschaltet' ?></b>
<?php if ($ap_w) { ?>
<?php echo ap_t('TEXT.USV'); ?> <b><?= ap_e($ap_w['status']) ?></b>
<?php if ($ap_w['battery_charge'] !== null) { ?><?php echo ap_t('TEXT.AKKU'); ?> <b><?= ap_e($ap_w['battery_charge']) ?><?php echo ap_t('TEXT.TEXT'); ?></b><?php } ?>
<?php } ?>
<?php echo ap_t('TEXT.MQTT'); ?> <b><?= ap_cfg($ap_cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus' ?></b>
<?php if ($ap_alter >= 0) { ?><?php echo ap_t('TEXT.STAND_VOR'); ?> <?= $ap_alter ?> s<?php } ?>
</div>

<div class="sm-tabs">
    <div class="sm-tab" data-pane="tab-settings"><?php echo ap_t('REITER.EINSTELLUNGEN'); ?></div>
    <div class="sm-tab" data-pane="tab-loxone"><?php echo ap_t('REITER.LOXONE'); ?></div>
    <div class="sm-tab" data-pane="tab-test"><?php echo ap_t('REITER.TEST'); ?></div>
    <div class="sm-tab" data-pane="tab-log"><?php echo ap_t('REITER.LOG'); ?></div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane" id="tab-settings">

<?php if ($ap_w) {
    $lad = $ap_w['battery_charge'];
    $akku = (int) $ap_w['on_battery'] === 1; ?>
<h2><?php echo ap_t('TEXT.AKTUELLER_ZUSTAND'); ?></h2>
<div class="sm-gross"><?= ap_e($ap_w['status']) ?><?= $akku ? ' &mdash; ' . ap_t('TEXT.NETZAUSFALL') : '' ?></div>
<?php if ($lad !== null) { ?>
<div class="sm-akku<?= ($lad < 50 || $akku) ? ' sm-warn' : '' ?>"><i style="width: <?= (float) $lad ?>%;"></i></div>
<div class="sm-small"><?php echo ap_t('TEXT.AKKU_2'); ?> <?= ap_e($lad) ?>&nbsp;%<?php
if ($ap_w['time_left'] !== null) { echo ' &middot; ' . ap_t('TEXT.NOCH_RUND') . ' ' . ap_e($ap_w['time_left']) . ' ' . ap_t('TEXT.MINUTEN'); }
if ($ap_w['load_watt'] !== null) { echo ' &middot; ' . ap_t('TEXT.LAST') . ' ' . ap_e($ap_w['load_watt']) . ' W'; }
?> <?php echo ap_t('TEXT.STAND_VOR'); ?> <?= $ap_alter ?> <?php echo ap_t('TEXT.SEKUNDEN'); ?></div>
<?php } ?>
<table class="sm-tbl" style="margin-top:10px;">
<tr><th style="width:34%;"><?php echo ap_t('TEXT.MODELL'); ?></th><td><?= ap_e($ap_w['model']) ?></td></tr>
<tr><th><?php echo ap_t('TEXT.NETZSPANNUNG'); ?></th><td><?= $ap_w['line_voltage'] === null ? '&ndash;' : ap_e($ap_w['line_voltage']) . ' V' ?></td></tr>
<tr><th><?php echo ap_t('TEXT.AKKUSPANNUNG'); ?></th><td><?= $ap_w['battery_voltage'] === null ? '&ndash;' : ap_e($ap_w['battery_voltage']) . ' V' ?></td></tr>
<tr><th><?php echo ap_t('TEXT.AKKU_EINGEBAUT'); ?></th><td><?= ap_e($ap_w['battery_date']) ?><?= $ap_w['replace_battery'] ? ' &mdash; <b>' . ap_t('TEXT.AUSTAUSCH_FLLIG') . '</b>' : '' ?></td></tr>
<tr><th><?php echo ap_t('TEXT.UMSCHALTUNGEN'); ?></th><td><?= $ap_w['transfers'] === null ? '&ndash;' : ap_e($ap_w['transfers']) ?><?php
if ($ap_w['last_transfer'] !== '') { echo ' &middot; ' . ap_t('TEXT.ZULETZT') . ' ' . ap_e($ap_w['last_transfer']); } ?></td></tr>
</table>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo ap_t('TEXT.NOCH_KEINE_WERTE_DER_REITER'); ?> <b><?php echo ap_t('TEXT.TEST'); ?></b> <?php echo ap_t('TEXT.ZEIGT_MIT'); ?>
<i><?php echo ap_t('TEXT.JETZT_ABFRAGEN'); ?></i><?php echo ap_t('TEXT.OB_DIE_USV_ANTWORTET'); ?></div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo ap_t('TEXT.BETRIEB'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1"<?= ap_cfg($ap_cfg, 'enabled', '1') === '1' ? ' checked' : '' ?>> <b><?php echo ap_t('TEXT.PLUGIN_EINGESCHALTET'); ?></b></label>
<div class="sm-small"><?php echo ap_t('TEXT.SOLANGE_DAS_NICHT_ANGEHAKT_IST_LUF'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo ap_t('TEXT.ABFRAGEN_ALLE_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="intervall" min="5" max="3600" value="<?= ap_e(ap_cfg($ap_cfg, 'intervall', '30')) ?>">
</div>
<div>
<label><?php echo ap_t('TEXT.ALLES_NEU_MELDEN_ALLE_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= ap_e(ap_cfg($ap_cfg, 'aktualisierung', '300')) ?>">
<div class="sm-small"><?php echo ap_t('TEXT.SONST_GEHT_NUR_HINAUS_WAS_SICH_GEN'); ?></div>
</div>
<div>
<label><?php echo ap_t('TEXT.USV_HOST'); ?></label>
<input data-role="none" type="text" name="host" value="<?= ap_e(ap_roh($ap_cfg, 'host')) ?>" placeholder="leer = &ouml;rtliche USV">
<div class="sm-small"><?php echo ap_t('TEXT.NUR_AUSFLLEN_WENN_DIE_USV_AN_EINEM'); ?> <b><?php echo ap_t('TEXT.ANDEREN'); ?></b> <?php echo ap_t('TEXT.RECHNER_HNGT_AUF_DEM_APCUPSD_MIT_N'); ?></div>
</div>
</div>

<h2><?php echo ap_t('TEXT.MELDEWEGE'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= ap_cfg($ap_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b><?php echo ap_t('TEXT.MQTT_2'); ?></b> <?php echo ap_t('TEXT.EMPFOHLEN'); ?></label>
<div class="sm-small"><?php echo ap_t('TEXT.ALLE_WERTE_GEHEN_RETAINED_AN_DEN_B'); ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="benachrichtigung" value="1"<?= ap_cfg($ap_cfg, 'benachrichtigung', '1') === '1' ? ' checked' : '' ?><?php echo ap_t('TEXT.LOXBERRY_BENACHRICHTIGUNG_BEI_ZUST'); ?></label>
<div class="sm-small"><?php echo ap_t('TEXT.ERSCHEINT_OBEN_IN_DER_LOXBERRY_OBE'); ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="email" value="1"<?= ap_cfg($ap_cfg, 'email', '0') === '1' ? ' checked' : '' ?><?php echo ap_t('TEXT.ZUSTZLICH_E_MAIL_VERSCHICKEN'); ?></label>
<div class="sm-small"><?php echo ap_t('TEXT.DER_WEG_DER_ORIGINALFASSUNG_BER_DE'); ?>
<span class="sm-mono"><?php echo ap_t('TEXT.SENDMAIL'); ?></span><?php echo ap_t('TEXT.SETZT_VORAUS_DASS_AUF_DEM_LOXBERRY'); ?></div>

<div class="sm-row" style="margin-top:8px;">
<div>
<label><?php echo ap_t('TEXT.E_MAIL_AN'); ?></label>
<input data-role="none" type="text" name="email_an" value="<?= ap_e(ap_cfg($ap_cfg, 'email_an', 'root')) ?>">
<div class="sm-small"><?php echo ap_t('TEXT.VOREINGESTELLT'); ?> <span class="sm-mono"><?php echo ap_t('TEXT.ROOT'); ?></span> <?php echo ap_t('TEXT.WOHIN_DAS_TATSCHLICH_GEHT_ENTSCHEI'); ?></div>
</div>
</div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo ap_t('TEXT.MQTT_THEMENPRFIX'); ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?= ap_e($ap_praefix) ?>">
</div>
</div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?php echo ap_t('TEXT.SPEICHERN'); ?></button>
<div class="sm-small"><?php echo ap_t('TEXT.BEIM_SPEICHERN_WIRD_DER_DIENST_NEU'); ?></div>
</form>

<h2><?php echo ap_t('TEXT.NOTABSCHALTUNG'); ?></h2>
<div class="sm-small">
<?php echo ap_t('TEXT.OB_UND_WANN_DER_LOXBERRY_BEI_LEERE'); ?>
<b><?php echo ap_t('TEXT.APCUPSD'); ?></b> <?php echo ap_t('TEXT.SELBST_NICHT_DIESES_PLUGIN_DIE_SCH'); ?>
<span class="sm-mono"><?php echo ap_t('TEXT.ETC_APCUPSD_APCUPSD_CONF'); ?></span> <?php echo ap_t('TEXT.UNTER'); ?> <span class="sm-mono"><?php echo ap_t('TEXT.BATTERYLEVEL'); ?></span>,
<span class="sm-mono"><?php echo ap_t('TEXT.MINUTES'); ?></span> und <span class="sm-mono"><?php echo ap_t('TEXT.TIMEOUT'); ?></span><?php echo ap_t('TEXT.VOREINGESTELLT_FHRT_APCUPSD_HERUNT'); ?>
</div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane" id="tab-loxone">

<h2><?php echo ap_t('TEXT.IN_DREI_SCHRITTEN_EINGERICHTET'); ?></h2>
<div class="sm-step"><b><?php echo ap_t('TEXT.1_USV_PRFEN'); ?></b> <?php echo ap_t('TEXT.IM_REITER_TEST_MIT'); ?> <i><?php echo ap_t('TEXT.JETZT_ABFRAGEN'); ?></i><?php echo ap_t('TEXT.KOMMEN_DORT_WERTE_IST_DER_SCHWIERI'); ?></div>
<div class="sm-step"><b><?php echo ap_t('TEXT.2_VORLAGE_HERUNTERLADEN'); ?></b> <?php echo ap_t('TEXT.UNTEN_UND_IN_LOXONE_CONFIG_EINLESE'); ?> <i><?php echo ap_t('TEXT.VORLAGE_EINFGEN'); ?></i>.</div>
<div class="sm-step"><b><?php echo ap_t('TEXT.3_EINGNGE_MIT_DEM_MQTT_GATEWAY_VER'); ?></b> <?php echo ap_t('TEXT.DIE_VORLAGE_LEGT_NUR_DIE_NAMEN_AN_'); ?> <i><?php echo ap_t('TEXT.INCOMING_OVERVIEW'); ?></i> <?php echo ap_t('TEXT.ERSCHEINEN_DIE_THEMEN_SOBALD_DER_D'); ?></div>

<div class="sm-small" style="margin-top:10px;">
<?php echo ap_t('TEXT.BROKER'); ?> <span class="sm-mono"><?= $ap_broker !== '' ? ap_e($ap_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
<?php echo ap_t('TEXT.THEMENPRFIX'); ?> <span class="sm-mono"><?= ap_e($ap_praefix) ?></span>
</div>

<?php if (ap_cfg($ap_cfg, 'mqtt', '1') !== '1') { ?>
<div class="sm-alert sm-err"><?php echo ap_t('TEXT.MQTT_IST_IM_REITER_EINSTELLUNGEN_A'); ?></div>
<?php } ?>

<h2><?php echo ap_t('TEXT.VORLAGEN'); ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo ap_t('LEGENDE.AKTION_DATEI'); ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_in"><?php echo ap_t('TEXT.VORLAGE_EINGNGE_MQTT'); ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="xml_in"><?php echo ap_t('TEXT.VORLAGE_ALTER_XML_WEG'); ?></button>
</div>
</form>
<div class="sm-small"><?php echo ap_t('TEXT.DIE_MQTT_VORLAGE_LEGT'); ?> <?= count(ap_status_themen()) ?> <?php echo ap_t('TEXT.VIRTUELLE_EINGNGE_AN_DIE_XML_VORLA'); ?></div>

<h2><?php echo ap_t('TEXT.WAS_VERFFENTLICHT_WIRD'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:32%;"><?php echo ap_t('TEXT.THEMA'); ?></th><th style="width:12%;">Art</th><th><?php echo ap_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php foreach (ap_status_themen() as $k => $info) { ?>
<tr><td><span class="sm-mono"><?= ap_e($ap_praefix . '/' . $k) ?></span></td><td><?= ap_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
<tr><td><span class="sm-mono"><?= ap_e($ap_praefix) ?><?php echo ap_t('TEXT.EVENT'); ?></span></td><td>text</td>
<td><?php echo ap_t('TEXT.KURZTEXT_BEIM_ZUSTANDSWECHSEL_Z_B'); ?> <span class="sm-mono"><?php echo ap_t('TEXT.STROMAUSFALL'); ?></span></td></tr>
</table>
<div class="sm-small"><?php echo ap_t('TEXT.ALLE_THEMEN_SIND'); ?> <b><?php echo ap_t('TEXT.RETAINED'); ?></b><?php echo ap_t('TEXT.DER_BROKER_MERKT_SICH_DEN_LETZTEN_'); ?></div>

<h2><?php echo ap_t('TEXT.DER_ALTE_WEG_BLEIBT_ERHALTEN'); ?></h2>
<div class="sm-small">
<?php echo ap_t('TEXT.DIE_ORIGINALFASSUNG_STELLTE_UNTER'); ?>
<span class="sm-mono"><?php echo ap_t('TEXT.PLUGINS'); ?><?= ap_e($ap_p['plugin']) ?><?php echo ap_t('TEXT.INDEX_PHP'); ?></span> <?php echo ap_t('TEXT.EINE_XML_SEITE_BEREIT_DIE_LOXONE_S'); ?>
<br><br>
<?php echo ap_t('TEXT.FR_NEUE_ANLAGEN_IST_MQTT_DER_BESSE'); ?>
</div>

<h2><?php echo ap_t('TEXT.WAS_SICH_ZUM_SCHALTEN_ANBIETET'); ?></h2>
<div class="sm-small">
<span class="sm-mono"><?php echo ap_t('TEXT.ON_BATTERY'); ?></span> <?php echo ap_t('TEXT.IST_DER_WERT_AUF_DEN_ES_MEISTENS_A'); ?> <span class="sm-mono"><?php echo ap_t('TEXT.TIME_LEFT'); ?></span> <?php echo ap_t('TEXT.SAGT_WIE_LANGE_NOCH_ZEIT_BLEIBT_UN'); ?> <span class="sm-mono"><?php echo ap_t('TEXT.REPLACE_BATTERY'); ?></span> <?php echo ap_t('TEXT.MELDET_WENN_DIE_USV_SELBST_EINEN_A'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo ap_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo ap_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo ap_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo ap_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo ap_t('TEXT.ZUSTAND_DES_DIENSTES'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="werte"><?php echo ap_t('TEXT.LETZTE_WERTE'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="mqttinfo"><?php echo ap_t('TEXT.MQTT_GATEWAY'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo ap_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="apcupsd"><?php echo ap_t('TEXT.APCUPSD_PRFEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo ap_t('TEXT.KONFIGURATION_ANZEIGEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo ap_t('TEXT.UMGEBUNG_UND_MODULE'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo ap_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abfragen"><?php echo ap_t('TEXT.JETZT_ABFRAGEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="melden"><?php echo ap_t('TEXT.TESTMELDUNG_ABLEGEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo ap_t('TEXT.DIENST_NEU_STARTEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo ap_t('TEXT.DIENST_ANHALTEN'); ?></button></form>
</div>

<?php if ($ap_test_titel !== '') { ?>
<h2><?= ap_e($ap_test_titel) ?></h2>
<div class="sm-log"><?= ap_e($ap_test_text) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info" style="margin-top:18px;"><?php echo ap_t('TEXT.NOCH_NICHTS_ABGEFRAGT_DIE_AUSGABE_'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-pane" id="tab-log">
<h2><?php echo ap_t('TEXT.PROTOKOLL'); ?></h2>
<div class="sm-small">
<?php if ($ap_log !== '') { ?>
<?php echo ap_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= ap_e($ap_log) ?></span> <?php echo ap_t('TEXT.LOG_NEUESTE'); ?>
<?php } else { ?>
<?php echo ap_t('TEXT.LOG_LEER'); ?>
<?php } ?>
</div>
<?php if ($ap_zeilen) { ?>
<div class="sm-log"><?php foreach ($ap_zeilen as $z) { echo ap_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    var start = <?= json_encode($ap_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('sm-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.sm-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('sm-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function () { zeige(t.getAttribute('data-pane')); });
        })(tabs[i]);
    }
    zeige(start);
})();
</script>
<?php
if ($ap_frame) {
    LBWeb::lbfooter();
}
