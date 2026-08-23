<?php
/**
 * Ecowitt-Weiche - Bedienoberflaeche
 *
 * Diese Datei ist NUR Oberflaeche. Der Datenabruf steht in
 * webfrontend/html/ew_lib.php, der Endpunkt fuer den Miniserver in
 * webfrontend/html/live.php - drei Aufgaben, drei Dateien.
 *
 * (c) Ecowitt-Weiche Plugin Authors - MIT-Lizenz
 */

/* Der Unterbau liegt im ANDEREN Baum: die Oberflaeche unter htmlauth, der
   Endpunkt und die Bibliothek unter html. Wie weit die beiden auseinander
   liegen, haengt davon ab, wie das Plugin gerade liegt:

     installiert  $LB/webfrontend/htmlauth/plugins/<ordner>/index.php
                  $LB/webfrontend/html/plugins/<ordner>/ew_lib.php
     im Archiv    <plugin>/webfrontend/htmlauth/index.php
                  <plugin>/webfrontend/html/ew_lib.php

   Das sind DREI Stufen bis webfrontend im einen Fall und ZWEI im anderen. Eine
   fest verdrahtete Stufenzahl trifft also immer nur eine der beiden Lagen -
   und im Pruefstand liegt die Archivlage, weshalb der Irrtum dort nicht
   auffaellt, sondern erst als HTTP 500 auf der Anlage.

   Vorrang hat die Umgebungsvariable, die LoxBerry selbst setzt. */
$ew_kandidaten = array();
$ew_html = getenv('LBPHTMLDIR');
if ($ew_html !== false && $ew_html !== '') {
    $ew_kandidaten[] = rtrim($ew_html, '/\\') . '/ew_lib.php';
}
$ew_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/'
                 . basename(__DIR__) . '/ew_lib.php';
$ew_kandidaten[] = dirname(__DIR__) . '/html/ew_lib.php';

