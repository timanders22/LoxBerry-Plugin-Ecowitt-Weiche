<?php
/**
 * Ecowitt-Weiche - gemeinsamer Unterbau
 *
 * WARUM ES DIESES PLUGIN GIBT
 * ---------------------------
 * Eine Ecowitt-Konsole (hier GW3000A) hat zwei Netzwerkschnittstellen, LAN und
 * WLAN, unter zwei Adressen. Loxone kann an einem virtuellen HTTP-Eingang aber
 * nur EINE Adresse eintragen. Faellt sie aus, stehen alle daran haengenden
 * Werte auf 0 - und in einer Loxone-Logik ist 0 ein gueltiger Messwert, kein
 * Fehler.
 *
 * Am 23.08.2026 war genau das der Fall: die LAN-Seite 192.168.178.20 lieferte
 * fuer Temperatur, Feuchte, Solar und UV die Zeichenfolge "---.-", Loxone machte
 * daraus 0, und die Beschattung des Hauses stand still. Ueber WLAN meldete
 * dieselbe Station im selben Moment 23,4 Grad und 673,64 W/m2.
 *
 * DER ENTSCHEIDENDE PUNKT
 * -----------------------
 * Die LAN-Seite war ERREICHBAR. Sie antwortete mit HTTP 200 und gueltigem JSON -
 * nur ohne Zahlen darin. Ein Ausweichen, das bloss auf Verbindungsfehler achtet,
 * waere an diesem Tag auf der kaputten Schnittstelle geblieben. Deshalb prueft
 * dieses Plugin den INHALT: eine Antwort gilt erst dann als brauchbar, wenn die
 * Aussenfeuchte eine Zahl groesser null ist. Reale Aussenluft hat nie 0 Prozent;
 * die Platzhalter "--" und "---.-" fallen damit durch.
 *
 * (c) Ecowitt-Weiche Plugin Authors - MIT-Lizenz
 */

/** Pfade des Plugins. LBP*-Umgebungsvariablen setzt LoxBerry. */
function ew_pfade()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $ordner = getenv('LBPPLUGINDIR');
    if ($ordner === false || $ordner === '') {
        /* Der Ordnername ist die LETZTE Stufe des Pfades: sowohl
           webfrontend/html/plugins/<ordner>/ als auch
           webfrontend/htmlauth/plugins/<ordner>/ enden darauf. Eine Stufe zu
           weit nach oben ergaebe "html" bzw. "plugins" - und das Plugin
           suchte seine Konfiguration in config/plugins/html/. */
        $ordner = basename(__DIR__);
    }
    if ($ordner === '' || $ordner === '.' || $ordner === '/'
        || $ordner === 'html' || $ordner === 'htmlauth' || $ordner === 'plugins') {
        $ordner = 'ecowittweiche';
    }
    $lb = getenv('LBHOMEDIR');
    if ($lb === false || $lb === '') {
        $lb = '/opt/loxberry';
    }
    $cfg = getenv('LBPCONFIGDIR');
    $log = getenv('LBPLOGDIR');
    $dat = getenv('LBPDATADIR');
    $p = array(
        'plugin' => $ordner,
        'lbhome' => $lb,
        'config' => ($cfg !== false && $cfg !== '') ? $cfg : $lb . '/config/plugins/' . $ordner,
        'log'    => ($log !== false && $log !== '') ? $log : $lb . '/log/plugins/' . $ordner,
        'data'   => ($dat !== false && $dat !== '') ? $dat : $lb . '/data/plugins/' . $ordner,
    );
    $p['cfgdatei'] = $p['config'] . '/ecowitt.json';
    $p['sicherung'] = $lb . '/config/plugins/' . $ordner . '.backup.json';
    return $p;
}

/** Vorgaben. Wer eine Adresse leer laesst, schaltet diese Schnittstelle ab. */
function ew_vorgaben()
{
    return array(
        'primaer'     => '',
        'ersatz'      => '',
        'pfad'        => '/get_livedata_info',
        'timeout'     => 4,
        'token'       => '',
        'pruefe_wert' => 1,
    );
}