$ew_geladen = '';
foreach ($ew_kandidaten as $ew_k) {
    if (is_file($ew_k)) {
        require_once $ew_k;
        $ew_geladen = $ew_k;
        break;
    }
}
if ($ew_geladen === '' || !function_exists('ew_pfade')) {
    /* Lieber eine lesbare Seite als ein leeres 500. Wer das hier liest, hat
       ein Plugin, dessen Oberflaeche steht und dessen Unterbau fehlt - und
       genau das soll dastehen, nicht "Diese Seite funktioniert nicht". */
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Ecowitt-Weiche: der Unterbau wurde nicht gefunden</h2>';
    echo '<p>Gesucht wurde an diesen Stellen:</p><ul>';
    foreach ($ew_kandidaten as $ew_k) {
        echo '<li><code>' . htmlspecialchars($ew_k, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul><p>Die Datei <code>ew_lib.php</code> gehoert nach '
       . '<code>webfrontend/html/plugins/&lt;ordner&gt;/</code>. Fehlt sie dort, '
       . 'ist die Installation unvollstaendig - das Plugin bitte neu installieren.</p>';
    exit;
}

/* Der LoxBerry-Rahmen. OHNE dieses require gibt es die Klasse LBWeb nicht,
   class_exists() ist dann immer falsch, und Kopf und Fuss der Seite entfallen
   stillschweigend - die Oberflaeche erscheint ohne Menue, als stuende sie
   allein im Netz. Eine Bedingung, die nie zutreffen KANN, sieht im Quelltext
   aus wie Vorsicht; sie ist eine tote Zeile.
   loxberry_system.php zuerst: loxberry_web.php baut darauf auf. */
$ew_lb = getenv('LBHOMEDIR');
if ($ew_lb === false || $ew_lb === '') {
    $ew_lb = is_dir('/opt/loxberry') ? '/opt/loxberry' : '';
}
if ($ew_lb !== '' && is_file($ew_lb . '/libs/phplib/loxberry_system.php')) {
    require_once $ew_lb . '/libs/phplib/loxberry_system.php';
    if (is_file($ew_lb . '/libs/phplib/loxberry_web.php')) {
        require_once $ew_lb . '/libs/phplib/loxberry_web.php';
    }
}

/* ---------- Sprache ------------------------------------------------------ */
function ew_sprachdatei()
{
    $t = getenv('LBPTEMPLATEDIR');
    if ($t === false || $t === '') {
        /* Zwei Lagen, zwei Pfade - wie beim Unterbau oben. Installiert liegen
           die Vorlagen in einem ganz anderen Zweig als im Archiv. */
        $lb = getenv('LBHOMEDIR');
        $kand = array();
        if ($lb !== false && $lb !== '') {
            $kand[] = rtrim($lb, '/\\') . '/templates/plugins/' . basename(__DIR__);
        }
        $kand[] = dirname(dirname(dirname(__DIR__))) . '/templates/plugins/' . basename(__DIR__);
        $kand[] = dirname(dirname(__DIR__)) . '/templates';
        $t = $kand[count($kand) - 1];
        foreach ($kand as $k) {
            if (is_dir($k . '/lang')) {
                $t = $k;
                break;
            }
        }
    }
    $lang = 'de';
    $g = (getenv('LBHOMEDIR') ?: '/opt/loxberry') . '/config/system/general.json';
    if (is_file($g)) {
        $d = json_decode((string) @file_get_contents($g), true);
        if (isset($d['Base']['Lang']) && $d['Base']['Lang'] === 'en') {
            $lang = 'en';
        }
    }
    $f = $t . '/lang/language_' . $lang . '.ini';
    return is_file($f) ? $f : $t . '/lang/language_de.ini';
}

function ew_t($schluessel)
{
    static $tab = null;
    if ($tab === null) {
        $tab = @parse_ini_file(ew_sprachdatei(), true);
        if (!is_array($tab)) {
            $tab = array();
        }
    }
    $teil = explode('.', $schluessel, 2);
    if (count($teil) === 2 && isset($tab[$teil[0]][$teil[1]])) {
        return $tab[$teil[0]][$teil[1]];
    }
    return $schluessel;
}

function ew_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Reiter: Positivliste, ids und Leiste gehoeren zusammen -------- */
$ew_reiter = array('tab-settings', 'tab-loxone', 'tab-test');
$ew_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $ew_reiter, true)) {
    $ew_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && in_array('tab-' . $_GET['form'], $ew_reiter, true)) {
    $ew_tab = 'tab-' . $_GET['form'];
}