function ew_config()
{
    $p = ew_pfade();
    $c = ew_vorgaben();
    $je_gesetzt = false;
    if (is_file($p['cfgdatei'])) {
        $d = json_decode((string) @file_get_contents($p['cfgdatei']), true);
        if (is_array($d)) {
            foreach ($c as $k => $v) {
                if (array_key_exists($k, $d)) {
                    $c[$k] = $d[$k];
                }
            }
            /* Nicht "Datei da oder nicht": postinstall.sh legt sie mit {} an,
               damit gaebe es den ersten Aufruf nie. Entscheidend ist, ob der
               SCHLUESSEL schon einmal geschrieben wurde. */
            $je_gesetzt = array_key_exists('token', $d);
        }
    }
    /* Solange der Schluessel noch nie geschrieben wurde, ein Wortzeichen
       erzeugen - der Endpunkt soll nicht ungeschuetzt im Netz stehen. Steht
       er einmal da, auch leer, bleibt er: wer ihn in der Oberflaeche bewusst
       leert, will es ohne. Ein stilles Nacherzeugen liesse jede
       Loxone-Adresse, die gerade ohne Token eingetragen ist, ab dem
       naechsten Abruf auf 403 laufen - der Behaelter waere offline, ohne
       dass jemand etwas geaendert haette. */
    if (!$je_gesetzt && $c['token'] === '') {
        $c['token'] = ew_token_erzeugen();
        ew_config_speichern($c);
    }
    $c['timeout'] = max(1, min(30, (int) $c['timeout']));
    return $c;
}

function ew_config_speichern(array $c)
{
    $p = ew_pfade();
    if (!is_dir($p['config'])) {
        @mkdir($p['config'], 0775, true);
    }
    /* Erst in eine Nebendatei schreiben, dann umbenennen. Ein Stromausfall
       mitten im Schreiben hinterlaesst sonst eine halbe Datei, und das Plugin
       startet danach ohne Konfiguration. */
    $tmp = $p['cfgdatei'] . '.tmp';
    $js = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $js) === false) {
        return false;
    }
    @rename($tmp, $p['cfgdatei']);
    @copy($p['cfgdatei'], $p['sicherung']);
    return true;
}

function ew_token_erzeugen()
{
    $b = @random_bytes(16);
    if ($b === false || $b === null) {
        return substr(str_replace('.', '', uniqid('', true)), 0, 32);
    }
    return bin2hex($b);
}

/** Adresse pruefen: nur IPv4 oder Hostname, nichts anderes. */
function ew_adresse_sauber($a)
{
    $a = trim((string) $a);
    if ($a === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,190}(:[0-9]{1,5})?$/', $a)) {
        return '';
    }
    return $a;
}

function ew_log($text)
{
    $p = ew_pfade();
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    $f = $p['log'] . '/ecowitt.log';
    /* Kurz halten - eine Minutenabfrage fuellt sonst die SD-Karte. */
    if (is_file($f) && filesize($f) > 262144) {
        $z = file($f, FILE_IGNORE_NEW_LINES) ?: array();
        @file_put_contents($f, implode("\n", array_slice($z, -800)) . "\n");
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $text . "\n", FILE_APPEND);
}

/**
 * Eine Antwort auf Brauchbarkeit pruefen.
 *
 * Rueckgabe: true, wenn im common_list eine Aussenfeuchte (id 0x07) mit einer
 * Zahl groesser null steht. Die Station schreibt bei verlorenem Funk zum
 * Aussensensor "--" bzw. "---.-" in dieselben Felder - JSON bleibt gueltig,
 * HTTP bleibt 200, und nur der Inhalt verraet den Ausfall.
 */
function ew_brauchbar($roh, &$grund = null)
{
    $grund = '';
    if (!is_string($roh) || $roh === '') {
        $grund = 'leere Antwort';
        return false;
    }
    $d = json_decode($roh, true);
    if (!is_array($d) || !isset($d['common_list']) || !is_array($d['common_list'])) {
        $grund = 'kein verwertbares JSON';
        return false;
    }
    foreach ($d['common_list'] as $e) {
        if (!is_array($e) || !isset($e['id']) || $e['id'] !== '0x07') {
            continue;
        }
        $v = isset($e['val']) ? (string) $e['val'] : '';
        if (!preg_match('/^\s*([0-9]+(\.[0-9]+)?)/', $v, $m)) {
            $grund = 'Aussenfeuchte ist Platzhalter (' . $v . ')';
            return false;
        }
        if ((float) $m[1] <= 0) {
            $grund = 'Aussenfeuchte 0 Prozent - das gibt es in echter Luft nicht';
            return false;
        }
        return true;
    }
    $grund = 'Aussenfeuchte fehlt in der Antwort';
    return false;
}

/**
 * Eine Adresse abfragen. Rueckgabe: der Rohtext oder false.
 *
 * WARUM HIER CURL STEHT
 * ---------------------
 * Die Angabe timeout im HTTP-Kontext von file_get_contents gilt nur fuer das
 * LESEN. Fuer den Verbindungsaufbau gilt default_socket_timeout, ab Werk 60
 * Sekunden. Gemessen am 23.08.2026: bei toter erster Schnittstelle brauchte
 * die Weiche 8134 ms statt der eingestellten 4 s.
 *
 * Das ist kein Schoenheitsfehler. Der Miniserver fragt diesen Endpunkt
 * zyklisch ab; dauert eine Antwort laenger als der Abstand zwischen zwei
 * Abfragen, stapeln sich die Anfragen im Webserver. Ein Ausweichen, das
 * langsamer ist als der Ausfall, den es ueberbruecken soll, hilft niemandem.
 *
 * curl kennt eine eigene Verbindungszeit. Sie steht bei der Haelfte der
 * Wartezeit, hoechstens aber bei zwei Sekunden: ein Geraet im eigenen Netz,
 * das nach zwei Sekunden die Verbindung nicht angenommen hat, nimmt sie auch
 * nach vier nicht an - und die restliche Zeit gehoert dem Lesen.
 */
function ew_abrufen($adresse, $pfad, $timeout)
{
    $adresse = ew_adresse_sauber($adresse);
    if ($adresse === '') {
        return false;
    }
    if ($pfad === '' || $pfad[0] !== '/') {
        $pfad = '/get_livedata_info';
    }
    $url = 'http://' . $adresse . $pfad;
    $verbinden = max(1, min(2, (int) ceil($timeout / 2)));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $verbinden);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LoxBerry Ecowitt-Weiche');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: close'));
        /* Keine Umleitungen: die Station antwortet selbst oder gar nicht. Wer
           Umleitungen folgt, laesst sich von einem falsch eingetragenen Geraet
           irgendwohin schicken. */
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $r = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $r === '' || ($code !== 0 && $code >= 400)) {
            return false;
        }
        return $r;
    }

    /* Ohne curl: wenigstens eine Grenze setzen. ini_set gilt nur fuer diesen
       Aufruf; der alte Wert wird danach zurueckgestellt, damit das Plugin
       nichts hinterlaesst, was andere Skripte trifft. */
    $merk = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string) $timeout);
    $ctx = stream_context_create(array('http' => array(
        'timeout'       => $timeout,
        'method'        => 'GET',
        'ignore_errors' => true,
        'header'        => "Connection: close\r\n",
        'user_agent'    => 'LoxBerry Ecowitt-Weiche',
    )));
    $r = @file_get_contents($url, false, $ctx);
    if ($merk !== false) {
        @ini_set('default_socket_timeout', (string) $merk);
    }
    return ($r === false) ? false : $r;
}