/* ---------- Eingaben verarbeiten ----------------------------------------- */
$ew_cfg = ew_config();
$ew_meldung = '';
$ew_fehler = '';
$ew_probe = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern'])) {
    $p = ew_adresse_sauber(isset($_POST['primaer']) ? $_POST['primaer'] : '');
    $e = ew_adresse_sauber(isset($_POST['ersatz']) ? $_POST['ersatz'] : '');
    $roh_p = trim((string) (isset($_POST['primaer']) ? $_POST['primaer'] : ''));
    $roh_e = trim((string) (isset($_POST['ersatz']) ? $_POST['ersatz'] : ''));
    /* Abweisen, nicht zurechtbiegen: eine unsaubere Adresse wird gemeldet,
       nicht stillschweigend geleert - sonst steht das Plugin ohne Quelle da
       und niemand weiss warum. */
    if (($roh_p !== '' && $p === '') || ($roh_e !== '' && $e === '')) {
        $ew_fehler = ew_t('TEXT.ADRESSE_UNGUELTIG');
    } else {
        $ew_cfg['primaer'] = $p;
        $ew_cfg['ersatz'] = $e;
        $pf = trim((string) (isset($_POST['pfad']) ? $_POST['pfad'] : ''));
        $ew_cfg['pfad'] = ($pf !== '' && $pf[0] === '/') ? $pf : '/get_livedata_info';
        $ew_cfg['timeout'] = max(1, min(30, (int) (isset($_POST['timeout']) ? $_POST['timeout'] : 4)));
        $ew_cfg['pruefe_wert'] = empty($_POST['pruefe_wert']) ? 0 : 1;
        /* Ein LEERES Feld heisst hier NICHT "loeschen". Ein Feld, das nichts
           anzuzeigen hat, sieht genauso aus wie eines, das jemand absichtlich
           geleert hat - und ein Geheimnis darf sich nicht als Nebenwirkung
           des Speicherns aendern. Zum Entfernen gibt es einen eigenen Knopf,
           der im Namen sagt, was er tut. */
        $tk = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) (isset($_POST['token']) ? $_POST['token'] : ''));
        if ($tk !== '') {
            $ew_cfg['token'] = $tk;
        }
        ew_config_speichern($ew_cfg);
        $ew_meldung = ew_t('TEXT.GESPEICHERT');
        $ew_cfg = ew_config();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $ew_cfg['token'] = ew_token_erzeugen();
    ew_config_speichern($ew_cfg);
    $ew_cfg = ew_config();
    $ew_meldung = ew_t('TEXT.TOKEN_ERZEUGT');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_weg'])) {
    $ew_cfg['token'] = '';
    ew_config_speichern($ew_cfg);
    $ew_cfg = ew_config();
    $ew_meldung = ew_t('TEXT.TOKEN_ENTFERNT');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pruefen'])) {
    $ew_probe = array();
    foreach (array('primaer', 'ersatz') as $seite) {
        $adr = ew_adresse_sauber($ew_cfg[$seite]);
        if ($adr === '') {
            $ew_probe[$seite] = array('adr' => '', 'ok' => false, 'text' => ew_t('TEXT.KEINE_ADRESSE'));
            continue;
        }
        $t0 = microtime(true);
        $roh = ew_abrufen($adr, $ew_cfg['pfad'], $ew_cfg['timeout']);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($roh === false) {
            $ew_probe[$seite] = array('adr' => $adr, 'ok' => false, 'ms' => $ms,
                                      'text' => ew_t('TEXT.KEINE_ANTWORT'));
            continue;
        }
        $grund = '';
        $gut = ew_brauchbar($roh, $grund);
        $werte = array();
        $d = json_decode($roh, true);
        if (is_array($d) && isset($d['common_list'])) {
            foreach ($d['common_list'] as $x) {
                if (isset($x['id']) && in_array($x['id'], array('0x02', '0x07', '0x15', '0x17'), true)) {
                    $werte[$x['id']] = isset($x['val']) ? (string) $x['val'] : '';
                }
            }
        }
        $ew_probe[$seite] = array('adr' => $adr, 'ok' => $gut, 'ms' => $ms,
                                  'text' => $gut ? ew_t('TEXT.BRAUCHBAR') : $grund, 'werte' => $werte);
    }
}

$ew_stand = ew_stand_lesen();
$ew_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? $_SERVER['HTTP_HOST'] : 'loxberry';
$ew_plugin = ew_pfade()['plugin'];
$ew_tokenteil = $ew_cfg['token'] !== '' ? '?token=' . rawurlencode($ew_cfg['token']) : '';

$ew_rahmen = class_exists('LBWeb', false);
if ($ew_rahmen) {
    LBWeb::lbheader(ew_t('ALLGEMEIN.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard - wortgetreu aus VORLAGE_hausstandard.css.html */
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
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap input[type=file],
.sm-wrap select, .sm-wrap textarea {
  width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
  font-size: 0.95em; box-sizing: border-box; }
.sm-wrap table input[type=text], .sm-wrap table select { max-width: none; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
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
.sm-knopfreihe form { margin: 0; display: flex; }
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
.sm-kachel span { font-size: 0.82em; color: #666; }
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
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1 1 220px; }
.sm-grau { color: #999; font-style: italic; }
</style>

<div class="sm-wrap">

<?php if ($ew_meldung !== '') { ?><div class="sm-hinweis"><?= ew_e($ew_meldung) ?></div><?php } ?>
<?php if ($ew_fehler !== '') { ?><div class="sm-fehler"><?= ew_e($ew_fehler) ?></div><?php } ?>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da, nicht in einer Schleife
     erzeugt. Umgeschaltet wird ueber den Server, damit jeder Reiter
     verlinkbar und die Seite ohne Skript bedienbar bleibt; das Merkmal am
     Verweis nennt daneben den Bereich, zu dem er gehoert, und macht das
     Paar fuer die Hauspruefung lesbar. Ob Leiste, Bereiche und Positivliste
     wirklich dieselben Namen fuehren, zaehlt der Reiter Test nach. -->
<div class="sm-tabs">
  <a class="sm-tab<?= $ew_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
     href="index.php?form=settings"><?php echo ew_t('REITER.EINSTELLUNGEN'); ?></a>
  <a class="sm-tab<?= $ew_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
     href="index.php?form=loxone"><?php echo ew_t('REITER.LOXONE'); ?></a>
  <a class="sm-tab<?= $ew_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
     href="index.php?form=test"><?php echo ew_t('REITER.TEST'); ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $ew_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<h2><?php echo ew_t('TEXT.H_EINSTELLUNGEN'); ?></h2>

<div class="sm-step"><?php echo ew_t('TEXT.WARUM'); ?></div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_PRIMAER'); ?></label>
  <input data-role="none" type="text" name="primaer" value="<?= ew_e($ew_cfg['primaer']) ?>" placeholder="192.168.178.20">
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_PRIMAER'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_ERSATZ'); ?></label>
  <input data-role="none" type="text" name="ersatz" value="<?= ew_e($ew_cfg['ersatz']) ?>" placeholder="192.168.178.21">
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_ERSATZ'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_PFAD'); ?></label>
  <input data-role="none" type="text" name="pfad" value="<?= ew_e($ew_cfg['pfad']) ?>">
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_PFAD'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_TIMEOUT'); ?></label>
  <input data-role="none" type="number" min="1" max="30" name="timeout" value="<?= ew_e($ew_cfg['timeout']) ?>">
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_TIMEOUT'); ?></p>
</div>

<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="pruefe_wert" value="1"<?= !empty($ew_cfg['pruefe_wert']) ? ' checked' : '' ?>>
    <?php echo ew_t('TEXT.L_PRUEFE'); ?></label>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_PRUEFE'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_TOKEN'); ?></label>
  <input data-role="none" type="text" name="token" value="<?= ew_e($ew_cfg['token']) ?>">
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_TOKEN'); ?></p>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo ew_t('TEXT.SPEICHERN'); ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo ew_t('TEXT.TOKEN_NEU'); ?></button>
<?php if ($ew_cfg['token'] !== '') { ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_weg" value="1"><?php echo ew_t('TEXT.TOKEN_WEG'); ?></button>
<?php } ?>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo ew_t('LEGENDE.AKTION'); ?></span></div>
<p class="sm-hilfe"><?php echo ew_t('TEXT.TOKEN_KNOEPFE'); ?></p>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ew_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo ew_t('TEXT.H_LOXONE'); ?></h2>

<div class="sm-step"><?php echo ew_t('TEXT.LOX_ERKLAERUNG'); ?></div>

<h3><?php echo ew_t('TEXT.LOX_ADRESSE'); ?></h3>
<p><span class="sm-mono">http://<?= ew_e($ew_host) ?>/plugins/<?= ew_e($ew_plugin) ?>/live.php<?= ew_e($ew_tokenteil) ?></span></p>
<p class="sm-hilfe"><?php echo ew_t('TEXT.LOX_ADRESSE_HILFE'); ?></p>

<h3><?php echo ew_t('TEXT.LOX_STATUS'); ?></h3>
<p><span class="sm-mono">http://<?= ew_e($ew_host) ?>/plugins/<?= ew_e($ew_plugin) ?>/live.php?status=1<?= $ew_cfg['token'] !== '' ? '&amp;token=' . ew_e(rawurlencode($ew_cfg['token'])) : '' ?></span></p>
<table class="sm-tbl">
  <tr><th><?php echo ew_t('TEXT.FELD'); ?></th><th><?php echo ew_t('TEXT.BEDEUTUNG'); ?></th><th>Min</th><th>Max</th></tr>
  <tr><td class="sm-mono">OK</td><td><?php echo ew_t('FELD.OK'); ?></td><td>0</td><td>1</td></tr>
  <tr><td class="sm-mono">QUELLE</td><td><?php echo ew_t('FELD.QUELLE'); ?></td><td>0</td><td>2</td></tr>
  <tr><td class="sm-mono">WECHSEL</td><td><?php echo ew_t('FELD.WECHSEL'); ?></td><td>0</td><td>100000</td></tr>
  <tr><td class="sm-mono">ALTER</td><td><?php echo ew_t('FELD.ALTER'); ?></td><td>-1</td><td>1000000</td></tr>
</table>
<div class="sm-warnung"><?php echo ew_t('TEXT.LOX_MINVAL'); ?></div>
</div>

<!-- ================= Test und Protokoll ================= -->
<div class="sm-seite<?= $ew_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo ew_t('TEXT.H_TEST'); ?></h2>

<?php if (!empty($ew_stand)) { ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><b><?= $ew_stand['quelle'] === 'primaer' ? ew_t('TEXT.PRIMAER') : ($ew_stand['quelle'] === 'ersatz' ? ew_t('TEXT.ERSATZ') : '—') ?></b><span><?php echo ew_t('TEXT.TRAEGT_GERADE'); ?></span></div>
  <div class="sm-kachel"><b><?= (int) (isset($ew_stand['wechsel']) ? $ew_stand['wechsel'] : 0) ?></b><span><?php echo ew_t('TEXT.WECHSEL_GESAMT'); ?></span></div>
  <div class="sm-kachel"><b><?= empty($ew_stand['letzte_gute']) ? '—' : date('H:i:s', (int) $ew_stand['letzte_gute']) ?></b><span><?php echo ew_t('TEXT.LETZTE_GUTE'); ?></span></div>
</div>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="pruefen" value="1"><?php echo ew_t('TEXT.BEIDE_PRUEFEN'); ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?php echo ew_t('LEGENDE.LESEN'); ?></span></div>
</form>

<?php if ($ew_probe !== null) { ?>
<table class="sm-tbl">
  <tr><th><?php echo ew_t('TEXT.SCHNITTSTELLE'); ?></th><th><?php echo ew_t('TEXT.ADRESSE'); ?></th>
      <th><?php echo ew_t('TEXT.ERGEBNIS'); ?></th><th>0x02</th><th>0x07</th><th>0x15</th><th>0x17</th></tr>
<?php foreach ($ew_probe as $seite => $r) { ?>
  <tr>
    <td><?= $seite === 'primaer' ? ew_t('TEXT.PRIMAER') : ew_t('TEXT.ERSATZ') ?></td>
    <td class="sm-mono"><?= ew_e($r['adr']) ?></td>
    <td><?= $r['ok'] ? '<span class="sm-an">' . ew_e($r['text']) . '</span>'
                     : '<span class="sm-aus">' . ew_e($r['text']) . '</span>' ?>
        <?= isset($r['ms']) ? ' <span class="sm-grau">(' . (int) $r['ms'] . ' ms)</span>' : '' ?></td>
<?php   foreach (array('0x02', '0x07', '0x15', '0x17') as $id) { ?>
    <td class="sm-mono"><?= isset($r['werte'][$id]) ? ew_e($r['werte'][$id]) : '—' ?></td>
<?php   } ?>
  </tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?php echo ew_t('TEXT.PROBE_HILFE'); ?></p>
<?php } ?>

<h3><?php echo ew_t('TEXT.H_SELBST'); ?></h3>
<?php
/* Gezaehlt wird in DIESER Datei, nicht in einer zweiten Liste daneben -
   sonst gaebe es eine Stelle mehr, die man mitpflegen muesste, und genau
   daran laufen Reiterleiste und Positivliste sonst auseinander. */
$ew_selbst = array();

$ew_quelle = (string) @file_get_contents(__FILE__);
preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $ew_quelle, $ew_m1);
preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $ew_quelle, $ew_m2);
$ew_leiste = array_unique($ew_m1[1]);
$ew_flaechen = array_unique($ew_m2[1]);
sort($ew_leiste);
sort($ew_flaechen);
$ew_soll = $ew_reiter;
sort($ew_soll);
$ew_selbst[] = array(
    'was' => ew_t('TEXT.S_REITER'),
    'ok'  => ($ew_leiste === $ew_flaechen && $ew_leiste === $ew_soll),
    'wie' => sprintf('%d / %d / %d', count($ew_leiste), count($ew_flaechen), count($ew_soll)),
);

/* Der Endpunkt liegt in webfrontend/html, die Oberflaeche in htmlauth -
   zwei getrennte Baeume. Ein Plugin, dessen Oberflaeche laeuft und dessen
   Endpunkt fehlt, sieht in der Oberflaeche vollstaendig aus, und der
   Miniserver holt sich schweigend eine 404. */
$ew_endp = getenv('LBPHTMLDIR');
if ($ew_endp === false || $ew_endp === '') {
    /* Drei Stufen bis webfrontend: <ordner> -> plugins -> htmlauth. Zwei
       Stufen blieben bei htmlauth stehen und suchten darunter ein html/. */
    $ew_endp = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . ew_pfade()['plugin'];
}
$ew_da = is_file($ew_endp . '/live.php') && is_file($ew_endp . '/ew_lib.php');
$ew_selbst[] = array(
    'was' => ew_t('TEXT.S_ENDPUNKT'),
    'ok'  => $ew_da,
    'wie' => $ew_da ? $ew_endp : ew_t('TEXT.S_NICHT_GEFUNDEN'),
);

$ew_cfgdatei = ew_pfade()['cfgdatei'];
$ew_schreib = is_writable(is_file($ew_cfgdatei) ? $ew_cfgdatei : dirname($ew_cfgdatei));
$ew_selbst[] = array(
    'was' => ew_t('TEXT.S_SCHREIBBAR'),
    'ok'  => $ew_schreib,
    'wie' => $ew_cfgdatei,
);

$ew_selbst[] = array(
    'was' => ew_t('TEXT.S_ADRESSEN'),
    'ok'  => (ew_adresse_sauber($ew_cfg['primaer']) !== ''
              || ew_adresse_sauber($ew_cfg['ersatz']) !== ''),
    'wie' => trim($ew_cfg['primaer'] . '  /  ' . $ew_cfg['ersatz'], ' /'),
);
?>
<table class="sm-tbl">
  <tr><th><?php echo ew_t('TEXT.PRUEFPUNKT'); ?></th><th><?php echo ew_t('TEXT.ERGEBNIS'); ?></th><th><?php echo ew_t('TEXT.GEMESSEN'); ?></th></tr>
<?php foreach ($ew_selbst as $ew_z) { ?>
  <tr>
    <td><?= ew_e($ew_z['was']) ?></td>
    <td><?= $ew_z['ok'] ? '<span class="sm-an">' . ew_e(ew_t('TEXT.S_OK')) . '</span>'
                        : '<span class="sm-aus">' . ew_e(ew_t('TEXT.S_NOK')) . '</span>' ?></td>
    <td class="sm-mono"><?= ew_e($ew_z['wie']) ?></td>
  </tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?php echo ew_t('TEXT.SELBST_HILFE'); ?></p>

<h3><?php echo ew_t('TEXT.PROTOKOLL'); ?></h3>
<?php
$ew_logdatei = ew_pfade()['log'] . '/ecowitt.log';
$ew_zeilen = is_file($ew_logdatei) ? array_slice(array_reverse(file($ew_logdatei, FILE_IGNORE_NEW_LINES) ?: array()), 0, 60) : array();
?>
<?php if ($ew_zeilen) { ?>
<div class="sm-log"><?= ew_e(implode("\n", $ew_zeilen)) ?></div>
<p class="sm-hilfe"><?php echo ew_t('TEXT.PROTOKOLL_HILFE'); ?></p>
<?php } else { ?>
<p class="sm-grau"><?php echo ew_t('TEXT.PROTOKOLL_LEER'); ?></p>
<?php } ?>
</div>

</div>
<?php
if ($ew_rahmen) {
    LBWeb::lbfooter();
}