/**
 * Die Weiche: erst die primaere Schnittstelle, dann die Ersatzschnittstelle.
 *
 * Rueckgabe-Feld:
 *   ok      true, wenn eine Seite brauchbare Daten geliefert hat
 *   quelle  'primaer' | 'ersatz' | ''
 *   adresse die Adresse, die getragen hat
 *   roh     der unveraenderte Antworttext der Station
 *   grund   warum die jeweilige Seite verworfen wurde (fuer das Protokoll)
 */
function ew_weiche()
{
    $c = ew_config();
    $aus = array('ok' => false, 'quelle' => '', 'adresse' => '', 'roh' => '',
                 'grund' => array(), 'ts' => time());
    foreach (array('primaer', 'ersatz') as $seite) {
        $adr = ew_adresse_sauber($c[$seite]);
        if ($adr === '') {
            $aus['grund'][$seite] = 'keine Adresse hinterlegt';
            continue;
        }
        $roh = ew_abrufen($adr, $c['pfad'], $c['timeout']);
        if ($roh === false) {
            /* Dieselbe Rechnung wie in ew_abrufen - im Protokoll sollen die
               Zahlen stehen, die wirklich gegolten haben. */
            $verb = max(1, min(2, (int) ceil($c['timeout'] / 2)));
            $aus['grund'][$seite] = 'keine Antwort (Verbindung ' . $verb
                                  . ' s, Lesen ' . $c['timeout'] . ' s)';
            continue;
        }
        $grund = '';
        /* Die Inhaltspruefung laesst sich abschalten - dann zaehlt nur, dass
           ueberhaupt geantwortet wurde. Gedacht fuer Stationen ohne 0x07. */
        if (!empty($c['pruefe_wert']) && !ew_brauchbar($roh, $grund)) {
            $aus['grund'][$seite] = $grund;
            continue;
        }
        $aus['ok'] = true;
        $aus['quelle'] = $seite;
        $aus['adresse'] = $adr;
        $aus['roh'] = $roh;
        return $aus;
    }
    return $aus;
}

/** Merkt sich, welche Seite zuletzt getragen hat - fuer Oberflaeche und Protokoll. */
function ew_stand_schreiben(array $w)
{
    $p = ew_pfade();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    $f = $p['data'] . '/stand.json';
    $alt = array();
    if (is_file($f)) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d)) {
            $alt = $d;
        }
    }
    $neu = array(
        'ts'      => $w['ts'],
        'ok'      => $w['ok'] ? 1 : 0,
        'quelle'  => $w['quelle'],
        'adresse' => $w['adresse'],
        'grund'   => $w['grund'],
        'wechsel' => isset($alt['wechsel']) ? (int) $alt['wechsel'] : 0,
        'letzte_gute' => isset($alt['letzte_gute']) ? (int) $alt['letzte_gute'] : 0,
    );
    if ($w['ok']) {
        $neu['letzte_gute'] = $w['ts'];
    }
    /* Nur der WECHSEL wird protokolliert, nicht jeder Abruf. Eine
       Minutenabfrage erzeugt sonst 1440 Zeilen am Tag, in denen die eine
       wichtige untergeht. */
    $vorher = isset($alt['quelle']) ? (string) $alt['quelle'] : null;
    if ($vorher !== null && $vorher !== $w['quelle']) {
        $neu['wechsel'] = $neu['wechsel'] + 1;
        ew_log('Wechsel: ' . ($vorher === '' ? 'keine Quelle' : $vorher)
             . ' -> ' . ($w['quelle'] === '' ? 'keine Quelle' : $w['quelle'] . ' (' . $w['adresse'] . ')')
             . ($w['grund'] ? '  Grund: ' . implode(' | ', $w['grund']) : ''));
    }
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($neu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        @rename($tmp, $f);
    }
    return $neu;
}

function ew_stand_lesen()
{
    $p = ew_pfade();
    $f = $p['data'] . '/stand.json';
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
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
function ew_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ew_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = ew_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ew_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ew_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

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
