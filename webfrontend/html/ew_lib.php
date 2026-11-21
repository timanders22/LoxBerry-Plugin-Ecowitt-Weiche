<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
/* Gemeinsames Sicherungsmodul (Abschrift von Werkzeuge/gemeinsam/sicherung.php, seit 0.9.34): "Einstellungen
 * sichern" und "Einstellungen zurueckspielen" verhalten sich in allen Plugins des Hauses gleich. Liegt neben
 * dieser Datei und schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sicherung.php';
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

/**
 * Die LoxBerry-Wurzel. Gelesen wird $LBHOMEDIR; fehlt die Umgebung, wird vom
 * Ablageort dieser Datei aufwaerts der Ordner gesucht, der config/plugins,
 * data/plugins UND config/system/general.json traegt (Regeln/06,
 * lb_wurzel_suchen()).
 *
 * Bis 0.9.12 stand hier ein fester Rueckfall auf den Geraetepfad. Ohne
 * LBHOMEDIR las das Plugin dann in jedem anderen Baum keine Konfiguration und
 * arbeitete still auf den Vorgaben - in WSL gemessen 18.09.2026
 * (Bestand-2026-09-18/klasse-H, Fall M2; Pruefung-Ecowitt-Weiche-0.9.13,
 * Faelle W1 bis W5).
 *
 * Findet sich keine Wurzel, bleibt sie leer, und die Bibliothek schreibt
 * nirgends hin.
 */
function ew_wurzel()
{
    $lb = getenv('LBHOMEDIR');
    if ($lb !== false && $lb !== '') {
        return $lb;
    }
    $d = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
            && is_file($d . '/config/system/general.json')) {
            return $d;
        }
        $eltern = dirname($d);
        if ($eltern === $d) {
            break;
        }
        $d = $eltern;
    }
    return '';
}

/** Pfade des Plugins. LBP*-Umgebungsvariablen setzt LoxBerry. */
function ew_paths()
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
    $lb = ew_wurzel();
    $cfg = getenv('LBPCONFIGDIR');
    $log = getenv('LBPLOGDIR');
    $dat = getenv('LBPDATADIR');
    $p = array(
        'plugin' => $ordner,
        'lbhome' => $lb,
        'config' => ($cfg !== false && $cfg !== '') ? $cfg
                    : ($lb !== '' ? $lb . '/config/plugins/' . $ordner : ''),
        'log'    => ($log !== false && $log !== '') ? $log
                    : ($lb !== '' ? $lb . '/log/plugins/' . $ordner : ''),
        'datadir'   => ($dat !== false && $dat !== '') ? $dat
                    : ($lb !== '' ? $lb . '/data/plugins/' . $ordner : ''),
    );
    $p['cfgdatei'] = $p['config'] !== '' ? $p['config'] . '/ecowitt.json' : '';
    if ($lb !== '') {
        $p['sicherung'] = $lb . '/config/plugins/' . $ordner . '.backup.json';
    } elseif ($p['config'] !== '') {
        $p['sicherung'] = dirname($p['config']) . '/' . $ordner . '.backup.json';
    } else {
        $p['sicherung'] = '';
    }
    return $p;
}

/** Vorgaben. Wer eine Adresse leer laesst, schaltet diese Schnittstelle ab. */
function ew_vorgaben()
{
    return ew_ansage_vorgaben() + array(
        'primaer'     => '',
        'ersatz'      => '',
        'pfad'        => '/get_livedata_info',
        'timeout'     => 3,          // k2: bis 0.9.17 4 (9000 ms > 8000 ms)
        'token'       => '',
        'pruefe_wert' => 1,
        /* Gruppe 3 (0.9.25): MQTT ab Werk an - aber nur fuer NEUE Installationen; eine bestehende
           Konfiguration ohne den Schluessel gilt als aus (ew_config(), E3). */
        'mqtt_ein'     => 1,
        'mqtt_praefix' => 'ecowittweiche',
        /* 0.9.38 (Entscheidung des Hausherrn 21.11.2026): Haus-Themen haus/wetter und haus/klima fuer andere Plugins,
           ab Werk aus; Bereich und Name je Fuehler der Station (ew_haus_fuehler()). */
        'haus_wetter_ein'     => 0,
        'haus_klima_ein'      => 0,
        'haus_aussen_bereich' => 'wetter',
        'haus_aussen_name'    => 'ecowitt',
        'haus_basis_bereich'  => 'klima',
        'haus_basis_name'     => 'ecowitt_basis',
    );
}

/* ------------------------------------------------------------------
 * Grenzen - jede steht genau hier (Regeln/04 "Eine Grenze steht genau
 * einmal, als Konstante in der Bibliothek").
 * ------------------------------------------------------------------ */

/* Hoechstens so viele Byte werden von einer Station gelesen. Eine Antwort
   von get_livedata_info ist wenige Kilobyte gross. Bis 0.9.14 wurde alles
   gelesen: 300 MB ergaben HTTP 500 mit leerem Rumpf, und die Ersatzseite
   wurde nie gefragt (Pruefer code, Befund 9, 30.09.2026). Groesser gilt
   als Fehlschlag, und die Weiche nimmt die andere Seite. */
define('EW_MAX_ANTWORT', 1048576);

/* Obergrenze in Sekunden fuer BEIDE Schnittstellen zusammen. Die Wartezeit
   je Adresse (1-3 s, gespeichert bis 30 s - k2) gilt weiter, ein Aufruf des Endpunkts dauert aber nie
   laenger als ew_gesamtfrist(). Bis 0.9.14 konnten es 2 x 30 s werden, und
   schon ab 9 s je Seite mehr als der Abrufabstand des Miniservers (16 s)
   (Pruefer code, Befund 11). */
define('EW_GESAMTFRIST', 10);

/* k2 (Bau 08.10.2026): Loxone nimmt beim Timeout eines Behaelters nur
   10-8000 ms an (Hausherr, 02.10.2026, in Loxone Config). Der Behaelter
   braucht die Gesamtfrist plus eine Sekunde, also 2 x Wartezeit + 1 s; mit
   4 s (Vorgabe bis 0.9.17) waeren es 9000 ms. Das Formular nimmt deshalb
   hoechstens EW_WARTEZEIT_MAX an (3 s -> 7000 ms). Gespeicherte und
   gesicherte Werte bis EW_WARTEZEIT_LESBAR (die Grenze bis 0.9.17) bleiben
   lesbar und wirken weiter - geaendert wird nichts still (Entscheidung
   Nr. 19); Oberflaeche und Zurueckspielen sagen es (X-3). */
define('EW_LOX_TIMEOUT_MAX', 8000);
define('EW_WARTEZEIT_MAX', 3);
define('EW_WARTEZEIT_LESBAR', 30);

/* Solange die primaere Seite vor hoechstens so vielen Sekunden verworfen
   wurde und seither die Ersatzseite traegt, wird die Ersatzseite ZUERST
   gefragt. Eine haengende Primaerseite kostete sonst bei jedem Abruf die
   volle Wartezeit, und Loxone gab vorher auf (Pruefer Weg zu Loxone,
   Befund 1). Nach Ablauf wird die primaere Seite einmal nachgeprueft; traegt
   sie wieder, ist sie wieder die erste. */
define('EW_ERSATZ_ZUERST', 600);

/* a1 (Verbesserungsbau 01.10.2026): ALTER aus dem Zeitstempel der Station.
   Eine Station, deren Bild einfriert, antwortet weiter mit gueltigem JSON
   und brauchbarer Aussenfeuchte - die Weiche haelt die Daten fuer frisch.
   Traegt die Antwort einen eigenen Zeitstempel, zaehlt er fuer ALTER.
   Liegt er mehr als EW_ZUKUNFT_TOLERANZ Sekunden in der Zukunft
   (Uhrsprung), gilt er als keine Aussage (wie Pumpenwacht 1.0.5). Vor
   EW_STATIONSZEIT_AB (2020-01-01) ist die Uhr der Station nicht gestellt.
   NICHT GEMESSEN: ob die GW3000A in get_livedata_info einen Zeitstempel
   fuehrt - es liegt keine echte Antwort vor; die Pruefstaende bilden sie
   nach und kennen nur "timestamp" im Block lightning (Zeit des letzten
   Blitzes), der hier ausdruecklich NICHT zaehlt. */
define('EW_ZUKUNFT_TOLERANZ', 5);
define('EW_STATIONSZEIT_AB', 1577836800);

/** Gesamtfrist eines Endpunktaufrufs in Sekunden: zweimal die Wartezeit je
 *  Adresse, hoechstens EW_GESAMTFRIST. */
function ew_gesamtfrist(array $c)
{
    $t = isset($c['timeout']) ? (int) $c['timeout'] : (int) ew_vorgaben()['timeout'];
    return max(1, min(EW_GESAMTFRIST, 2 * max(1, $t)));
}

/** Timeout, den der Behaelter in Loxone braucht (Millisekunden): die
 *  Gesamtfrist und eine Sekunde fuer den Weg - ohne Ruecksicht auf Loxone. */
function ew_behaelter_timeout_noetig_ms(array $c)
{
    return (ew_gesamtfrist($c) + 1) * 1000;
}

/** Timeout, der im Behaelter einzutragen ist (Millisekunden): der noetige,
 *  hoechstens EW_LOX_TIMEOUT_MAX - mehr nimmt Loxone nicht an (k2). Reicht
 *  das nicht, sagt es ew_wartezeit_zu_lang(). */
function ew_behaelter_timeout_ms(array $c)
{
    return min(EW_LOX_TIMEOUT_MAX, ew_behaelter_timeout_noetig_ms($c));
}

/** k2: braucht die gespeicherte Wartezeit mehr Timeout, als Loxone annimmt? */
function ew_wartezeit_zu_lang(array $c)
{
    return ew_behaelter_timeout_noetig_ms($c) > EW_LOX_TIMEOUT_MAX;
}

/* ------------------------------------------------------------------
 * b1 (Verbesserungsbau 01.10.2026): Timeout der Loxone-Behaelter aus der
 * Projektdatei - nur, wenn sie dem Plugin vorliegt.
 *
 * Anlass: beide Behaelter dieser Anlage ("Wetterstation Ecowitt" VHI2 und
 * "Ecowitt-Weiche Zustand" VHI28) trugen 4000 ms; noetig waren bei der
 * damaligen Vorgabe von 4 s 9000 ms (Befunde 30.09.2026, "Zu tun in Loxone
 * Config"). Seit k2 (0.9.18) sind es hoechstens 3 s und 7000 ms.
 *
 * WARUM KEIN UPLOAD: am Geraet gilt upload_max_filesize = 2M, eine
 * .Loxone-Datei dieser Anlage hat 3-4 MB, und das Plugin kann die Grenze
 * nicht anheben (Regeln/04). Die Datei wird deshalb in den
 * Konfigurationsordner des Plugins GELEGT (Windows-Freigabe, WinSCP, scp).
 * Gesucht wird nur dort - ein fester Ort, kein Pfad aus dem Formular.
 * ------------------------------------------------------------------ */

/* Groessere Dateien werden nicht gelesen (Speicher des Webservers). */
define('EW_PROJEKT_MAX', 33554432);

/** Der Ordner, in dem eine abgelegte Projektdatei gesucht wird. */
function ew_projekt_ordner()
{
    return ew_paths()['config'];
}

/** Die juengste *.Loxone-Datei im Projektordner als array(pfad, name, zeit,
 *  groesse), sonst null. Punktdateien und Verknuepfungen zaehlen nicht. */
function ew_projekt_datei()
{
    $o = ew_projekt_ordner();
    if ($o === '' || !is_dir($o) || !is_readable($o)) {
        return null;
    }
    $liste = @scandir($o);
    if (!is_array($liste)) {
        return null;
    }
    $beste = null;
    foreach ($liste as $n) {
        if ($n === '' || $n[0] === '.' || !preg_match('/\.loxone\z/i', $n)) {
            continue;
        }
        $f = $o . '/' . $n;
        clearstatcache(true, $f);
        if (is_link($f) || !is_file($f) || !is_readable($f)) {
            continue;
        }
        $z = (int) @filemtime($f);
        if ($beste === null || $z > $beste['zeit']
            || ($z === $beste['zeit'] && strcmp($n, $beste['name']) < 0)) {
            $beste = array('pfad' => $f, 'name' => $n, 'zeit' => $z, 'groesse' => (int) @filesize($f));
        }
    }
    return $beste;
}

/**
 * Die HTTP-Behaelter (Type="VirtualHttpIn") einer Projektdatei, deren
 * Adresse auf /plugins/<ordner>/live.php zeigt.
 * Rueckgabe: Liste von array(titel, timeout = Millisekunden oder null,
 * status = true fuer den Zustandsbehaelter ?status=1).
 *
 * Die ADRESSE verlaesst diese Funktion nie: sie traegt das Wortzeichen.
 * Ein regulaerer Ausdruck statt eines XML-Lesers: .Loxone-Dateien tragen
 * doppelte Attribute, an denen ein strenger Leser abbricht (gemessen an der
 * Projektdatei dieser Anlage). Ein Attributwert enthaelt nie ein rohes "<".
 */
function ew_projekt_behaelter($roh, $ordner)
{
    $aus = array();
    if (!is_string($roh) || !is_string($ordner) || $ordner === '') {
        return $aus;
    }
    $ziel = '/plugins/' . $ordner . '/live.php';
    $n = preg_match_all('/<C\s(?=[^<]*?\bType="VirtualHttpIn")((?:\s*[A-Za-z_][A-Za-z0-9_.\-]*="[^"]*")+)\s*\/?>/',
        $roh, $mm);
    if (!$n) {
        return $aus;
    }
    foreach ($mm[1] as $teil) {
        preg_match_all('/([A-Za-z_][A-Za-z0-9_.\-]*)="([^"]*)"/', $teil, $aa, PREG_SET_ORDER);
        $a = array();
        foreach ($aa as $x) {
            /* Doppelte Attribute: das erste gilt. */
            if (!array_key_exists($x[1], $a)) {
                $a[$x[1]] = html_entity_decode($x[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        if (!isset($a['Type']) || $a['Type'] !== 'VirtualHttpIn') {
            continue;
        }
        $adr = isset($a['Address']) ? $a['Address'] : '';
        if (strpos($adr, $ziel) === false) {
            continue;
        }
        $to = (isset($a['Timeout']) && preg_match('/^[0-9]{1,7}\z/', $a['Timeout'])) ? (int) $a['Timeout'] : null;
        $aus[] = array(
            'titel'   => isset($a['Title']) ? ew_kurz($a['Title'], 80) : '',
            'timeout' => $to,
            'status'  => preg_match('/[?&]status=1(&|\z)/', $adr) === 1,
        );
    }
    return $aus;
}

/**
 * Die abgelegte Projektdatei pruefen. Rueckgabe array(lage, art, datei,
 * zeit, mindest, behaelter[]) - lage ok|fehl|hinweis, art keine|gross|
 * unlesbar|ohne|geprueft; je Behaelter zusaetzlich urteil ok|fehl|hinweis.
 */
function ew_projekt_pruefen(array $c)
{
    $e = array('lage' => 'hinweis', 'art' => 'keine', 'datei' => '', 'zeit' => 0,
               'mindest' => ew_behaelter_timeout_ms($c), 'behaelter' => array());
    $d = ew_projekt_datei();
    if ($d === null) {
        return $e;
    }
    $e['datei'] = $d['name'];
    $e['zeit'] = $d['zeit'];
    if ($d['groesse'] > EW_PROJEKT_MAX) {
        $e['art'] = 'gross';
        return $e;
    }
    $roh = @file_get_contents($d['pfad'], false, null, 0, EW_PROJEKT_MAX);
    if (!is_string($roh) || $roh === '') {
        $e['art'] = 'unlesbar';
        return $e;
    }
    $b = ew_projekt_behaelter($roh, ew_paths()['plugin']);
    unset($roh);
    if (!$b) {
        $e['art'] = 'ohne';
        return $e;
    }
    $lage = 'ok';
    foreach ($b as $i => $x) {
        if ($x['timeout'] === null) {
            $b[$i]['urteil'] = 'hinweis';
            if ($lage === 'ok') {
                $lage = 'hinweis';
            }
        } elseif ($x['timeout'] < $e['mindest']) {
            $b[$i]['urteil'] = 'fehl';
            $lage = 'fehl';
        } else {
            $b[$i]['urteil'] = 'ok';
        }
    }
    $e['lage'] = $lage;
    $e['art'] = 'geprueft';
    $e['behaelter'] = $b;
    return $e;
}

/* ------------------------------------------------------------------
 * Wertpruefung - EINE fuer Formular und Sicherung (Regeln/05 "Adressen
 * werden an beiden Enden mit derselben Beurteilung geprueft", "Jeder Wert
 * der Sicherungsdatei wird geprueft"). Geprueft wird der Wert, der
 * gespeichert wird: hier wird nichts getrimmt. Das Formular trimmt vorher
 * und speichert das Getrimmte; eine Sicherung mit Rand wird abgewiesen
 * (Regeln/05, Ergaenzung 24.09.2026). Die Muster enden mit \z.
 * ------------------------------------------------------------------ */

/** Adresse: IPv4 oder Hostname, dahinter optional ein Port 1-65535.
 *  Rueckgabe die Adresse oder '' (leer oder unzulaessig). */
function ew_adresse_pruefen($a)
{
    if (!is_string($a) || $a === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,190}(?::([0-9]{1,5}))?\z/', $a, $m)) {
        return '';
    }
    if (isset($m[1]) && ((int) $m[1] < 1 || (int) $m[1] > 65535)) {
        return '';
    }
    return $a;
}

/** Pfad der Abfrage: beginnt mit /, ohne Leerraum und Steuerzeichen. */
function ew_pfad_taugt($p)
{
    return is_string($p)
        && preg_match('#^/[A-Za-z0-9._~%!$&*+,;=:@/?\-]{0,200}\z#', $p) === 1;
}

/** Wartezeit je Adresse: ganze Zahl 1 bis $hoechst. Rueckgabe die Zahl oder
 *  null. Das Formular nimmt hoechstens EW_WARTEZEIT_MAX an (k2); gelesen und
 *  zurueckgespielt wird bis EW_WARTEZEIT_LESBAR (ew_wert_pruefen()). */
function ew_timeout_wert($t, $hoechst = EW_WARTEZEIT_MAX)
{
    if (is_int($t)) {
        $n = $t;
    } elseif (is_string($t) && preg_match('/^[0-9]{1,2}\z/', $t)) {
        $n = (int) $t;
    } else {
        return null;
    }
    return ($n >= 1 && $n <= (int) $hoechst) ? $n : null;
}

/** Schalter: 0 oder 1 (als Zahl oder Ziffer). Rueckgabe 0/1 oder null. */
function ew_schalter_wert($v)
{
    if ($v === 0 || $v === 1) {
        return $v;
    }
    if ($v === '0' || $v === '1') {
        return (int) $v;
    }
    return null;
}

/** Wortzeichen: was ohne Kodierung in eine Adresse passt, hoechstens 64
 *  Zeichen. Leer heisst "ohne Wortzeichen" (Regeln/05 Z. 186). */
function ew_token_taugt($t)
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_.\-]{0,64}\z/', $t) === 1;
}

/** Einen fremden Wert fuer eine Meldung beschreiben. Ein Wortzeichen wird
 *  nie gezeigt, nur seine Laenge. Maskiert wird erst bei der Ausgabe. */
function ew_wert_zeigen($w, $geheim = false)
{
    if (is_array($w)) {
        return ew_t('WERT.LISTE');
    }
    if (is_bool($w)) {
        return $w ? 'true' : 'false';
    }
    if ($w === null) {
        return 'null';
    }
    $s = (string) $w;
    if ($geheim) {
        return sprintf(ew_t('WERT.ZEICHEN'), strlen($s));
    }
    return '"' . ew_kurz($s, 60) . '"';
}

/** Eine Zeichenkette fuer eine Meldung kuerzen: eine Zeile, hoechstens $n
 *  Zeichen, Steuerzeichen als Leerzeichen. */
function ew_kurz($s, $n = 80)
{
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s));
    return strlen($s) > $n ? substr($s, 0, $n) . '...' : $s;
}

/**
 * Einen Wert fuer eine Einstellung pruefen - fuer Formular und Sicherung.
 * Rueckgabe array(zulaessig, normierter Wert, Beanstandung).
 */
function ew_wert_pruefen($k, $w)
{
    $ok = false;
    $wert = null;
    switch ($k) {
    case 'primaer':
    case 'ersatz':
        $ok = is_string($w) && ($w === '' || ew_adresse_pruefen($w) === $w);
        $wert = $w;
        break;
    case 'pfad':
        $ok = ew_pfad_taugt($w);
        $wert = $w;
        break;
    case 'timeout':
        /* k2: eine Sicherung aus 0.9.17 und frueher (Vorgabe 4 s) muss
         * zurueckspielbar bleiben - die Grenze der damaligen Fassungen gilt
         * hier weiter; ew_sicherung_nachziehen() gibt den Hinweis. */
        $wert = ew_timeout_wert($w, EW_WARTEZEIT_LESBAR);
        $ok = $wert !== null;
        break;
    case 'pruefe_wert':
        $wert = ew_schalter_wert($w);
        $ok = $wert !== null;
        break;
    case 'token':
        $ok = ew_token_taugt($w);
        $wert = $w;
        break;
    case 'mqtt_ein':
        $wert = ew_schalter_wert($w);
        $ok = $wert !== null;
        break;
    case 'mqtt_praefix':
        $ok = ew_mqtt_praefix_taugt($w);
        $wert = $w;
        break;
    case 'haus_wetter_ein':
    case 'haus_klima_ein':
        $wert = ew_schalter_wert($w);
        $ok = $wert !== null;
        break;
    case 'haus_aussen_bereich':
    case 'haus_basis_bereich':
        $ok = is_string($w) && in_array($w, array('aus', 'wetter', 'klima'), true);
        $wert = $w;
        break;
    case 'haus_aussen_name':
    case 'haus_basis_name':
        $ok = ew_haus_name_taugt($w);
        $wert = $w;
        break;
    }
    if ($ok) {
        return array(true, $wert, '');
    }
    return array(false, null, sprintf(ew_t('TEXT.WERT_UNZULAESSIG'), $k,
        ew_wert_zeigen($w, $k === 'token'), ew_t('ERLAUBT.' . strtoupper($k))));
}

/** Eine JSON-Datei als Feld lesen; null, wenn sie fehlt oder kein Objekt ist. */
function ew_json_lesen($f)
{
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/**
 * Traegt ein Stand Inhalt? Ja, wenn er ein Objekt ist, in dem das
 * Wortzeichen schon einmal geschrieben wurde - auch leer, denn wer es in der
 * Oberflaeche bewusst entfernt, will es ohne (siehe ew_config()).
 */
function ew_hat_inhalt($d)
{
    return is_array($d) && array_key_exists('token', $d) && is_string($d['token']);
}

/**
 * Unteilbar schreiben: Nebendatei mit 0600, vollstaendig schreiben, dann
 * umbenennen. Rueckgabe true nur, wenn die Datei danach dasteht.
 *
 * Bis 0.9.12 ging die Zweitschrift per PHP-Kopierfunktion direkt ueber die
 * alte; die kappt das Ziel, bevor sie es fuellt (Bestand-2026-09-18/klasse-D, Einzelprobe
 * unter ulimit -f 0: "sicherung.json nachher: 0 Byte"), und eine neu
 * angelegte Zweitschrift bekam 0644 - mit dem Wortzeichen darin
 * (Pruefung-Ecowitt-Weiche-0.9.13, Fall Z8: "Rechte Zweitschrift: 644").
 * Vorbild: Raumklima 0.11.10 rk_json_schreiben().
 */
function ew_json_schreiben($pfad, array $daten)
{
    if ($pfad === '') {
        return false;
    }
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    /* Die Rechte gehoeren an das Anlegen, nicht an ein chmod danach. */
    $umask_alt = umask(0077);
    $fh = @fopen($tmp, 'c');
    umask($umask_alt);
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    /* Bei voller Karte schreibt fwrite() nur einen Teil und meldet dessen
       Laenge; deshalb wird die Laenge verglichen, nicht auf false geprueft. */
    $ok = ftruncate($fh, 0) && @fwrite($fh, $js) === strlen($js);
    if (!@fflush($fh)) {
        $ok = false;
    }
    if (!@fclose($fh)) {
        $ok = false;
    }
    if (!$ok || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Selbstheilung nach Inhalt, aufgerufen, wenn die Konfiguration keinen
 * Inhalt traegt (fehlt, "{}" oder unlesbar).
 *
 * Bis 0.9.12 erzeugte ew_config() in dieser Lage ein neues Wortzeichen und
 * speicherte es - ueber die Konfiguration UND ueber die Zweitschrift. Damit
 * waren Adressen und Wortzeichen an beiden Stellen fort. Der Miniserver ruft
 * live.php alle 16 s ab; ein Abruf zwischen purge_installation und
 * postinstall.sh genuegte (Pruefung-Ecowitt-Weiche-0.9.13, Faelle Z1-Z6, H9,
 * H10, vorher rot).
 *
 * Jetzt: traegt die Zweitschrift Inhalt, wird aus ihr geheilt; ein
 * verdraengter Stand, der mehr ist als "{}", bleibt als ecowitt.json.kaputt
 * (0600) liegen. Die Zweitschrift selbst wird dabei nicht angefasst.
 * Rueckgabe: der Stand, mit dem ew_config() weiterarbeitet (null = neu).
 */
function ew_config_heilen(array $p, $d)
{
    if ($p['cfgdatei'] === '') {
        return $d;
    }
    $da = is_file($p['cfgdatei']);
    $kaputt = $da && $d === null;
    $z = ew_json_lesen($p['sicherung']);
    if (!ew_hat_inhalt($z) && !$kaputt) {
        return $d;
    }
    if ($kaputt || (is_array($d) && count($d) > 0)) {
        $k = $p['cfgdatei'] . '.kaputt';
        if (@rename($p['cfgdatei'], $k)) {
            @chmod($k, 0600);
        }
    }
    if (!ew_hat_inhalt($z)) {
        ew_log('Konfiguration unlesbar und keine Zweitschrift mit Inhalt - der Stand liegt als '
             . basename($p['cfgdatei']) . '.kaputt, begonnen wird mit den Vorgaben.');
        return null;
    }
    if (!ew_json_schreiben($p['cfgdatei'], $z)) {
        ew_log('Konfiguration ohne Inhalt; die Zweitschrift liess sich nicht zurueckschreiben.');
        return $z;
    }
    ew_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
    return $z;
}

/**
 * Den Zustand der Konfiguration, wie ihn der ERSTE Aufruf von ew_config()
 * in diesem Prozess vorfand - vor der Heilung. Ein spaeteres "ok"
 * ueberschreibt ihn nicht (Regeln/05 "Eine Zeile, die den Zustand der
 * Konfiguration meldet, merkt ihn sich, bevor die Selbstheilung ihn
 * beseitigt"). Werte: ok, fehlt, leer, ohne_token, zweitschrift,
 * kaputt_zweitschrift, kaputt; null = noch nicht gelesen.
 */
function ew_config_zustand($neu = null)
{
    static $z = null;
    if ($neu !== null && $z === null) {
        $z = $neu;
    }
    return $z;
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = true (Oberflaeche): heilt aus der Zweitschrift und legt beim
 * ersten Mal ein Wortzeichen an.
 *
 * $erzeugen = false (Endpunkt live.php): liest NUR. Traegt die Datei keinen
 * Inhalt, kommt null zurueck; geschrieben, geheilt oder angelegt wird
 * nichts. Bis 0.9.14 legte ein Aufruf ohne Token Konfiguration und
 * Zweitschrift mit frischem Wortzeichen an bzw. spielte eine
 * liegengebliebene Zweitschrift ein (Pruefer code, Befund 13, S1/S2;
 * Regeln/05 "Der unangemeldete Endpunkt darf nichts anlegen").
 */
function ew_config($erzeugen = true)
{
    $p = ew_paths();
    $c = ew_vorgaben();
    $je_gesetzt = false;
    $mqtt_nachtragen = false;
    $d = ew_json_lesen($p['cfgdatei']);
    if (!ew_hat_inhalt($d)) {
        if (!$erzeugen) {
            return null;
        }
        $da = $p['cfgdatei'] !== '' && is_file($p['cfgdatei']);
        $vorher = !$da ? 'fehlt'
                : ($d === null ? 'kaputt' : (count($d) === 0 ? 'leer' : 'ohne_token'));
        $d = ew_config_heilen($p, $d);
        if (ew_hat_inhalt($d)) {
            $vorher = ($vorher === 'kaputt') ? 'kaputt_zweitschrift' : 'zweitschrift';
        }
        ew_config_zustand($vorher);
    } else {
        ew_config_zustand('ok');
    }
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
        /* Gruppe 3 (0.9.25, E3): MQTT ab Werk an nur fuer NEUE Installationen. Wurde das Wortzeichen schon
           einmal geschrieben (bestehende Anlage) und fehlt der Schluessel mqtt_ein, gilt MQTT als aus -
           ein Update schaltet nichts still ein. Die Oberflaeche speichert das beim ersten Aufruf mit. */
        if ($je_gesetzt && !array_key_exists('mqtt_ein', $d)) {
            $c['mqtt_ein'] = 0;
            $mqtt_nachtragen = true;
        }
    }
    /* Solange der Schluessel noch nie geschrieben wurde, ein Wortzeichen
       erzeugen - der Endpunkt soll nicht ungeschuetzt im Netz stehen. Steht
       er einmal da, auch leer, bleibt er: wer ihn in der Oberflaeche bewusst
       leert, will es ohne. Ein stilles Nacherzeugen liesse jede
       Loxone-Adresse, die gerade ohne Token eingetragen ist, ab dem
       naechsten Abruf auf 403 laufen - der Behaelter waere offline, ohne
       dass jemand etwas geaendert haette. */
    if ($erzeugen && !$je_gesetzt && $c['token'] === '') {
        $c['token'] = ew_token_erzeugen();
        ew_config_speichern($c);
    } elseif ($erzeugen && $mqtt_nachtragen) {
        ew_config_speichern($c);
    }
    $c['timeout'] = max(1, min(EW_WARTEZEIT_LESBAR, (int) $c['timeout']));
    $c['mqtt_ein'] = ew_schalter_wert($c['mqtt_ein']) === 1 ? 1 : 0;
    $c['haus_wetter_ein'] = ew_schalter_wert($c['haus_wetter_ein']) === 1 ? 1 : 0;   // 0.9.38, nur 1 schaltet ein
    $c['haus_klima_ein'] = ew_schalter_wert($c['haus_klima_ein']) === 1 ? 1 : 0;
    return $c;
}

/**
 * Konfiguration und Zweitschrift schreiben, beide unteilbar
 * (ew_json_schreiben()). Rueckgabe true nur, wenn die Konfiguration dasteht;
 * bis 0.9.12 kam true auch dann, wenn schon die Umbenennung gescheitert war,
 * und die Oberflaeche meldete "gespeichert" (Fall O1, vorher rot).
 * Die Zweitschrift wird nur mit einem Stand beschrieben, der Inhalt traegt.
 */
function ew_config_speichern(array $c, &$zweit = null)
{
    $p = ew_paths();
    $zweit = false;
    if (!ew_json_schreiben($p['cfgdatei'], $c)) {
        return false;
    }
    /* $zweit sagt dem Aufrufer, ob auch die Zweitschrift steht: das
       Zurueckspielen meldet "uebernommen" erst dann (C1). */
    if (ew_hat_inhalt($c)) {
        $zweit = ew_json_schreiben($p['sicherung'], $c);
        if (!$zweit) {
            ew_log('Die Zweitschrift ' . $p['sicherung'] . ' liess sich nicht schreiben.');
        }
    }
    return true;
}

/** Leere Objekte des Rohtextes ($o, json_decode ohne true) im Feld $a wieder als Objekt fuehren (seit 0.9.32),
 *  damit json_encode() sie als {} und nicht als [] schreibt. Nur fuer ew_config_vervollstaendigen(). */
function ew_json_leere_objekte($a, $o)
{
    if (!is_array($a)) { return $a; }
    if (is_object($o)) {
        if (!get_object_vars($o) && $a === array()) { return new stdClass(); }
        foreach ($a as $k => $v) {
            $ks = (string) $k;
            if (is_array($v) && property_exists($o, $ks)) { $a[$k] = ew_json_leere_objekte($v, $o->$ks); }
        }
    } elseif (is_array($o)) {
        foreach ($a as $k => $v) {
            if (is_array($v) && array_key_exists($k, $o)) { $a[$k] = ew_json_leere_objekte($v, $o[$k]); }
        }
    }
    return $a;
}

/**
 * Fehlende Vorgaben EINMAL in ecowitt.json schreiben (seit 0.9.32, Regeln/05).
 *
 * Bis 0.9.31 ergaenzte ew_config() fehlende Schluessel nur im Speicher. Geschrieben wurde die Datei nur beim
 * ersten Wortzeichen und - nur aus der Oberflaeche, ew_config(true) - wenn mqtt_ein fehlte; dieser Weg schreibt
 * die aufbereiteten Vorgabenschluessel und laesst fremde fallen. Er bleibt unveraendert; wer zuerst kommt, ist
 * jetzt der Minutentakt (bin/ew_takt.php, vor ew_takt() und damit vor dem ersten Lesen der Konfiguration).
 * Weil nur bei FEHLENDEN Schluesseln geschrieben wird, bleibt es bei einem Schreibvorgang.
 *
 * - Nur mit LoxBerry-Wurzel, nicht waehrend einer Aktualisierung (dieselbe Marke und Frist wie ew_takt(): Datei
 *   data/plugins/<ordner>.upgrade_laeuft, juenger als EW_MARKE_FRIST), und nur, wenn ecowitt.json ein Objekt ist
 *   und ein nicht leeres, taugliches Wortzeichen traegt (ew_token_taugt()). Eine fehlende, kaputte oder leere
 *   Datei ist Sache der Selbstheilung; eine Anlage mit bewusst geleertem Wortzeichen wird hier nicht angefasst.
 * - Geschrieben werden die WERTE AUS DER DATEI plus die fehlenden Vorgaben, nicht die von ew_config()
 *   aufbereiteten (Wartezeit begrenzt, Schalter auf 0/1); fremde Schluessel bleiben stehen.
 * - Lese-Regel (Gruppe 3, E3): eine Bestandsdatei ohne mqtt_ein liest ew_config() als 0 (MQTT aus). Genau dieser
 *   Wert wird geschrieben, nicht die Vorgabe 1 einer Neuinstallation - sonst schaltete das Nachtragen MQTT ein.
 * - Nur die Schluessel aus ew_vorgaben() - genau die, die das Speichern im Formular schreibt. Geschrieben wird
 *   ueber ew_config_speichern() (0600, Zweitschrift wie beim Speichern).
 * - Genau eine Protokollzeile mit den Namen, nie mit Werten.
 *
 * Rueckgabe: array('stand' => ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler, 'schluessel' => array())
 */
function ew_config_vervollstaendigen()
{
    $p = ew_paths();
    if ($p['lbhome'] === '' || $p['datadir'] === '' || $p['cfgdatei'] === '') {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    $marke = dirname($p['datadir']) . '/' . $p['plugin'] . '.upgrade_laeuft';
    if (ew_marke_frisch($marke)) {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    clearstatcache(true, $p['cfgdatei']);
    if (!is_file($p['cfgdatei'])) {
        return array('stand' => 'ohne_datei', 'schluessel' => array());
    }
    $roh = @file_get_contents($p['cfgdatei']);
    $datei = is_string($roh) ? json_decode($roh, true) : null;
    if (!is_array($datei) || !isset($datei['token']) || !ew_token_taugt($datei['token']) || $datei['token'] === '') {
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    $fehlend = array();
    foreach (ew_vorgaben() as $k => $w) {
        if (!array_key_exists($k, $datei)) {
            // Lese-Regel E3 (ew_config()): Wortzeichen schon geschrieben, mqtt_ein fehlt -> MQTT aus.
            $datei[$k] = ($k === 'mqtt_ein') ? 0 : $w;
            $fehlend[] = $k;
        }
    }
    if (!$fehlend) {
        return array('stand' => 'vollstaendig', 'schluessel' => array());
    }
    // Ein leeres Objekt der Datei ({}) bliebe nach json_decode(..., true) ein leeres Feld und kaeme als []
    // zurueck - der Rohwert wird erhalten.
    $datei = ew_json_leere_objekte($datei, json_decode($roh));
    if (!ew_config_speichern($datei)) {
        ew_log('Konfiguration NICHT vervollstaendigt: ecowitt.json liess sich nicht schreiben ('
             . count($fehlend) . ' fehlende Schluessel).');
        return array('stand' => 'fehler', 'schluessel' => $fehlend);
    }
    ew_log('Konfiguration vervollstaendigt - ' . count($fehlend)
         . ' fehlende Vorgaben in ecowitt.json geschrieben: ' . implode(', ', $fehlend));
    return array('stand' => 'ergaenzt', 'schluessel' => $fehlend);
}

function ew_token_erzeugen()
{
    $b = @random_bytes(16);
    if ($b === false || $b === null) {
        return substr(str_replace('.', '', uniqid('', true)), 0, 32);
    }
    return bin2hex($b);
}

/** Adresse fuer den Gebrauch: Rand ab, dann dieselbe Pruefung wie beim
 *  Speichern (ew_adresse_pruefen). Nur Zeichenketten - ein Feld aus einer
 *  von Hand bearbeiteten Datei ergibt '' statt "Array". */
function ew_adresse_sauber($a)
{
    if (!is_string($a)) {
        return '';
    }
    return ew_adresse_pruefen(trim($a));
}

function ew_log($text)
{
    $p = ew_paths();
    if ($p['log'] === '') {
        return;
    }
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    $f = $p['log'] . '/ecowitt.log';
    /* Kurz halten - eine Minutenabfrage fuellt sonst die SD-Karte. */
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        $z = file($f, FILE_IGNORE_NEW_LINES) ?: array();
        @file_put_contents($f, implode("\n", array_slice($z, -800)) . "\n");
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $text . "\n", FILE_APPEND);
}

/**
 * Den Grund eines Fehlschlags als Satz aus der Sprachdatei (O4). Bis 0.9.14
 * standen die Gruende fest deutsch in der Bibliothek, und HTTP 500,
 * Zeitueberschreitung und abgewiesene Verbindung hiessen alle "keine
 * Antwort" (Pruefer oberflaeche, Befund 6).
 */
function ew_grund_text($art, $wert = '')
{
    $t = ew_t('GRUND.' . strtoupper((string) $art));
    return strpos($t, '%s') !== false ? sprintf($t, ew_kurz($wert, 40)) : $t;
}

/** Den Grund eines misslungenen Abrufs (Rueckgabe von ew_abrufen) als Satz. */
function ew_abruf_text($info)
{
    $art = (is_array($info) && isset($info['art'])) ? (string) $info['art'] : 'verbindung';
    switch ($art) {
    case 'http':
    case 'umleitung':
        return ew_grund_text($art, (string) (int) $info['code']);
    case 'zeit':
        return ew_grund_text($art, (string) (int) $info['ms']);
    case 'gross':
        return ew_grund_text($art, (string) EW_MAX_ANTWORT);
    }
    return ew_grund_text($art === '' ? 'verbindung' : $art);
}

/**
 * Eine Antwort auf Brauchbarkeit pruefen.
 *
 * Rueckgabe: true, wenn im common_list eine Aussenfeuchte (id 0x07) mit einer
 * Zahl groesser null steht. Die Station schreibt bei verlorenem Funk zum
 * Aussensensor "--" bzw. "---.-" in dieselben Felder - JSON bleibt gueltig,
 * HTTP bleibt 200, und nur der Inhalt verraet den Ausfall.
 *
 * $platzhalter = false (Schalter "Inhalt pruefen" aus) schaltet NUR die
 * Pruefung der Aussenfeuchte ab. Eine HTML-Seite oder kaputtes JSON bleibt
 * ein Fehlschlag: bis 0.9.14 ging dann eine Anmeldeseite als
 * application/json mit "ew_ok":1 hinaus (Pruefer code, Befund 12).
 */
function ew_brauchbar($roh, &$grund = null, $platzhalter = true, &$art = null)
{
    $grund = '';
    $art = '';
    if (!is_string($roh) || trim($roh) === '') {
        $art = 'leer';
        $grund = ew_grund_text($art);
        return false;
    }
    $anfang = substr(ltrim($roh), 0, 1);
    $d = json_decode($roh, true);
    if (!is_array($d) || $anfang !== '{') {
        $art = ($anfang === '<') ? 'html' : 'kein_json';
        $grund = ew_grund_text($art);
        return false;
    }
    if (!$platzhalter) {
        return true;
    }
    if (!isset($d['common_list']) || !is_array($d['common_list'])) {
        $art = 'ohne_liste';
        $grund = ew_grund_text($art);
        return false;
    }
    foreach ($d['common_list'] as $e) {
        if (!is_array($e) || !isset($e['id']) || $e['id'] !== '0x07') {
            continue;
        }
        $v = (isset($e['val']) && !is_array($e['val'])) ? (string) $e['val'] : '';
        if (!preg_match('/^\s*([0-9]+(\.[0-9]+)?)/', $v, $m)) {
            $art = 'platzhalter';
            $grund = ew_grund_text($art, $v);
            return false;
        }
        if ((float) $m[1] <= 0) {
            $art = 'feuchte_null';
            $grund = ew_grund_text($art);
            return false;
        }
        return true;
    }
    $art = 'feuchte_fehlt';
    $grund = ew_grund_text($art);
    return false;
}

/**
 * Eine Adresse abfragen. Rueckgabe: der Rohtext oder false; $info traegt
 * art (adresse, pfad, frist, verbindung, zeit, umleitung, http, gross),
 * code (HTTP-Status), ms, rumpf (Anfang einer Fehlerantwort) und weg.
 *
 * WARUM HIER CURL STEHT
 * ---------------------
 * curl kennt eine eigene Verbindungszeit. Sie steht bei der Haelfte der
 * Wartezeit, hoechstens aber bei zwei Sekunden: ein Geraet im eigenen Netz,
 * das nach zwei Sekunden die Verbindung nicht angenommen hat, nimmt sie auch
 * nach vier nicht an - und die restliche Zeit gehoert dem Lesen. Gemessen am
 * 23.08.2026: ohne curl brauchte die Weiche bei toter erster Schnittstelle
 * 8134 ms statt der eingestellten 4 s.
 *
 * WAS BEIDE WEGE GLEICH TUN (seit 0.9.15)
 * ---------------------------------------
 * - Keine Umleitungen: eine 3xx-Antwort ist ein Fehlschlag. Bis 0.9.14
 *   folgte der Weg ohne curl einer Umleitung auf einen fremden Rechner, und
 *   curl reichte den Rumpf einer 302 als Stationsdaten durch (Pruefer code,
 *   Befunde 6 und 7; Regeln/03 "Beide Abrufwege muessen sich gleich
 *   verhalten").
 * - Nur 2xx gilt; jede Antwort >= 400 ist ein Fehlschlag, auch mit
 *   gueltigem JSON im Rumpf (bis 0.9.14 galt das ohne curl nicht).
 * - Hoechstens EW_MAX_ANTWORT Byte werden gelesen (Befund 9).
 * - Die Wartezeit ist eine GESAMTFRIST fuer Verbindung und Lesen; ohne curl
 *   war sie bis 0.9.14 nur eine Leerlaufgrenze, und eine tropfende Station
 *   hielt den Abruf 25 s fest statt 4 (Befund 10). $frist_bis kuerzt sie auf
 *   die Restzeit des ganzen Endpunktaufrufs (Befund 11).
 */
function ew_abrufen($adresse, $pfad, $timeout, &$info = null, $frist_bis = null)
{
    $t0 = microtime(true);
    $info = array('art' => '', 'code' => 0, 'ms' => 0, 'rumpf' => '', 'weg' => '');
    $adresse = ew_adresse_sauber($adresse);
    if ($adresse === '') {
        $info['art'] = 'adresse';
        return false;
    }
    /* Bis 0.9.14 fiel ein Pfad ohne / still auf /get_livedata_info zurueck. */
    if (!is_string($pfad) || $pfad === '' || $pfad[0] !== '/'
        || preg_match('/[\x00-\x20\x7F]/', $pfad)) {
        $info['art'] = 'pfad';
        return false;
    }
    $grenze = (float) max(1, (int) $timeout);
    if ($frist_bis !== null) {
        $grenze = min($grenze, (float) $frist_bis - $t0);
    }
    if ($grenze < 0.2) {
        $info['art'] = 'frist';
        return false;
    }
    $verbinden = min($grenze, (float) max(1, min(2, (int) ceil($timeout / 2))));
    $url = 'http://' . $adresse . $pfad;
    $code = 0;
    $r = '';

    if (function_exists('curl_init')) {
        $info['weg'] = 'curl';
        $zu_gross = false;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) ceil($verbinden * 1000));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) ceil($grenze * 1000));
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LoxBerry Ecowitt-Weiche');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: close'));
        /* Keine Umleitungen: die Station antwortet selbst oder gar nicht. Wer
           Umleitungen folgt, laesst sich von einem falsch eingetragenen Geraet
           irgendwohin schicken. */
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $teil) use (&$r, &$zu_gross) {
            if (strlen($r) + strlen($teil) > EW_MAX_ANTWORT) {
                $zu_gross = true;
                return 0;
            }
            $r .= $teil;
            return strlen($teil);
        });
        $lief = curl_exec($ch);
        $nr = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        /* curl_close() wirkt ab PHP 8.0 nicht mehr und meldet sich ab 8.5 als
           veraltet; mit display_errors=1 ging die Meldung vor dem JSON bzw.
           statt der 503 hinaus (Pruefer code, Befund 8). */
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        $info['code'] = $code;
        $info['ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($zu_gross) {
            $info['art'] = 'gross';
            return false;
        }
        if ($lief === false || $nr !== 0) {
            $info['art'] = ($nr === 28) ? 'zeit' : 'verbindung';
            return false;
        }
    } else {
        /* Ohne curl. Die Verbindungszeit folgt default_socket_timeout; der
           alte Wert wird danach zurueckgestellt, damit das Plugin nichts
           hinterlaesst, was andere Skripte trifft. Ein Fehlschlag ist hier
           ein vorgesehener Ausgang: der Fehlerbehandler wird nur fuer diesen
           einen Aufruf ausgetauscht (Regeln/03). */
        $info['weg'] = 'stream';
        $merk = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) max(1, (int) ceil($verbinden)));
        $ctx = stream_context_create(array('http' => array(
            'timeout'         => $grenze,
            'method'          => 'GET',
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'header'          => "Connection: close\r\n",
            'user_agent'      => 'LoxBerry Ecowitt-Weiche',
        )));
        set_error_handler(function () { return true; });
        $fp = fopen($url, 'r', false, $ctx);
        restore_error_handler();
        if ($merk !== false) {
            @ini_set('default_socket_timeout', (string) $merk);
        }
        if ($fp === false) {
            $info['ms'] = (int) round((microtime(true) - $t0) * 1000);
            $info['art'] = (microtime(true) - $t0 >= $grenze - 0.05) ? 'zeit' : 'verbindung';
            return false;
        }
        /* Die Statuszeile aus den Kopfzeilen des Stroms - nicht aus
           $http_response_header, das PHP 8.5 als veraltet meldet. Es gilt
           die letzte Statuszeile. */
        $meta = stream_get_meta_data($fp);
        $kopf = (isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
              ? $meta['wrapper_data'] : array();
        foreach ($kopf as $z) {
            if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
                $code = (int) $m[1];
            }
        }
        /* fread, nicht stream_get_contents mit Laenge: das liest intern
           weiter, bis die Laenge voll ist, und eine Station, die jede Sekunde
           ein Byte schickt, hielte den Abruf trotz Frist fest. fread kehrt
           nach EINEM Lesevorgang zurueck, und die Uhr wird je Runde geprueft. */
        $zeit = false;
        $zu_gross = false;
        $abgebrochen = false;
        while (!feof($fp)) {
            $rest = $grenze - (microtime(true) - $t0);
            if ($rest <= 0) {
                $zeit = true;
                break;
            }
            stream_set_timeout($fp, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
            $teil = fread($fp, 65536);
            $m2 = stream_get_meta_data($fp);
            /* timed_out zaehlt nur bei einem Lesevorgang OHNE Daten: unter
               PHP 8.5 steht der Merker auch nach einem Lesevorgang, der die
               ganze Antwort geliefert hat (gemessen 30.09.2026, 4 von 6
               Abrufen; unter 7.4 nie - proben/diag_fread.php). */
            if ($teil === false) {
                $zeit = !empty($m2['timed_out']);
                $abgebrochen = !$zeit && !feof($fp);
                break;
            }
            $r .= $teil;
            if (strlen($r) > EW_MAX_ANTWORT) {
                $zu_gross = true;
                break;
            }
            if ($teil === '' && !empty($m2['timed_out'])) {
                $zeit = true;
                break;
            }
        }
        fclose($fp);
        $info['code'] = $code;
        $info['ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($zu_gross) {
            $info['art'] = 'gross';
            return false;
        }
        if ($zeit) {
            $info['art'] = 'zeit';
            return false;
        }
        if ($abgebrochen) {
            $info['art'] = 'verbindung';
            return false;
        }
    }
    /* Nur 2xx ist eine Antwort der Station (C3). */
    if ($code >= 300 && $code < 400) {
        $info['art'] = 'umleitung';
        $info['rumpf'] = substr($r, 0, 200);
        return false;
    }
    if ($code < 200 || $code >= 300) {
        $info['art'] = 'http';
        $info['rumpf'] = substr($r, 0, 200);
        return false;
    }
    return $r;
}

/**
 * Soll die Ersatzseite zuerst gefragt werden? Ja, wenn sie laut stand.json
 * zuletzt getragen hat und die primaere Seite hoechstens EW_ERSATZ_ZUERST
 * Sekunden vorher verworfen wurde (C5).
 */
function ew_ersatz_zuerst(array $stand)
{
    if (!isset($stand['quelle']) || $stand['quelle'] !== 'ersatz' || empty($stand['ok'])) {
        return false;
    }
    $v = isset($stand['primaer_verworfen']) ? (int) $stand['primaer_verworfen'] : 0;
    $alter = time() - $v;
    return $v > 0 && $alter >= 0 && $alter <= EW_ERSATZ_ZUERST;
}

/**
 * Die Weiche: die beiden Schnittstellen in der Reihenfolge, die
 * ew_ersatz_zuerst() waehlt, zusammen hoechstens ew_gesamtfrist() Sekunden.
 * Die Konfiguration kommt vom Aufrufer - der Endpunkt liest sie ohne
 * Heilung (C7), die Oberflaeche mit.
 *
 * Rueckgabe-Feld:
 *   ok      true, wenn eine Seite brauchbare Daten geliefert hat
 *   quelle  'primaer' | 'ersatz' | ''
 *   adresse die Adresse, die getragen hat
 *   roh     der unveraenderte Antworttext der Station
 *   grund   warum die jeweilige Seite verworfen wurde (fuer das Protokoll)
 *   primaer_verworfen  Zeitpunkt, wenn die primaere Seite gefragt und
 *           verworfen wurde, sonst 0
 *   stationszeit  Zeitstempel der Station aus der brauchbaren Antwort
 *           (ew_stationszeit), 0 = keiner (a1)
 */
function ew_weiche(array $c, array $stand = array())
{
    $aus = array('ok' => false, 'quelle' => '', 'adresse' => '', 'roh' => '',
                 'grund' => array(), 'ts' => time(), 'ersatz_zuerst' => false,
                 'primaer_verworfen' => 0, 'stationszeit' => 0);
    $bis = microtime(true) + ew_gesamtfrist($c);
    $reihe = array('primaer', 'ersatz');
    if (ew_ersatz_zuerst($stand)) {
        $reihe = array('ersatz', 'primaer');
        $aus['ersatz_zuerst'] = true;
    }
    foreach ($reihe as $seite) {
        $roh_adr = isset($c[$seite]) ? $c[$seite] : '';
        $adr = ew_adresse_sauber($roh_adr);
        if ($adr === '') {
            $aus['grund'][$seite] = ($roh_adr === '' || $roh_adr === null)
                ? ew_t('TEXT.KEINE_ADRESSE') : ew_grund_text('adresse');
            continue;
        }
        $info = null;
        $roh = ew_abrufen($adr, $c['pfad'], $c['timeout'], $info, $bis);
        $grund = '';
        if ($roh === false) {
            $grund = ew_abruf_text($info);
        } elseif (!ew_brauchbar($roh, $grund, !empty($c['pruefe_wert']))) {
            $roh = false;
        }
        if ($roh === false) {
            $aus['grund'][$seite] = $grund;
            if ($seite === 'primaer' && (!is_array($info) || $info['art'] !== 'frist')) {
                $aus['primaer_verworfen'] = time();
            }
            continue;
        }
        $aus['ok'] = true;
        $aus['quelle'] = $seite;
        $aus['adresse'] = $adr;
        $aus['roh'] = $roh;
        $aus['stationszeit'] = ew_stationszeit($roh);
        return $aus;
    }
    return $aus;
}

/**
 * Merkt sich, welche Seite zuletzt getragen hat - fuer Oberflaeche,
 * Protokoll und die Reihenfolge der Weiche.
 *
 * Lesen, aendern und schreiben laufen unter einer Sperre (stand.lock), und
 * geschrieben wird ueber ew_json_schreiben() (Nebendatei mit PID, Laenge
 * verglichen, rename). Bis 0.9.14 hiess die Nebendatei fuer alle Prozesse
 * stand.json.tmp, ohne Sperre: in WSL mit zwei gleichzeitigen Schreibern
 * rund 6 % unlesbare Staende und hunderte Ruecksprunge des Zaehlers
 * (Pruefer Weg zu Loxone, Befund 4; Pruefer code, Befund 14).
 */
function ew_stand_schreiben(array $w)
{
    $p = ew_paths();
    /* Ohne Wurzel gibt es keinen Datenordner; dann wird nichts geschrieben. */
    $f = $p['datadir'] !== '' ? $p['datadir'] . '/stand.json' : '';
    if ($f !== '' && !is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $sperre = false;
    if ($f !== '') {
        $sperre = @fopen($p['datadir'] . '/stand.lock', 'c');
        if ($sperre !== false && !flock($sperre, LOCK_EX)) {
            fclose($sperre);
            $sperre = false;
        }
    }
    $alt = array();
    if ($f !== '' && is_file($f)) {
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
        'primaer_verworfen' => isset($alt['primaer_verworfen']) ? (int) $alt['primaer_verworfen'] : 0,
        'stationszeit' => isset($alt['stationszeit']) ? (int) $alt['stationszeit'] : 0,
    );
    if ($w['ok']) {
        $neu['letzte_gute'] = $w['ts'];
        /* a1: der Zeitstempel gehoert zur letzten brauchbaren Antwort; traegt
           sie keinen, gilt wieder das Alter der Weiche. */
        $neu['stationszeit'] = isset($w['stationszeit']) ? (int) $w['stationszeit'] : 0;
    }
    if ($w['quelle'] === 'primaer') {
        $neu['primaer_verworfen'] = 0;
    } elseif (!empty($w['primaer_verworfen'])) {
        $neu['primaer_verworfen'] = (int) $w['primaer_verworfen'];
    }
    /* Nur der WECHSEL wird protokolliert, nicht jeder Abruf. Eine
       Minutenabfrage erzeugt sonst 1440 Zeilen am Tag, in denen die eine
       wichtige untergeht. */
    $vorher = isset($alt['quelle']) ? (string) $alt['quelle'] : null;
    if ($vorher !== null && $vorher !== $w['quelle']) {
        $neu['wechsel'] = $neu['wechsel'] + 1;
        ew_log('Wechsel: ' . ($vorher === '' ? 'keine Quelle' : $vorher)
             . ' -> ' . ($w['quelle'] === '' ? 'keine Quelle' : $w['quelle'] . ' (' . $w['adresse'] . ')')
             . ($w['grund'] ? '  Grund: ' . ew_kurz(implode(' | ', $w['grund']), 400) : ''));
    }
    if ($f !== '') {
        ew_json_schreiben($f, $neu);
    }
    if ($sperre !== false) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
    }
    return $neu;
}

/**
 * a1: Der Zeitstempel einer Stationsantwort in Unix-Sekunden, 0 = keiner.
 *
 * Gelesen wird NUR die oberste Ebene des JSON:
 *   dateutc    "JJJJ-MM-TT hh:mm:ss" (auch mit T oder + als Trenner), UTC -
 *              die Form des Ecowitt-Uploadprotokolls
 *   timestamp  Unix-Sekunden, Zahl oder Ziffernfolge (9-10 Stellen)
 *   time       ebenso
 * Der erste vorhandene Schluessel entscheidet; ist sein Wert unbrauchbar
 * ("now", "--", vor 2020), gilt: keiner. Ein "timestamp" in einem Block
 * darunter (lightning: letzter Blitz) ist kein Zeitpunkt der Messung.
 */
function ew_stationszeit($roh)
{
    $d = is_string($roh) ? json_decode($roh, true) : null;
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('dateutc', 'timestamp', 'time') as $k) {
        if (!array_key_exists($k, $d)) {
            continue;
        }
        $v = $d[$k];
        $ts = 0;
        if ($k === 'dateutc') {
            if (is_string($v) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})[ T+]([0-9]{2}):([0-9]{2}):([0-9]{2})\z/', $v, $m)
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60) {
                $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
            }
        } elseif (is_int($v) || (is_string($v) && preg_match('/^[0-9]{9,10}\z/', $v))) {
            $ts = (int) $v;
        }
        return ($ts >= EW_STATIONSZEIT_AB) ? (int) $ts : 0;
    }
    return 0;
}

/**
 * a1: ALTER fuer live.php?status=1. -1 = noch nie brauchbare Daten; sonst
 * die Sekunden seit der letzten brauchbaren Antwort - und, wenn diese einen
 * Zeitstempel der Station trug, mindestens die Sekunden seit diesem.
 * Ein Zeitstempel mehr als EW_ZUKUNFT_TOLERANZ s in der Zukunft zaehlt nicht.
 */
function ew_alter(array $stand, $jetzt = null)
{
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    if (empty($stand['letzte_gute'])) {
        return -1;
    }
    $alter = $jetzt - (int) $stand['letzte_gute'];
    $sz = isset($stand['stationszeit']) ? (int) $stand['stationszeit'] : 0;
    if ($sz > 0 && $sz <= $jetzt + EW_ZUKUNFT_TOLERANZ) {
        $alter = max($alter, $jetzt - $sz);
    }
    return $alter;
}

function ew_stand_lesen()
{
    $p = ew_paths();
    $f = $p['datadir'] !== '' ? $p['datadir'] . '/stand.json' : '';
    if ($f === '' || !is_file($f)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
}


/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function ew_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function ew_ansage_vorgaben()
{
    return array('tts' => ansage_werk_block(), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('ausfall' => 1), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function ew_ansage_wert($k, $w)
{
    if (!in_array((string) $k, ew_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, ew_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('ausfall' => 1)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function ew_ansage_cfg()
{
    $c = ew_config();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(ew_paths()['cfgdatei']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && ew_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            ew_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function ew_ansage_cfg_schreiben(array $cfg)
{
    return ew_config_speichern($cfg);
}

/** Die Ausgabearten dieser Linie. */
function ew_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function ew_tts($cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    return ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), ew_ansage_modi());
}

/** Der Kontext des Moduls. */
function ew_ansage_k()
{
    $d = ew_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry Ecowitt-Weiche'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return ew_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function ew_ansage_anlaesse()
{
    return array('ausfall' => ew_t('ANSAGEPLUGIN.L_AUSFALL'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function ew_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('ausfall' => 1);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken. */
function ew_ansage_eingaben($setzen = null)
{
    $f = ew_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, (string) json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d) && $d) { @file_put_contents($f, '{}'); return $d; }
    return array();
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function ew_ansage_post(array $post)
{
    $cfg = ew_ansage_cfg();
    $k = ew_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, ew_tts($cfg), $m, $b, array('modi' => ew_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, ew_ansage_regeln($cfg), ew_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if ((string) $n !== 'tts_alexa_token' && (string) $n !== 'tts_google_token') { $e[$n] = $w; }
            }
        }
        ew_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    // 0.9.29: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 0.9.28 ueberschrieb es der naechste Abgleich.
    $cfg['ansage_token_herkunft'] = ansage_token_herkunft_formular($post, isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array());
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(ew_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function ew_ansage_form_html($felder)
{
    $cfg = ew_ansage_cfg();
    $k = ew_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = ew_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(ew_t('ANSAGEPLUGIN.H')) . '</h2>';
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(ew_tts($cfg), $h + array('modi' => ew_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(ew_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('ausfall' => 1), ew_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(ew_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(ew_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = ew_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function ew_ansage_test()
{
    $k = ew_ansage_k();
    ew_ansage_token_pflegen();
    $r = ansage_testansage(ew_ansage_vor_ort(), $k);
    ew_log('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (0.9.27, Welle S7, Stamm sprachausgabe.php 1.4.0) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('EW_ANSAGE_HOECHSTALTER')) { define('EW_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function ew_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function ew_ansage_warte($fn)
{
    $d = ew_paths()['datadir'];
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
function ew_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    ew_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; ew_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function ew_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(ew_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), ew_ansage_k());
    if (!$g) { return false; }
    $cfg = ew_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!ew_ansage_cfg_schreiben($cfg)) { return false; }
    ew_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function ew_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    return ansage_token_fuer_lauf(ew_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), ew_ansage_k());
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function ew_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    $t = ew_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(ew_ansage_vor_ort($cfg), ew_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function ew_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = ew_ansage_cfg(); }
    $k = ew_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(ew_tts($cfg), ew_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function ew_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function ew_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = ew_ansage_cfg();
    $k = ew_ansage_k();
    $t = $tts === null ? ew_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, ew_ansage_regeln($conf), $k);
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
function ew_ansage_senden($jetzt = null)
{
    $d = ew_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = ew_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= EW_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = ew_ansage_cfg();
        ew_ansage_token_pflegen($conf);
        $conf = ew_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, ew_tts($conf), ew_ansage_regeln($conf), ew_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { ew_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            ew_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/**
 * Die Flanke eines Anlasses (0.9.27). Merker <Daten>/ansage_flanke.json, unter Dateisperre; er ueberlebt einen Neustart des Dienstes.
 * Der erste Lauf schweigt: Was schon gestoert war, als die Linie zum ersten Mal hinsah, sagt sie nicht an und meldet auch keine Rueckkehr.
 * Nur der Eintritt spricht, nach $frist Sekunden Dauer; die Rueckkehr nur, wenn der Eintritt gesprochen hat. Ein abgewaehlter Haken laeuft mit: Einschalten sagt nichts Altes an.
 * $name: der Name, den der Nutzer vergeben hat, nur im Satz, nie im Protokoll; Kennungen, Adressen und Seriennummern gehoeren nicht hierher.
 * Rueckgabe: nichts, erster_lauf, aus, oder das Ergebnis von <px>_ansage_legen().
 */
function ew_ansage_flanke($anlass, $gestoert, $name = '', $frist = 0, $jetzt = null)
{
    $l = array('ausfall' => array('ausfall', 'SATZ_AUSFALL', 'SATZ_WIEDER', 0));
    if (!isset($l[$anlass])) { return 'unbekannt'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $name = trim((string) preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $name));
    $sch = $anlass . ($name !== '' ? '|' . substr(md5($name), 0, 8) : '');
    $d = ew_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $h = @fopen($d . '/ansage_flanke.json', 'c+');
    if ($h === false) { return 'nichts'; }
    if (!flock($h, LOCK_EX)) { fclose($h); return 'nichts'; }
    $m = json_decode((string) stream_get_contents($h), true);
    $m = is_array($m) ? $m : array();
    $e = (isset($m[$sch]) && is_array($m[$sch]) && isset($m[$sch]['z'], $m[$sch]['s'], $m[$sch]['g'])) ? $m[$sch] : null;
    $neu = $gestoert ? 1 : 0;
    $tat = 'nichts';
    if ($e === null) {
        $e = array('z' => $neu, 's' => $jetzt, 'g' => $neu ? 2 : 0);
        $tat = 'erster_lauf';
    } elseif ($neu === 1) {
        if ((int) $e['z'] === 0) { $e = array('z' => 1, 's' => $jetzt, 'g' => 0); }
        if ((int) $e['g'] === 0 && $jetzt - (int) $e['s'] >= (int) $frist) {
            if (ew_ansage_anlass_an($l[$anlass][0])) { $e['g'] = 1; $tat = 'stoerung'; } else { $e['g'] = 2; $tat = 'aus'; }
        }
    } elseif ((int) $e['z'] === 1) {
        if ((int) $e['g'] === 1) { $tat = $l[$anlass][2] !== '' ? 'wieder' : 'nichts'; }
        $e = array('z' => 0, 's' => $jetzt, 'g' => 0);
    }
    if (!isset($m[$sch]) || $m[$sch] !== $e) {     // nur bei einer Aenderung schreiben: Dienste rufen das im Takt
        $m[$sch] = $e;
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, (string) json_encode($m));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($d . '/ansage_flanke.json', 0600);
    if ($tat !== 'stoerung' && $tat !== 'wieder') { return $tat; }
    $satz = ew_t('ANSAGEPLUGIN.' . ($tat === 'stoerung' ? $l[$anlass][1] : $l[$anlass][2]));
    if (strpos($satz, '%s') !== false) { $satz = sprintf($satz, $name); }
    $ein = array('schluessel' => $tat === 'stoerung' ? $sch : 'wieder|' . $sch, 'text' => $satz);
    if ($tat === 'wieder') { $ein['nur_nach'] = $sch; }
    if ($tat === 'stoerung' && $l[$anlass][3]) { $ein['dringend'] = true; }
    ew_ansage_token_pflegen();
    return ew_ansage_legen(array($ein));
}

/**
 * 0.9.34: Die Beschreibung dieser Linie fuer das gemeinsame Sicherungsmodul
 * (Werkzeuge/gemeinsam/sicherung.php, Auftrag des Hausherrn 12.11.2026). Lesen,
 * Pruefen und Zurueckspielen macht das Modul; bis 0.9.33 stand hier eine eigene
 * Schleife (ew_sicherung_lesen()): die Sicherung trug gar keinen Kopf, eine
 * fremde Datei wurde nur an ihren unbekannten Schluesseln erkannt, ein
 * unbekannter Schluessel oben oder in einem Block (Sprachansagen) wies die ganze
 * Datei ab, und ein leerer Wert ueberschrieb den bestehenden (gemessen 14.11.2026
 * an 0.9.33 mit Werkzeuge/sicherung_pruefstand.py --profil ist).
 *
 * Seither: der Kopf traegt das Kennzeichen LoxBerry-Plugin-Ecowitt-Weiche (Name
 * des Repositorys) und die Fassung; eine Datei mit fremdem oder ohne Kennzeichen
 * wird abgewiesen. Ausgenommen ist eine Sicherung bis 0.9.33, denn keine dieser
 * Fassungen schrieb einen Kopf: ew_sicherung_alt_erkennen() erkennt sie an den
 * Schluesseln, die jede von ihnen schrieb und die keine andere Linie zusammen
 * fuehrt (Entscheidung des Hausherrn 14.11.2026 "Ja, erkennen", Regeln/05
 * Regel 1; der Kopf _warnung allein taugte nicht, er kam nur bei beanstandeten
 * Werten, und sein Text steht wortgleich in einem anderen Plugin). Unbekannte
 * Schluessel oben und in den Bloecken werden verworfen und
 * mit Namen gemeldet; ein leerer Wert ueberschreibt keinen bestehenden.
 *
 * Wie bisher: jeder Wert geht durch ew_wert_pruefen() (dieselbe Pruefung wie
 * das Formular) und wird in dessen Normalform uebernommen, die Sprachansagen
 * durch ew_ansage_wert() (eine Datei mit Sprechtoken wird abgewiesen); eine
 * halb gueltige Datei aendert nichts, alle Beanstandungen werden gesammelt; ein
 * fehlender Schluessel ist eine Beanstandung, ausser denen, die eine fruehere
 * Fassung nicht kannte (ew_sicherung_spaete_schluessel()); der Vermerk
 * ansage_token_herkunft und ansage_aus_datei kommen nie aus einer Datei.
 * Maskiert wird erst bei der Ausgabe (ew_e()); der Knopf gibt dem Modul deshalb
 * die Beschreibung mit 'e' = Klartext (ew_sicherung_klartext(), Regeln/05
 * "Meldungen als Klartext"). Die Haken-Zeile bekommt diese Beschreibung
 * unveraendert und bleibt maskiert.
 */
function ew_sicherung_beschreibung()
{
    $p = ew_paths();
    $tts = array_merge(array_keys(ansage_werk_block()), ansage_geheim());
    $schluessel = array(
        'tts' => sicherung_block($tts, array('ziele' => sicherung_liste(array('art', 'wo', 'laut', 'sprache', 'ein'))))
                 + array(
                     'sichern' => 'ansage_sicherung_bereinigen',
                     'pruefen' => function ($w, $k = '', $neu = null, $roh = null) {
                         /* Eine Sicherung dieses Plugins traegt nie ein Sprechtoken - traegt die Datei eines, wird
                          * sie abgewiesen. Gemessen am Block der DATEI, denn Regel 3 des Moduls setzt ein leeres
                          * Feld vorher auf den geltenden Wert. */
                         if (ansage_sicherung_mangel($roh) || ew_ansage_wert('tts', ansage_sicherung_bereinigen($w)) !== true) {
                             return ew_t('ANSAGEPLUGIN.M_FEHL');
                         }
                         return '';
                     },
                     'wandeln' => function ($w) {
                         $g = '';
                         return ansage_wert_pruefen(ansage_sicherung_bereinigen($w), $g, ew_ansage_modi());
                     }),
        'ansage_regeln' => sicherung_block(array_keys(ansage_regeln_vorgaben())),
        'ansage_haken' => sicherung_block(array('ausfall')),
    );
    /* Normalform wie bisher: Wartezeit und Schalter als Zahl (ew_wert_pruefen() gibt sie zurueck). */
    foreach (array('timeout', 'pruefe_wert', 'mqtt_ein', 'haus_wetter_ein', 'haus_klima_ein') as $s) {
        $schluessel[$s] = array('wandeln' => function ($w) use ($s) {
            list(, $v) = ew_wert_pruefen($s, $w);
            return $v;
        });
    }
    foreach (ew_sicherung_spaete_schluessel() as $s) {
        if (!isset($schluessel[$s])) { $schluessel[$s] = array(); }
        $schluessel[$s]['fehlt'] = 'behalten';
    }
    return array(
        'kennzeichen' => 'LoxBerry-Plugin-Ecowitt-Weiche',
        'hinweis' => ew_t('TEXT.SICH_KOPF'),
        /* Die Fassung im Kopf kommt aus der Plugin-Datenbank bzw. plugin.cfg - keine fuenfte Stelle neben den
         * vier, die Werkzeuge/fassung_setzen.py pflegt. */
        'home' => (string) $p['lbhome'], 'ordner' => (string) $p['plugin'],
        'plugin_cfg' => dirname(dirname(__DIR__)) . '/plugin.cfg',
        'vorgaben' => ew_vorgaben(),
        'schluessel' => $schluessel,
        'nie_aus_datei' => array('ansage_token_herkunft', 'ansage_aus_datei'),
        'uebersetzer' => 'ew_sicherung_alt_erkennen',
        'pruefen' => function ($k, $w) {
            $a = ew_ansage_wert($k, $w);
            if ($a !== null) { return $a ? '' : ew_t('ANSAGEPLUGIN.M_FEHL'); }
            list($ok, , $grund) = ew_wert_pruefen($k, $w);
            return $ok ? '' : $grund;
        },
        'vorbereiten' => 'ew_sicherung_vorbereiten',
        // 0.9.35 (Modul 1.2.9, Bauform vom 15.11.2026): das Modul sieht die geltende Konfiguration ungefiltert -
        // ew_config() liefert nur die Vorgabenschluessel; was die Datei sonst traegt, haengt hier an, damit das
        // Zurueckspielen es beim Namen nennt, statt es still wegfallen zu lassen.
        'jetzt' => function () {
            $j = ew_ansage_cfg();
            $w = ew_json_lesen(ew_paths()['cfgdatei']);
            return $j + (is_array($w) ? $w : array());
        },
        't' => 'ew_t',
    );
}

/**
 * 0.9.34: Die Beschreibung fuer den Knopf "Einstellungen zurueckspielen": Meldungen
 * des Moduls als Klartext ('e'), denn die Oberflaeche maskiert jede Meldung
 * einmal bei der Ausgabe (ew_e()). Regeln/05 "Meldungen als Klartext".
 */
function ew_sicherung_klartext()
{
    return array('e' => function ($s) { return (string) $s; }) + ew_sicherung_beschreibung();
}

/**
 * 0.9.34: Die Schluessel, die eine aeltere Sicherung dieser Linie noch nicht
 * kennen konnte. Fehlen sie, behalten sie den geltenden Wert, und die Meldung
 * nennt sie (Regeln/05 Punkt 6): MQTT (mqtt_ein, mqtt_praefix seit 0.9.25; bis
 * 0.9.33 galt dann MQTT aus und das Praefix ab Werk) und die Sprachansagen (bis
 * 0.9.33 setzte ew_ansage_sicherung_vor() sie still auf den geltenden Wert).
 */
function ew_sicherung_spaete_schluessel()
{
    /* 0.9.38: die Haus-Themen kennt eine Sicherung bis 0.9.37 nicht - fehlen sie, bleibt der geltende Wert. */
    return array('mqtt_ein', 'mqtt_praefix', 'tts', 'ansage_regeln', 'ansage_haken', 'haus_wetter_ein', 'haus_klima_ein',
                 'haus_aussen_bereich', 'haus_aussen_name', 'haus_basis_bereich', 'haus_basis_name');
}

/**
 * 0.9.34: Woran eine Sicherung bis 0.9.33 erkannt wird (Entscheidung des
 * Hausherrn 14.11.2026, "Ja, erkennen"). Keine dieser Fassungen schrieb einen
 * Kopf mit _plugin, jede aber diese sechs Schluessel. Gemessen 15.11.2026 mit
 * dem Code aller 25 Vorfassungen 0.9.9 bis 0.9.33 (je drei Lagen, PHP 7.4 und
 * 8.4, 150 Sicherungen: jede traegt alle sechs) und gegen 59 andere Linien
 * (Vorgabenfunktionen, mitgelieferte Konfiguration, 107 Sicherungen ueber den
 * Pruefstand, die Sicherungen aller Vorfassungen von FerienFeiertage): keine
 * fuehrt mehr als einen davon zusammen. Belege im Pruefordner
 * Pruefung-Ecowitt-Weiche-0.9.34/ohne_kopf/.
 */
function ew_sicherung_alt_merkmal()
{
    return array('primaer', 'ersatz', 'pfad', 'timeout', 'token', 'pruefe_wert');
}

/**
 * 0.9.34: Die Kopfschluessel, die eine Fassung bis 0.9.33 schreiben konnte:
 * _warnung (seit 0.9.16, bei einem Wert, den das eigene Zurueckspielen
 * abwiese). Ein anderer Schluessel mit Unterstrich (_hinweis, _fassung, _stand)
 * stammt aus einer Fassung mit Kopf, die immer _plugin schreibt - ohne _plugin
 * ist eine solche Datei keine aeltere eigene.
 */
function ew_sicherung_alt_kopf()
{
    return array('_warnung');
}

/**
 * 0.9.34: Der Uebersetzer fuer das Sicherungsmodul ('uebersetzer'; das Modul
 * ruft ihn nur fuer eine Datei ohne _plugin). Angenommen wird sie nur, wenn
 * jeder Schluessel aus ew_sicherung_alt_merkmal() oben steht und sie keinen
 * anderen Kopfschluessel als die aus ew_sicherung_alt_kopf() traegt; die
 * Meldung sagt dann "aeltere Sicherung erkannt" (Modultext K_ALT_ERKANNT).
 * Sonst null, und das Modul weist die Datei als Ganzes ab. Umgeschrieben wird
 * nichts. Rueckgabe: die Daten oder null.
 */
function ew_sicherung_alt_erkennen($d)
{
    if (!is_array($d) || array_key_exists('_plugin', $d)) { return null; }
    foreach (array_keys($d) as $k) {
        if (is_string($k) && $k !== '' && $k[0] === '_' && !in_array($k, ew_sicherung_alt_kopf(), true)) { return null; }
    }
    foreach (ew_sicherung_alt_merkmal() as $k) {
        if (!array_key_exists($k, $d)) { return null; }
    }
    return $d;
}

/**
 * 0.9.34: Vor dem Speichern (Rueckruf 'vorbereiten' des Moduls). Der Block tts
 * wird wie bis 0.9.33 vervollstaendigt, und die geltenden Sprechtoken bleiben
 * (die Sicherung traegt keine). Kam der Block nicht aus der Datei, steht dort
 * schon der geltende. Die Vermerke ansage_token_herkunft und ansage_aus_datei
 * behalten ihren geltenden Wert ('nie_aus_datei', Modul 1.2.1); der geltende
 * Stand kommt aus ew_ansage_cfg() ('jetzt'), die eine liegengebliebene
 * ansage.json aus der Probe vom 01.11. vorher einmal uebernimmt.
 */
function ew_sicherung_vorbereiten($neu, $jetzt, $r)
{
    if (!in_array('tts', $r['fehlt_behalten'], true) && isset($neu['tts']) && is_array($neu['tts'])) {
        $jt = (is_array($jetzt) && isset($jetzt['tts']) && is_array($jetzt['tts'])) ? $jetzt['tts'] : array();
        list($tv) = ansage_vervollstaendigen($neu['tts'], 'aus');
        $neu['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
    }
    return $neu;
}

/**
 * 0.9.34: Nach dem Zurueckspielen nachziehen und sagen, was geschah (Regeln/05).
 * Rueckgabe: Hinweise (Texte aus der Sprachdatei, Klartext).
 *  - Ohne Wortzeichen: der Endpunkt ist offen (wie bis 0.9.33). Ein leeres
 *    Wortzeichen in der Datei behaelt seit 0.9.34 ein bestehendes (Regel 3 des
 *    Moduls, die Meldung nennt es); ohne Wortzeichen bleibt es also nur, wenn
 *    auch vorher keines gesetzt war.
 *  - Eine Wartezeit, die Loxone nicht annimmt, wird uebernommen und gesagt (k2).
 *  - Die Weiche hat keinen eigenen Dienst. Mit MQTT liest der Minutentakt die
 *    Konfiguration bei jedem Lauf neu (ew_takt()); wie nach dem Speichern im
 *    Reiter MQTT geht beim naechsten Lauf der volle Satz hinaus, und die
 *    Abo-Datei fuer Gateway V1 folgt dem Praefix. Ohne MQTT liest live.php sie
 *    bei jedem Abruf des Miniservers. Ein Neustart ist nicht noetig; das wird
 *    gesagt.
 */
function ew_sicherung_nachziehen($neu, $jetzt, $r)
{
    $h = array();
    if ((string) $neu['token'] === '') {
        $h[] = ew_t('TEXT.SICH_OHNE_TOKEN');
    }
    if (ew_wartezeit_zu_lang($neu)) {
        $h[] = sprintf(ew_t('TEXT.SICH_WARTEZEIT_ALT'), (int) $neu['timeout'],
            ew_behaelter_timeout_noetig_ms($neu), EW_LOX_TIMEOUT_MAX, EW_WARTEZEIT_MAX);
    }
    if (!empty($neu['mqtt_ein'])) {
        ew_takt_voll_merken();
        if (!ew_abo_schreiben($neu)) {
            $h[] = ew_t('MQTT.ABO_NICHT_GESCHRIEBEN');
        }
        $h[] = ew_t('TEXT.SICH_DIENST_TAKT');
    } else {
        $h[] = ew_t('TEXT.SICH_DIENST_OHNE');
    }
    /* 0.9.38: Haus-Themen - was nach dem Zurueckspielen nicht mehr gesendet wird, sofort mit Ruecklesen abraeumen; der
       naechste Minutenlauf sendet den vollen Satz. */
    list($hm) = ew_haus_folgen($neu);
    if ($hm !== '') {
        $h[] = $hm;
    }
    return $h;
}

/**
 * Die Sicherungsdatei - lesbarer Kopf (_hinweis, _plugin, _fassung, _stand, bei
 * Bedarf _warnung) und alle Schluessel der Vorgaben aus $cfg, gebaut vom
 * gemeinsamen Sicherungsmodul (sicherung_bauen()). Nie ein Sprechtoken, nie die
 * Vermerke. X-3 (Verbesserungsbau 01.10.2026): wuerde das eigene Zurueckspielen
 * diese Datei abweisen, sagt es der Kopf _warnung - nur Namen, nie Werte;
 * geliefert wird sie trotzdem vollstaendig. Rueckgabe: der JSON-Text, '' wenn
 * er sich nicht bilden laesst.
 */
function ew_sicherung_bauen(array $cfg)
{
    return sicherung_json(sicherung_bauen(ew_sicherung_beschreibung(), $cfg));
}

/**
 * X-3: Welche gespeicherten Werte bestuenden das eigene Zurueckspielen
 * nicht? Seit 0.9.34 dieselbe Pruefung wie beim Zurueckspielen ueber das
 * Sicherungsmodul (sicherung_selbstpruefung()) an genau der Datei, die
 * "Einstellungen sichern" liefert. Rueckgabe: Liste der NAMEN, nie der Werte;
 * leer = die Sicherung liesse sich zurueckspielen.
 */
function ew_rueckspiel_altwerte(array $cfg)
{
    $b = ew_sicherung_beschreibung();
    $d = sicherung_bauen($b, $cfg);
    unset($d['_warnung']);
    return sicherung_selbstpruefung($b, $d, $cfg);
}

function ew_sprachdatei()
{
    $t = getenv('LBPTEMPLATEDIR');
    if ($t === false || $t === '') {
        /* Zwei Lagen, zwei Pfade - wie beim Unterbau oben. Installiert liegen
           die Vorlagen in einem ganz anderen Zweig als im Archiv. */
        $lb = ew_paths()['lbhome'];
        $kand = array();
        if ($lb !== '') {
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
    /* Dieselbe Wurzel wie ew_paths(); bis 0.9.12 fiel diese Zeile ohne
       LBHOMEDIR auf den festen Geraetepfad zurueck (Fall W3, vorher rot). */
    $lb = ew_paths()['lbhome'];
    $g = $lb !== '' ? $lb . '/config/system/general.json' : '';
    if ($g !== '' && is_file($g)) {
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


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function ew_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = ew_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function ew_formtoken()
{
    $grund = ew_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function ew_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(ew_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function ew_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = ew_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return ew_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return ew_t('WACHE.FALSCH');
    }
    return '';
}

/* Der Escape-Helfer gehoert in die Bibliothek, nicht in
 * index.php: sonst steht er dem Endpunkt und jedem weiteren
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2). */
function ew_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * EINMALMELDUNG NACH EINEM POST (O1)
 * ==================================================================
 * Regeln/04 "Jeder POST-Handler endet mit einer Umleitung; das Ergebnis
 * reist als Einmalmeldung". Bis 0.9.14 wurde die Seite unmittelbar nach dem
 * POST gerendert; ein Neuladen wiederholte die Handlung, und "Wortzeichen
 * neu erzeugen" wuerfelte ein zweites Mal - die eben in Loxone eingetragene
 * Adresse war sofort wieder ungueltig (Pruefer oberflaeche, Befund 1).
 * Bauform wie awm_einmal_* (AWM-Abfuhr 1.4.15): eine Datei im Datenordner,
 * 0600, gelesen NUR beim GET und dabei geloescht, aelter als 120 s
 * verworfen. Sie traegt Meldungstexte und das Ergebnis des Abruftests -
 * kein Wortzeichen.
 * ================================================================== */
function ew_einmal_datei()
{
    $p = ew_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/einmalmeldung.json' : '';
}

function ew_einmal_schreiben(array $m)
{
    $f = ew_einmal_datei();
    $m['zeit'] = time();
    return $f !== '' && ew_json_schreiben($f, $m);
}

function ew_einmal_lesen()
{
    $f = ew_einmal_datei();
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    return $d;
}

/* ==================================================================
 * EINGABEN NACH EINER BEANSTANDUNG (X-2, Verbesserungsbau 01.10.2026)
 * ==================================================================
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder
 * im Formular". Nur das Einstellungsformular, nur seine Felder, nie das
 * Wortzeichen (es steht in keiner Liste und reist deshalb nie mit).
 * ================================================================== */

/** Die Felder des Einstellungsformulars: Text und Haken. */
function ew_eingabe_felder()
{
    return array('text'  => array('primaer', 'ersatz', 'pfad', 'timeout'),
                 'haken' => array('pruefe_wert'));
}

/** Die eingetippten Werte aus $_POST fuer die Einmalmeldung, oder null. */
function ew_eingaben_sammeln(array $beanstandet)
{
    if (!$beanstandet) {
        return null;
    }
    $f = ew_eingabe_felder();
    $werte = array();
    foreach ($f['text'] as $feld) {
        if (isset($_POST[$feld]) && is_string($_POST[$feld]) && strlen($_POST[$feld]) <= 256
            && preg_match('//u', $_POST[$feld]) === 1) {
            $werte[$feld] = $_POST[$feld];
        }
    }
    foreach ($f['haken'] as $feld) {
        $werte[$feld] = empty($_POST[$feld]) ? '' : '1';
    }
    return array('form' => 'settings', 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))));
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur
 *  Text); ohne Argument: der angenommene Stand. */
function ew_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || $roh['form'] !== 'settings') {
        return $ein;
    }
    $f = ew_eingabe_felder();
    $felder = array_merge($f['text'], $f['haken']);
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            if (in_array((string) $k, $felder, true) && is_string($v)) {
                $werte[(string) $k] = $v;
            }
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && in_array($b, array_merge($felder, array('token')), true)) {
                $bean[] = $b;
            }
        }
    }
    if ($bean) {
        $ein = array('form' => 'settings', 'werte' => $werte, 'beanstandet' => $bean);
    }
    return $ein;
}

/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function ew_eingabe($feld, $gespeichert)
{
    $ein = ew_eingaben_setzen();
    if ($ein['form'] === 'settings' && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Haken: nach einer Beanstandung der abgeschickte Stand, sonst der gespeicherte. */
function ew_eingabe_an($feld, $gespeichert)
{
    $ein = ew_eingaben_setzen();
    if ($ein['form'] === 'settings' && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld] === '1';
    }
    return !empty($gespeichert);
}

/** Das beanstandete Feld wird rot umrandet (Klasse sm-beanstandet). */
function ew_markierung($feld)
{
    $ein = ew_eingaben_setzen();
    return in_array($feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/* ==================================================================
 * PRUEFZEILEN DES REITERS TEST (O5)
 * ==================================================================
 * Jede Funktion liefert array(lage, text); lage ist 'ok', 'fehl' oder
 * 'hinweis' ("nicht feststellbar" - nie als bestanden gezaehlt).
 * Bis 0.9.14 hatte der Reiter vier Zeilen; ein 500 des Endpunkts, ein
 * Formular ohne Merkmal und eine Heilung aus der Zweitschrift blieben
 * unsichtbar (Pruefer oberflaeche, Befund 8).
 * ================================================================== */

/** Der Port des LoxBerry-Webservers (general.json), sonst 80. */
function ew_webport()
{
    $p = ew_paths();
    $g = $p['lbhome'] !== '' ? $p['lbhome'] . '/config/system/general.json' : '';
    if ($g !== '' && is_file($g)) {
        $d = json_decode((string) @file_get_contents($g), true);
        $port = isset($d['Webserver']['Port']) ? (int) $d['Webserver']['Port'] : 0;
        if ($port > 0 && $port <= 65535) {
            return $port;
        }
    }
    return 80;
}

/** Antwortet der eigene Endpunkt? Ein echter Aufruf ueber 127.0.0.1 mit
 *  drei Ausgaengen (Regeln/04). ?selftest=1 fragt keine Station. */
function ew_pruef_endpunkt($token)
{
    $p = ew_paths();
    if ($p['lbhome'] === '') {
        return array('hinweis', ew_t('TEST.EP_OHNE'));
    }
    $pfad = '/plugins/' . rawurlencode($p['plugin']) . '/live.php?selftest=1'
          . ((is_string($token) && $token !== '') ? '&token=' . rawurlencode($token) : '');
    $info = null;
    $r = ew_abrufen('127.0.0.1:' . ew_webport(), $pfad, 3, $info);
    if ($r !== false) {
        if (strpos($r, 'SELFTEST;OK=1') === 0) {
            return array('ok', sprintf(ew_t('TEST.EP_OK'), ew_kurz($r, 60)));
        }
        return array('fehl', sprintf(ew_t('TEST.EP_FALSCH'), 200, ew_kurz($r, 80)));
    }
    if ($info['code'] > 0) {
        return array('fehl', sprintf(ew_t('TEST.EP_FALSCH'), $info['code'], ew_kurz($info['rumpf'], 80)));
    }
    return array('hinweis', sprintf(ew_t('TEST.EP_KEINE'), ew_abruf_text($info)));
}

/** b1: Pruefzeile "Timeout der Loxone-Behaelter" aus ew_projekt_pruefen().
 *  Ohne abgelegte Projektdatei: nicht feststellbar, mit dem Mindestwert. */
function ew_pruef_behaelter(array $e)
{
    $ordner = ew_projekt_ordner();
    switch ($e['art']) {
    case 'keine':
        return array('hinweis', sprintf(ew_t('TEST.PROJ_KEINE'), (int) $e['mindest'], $ordner));
    case 'gross':
        return array('hinweis', sprintf(ew_t('TEST.PROJ_GROSS'), $e['datei'], EW_PROJEKT_MAX));
    case 'unlesbar':
        return array('hinweis', sprintf(ew_t('TEST.PROJ_UNLESBAR'), $e['datei']));
    case 'ohne':
        return array('hinweis', sprintf(ew_t('TEST.PROJ_OHNE'), $e['datei'],
            '/plugins/' . ew_paths()['plugin'] . '/live.php', (int) $e['mindest']));
    }
    $zu = array();
    $ohne = array();
    foreach ($e['behaelter'] as $x) {
        if ($x['urteil'] === 'fehl') {
            $zu[] = $x['titel'] . ' ' . (int) $x['timeout'] . ' ms';
        } elseif ($x['urteil'] === 'hinweis') {
            $ohne[] = $x['titel'];
        }
    }
    $n = count($e['behaelter']);
    if ($zu) {
        return array('fehl', sprintf(ew_t('TEST.PROJ_FEHL'), count($zu), $n, (int) $e['mindest'],
            implode('; ', $zu), $e['datei']));
    }
    if ($ohne) {
        return array('hinweis', sprintf(ew_t('TEST.PROJ_OHNE_TIMEOUT'), implode('; ', $ohne),
            (int) $e['mindest'], $e['datei']));
    }
    return array('ok', sprintf(ew_t('TEST.PROJ_OK'), $n, (int) $e['mindest'], $e['datei']));
}

/** Tragen alle POST-Formulare der Oberflaeche das Formularmerkmal? Gezaehlt
 *  im Quelltext der Oberflaeche; eine leere Menge ist ein Kreuz. */
function ew_pruef_formulare($quelle)
{
    $n = 0;
    $ohne = 0;
    foreach (preg_split('/<form\b/i', (string) $quelle) as $i => $teil) {
        if ($i === 0) {
            continue;
        }
        $ende = stripos($teil, '</form>');
        $block = $ende === false ? $teil : substr($teil, 0, $ende);
        if (!preg_match('/^[^>]*method="post"/i', $block)) {
            continue;
        }
        $n++;
        if (strpos($block, 'ew_fmt()') === false) {
            $ohne++;
        }
    }
    if ($n === 0) {
        return array('fehl', ew_t('TEST.FORM_LEER'));
    }
    if ($ohne > 0) {
        return array('fehl', sprintf(ew_t('TEST.FORM_FEHL'), $ohne, $n));
    }
    return array('ok', sprintf(ew_t('TEST.FORM_OK'), $n));
}

/** Ist die Konfiguration heil? Aus dem Zustand, den ew_config() VOR der
 *  Heilung vorfand; eine liegende .kaputt-Datei wird genannt. */
function ew_pruef_konfiguration($z)
{
    $p = ew_paths();
    $k = $p['cfgdatei'] !== '' ? $p['cfgdatei'] . '.kaputt' : '';
    $kd = ($k !== '' && is_file($k)) ? ' ' . sprintf(ew_t('TEST.KONF_KAPUTTDATEI'), $k) : '';
    switch ($z) {
    case 'ok':
        return array($kd === '' ? 'ok' : 'hinweis', ew_t('TEST.KONF_OK') . $kd);
    case 'fehlt':
        return array('hinweis', ew_t('TEST.KONF_FEHLT') . $kd);
    case 'leer':
        return array('hinweis', ew_t('TEST.KONF_LEER') . $kd);
    case 'ohne_token':
        return array('hinweis', ew_t('TEST.KONF_OHNE_TOKEN') . $kd);
    case 'zweitschrift':
        return array('fehl', ew_t('TEST.KONF_ZWEITSCHRIFT') . $kd);
    case 'kaputt_zweitschrift':
        return array('fehl', ew_t('TEST.KONF_KAPUTT_ZWEITSCHRIFT') . $kd);
    case 'kaputt':
        return array('fehl', ew_t('TEST.KONF_KAPUTT') . $kd);
    }
    return array('hinweis', ew_t('TEST.KONF_UNBEKANNT'));
}

/** Ein Alter in Worten ("vor 3 Tagen"). */
function ew_alter_text($sek)
{
    $sek = (int) $sek;
    if ($sek < 0) {
        return ew_t('ALTER.ZUKUNFT');
    }
    if ($sek < 120) {
        return sprintf(ew_t('ALTER.S'), $sek);
    }
    if ($sek < 7200) {
        return sprintf(ew_t('ALTER.MIN'), (int) floor($sek / 60));
    }
    if ($sek < 172800) {
        return sprintf(ew_t('ALTER.H'), (int) floor($sek / 3600));
    }
    return sprintf(ew_t('ALTER.T'), (int) floor($sek / 86400));
}

/** Suchtext fuer ein Feld der Statuszeile (Hausform, CLAUDE.md 9). */
function ew_suchtext($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/* ==================================================================
 * MQTT (Gruppe 3, 0.9.25)
 * ==================================================================
 * Musterprojekt/entwurf_gruppe3_ew.txt (Abschnitte 2 und 12) und
 * ENTSCHEIDUNGEN_GRUPPE3_MQTT.md (Hausherr 19.10.2026): die Weiche bekommt
 * MQTT. Ein Minutentakt (cron/cron.01min -> bin/ew_takt.php) ruft DIESELBE
 * Weiche wie live.php (ew_weiche, ew_stand_schreiben, ew_alter), zieht aus
 * der Antwort nur eine feste Liste von Zahlen und gibt sie als
 * "publish <praefix>/<thema> <zahl>" an den UDP-Eingang des MQTT-Gateways -
 * nie retained, nie die Rohzeile, nie ein Text. Damit kann kein
 * Stationsschluessel in ein Thema geraten: jede Nutzlast ist eine Zahl.
 * Praefix ab Werk "ecowittweiche", NICHT "ecowitt": das Rohthema der Station
 * heisst ecowitt/<Kennung> und traegt den Stationsschluessel; ein Abo
 * ecowitt/# fischte es mit (E2).
 * live.php und die HTTP-Vorlagen bleiben unveraendert (E16).
 * ================================================================== */

/* Werte gelten als eingefroren, wenn ew_alter() mindestens so viele
   Sekunden zaehlt (dieselbe Schwelle wie die bisherige Musterseite, E6). */
define('EW_VERALTET', 900);
/* Schranke gegen den Doppelstart: ein Lauf vor weniger als so vielen
   Sekunden beendet den neuen Lauf (Regeln/03: max(30, 60 - 30)). */
define('EW_TAKT_SCHRANKE', 30);
/* Alle so viele Sekunden geht der volle Satz hinaus, dazwischen nur
   Aenderungen (E8: nach einem Miniserver-Neustart ist die
   Aussentemperatur vor Ablauf der Frostverzoegerung 600 s wieder da). */
define('EW_VOLLVERSAND', 300);
/* Eine Upgrade-Marke, die juenger ist, haelt den Takt an (Regeln/06). */
define('EW_MARKE_FRIST', 3600);
/* Frage 74 (Entscheidung des Hausherrn 18.11.2026): die Marke gilt nach der Hausregel - Inhalt Unixzeit
   (preupgrade.sh schreibt sie seither so), nicht aus der Zukunft, juenger als EW_MARKE_FRIST. Bis zur
   Vorfassung zaehlte die Aenderungszeit mit abs(), eine Marke aus der Zukunft galt also. */
function ew_marke_frisch($marke)
{
    clearstatcache(true, $marke);
    if (!is_file($marke)) {
        return false;
    }
    $t = trim((string) @file_get_contents($marke));
    if (!preg_match('/^[0-9]{1,12}$/', $t)) {
        return false;
    }
    $alter = time() - (int) $t;
    return $alter >= 0 && $alter < EW_MARKE_FRIST;
}
/* Ein Lauf, der laenger zurueckliegt, ist im Reiter Test ein Befund. */
define('EW_TAKT_ALT', 180);
define('EW_MQTT_PRAEFIX_VORGABE', 'ecowittweiche');

/** Praefix: Kleinbuchstaben, Ziffern, _, hoechstens vier Ebenen - und nie unter "ecowitt": dort sendet die Station
 *  selbst ihre Rohzeile mit dem Stationsschluessel, und ein Abo <praefix>/# (Gateway V1, Abo-Datei) holte sie mit
 *  nach Loxone (E2; der Schluessel darf in keinem Thema stehen). */
function ew_mqtt_praefix_taugt($p)
{
    return is_string($p) && preg_match('#^[a-z0-9_]+(/[a-z0-9_]+){0,3}\z#', $p) === 1
        && preg_match('#^ecowitt(/|\z)#', $p) !== 1;
}

/** Das gueltige Praefix aus der Konfiguration, sonst die Vorgabe. */
function ew_mqtt_praefix($c = null)
{
    $p = (is_array($c) && isset($c['mqtt_praefix'])) ? $c['mqtt_praefix'] : EW_MQTT_PRAEFIX_VORGABE;
    return ew_mqtt_praefix_taugt($p) ? $p : EW_MQTT_PRAEFIX_VORGABE;
}

/** Der Name, den das MQTT-Gateway (V1 und V2) aus dem Thema macht: "/",
 *  Leerzeichen und "%" werden "_" (Regeln/07). EINE Funktion fuer
 *  Namenstabelle, Hakenliste, Baustein-Liste und Selbstpruefung. */
function ew_mqtt_gateway_name($thema, $c = null)
{
    return str_replace(array('/', ' ', '%'), '_', ew_mqtt_praefix($c) . '/' . $thema);
}

/**
 * Die Themen des Minutentakts - EINE Liste fuer Takt, Reiter MQTT
 * (Themenliste), Hakenliste fuer Gateway V2, Namenstabelle und
 * Selbstpruefung (Regeln/07). Je Eintrag:
 *   thema, name (Gateway), klasse (kern | wahl | batterie | batterie_wahl |
 *   weitere), quelle (array: art und Feld), fehlwert (null = keiner),
 *   einheit (Anzeige des Virtuellen Eingangs), min, max, bedeutung
 *   (Sprachschluessel), seite (Musterprojekt-Seite 1..3, 0 = keine).
 * kern: wird immer gesendet; fehlt das Feld oder traegt es keine Zahl, geht
 * der Fehlwert hinaus (-1, -999 bei Temperaturen - E4).
 * wahl, batterie, batterie_wahl: nur, wenn die Station das Feld liefert;
 * steht es da, aber ohne Zahl, der Fehlwert; eine Batterie 9 heisst "nicht
 * angemeldet" und wird nicht gesendet (E20).
 * weitere: status/ts, in Loxone nicht gebraucht.
 */
function ew_mqtt_namen($c = null)
{
    $g = "\u{00B0}";
    $roh = array(
        array('status/ok', 'kern', array('weiche', 'ok'), null, '<v.0>', 0, 1, 1),
        array('status/zaehler', 'kern', array('weiche', 'zaehler'), null, '<v.0>', 0, 999, 1),
        array('status/ts', 'weitere', array('weiche', 'ts'), null, '<v.0> s', 0, 2147483647, 0),
        array('quelle', 'kern', array('weiche', 'quelle'), null, '<v.0>', 0, 2, 1),
        array('wechsel', 'kern', array('weiche', 'wechsel'), null, '<v.0>', 0, 100000, 1),
        array('aussentemperatur', 'kern', array('id', '0x02'), -999, '<v.1> ' . $g . 'C', -999, 70, 2),
        array('aussenfeuchte', 'kern', array('id', '0x07'), -1, '<v.0> %', -1, 100, 2),
        array('taupunkt', 'kern', array('id', '0x03'), -999, '<v.1> ' . $g . 'C', -999, 50, 2),
        array('windrichtung', 'kern', array('id', '0x0A'), -1, '<v.0>' . $g, -1, 360, 2),
        array('wind', 'kern', array('id', '0x0B'), -1, '<v.1> km/h', -1, 300, 2),
        array('boee', 'kern', array('id', '0x0C'), -1, '<v.1> km/h', -1, 300, 2),
        array('boee_max_heute', 'kern', array('id', '0x19'), -1, '<v.1> km/h', -1, 300, 2),
        array('solar', 'kern', array('id', '0x15'), -1, "<v.0> W/m\u{00B2}", -1, 2000, 2),
        array('uv', 'kern', array('id', '0x17'), -1, '<v.0>', -1, 20, 2),
        array('regenrate', 'kern', array('feld', '0x0E'), -1, '<v.1> mm/h', -1, 500, 2),
        array('regen_ereignis', 'kern', array('feld', '0x0D'), -1, '<v.1> mm', -1, 1000, 2),
        array('regen_heute', 'kern', array('feld', '0x10'), -1, '<v.1> mm', -1, 1000, 2),
        array('regen_woche', 'kern', array('feld', '0x11'), -1, '<v.1> mm', -1, 2000, 2),
        array('regen_monat', 'kern', array('feld', '0x12'), -1, '<v.1> mm', -1, 5000, 2),
        array('regen_jahr', 'kern', array('feld', '0x13'), -1, '<v.0> mm', -1, 10000, 2),
        array('innentemperatur', 'kern', array('feld', 'intemp'), -999, '<v.1> ' . $g . 'C', -999, 60, 2),
        array('innenfeuchte', 'kern', array('feld', 'inhumi'), -1, '<v.0> %', -1, 100, 2),
        array('luftdruck_rel', 'kern', array('feld', 'rel'), -1, '<v.1> hPa', -1, 1200, 2),
        array('luftdruck_abs', 'kern', array('feld', 'abs'), -1, '<v.1> hPa', -1, 1200, 2),
        array('blitz_entfernung', 'kern', array('feld', 'distance'), -1, '<v.0> km', -1, 100, 2),
        array('blitz_anzahl', 'kern', array('feld', 'count'), -1, '<v.0>', -1, 100000, 2),
        array('gefuehlt', 'wahl', array('id', '3'), -999, '<v.1> ' . $g . 'C', -999, 70, 2),
        array('vpd', 'wahl', array('id', '5'), -1, '<v.2> kPa', -1, 20, 2),
        array('laufzeit', 'wahl', array('feld', 'runtime'), -1, '<v.0> s', -1, 2147483647, 2),
        array('regnet', 'wahl', array('feld', 'srain_piezo'), -1, '<v.0>', -1, 1, 2),
        array('windrichtung_10min', 'wahl', array('id', '0x6D'), -1, '<v.0>' . $g, -1, 360, 2),
        array('batterie_ws90', 'batterie', array('block', 'piezoRain', 'battery'), -1, '<v.0>', -1, 5, 3),
        array('spannung_ws90', 'batterie', array('block', 'piezoRain', 'voltage'), -1, '<v.2> V', -1, 10, 3),
        array('kondensator_ws90', 'batterie', array('block', 'piezoRain', 'ws90cap_volt'), -1, '<v.2> V', -1, 10, 3),
        array('batterie_wh40', 'batterie', array('block', 'rain', 'battery'), -1, '<v.0>', -1, 5, 3),
        array('spannung_wh40', 'batterie', array('block', 'rain', 'voltage'), -1, '<v.2> V', -1, 10, 3),
        array('batterie_wh57', 'batterie', array('block', 'lightning', 'battery'), -1, '<v.0>', -1, 5, 3),
        array('batterie_wh25', 'batterie', array('block', 'wh25', 'battery'), -1, '<v.0>', -1, 1, 3),
        array('batterie_ec1', 'batterie_wahl', array('kanal', 'ch_ec', 1, 'battery'), -1, '<v.0>', -1, 5, 0),
        array('spannung_ec1', 'batterie_wahl', array('kanal', 'ch_ec', 1, 'voltage'), -1, '<v.2> V', -1, 10, 0),
        array('batterie_ec2', 'batterie_wahl', array('kanal', 'ch_ec', 2, 'battery'), -1, '<v.0>', -1, 5, 0),
        array('spannung_ec2', 'batterie_wahl', array('kanal', 'ch_ec', 2, 'voltage'), -1, '<v.2> V', -1, 10, 0),
    );
    $aus = array();
    foreach ($roh as $r) {
        $aus[] = array('thema' => $r[0], 'name' => ew_mqtt_gateway_name($r[0], $c), 'klasse' => $r[1],
                       'quelle' => $r[2], 'fehlwert' => $r[3], 'einheit' => $r[4], 'min' => $r[5],
                       'max' => $r[6], 'seite' => $r[7],
                       'bedeutung' => 'MQTT.T_' . strtoupper(str_replace('/', '_', $r[0])));
    }
    return $aus;
}

/**
 * Ein Feld in der Stationsantwort suchen, in DOKUMENTREIHENFOLGE - dieselbe
 * Regel, nach der Loxones Suchtext die erste Fundstelle nimmt (etwa "0x0E" in
 * rain vor piezoRain). $nur_id: nur ein Objekt mit "id" = $name zaehlt (sein
 * "val"); sonst auch ein Schluessel $name. Rueckgabe: der Wert als
 * Zeichenkette, '' fuer einen Wert ohne Text, null = nicht gefunden.
 */
function ew_mqtt_suchen($knoten, $name, $nur_id)
{
    if (!is_array($knoten)) {
        return null;
    }
    foreach ($knoten as $k => $v) {
        if (!$nur_id && (string) $k === $name && !is_array($v)) {
            return $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }
        if ((string) $k === 'id' && !is_array($v) && (string) $v === $name) {
            $val = isset($knoten['val']) ? $knoten['val'] : null;
            return (is_array($val) || $val === null) ? '' : (string) $val;
        }
        if (is_array($v)) {
            $r = ew_mqtt_suchen($v, $name, $nur_id);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return null;
}

/** Ein Schluessel innerhalb eines Blocks (Liste oder Objekt), erste
 *  Fundstelle; null = nicht da. */
function ew_mqtt_im_block($block, $feld)
{
    if (!is_array($block)) {
        return null;
    }
    foreach ($block as $k => $v) {
        if ((string) $k === $feld && !is_array($v)) {
            return $v === null ? '' : (string) $v;
        }
        if (is_array($v)) {
            $r = ew_mqtt_im_block($v, $feld);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return null;
}

/** Ein Feld eines Kanals in einem Kanalblock (ch_ec). Traegt ein Element
 *  das Feld "channel", zaehlt es; traegt keines eines, gilt die Reihenfolge
 *  im Block (Entwurf 12.2). */
function ew_mqtt_kanal($block, $nr, $feld)
{
    if (!is_array($block)) {
        return null;
    }
    $mit = false;
    foreach ($block as $e) {
        if (is_array($e) && array_key_exists('channel', $e)) {
            $mit = true;
            if ((string) $e['channel'] === (string) $nr) {
                return (isset($e[$feld]) && !is_array($e[$feld])) ? (string) $e[$feld] : (array_key_exists($feld, $e) ? '' : null);
            }
        }
    }
    if ($mit) {
        return null;
    }
    $liste = array_values($block);
    $e = isset($liste[$nr - 1]) ? $liste[$nr - 1] : null;
    if (!is_array($e) || !array_key_exists($feld, $e)) {
        return null;
    }
    return is_array($e[$feld]) ? '' : (string) $e[$feld];
}

/** Die Zahl am Anfang einer Zeichenkette, unveraendert (keine
 *  Umformatierung, wie live.php); null = keine Zahl ("--", "---.-"). */
function ew_mqtt_zahl($s)
{
    if (!is_string($s) || !preg_match('/^\s*(-?[0-9]+(?:\.[0-9]+)?)/', $s, $m)) {
        return null;
    }
    return $m[1];
}

/**
 * Die Werte einer Stationsantwort fuer MQTT. Rueckgabe array(
 *   'werte'     => thema => Zahl als Zeichenkette (nur Messwerte),
 *   'gefunden'  => Themen der Klassen wahl/batterie/batterie_wahl, die die
 *                  Station liefert,
 *   'einheiten' => Einheiten, die nicht metrisch sind (Hinweis, E11)).
 */
function ew_mqtt_werte($roh)
{
    $aus = array('werte' => array(), 'gefunden' => array(), 'einheiten' => array());
    $d = is_string($roh) ? json_decode($roh, true) : null;
    if (!is_array($d)) {
        return $aus;
    }
    foreach (ew_mqtt_namen() as $e) {
        $q = $e['quelle'];
        if ($q[0] === 'weiche') {
            continue;
        }
        if ($q[0] === 'id') {
            $w = ew_mqtt_suchen($d, $q[1], true);
        } elseif ($q[0] === 'feld') {
            $w = ew_mqtt_suchen($d, $q[1], false);
        } elseif ($q[0] === 'block') {
            $w = isset($d[$q[1]]) ? ew_mqtt_im_block($d[$q[1]], $q[2]) : null;
        } else {
            $w = isset($d[$q[1]]) ? ew_mqtt_kanal($d[$q[1]], $q[2], $q[3]) : null;
        }
        $zahl = ew_mqtt_zahl($w);
        if ($e['klasse'] !== 'kern') {
            if ($w === null) {
                continue;
            }
            /* batt = 9 heisst "nicht angemeldet" (Messung 19.10.2026) */
            if (($q[0] === 'block' || $q[0] === 'kanal') && end($q) === 'battery' && $zahl === '9') {
                continue;
            }
            $aus['gefunden'][] = $e['thema'];
        }
        $aus['werte'][$e['thema']] = $zahl !== null ? $zahl : (string) $e['fehlwert'];
    }
    $aus['einheiten'] = ew_mqtt_einheiten($d);
    return $aus;
}

/** Einheiten der Stationsantwort, die nicht Grad C, km/h, mm, mm/h bzw. hPa
 *  sind - nur fuer einen Hinweis im Reiter Test; umgerechnet wird nichts (E11). */
function ew_mqtt_einheiten(array $d)
{
    $fremd = array();
    $soll = array('temp' => array('C'), 'wind' => array('km/h'), 'regen' => array('mm', 'mm/Hr', 'mm/h'),
                  'druck' => array('hPa'));
    $art = array('0x02' => 'temp', '0x03' => 'temp', '3' => 'temp', '0x0B' => 'wind', '0x0C' => 'wind',
                 '0x19' => 'wind', '0x0D' => 'regen', '0x0E' => 'regen', '0x10' => 'regen', '0x11' => 'regen',
                 '0x12' => 'regen', '0x13' => 'regen');
    $sammeln = function ($knoten) use (&$sammeln, &$fremd, $soll, $art) {
        if (!is_array($knoten)) {
            return;
        }
        if (isset($knoten['id']) && !is_array($knoten['id']) && isset($art[(string) $knoten['id']])) {
            $a = $art[(string) $knoten['id']];
            $e = '';
            if ($a === 'temp') {
                $e = (isset($knoten['unit']) && is_string($knoten['unit'])) ? trim($knoten['unit']) : '';
            } elseif (isset($knoten['val']) && is_string($knoten['val'])
                      && preg_match('/^\s*-?[0-9.]+\s*(\S.*)$/', $knoten['val'], $m)) {
                $e = trim($m[1]);
            }
            if ($e !== '' && !in_array($e, $soll[$a], true)) {
                $fremd[$e] = true;
            }
        }
        foreach (array('rel', 'abs') as $k) {
            if (isset($knoten[$k]) && is_string($knoten[$k])
                && preg_match('/^\s*-?[0-9.]+\s*(\S.*)$/', $knoten[$k], $m) && trim($m[1]) !== 'hPa') {
                $fremd[trim($m[1])] = true;
            }
        }
        foreach ($knoten as $v) {
            if (is_array($v)) {
                $sammeln($v);
            }
        }
    };
    $sammeln($d);
    $l = array_keys($fremd);
    sort($l);
    return array_map('strval', $l);
}

/**
 * Ein Loxone-Suchtext (Befehlserkennung) auf eine Antwort angewandt, wie der
 * Miniserver ihn liest: Text wird der Reihe nach gesucht, \i trennt, \v liest
 * die Zahl an der Stelle. Rueckgabe die Zahl als Zeichenkette, null = keine
 * (Loxone macht daraus 0). Nur fuer die Selbstpruefung "MQTT = HTTP".
 */
function ew_suchtext_wert($roh, $such)
{
    if (!is_string($roh) || !is_string($such)) {
        return null;
    }
    $p = 0;
    foreach (preg_split('/(\\\\[iv])/', $such, -1, PREG_SPLIT_DELIM_CAPTURE) as $t) {
        if ($t === '' || $t === '\i') {
            continue;
        }
        if ($t === '\v') {
            return preg_match('/\G\s*(-?[0-9]+(?:\.[0-9]+)?)/', $roh, $m, 0, $p) ? $m[1] : null;
        }
        $q = strpos($roh, $t, $p);
        if ($q === false) {
            return null;
        }
        $p = $q + strlen($t);
    }
    return null;
}

/**
 * Selbstpruefung "MQTT = HTTP" (E18): je Kernfeld der Stationsvorlage den
 * Suchtext aus ew_wetter_befehle() auf dieselbe Antwort anwenden und mit dem
 * MQTT-Wert vergleichen. Kein Zahlwert ueber HTTP gilt als gleich, wenn MQTT
 * den Fehlwert sendet. $befehle nur fuer die Eichung (vertauschtes Feld).
 * Rueckgabe array('geprueft' => n, 'abweichend' => Themen).
 */
function ew_mqtt_http_vergleich($roh, $werte = null, $befehle = null)
{
    if (!is_array($werte)) {
        $werte = ew_mqtt_werte($roh);
        $werte = $werte['werte'];
    }
    if (!is_array($befehle)) {
        $befehle = ew_wetter_befehle();
    }
    $kern = array();
    foreach (ew_mqtt_namen() as $e) {
        if ($e['klasse'] === 'kern' && $e['quelle'][0] !== 'weiche') {
            $kern[] = $e;
        }
    }
    $ab = array();
    foreach ($kern as $i => $e) {
        if (!isset($befehle[$i])) {
            $ab[] = $e['thema'];
            continue;
        }
        $http = ew_suchtext_wert($roh, $befehle[$i][1]);
        $mq = isset($werte[$e['thema']]) ? (string) $werte[$e['thema']] : null;
        $gleich = ($http === null) ? ($mq === (string) $e['fehlwert'])
                                   : ($mq !== null && (float) $mq === (float) $http);
        if (!$gleich) {
            $ab[] = $e['thema'];
        }
    }
    return array('geprueft' => count($kern), 'abweichend' => $ab);
}

/** Eine nachgebildete Stationsantwort mit allen Feldern, die das Plugin
 *  liest (Aufbau wie get_livedata_info, Werte erfunden). Nur fuer die
 *  Selbstpruefung im Reiter Test; der Takt fragt immer die Station. */
function ew_mqtt_probeantwort()
{
    return '{"common_list":[{"id":"0x02","val":"12.3","unit":"C"},{"id":"0x07","val":"78%"},'
        . '{"id":"3","val":"11.8","unit":"C"},{"id":"0x03","val":"8.6","unit":"C"},'
        . '{"id":"5","val":"0.317","unit":"kPa"},{"id":"0x0B","val":"4.3 km/h"},{"id":"0x0C","val":"7.2 km/h"},'
        . '{"id":"0x19","val":"25.2 km/h"},{"id":"0x15","val":"123.45 W/m2"},{"id":"0x17","val":"1"},'
        . '{"id":"0x0A","val":"215"},{"id":"0x6D","val":"220"}],'
        . '"rain":[{"id":"0x0D","val":"0.8 mm"},{"id":"0x0E","val":"1.2 mm/Hr"},{"id":"0x10","val":"0.4 mm"},'
        . '{"id":"0x11","val":"3.2 mm"},{"id":"0x12","val":"20.9 mm"},'
        . '{"id":"0x13","val":"195.8 mm","battery":"5","voltage":"1.5"}],'
        . '"piezoRain":[{"id":"srain_piezo","val":"0"},{"id":"0x0D","val":"0.0 mm"},{"id":"0x0E","val":"0.0 mm/Hr"},'
        . '{"id":"0x13","val":"207.8 mm","battery":"5","voltage":"3.02","ws90cap_volt":"4.5","ws90_ver":"162"}],'
        . '"lightning":[{"distance":"12 km","timestamp":"10/19/2026 10:00:00","count":"3","battery":"5"}],'
        . '"wh25":[{"intemp":"21.4","unit":"C","inhumi":"52%","abs":"985.3 hPa","rel":"1013.6 hPa","battery":"0"}],'
        . '"ch_ec":[{"channel":"1","name":"","battery":"5","voltage":"1.50"},'
        . '{"channel":"2","name":"","battery":"5","voltage":"1.56"}],'
        . '"debug":[{"heap":"110000","runtime":"86400","usr_interval":"60"}]}';
}

/** Das MQTT-Gateway laut general.json: fassung (0 = nicht feststellbar,
 *  sonst Gatewayversion mit Rueckfall 1), autostart, udpport (Rueckfall
 *  11884). Gelesen wird nur, nie ein Kennwort. */
function ew_mqtt_gateway()
{
    $aus = array('fassung' => 0, 'autostart' => 0, 'udpport' => 11884, 'gefunden' => 0);
    $lb = ew_paths()['lbhome'];
    $g = $lb !== '' ? ew_json_lesen($lb . '/config/system/general.json') : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : null;
    if ($m === null) {
        return $aus;
    }
    $aus['gefunden'] = 1;
    $aus['fassung'] = (isset($m['Gatewayversion']) && is_numeric($m['Gatewayversion'])) ? (int) $m['Gatewayversion'] : 1;
    $aus['autostart'] = (isset($m['Gatewayautostart'])
        && in_array((string) $m['Gatewayautostart'], array('1', 'true'), true)) ? 1 : 0;
    $port = isset($m['Udpinport']) ? (int) $m['Udpinport'] : 0;
    if ($port > 0 && $port <= 65535) {
        $aus['udpport'] = $port;
    }
    return $aus;
}

/**
 * Zeilen an den UDP-Eingang des MQTT-Gateways: "publish <praefix>/<thema>
 * <zahl>", nie "retain" (Messwerte und Aussagen ueber sich selbst sind nie
 * retained, Regeln/07). fsockopen statt socket_* (steht im PHP-Kern), 5 ms
 * zwischen zwei Datagrammen (Regeln/07: der UDP-Eingang verwirft unter
 * Last). Gezaehlt wird "gesendet", nicht "uebermittelt".
 */
function ew_mqtt_senden($praefix, array $paare, $port)
{
    if (!$paare) {
        return 0;
    }
    $fp = @fsockopen('udp://127.0.0.1', (int) $port, $eno, $etxt, 2);
    if (!$fp) {
        return 0;
    }
    stream_set_timeout($fp, 2);
    $n = 0;
    $i = 0;
    foreach ($paare as $t => $w) {
        if (ew_mqtt_zahl((string) $w) !== (string) $w) {
            continue;
        }
        if ($i++ > 0) {
            usleep(5000);
        }
        $z = 'publish ' . $praefix . '/' . $t . ' ' . $w . "\n";
        if (@fwrite($fp, $z) === strlen($z)) {
            $n++;
        }
    }
    fclose($fp);
    return $n;
}

/** Ablage des Takts im Datenordner (kein Wortzeichen, keine Rohantwort). */
function ew_takt_datei()
{
    $p = ew_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/takt.json' : '';
}

function ew_takt_lesen()
{
    $d = ew_json_lesen(ew_takt_datei());
    return is_array($d) ? $d : array();
}

/** Merker "beim naechsten Lauf alles senden" (Speichern im Reiter MQTT). */
function ew_takt_voll_merken()
{
    $p = ew_paths();
    if ($p['datadir'] === '') {
        return false;
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    return @file_put_contents($p['datadir'] . '/takt.voll', (string) time()) !== false;
}

/** Die Abo-Datei fuer Gateway V1 (config/mqtt_subscriptions.cfg). */
function ew_abo_datei()
{
    $p = ew_paths();
    return $p['config'] !== '' ? $p['config'] . '/mqtt_subscriptions.cfg' : '';
}

/** Traegt die Abo-Datei das Abo <praefix>/#? Rueckgabe array(da, traegt). */
function ew_abo_pruefen($c = null)
{
    $f = ew_abo_datei();
    if ($f === '' || !is_file($f)) {
        return array(false, false);
    }
    $soll = ew_mqtt_praefix($c) . '/#';
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($f)) as $z) {
        if (trim($z) === $soll) {
            return array(true, true);
        }
    }
    return array(true, false);
}

/** Die Abo-Datei auf <praefix>/# setzen - nur, wenn sie abweicht, mit
 *  Protokollzeile (Entwurf 2.3). Rueckgabe true, wenn sie danach stimmt. */
function ew_abo_schreiben($c = null)
{
    list($da, $traegt) = ew_abo_pruefen($c);
    if ($traegt) {
        return true;
    }
    $f = ew_abo_datei();
    if ($f === '' || !is_dir(dirname($f))) {
        return false;
    }
    $tmp = $f . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, ew_mqtt_praefix($c) . "/#\n") === false || !@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    ew_log('MQTT: Abo-Datei auf ' . ew_mqtt_praefix($c) . '/# gesetzt (Gateway V1).');
    return true;
}

/**
 * EIN Lauf des Minutentakts (bin/ew_takt.php). Rueckgabe array mit 'ende'
 * (upgrade | ohne_wurzel | nicht_eingerichtet | mqtt_aus | sperre_fehlt |
 * besetzt | schranke | gelaufen); nach einem Lauf dazu der Stand aus
 * takt.json und 'paare' (die gesendeten Themen).
 *  1. Upgrade-Marke juenger als EW_MARKE_FRIST -> Ende (Regeln/06).
 *  2. ew_config(false) - liest nur, heilt und legt nichts an (wie live.php);
 *     null oder MQTT aus -> Ende, die Station wird NICHT gefragt.
 *  3. Sperre takt.lock, nicht blockierend; besetzt -> Ende (Regeln/03).
 *  4. Schranke: letzter Lauf vor weniger als EW_TAKT_SCHRANKE s -> Ende.
 *  5. Dieselben drei Aufrufe wie live.php: ew_weiche, ew_stand_schreiben,
 *     ew_alter - ein Wechselzaehler fuer HTTP und MQTT (E16).
 *  6. Lebenszeichen jede Minute; uebrige Themen bei Aenderung, alle
 *     EW_VOLLVERSAND s und nach dem Speichern im Reiter MQTT alle. Bei Ausfall
 *     oder eingefrorener Station nur das Signal (status/ok 0, quelle,
 *     wechsel), die Messwerte bleiben stehen (Regeln/07, E5).
 */
function ew_takt()
{
    $p = ew_paths();
    if ($p['datadir'] === '' || $p['lbhome'] === '') {
        return array('ende' => 'ohne_wurzel');
    }
    $marke = dirname($p['datadir']) . '/' . $p['plugin'] . '.upgrade_laeuft';
    if (ew_marke_frisch($marke)) {
        return array('ende' => 'upgrade');
    }
    $c = ew_config(false);
    if ($c === null) {
        return array('ende' => 'nicht_eingerichtet');
    }
    if (empty($c['mqtt_ein'])) {
        /* 0.9.38: ohne MQTT sendet die Weiche keine Haus-Themen - gemerkte raeumt sie trotzdem ab (alle 10 Minuten). */
        ew_haus_nachraeumen($c, time());
        return array('ende' => 'mqtt_aus');
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $sperre = @fopen($p['datadir'] . '/takt.lock', 'c');
    if ($sperre === false) {
        return array('ende' => 'sperre_fehlt');
    }
    if (!flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        return array('ende' => 'besetzt');
    }
    $alt = ew_takt_lesen();
    $jetzt = time();
    $vorher = isset($alt['ts']) ? (int) $alt['ts'] : 0;
    if ($vorher > 0 && $jetzt - $vorher >= 0 && $jetzt - $vorher < EW_TAKT_SCHRANKE) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array('ende' => 'schranke');
    }
    $w = ew_weiche($c, ew_stand_lesen());
    $stand = ew_stand_schreiben($w);
    $alter = ew_alter($stand);
    $ok = $w['ok'] && $alter >= 0 && $alter < EW_VERALTET;
    if ((string) $c['primaer'] !== '' || (string) $c['ersatz'] !== '') { ew_ansage_flanke('ausfall', !$ok, '', 300, $jetzt); }     // nur eingerichtet; der erste Lauf schweigt
    $q = ($w['quelle'] === 'primaer') ? 1 : (($w['quelle'] === 'ersatz') ? 2 : 0);
    $zaehler = isset($alt['zaehler']) ? ((int) $alt['zaehler'] + 1) % 1000 : 0;
    $praefix = ew_mqtt_praefix($c);
    $vollmerk = $p['datadir'] . '/takt.voll';
    $voll_ts = isset($alt['voll_ts']) ? (int) $alt['voll_ts'] : 0;
    $voll = $voll_ts <= 0 || $jetzt - $voll_ts >= EW_VOLLVERSAND || $jetzt < $voll_ts || is_file($vollmerk)
         || !isset($alt['praefix']) || $alt['praefix'] !== $praefix;
    $letzte = (isset($alt['letzte']) && is_array($alt['letzte'])) ? $alt['letzte'] : array();
    $neu = array('quelle' => (string) $q, 'wechsel' => (string) (int) $stand['wechsel']);
    $takt = array('felder' => isset($alt['felder']) && is_array($alt['felder']) ? $alt['felder'] : array(),
                  'einheiten' => isset($alt['einheiten']) && is_array($alt['einheiten']) ? $alt['einheiten'] : array(),
                  'vergleich' => isset($alt['vergleich']) && is_array($alt['vergleich']) ? $alt['vergleich'] : null);
    if ($ok) {
        $mw = ew_mqtt_werte($w['roh']);
        $neu = $neu + $mw['werte'];
        $takt['felder'] = $mw['gefunden'];
        $takt['einheiten'] = $mw['einheiten'];
        $takt['vergleich'] = ew_mqtt_http_vergleich($w['roh'], $mw['werte']);
    }
    $paare = array('status/ok' => $ok ? '1' : '0', 'status/ts' => (string) $jetzt, 'status/zaehler' => (string) $zaehler);
    foreach ($neu as $t => $v) {
        if ($voll || !array_key_exists($t, $letzte) || (string) $letzte[$t] !== (string) $v) {
            $paare[$t] = (string) $v;
        }
    }
    $gw = ew_mqtt_gateway();
    $n = ew_mqtt_senden($praefix, $paare, $gw['udpport']);
    foreach ($paare as $t => $v) {
        if (strpos($t, 'status/') !== 0) {
            $letzte[$t] = $v;
        }
    }
    if (is_file($vollmerk)) {
        @unlink($vollmerk);
    }
    $log_ts = isset($alt['log_versand_ts']) ? (int) $alt['log_versand_ts'] : 0;
    if ($n < count($paare) && ($jetzt - $log_ts >= 3600 || $jetzt < $log_ts)) {
        ew_log('MQTT: Versand gescheitert - ' . $n . ' von ' . count($paare) . ' Zeilen an den UDP-Eingang '
             . (int) $gw['udpport'] . ' gegeben (diese Meldung hoechstens stuendlich).');
        $log_ts = $jetzt;
    }
    ew_abo_schreiben($c);
    /* 0.9.38: Haus-Themen haus/wetter und haus/klima (ab Werk aus) - dieselbe Antwort, derselbe Zaehler. */
    ew_haus_lauf($c, $ok ? $w['roh'] : null, $alter, $zaehler, $jetzt);
    $takt = array('ts' => $jetzt, 'zaehler' => $zaehler, 'ok' => $ok ? 1 : 0, 'quelle' => $q,
                  'themen' => count($paare), 'gesendet' => $n, 'vollversand' => $voll ? 1 : 0,
                  'voll_ts' => $voll ? $jetzt : $voll_ts, 'praefix' => $praefix, 'udpport' => (int) $gw['udpport'],
                  'log_versand_ts' => $log_ts, 'letzte' => $letzte) + $takt;
    ew_json_schreiben(ew_takt_datei(), $takt);
    flock($sperre, LOCK_UN);
    fclose($sperre);
    return array('ende' => 'gelaufen', 'paare' => $paare) + $takt;
}

/* ---------- Selbstpruefungen des Reiters Test (Entwurf 2.9) ---------- */

/** (1) Jeder Name der Namenstabelle ist ein Thema, das der Takt sendet -
 *  Gegenprobe mit der nachgebildeten Antwort. */
function ew_pruef_mqtt_namen($c = null)
{
    $mw = ew_mqtt_werte(ew_mqtt_probeantwort());
    $gesendet = array_merge(array('status/ok', 'status/ts', 'status/zaehler', 'quelle', 'wechsel'),
                            array_keys($mw['werte']));
    $namen = array();
    foreach (ew_mqtt_namen($c) as $e) {
        $namen[] = $e['thema'];
    }
    $fehlt = array_values(array_diff($namen, $gesendet));
    $mehr = array_values(array_diff($gesendet, $namen));
    if ($fehlt || $mehr) {
        return array('fehl', sprintf(ew_t('TEST.MQTT_NAMEN_FEHL'), implode(', ', array_merge($fehlt, $mehr))));
    }
    return array('ok', sprintf(ew_t('TEST.MQTT_NAMEN_OK'), count($namen)));
}

/** (2) MQTT = HTTP je Kernfeld: aus dem letzten Lauf, sonst an der
 *  nachgebildeten Antwort. */
function ew_pruef_mqtt_http()
{
    $t = ew_takt_lesen();
    if (isset($t['vergleich']['geprueft']) && isset($t['vergleich']['abweichend']) && is_array($t['vergleich']['abweichend'])) {
        $v = $t['vergleich'];
        $woher = ew_t('TEST.MQTT_HTTP_LAUF');
    } else {
        $v = ew_mqtt_http_vergleich(ew_mqtt_probeantwort());
        $woher = ew_t('TEST.MQTT_HTTP_PROBE');
    }
    if ($v['abweichend']) {
        return array('fehl', sprintf(ew_t('TEST.MQTT_HTTP_FEHL'), count($v['abweichend']), (int) $v['geprueft'],
            implode(', ', $v['abweichend']), $woher));
    }
    return array('ok', sprintf(ew_t('TEST.MQTT_HTTP_OK'), (int) $v['geprueft'], $woher));
}

/** (3) Abo-Datei traegt <praefix>/# (nur unter Gateway V1 von Belang). */
function ew_pruef_mqtt_abo($c = null)
{
    list($da, $traegt) = ew_abo_pruefen($c);
    $gw = ew_mqtt_gateway();
    $soll = ew_mqtt_praefix($c) . '/#';
    if ($traegt) {
        return array('ok', sprintf(ew_t('TEST.ABO_OK'), $soll));
    }
    if ($gw['fassung'] >= 2) {
        return array('hinweis', sprintf(ew_t('TEST.ABO_V2'), $soll));
    }
    return array('fehl', sprintf(ew_t($da ? 'TEST.ABO_FALSCH' : 'TEST.ABO_FEHLT'), $soll));
}

/**
 * Nachtwelle N3 (0.9.26): die angehakten Datenpunkte aus config/system/subscriptions.json des MQTT-Gateways V2
 * (Subscriptions[].Id je Thema, Noncached; Form wie Govee gv_mqtt_v2_haken_lage(), MGiSmart mg_v2_abos()). Rein.
 * Rueckgabe array(Thema => Noncached bool), null = keine JSON-Daten. Ohne Liste in der Datei: leer (nichts angehakt).
 */
function ew_mqtt_v2_abos($daten)
{
    if (!is_array($daten)) { return null; }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) { $liste = $daten[$s]; break; }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) { continue; }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) { $id = trim($e[$k]); break; }
        }
        if ($id === '') { continue; }
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
 * Nachtwelle N3 (0.9.26): Soll gegen Ist (Rein). Soll kommt aus ew_mqtt_namen() - derselben Liste wie die Hakenliste
 * im Reiter Einbindung, Schritt 2: die Klasse kern immer, wahl/batterie/batterie_wahl nur, wenn die Station das Thema
 * geliefert hat ($geliefert = die Themen aus takt.json 'letzte'; "wer einen Sensor nicht hat, hakt ihn nicht an"),
 * weitere (status/ts) nie. "Noncached" braucht kein Datenpunkt (Reiter Einbindung) - traegt einer es trotzdem, steht
 * er in nc_zuviel[] und ist ein Fehler (Hausform wie MGiSmart 1.1.28 mg_v2_haken_abgleich(), Vereinheitlichung
 * 29.10.2026). Rueckgabe array(ok bool, fehlt[], angehakt, soll, davon kern, nc_zuviel[]).
 */
function ew_mqtt_v2_abgleich(array $namen, array $abo, $praefix, array $geliefert)
{
    $fehlt = array();
    $zuviel = array();
    $da = 0;
    $soll = 0;
    $kern = 0;
    foreach ($namen as $e) {
        if ($e['klasse'] === 'weitere') { continue; }
        if ($e['klasse'] !== 'kern' && !in_array($e['thema'], $geliefert, true)) { continue; }
        $soll++;
        if ($e['klasse'] === 'kern') { $kern++; }
        $t = $praefix . '/' . $e['thema'];
        if (!array_key_exists($t, $abo)) { $fehlt[] = $t; continue; }
        $da++;
        if ($abo[$t]) { $zuviel[] = $t; }   // kein Datenpunkt braucht Noncached
    }
    return array(!$fehlt && !$zuviel, $fehlt, $da, $soll, $kern, $zuviel);
}

/** (3b) Nachtwelle N3 (0.9.26): unter Gateway V2 alle Datenpunkte der Hakenliste angehakt? Liest nur general.json
 *  (ew_mqtt_gateway()), takt.json und config/system/subscriptions.json. Rueckgabe array(ok|fehl|hinweis, Text);
 *  hinweis = trifft nicht zu (Gateway V1, MQTT aus) oder nicht feststellbar. */
function ew_pruef_mqtt_v2($c = null)
{
    $gw = ew_mqtt_gateway();
    if ($gw['fassung'] === 0) {
        return array('hinweis', ew_t('TEST.V2_UNKLAR'));
    }
    if ($gw['fassung'] < 2) {
        return array('hinweis', ew_t('TEST.V2_V1'));
    }
    if (!is_array($c) || empty($c['mqtt_ein'])) {
        return array('hinweis', ew_t('TEST.TAKT_AUS'));
    }
    $abo = ew_mqtt_v2_abos(ew_json_lesen(ew_paths()['lbhome'] . '/config/system/subscriptions.json'));
    if ($abo === null) {
        return array('hinweis', ew_t('TEST.V2_UNKLAR_DATEI'));
    }
    $t = ew_takt_lesen();
    $geliefert = (isset($t['letzte']) && is_array($t['letzte'])) ? array_map('strval', array_keys($t['letzte'])) : array();
    list($ok, $fehlt, $da, $soll, $kern, $zuviel) = ew_mqtt_v2_abgleich(ew_mqtt_namen($c), $abo, ew_mqtt_praefix($c), $geliefert);
    if ($soll === 0) {
        return array('hinweis', ew_t('TEST.V2_LEER'));   // leere Hakenliste: kein Urteil
    }
    if ($ok) {
        return array('ok', sprintf(ew_t('TEST.V2_OK'), $soll, $kern, $soll - $kern));
    }
    return array('fehl', sprintf(ew_t('TEST.V2_FEHLT'), $da, $soll, !$fehlt ? '-' : (count($fehlt) > 10
        ? implode(', ', array_slice($fehlt, 0, 10)) . ' … (+' . (count($fehlt) - 10) . ')' : implode(', ', $fehlt)))
        . ($zuviel ? '; ' . sprintf(ew_t('TEST.V2_NC_ZUVIEL'), implode(', ', $zuviel)) : ''));
}

/** (4) Einheiten der Station: nur ein Hinweis. */
function ew_pruef_mqtt_einheiten()
{
    $t = ew_takt_lesen();
    if (!isset($t['einheiten']) || !is_array($t['einheiten']) || empty($t['ts'])) {
        return array('hinweis', ew_t('TEST.EINHEIT_UNBEKANNT'));
    }
    if ($t['einheiten']) {
        return array('hinweis', sprintf(ew_t('TEST.EINHEIT_FREMD'), implode(', ', $t['einheiten'])));
    }
    return array('ok', ew_t('TEST.EINHEIT_OK'));
}

/** (5) Letzter Lauf des Takts juenger als EW_TAKT_ALT s, wenn MQTT an. */
function ew_pruef_mqtt_takt(array $c)
{
    if (empty($c['mqtt_ein'])) {
        return array('hinweis', ew_t('TEST.TAKT_AUS'));
    }
    $t = ew_takt_lesen();
    $ts = isset($t['ts']) ? (int) $t['ts'] : 0;
    if ($ts <= 0) {
        return array('fehl', sprintf(ew_t('TEST.TAKT_NIE'), EW_TAKT_ALT));
    }
    $a = time() - $ts;
    if ($a < 0 || $a > EW_TAKT_ALT) {
        return array('fehl', sprintf(ew_t('TEST.TAKT_ALT'), ew_alter_text($a), EW_TAKT_ALT));
    }
    return array('ok', sprintf(ew_t('TEST.TAKT_OK'), ew_alter_text($a), isset($t['zaehler']) ? (int) $t['zaehler'] : 0,
        isset($t['gesendet']) ? (int) $t['gesendet'] : 0));
}

/* ==================================================================
 * HAUS-THEMEN haus/wetter UND haus/klima (0.9.38)
 * ==================================================================
 * Entscheidung des Hausherrn 21.11.2026 ("Wir haben doch die Ecowitt Wetterstation mit echten Messwerten"): die
 * Weiche gibt ihre Messwerte anderen Plugins unter haus/wetter/<name>/... (Aussenfuehler) und haus/klima/<name>/...
 * (Innenfuehler der Basis), Regeln/07 Nr. 15. Die Form ist die von Zigbee2MqttNG 4.2.5 (bin/zigbee2mqttng_extension.mjs:
 * Felder mit Rundung und Plausibilitaet, Namensregel, "ohne Aussage" nach einer Stunde, zeit nur zusammen mit einem
 * Wert, anbieter je Name, Zaehler je Familie, fremde retained Werte werden nie ueberschrieben, Abraeumen mit
 * Ruecklesen, bin/haus-leeren.php), die Sitzung die von Spotpreis aWATTar 1.2.39 (spot_haus_sitzung(),
 * spot_haus_leeren(); MQTT 3.1.1 von Hand, kurze Sitzung direkt am Broker).
 *  - Ab Werk aus: zwei Haken im Reiter MQTT (haus_wetter_ein, haus_klima_ein); wirkt nur mit "MQTT aktivieren".
 *  - Je Fuehler der Station (aussen, basis) waehlt der Anwender Bereich (wetter, klima, aus) und Namen.
 *  - Werte, zeit und anbieter retained; haus/<familie>/zaehler/ecowittweiche nie retained (Lebenszeichen, derselbe
 *    Zaehler wie status/zaehler).
 *  - Die Haus-Themen sind fuer Plugins, nicht fuer den Miniserver: keine Hakenliste, keine Namenstabelle, nicht in
 *    der Abo-Datei des Gateways.
 *  - Gemerkt wird jedes je gesendete retained Thema (haus.json im Datenordner, uebersteht ein Update ueber
 *    preupgrade.sh/postinstall.sh); die Liste ist der Merker "war Anbieter" und die einzige Quelle beim Abraeumen.
 *    Nie haus/#, nie ein Thema, das diese Installation nicht selbst gesendet hat.
 * ================================================================== */

/** Der eigene Name in haus/<familie>/<name>/anbieter und haus/<familie>/zaehler/<anbieter> (Ordnername). */
define('EW_HAUS_ANBIETER', 'ecowittweiche');
/** Liegt die letzte brauchbare Antwort laenger zurueck (oder ist sie unbekannt), geht "ohne Aussage" hinaus. */
define('EW_HAUS_STUMM', 3600);
/** Alle so viele Sekunden geht der volle Satz hinaus (zeit bleibt damit hoechstens so alt wie dieser Abstand). */
define('EW_HAUS_VOLL', 1800);
/** Ein gescheitertes Abraeumen versucht der Minutentakt hoechstens in diesem Abstand erneut. */
define('EW_HAUS_LEEREN_ABSTAND', 600);
/** So lange wartet eine Sitzung nach dem SUBACK auf zurueckbehaltene Werte. */
define('EW_HAUS_LESEFENSTER', 1.0);

/** Die beiden Familien, die diese Linie liefern kann. */
function ew_haus_familien()
{
    return array('wetter', 'klima');
}

/** Namen, die nie ein <name> sind (Regeln/07 Nr. 15). Fuer die Pruefung einer Eingabe gilt die Vereinigung beider
 *  Familien (ew_haus_name_taugt()), damit ein Name nicht beim Wechsel des Bereichs ungueltig wird. */
function ew_haus_reserviert()
{
    return array('zaehler', 'anbieter', 'jetzt', 'heute', 'morgen', 'warnung', 'pollen', 'ok', 'datum', 'zeit', 'sprache');
}

/**
 * Die Fuehler der Station, wie die Linie sie liest (dieselben Fundstellen wie ew_mqtt_namen()):
 *   schluessel => array(Vorgabe Bereich, Vorgabe Name, Felder)
 * Feld: array(Haus-Feld, Suche (id | feld), Fundstelle, Art (z1 eine Nachkommastelle | z0 ganz), ohne Aussage,
 *             kleinster, groesster plausibler Wert, zulaessige Einheiten ('' = ohne Einheit))
 * Eine andere Einheit (Station auf Fahrenheit, m/s, inHg ...) ergibt "ohne Aussage" - umgerechnet wird nichts.
 * temperatur, feuchte, druck nach Regeln/07; die uebrigen Felder des Aussenfuehlers sind ein Vorschlag fuer Regeln/07
 * nach dem Muster dort (Einheit im Namen wie voc_ugm3; wind_kmh, boeen_kmh, regen_mm_h wie der feste Satz von
 * Weatherdata). helligkeit (lx) liefert die Station nicht - sie misst die Strahlung in W/m2 (strahlung_wm2).
 */
function ew_haus_fuehler()
{
    $grad = array('', 'C', "\u{00B0}C", "\u{2103}");
    return array(
        'aussen' => array('wetter', 'ecowitt', array(
            array('temperatur', 'id', '0x02', 'z1', -999, -60, 100, $grad),
            array('feuchte', 'id', '0x07', 'z0', -1, 0, 100, array('', '%')),
            array('druck', 'feld', 'abs', 'z1', -1, 300, 1200, array('', 'hPa')),
            array('wind_kmh', 'id', '0x0B', 'z1', -1, 0, 300, array('', 'km/h')),
            array('boeen_kmh', 'id', '0x0C', 'z1', -1, 0, 300, array('', 'km/h')),
            array('windrichtung_grad', 'id', '0x0A', 'z0', -1, 0, 360, array('', "\u{00B0}")),
            array('regen_mm_h', 'feld', '0x0E', 'z1', -1, 0, 500, array('', 'mm/Hr', 'mm/h')),
            array('regen_heute_mm', 'feld', '0x10', 'z1', -1, 0, 1000, array('', 'mm')),
            array('uv', 'id', '0x17', 'z1', -1, 0, 20, array('')),
            array('strahlung_wm2', 'id', '0x15', 'z0', -1, 0, 2000, array('', 'W/m2', "W/m\u{00B2}")),
        )),
        'basis' => array('klima', 'ecowitt_basis', array(
            array('temperatur', 'feld', 'intemp', 'z1', -999, -60, 100, $grad),
            array('feuchte', 'feld', 'inhumi', 'z0', -1, 0, 100, array('', '%')),
            array('druck', 'feld', 'abs', 'z1', -1, 300, 1200, array('', 'hPa')),
        )),
    );
}

/** Namensregel (Regeln/07 Nr. 15, wie haus_name() in Matter2Lox und hausName() in Zigbee2MqttNG): klein, Umlaute
 *  ausgeschrieben, jedes andere Zeichen ausser a-z, 0-9, _ und - wird _, hoechstens 40 Zeichen. */
function ew_haus_name($t)
{
    $t = str_replace(array("\u{00E4}", "\u{00F6}", "\u{00FC}", "\u{00DF}", "\u{00C4}", "\u{00D6}", "\u{00DC}"),
                     array('ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'), (string) $t);
    $t = preg_replace('/[^a-z0-9_-]+/', '_', strtolower($t));
    $t = trim((string) $t, '_');
    return rtrim(substr($t, 0, 40), '_');
}

/** Taugt eine Eingabe als <name>? Nur, wenn die Namensregel sie unveraendert laesst und sie nicht reserviert ist -
 *  abgewiesen wird, nicht zurechtgebogen (Regeln/05). */
function ew_haus_name_taugt($n)
{
    return is_string($n) && $n !== '' && ew_haus_name($n) === $n && !in_array($n, ew_haus_reserviert(), true);
}

/**
 * Welche Fuehler gehen unter welchem Namen hinaus? Rueckgabe array(liste, beanstandet): liste = array von
 * array(fuehler, familie, name) - nur mit MQTT, eingeschaltetem Haken der Familie und gueltigem Namen; beanstandet =
 * Fuehler, deren Bereich an ist, deren Name aber nicht taugt oder schon von einem anderen Fuehler derselben Familie
 * belegt ist (geschlossen ausfallen: dieser Fuehler sendet nicht).
 */
function ew_haus_zuordnung(array $c)
{
    $liste = array();
    $schlecht = array();
    $belegt = array();
    foreach (array_keys(ew_haus_fuehler()) as $k) {
        $b = isset($c['haus_' . $k . '_bereich']) ? (string) $c['haus_' . $k . '_bereich'] : '';
        $n = isset($c['haus_' . $k . '_name']) ? (string) $c['haus_' . $k . '_name'] : '';
        if (!in_array($b, ew_haus_familien(), true) || empty($c['haus_' . $b . '_ein']) || empty($c['mqtt_ein'])) {
            continue;
        }
        if (!ew_haus_name_taugt($n) || isset($belegt[$b . '/' . $n])) {
            $schlecht[] = $k;
            continue;
        }
        $belegt[$b . '/' . $n] = true;
        $liste[] = array('fuehler' => $k, 'familie' => $b, 'name' => $n);
    }
    return array($liste, $schlecht);
}

/** Ein Feld in der Stationsantwort, in derselben Reihenfolge wie ew_mqtt_suchen() (erste Fundstelle). Rueckgabe
 *  array(Wert als Zeichenkette, Einheit des Knotens) oder null = die Station liefert das Feld nicht. */
function ew_haus_suchen($knoten, $name, $nur_id)
{
    if (!is_array($knoten)) {
        return null;
    }
    $einheit = (isset($knoten['unit']) && is_string($knoten['unit'])) ? trim($knoten['unit']) : '';
    foreach ($knoten as $k => $v) {
        if (!$nur_id && (string) $k === $name && !is_array($v)) {
            return array($v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v), $einheit);
        }
        if ((string) $k === 'id' && !is_array($v) && (string) $v === $name) {
            $val = isset($knoten['val']) ? $knoten['val'] : null;
            return array((is_array($val) || $val === null) ? '' : (string) $val, $einheit);
        }
        if (is_array($v)) {
            $r = ew_haus_suchen($v, $name, $nur_id);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return null;
}

/** Eine Zahl fuer ein Haus-Thema: Punkt, z1 eine Nachkommastelle (eine 0 dahinter faellt weg wie in Zigbee2MqttNG),
 *  z0 ganz, nie Exponent, "-0" -> "0". */
function ew_haus_zahl($x, $art)
{
    if ($art === 'z1') {
        $s = sprintf('%.1F', round((float) $x, 1));
        if (substr($s, -2) === '.0') {
            $s = substr($s, 0, -2);
        }
    } else {
        $s = sprintf('%.0F', round((float) $x));
    }
    return ($s === '-0') ? '0' : $s;
}

/** Der Wert eines Felds aus seiner Fundstelle: null = nicht geliefert; ohne Zahl, mit fremder Einheit oder ausserhalb
 *  der Grenzen der Wert "ohne Aussage" (Regeln/07: -999 bei temperatur, sonst -1). */
function ew_haus_wert(array $f, $fund)
{
    if (!is_array($fund)) {
        return null;
    }
    $roh = (string) $fund[0];
    $z = ew_mqtt_zahl($roh);
    if ($z === null) {
        return (string) $f[4];
    }
    $e = '';
    if (preg_match('/^\s*+-?[0-9]++(?:\.[0-9]++)?+\s*+(\S.*)$/', $roh, $m)) {   // besitzergreifend: '215' ergibt keine Einheit '5'
        $e = trim($m[1]);
    } elseif ($f[0] === 'temperatur') {
        $e = (string) $fund[1];
    }
    if (!in_array($e, $f[7], true)) {
        return (string) $f[4];
    }
    $x = (float) $z;
    if ($x < $f[5] || $x > $f[6]) {
        return (string) $f[4];
    }
    return ew_haus_zahl($x, $f[3]);
}

/** Ablage im Datenordner: haus.json (die gemerkten Themen, reist beim Update mit), haus_lauf.json (letzte Sitzung,
 *  zuletzt gesendete Werte; ein Update loescht sie, dann geht der volle Satz hinaus). */
function ew_haus_datei()
{
    $p = ew_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/haus.json' : '';
}

function ew_haus_lauf_datei()
{
    $p = ew_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/haus_lauf.json' : '';
}

/** Die gemerkten Themen: Thema => Fuehler. Nur Themen unter haus/wetter/ und haus/klima/ ohne # und +. */
function ew_haus_themen_lesen()
{
    $d = ew_json_lesen(ew_haus_datei());
    $aus = array();
    if (is_array($d) && isset($d['themen']) && is_array($d['themen'])) {
        foreach ($d['themen'] as $t => $f) {
            if (is_string($t) && preg_match('#^haus/(wetter|klima)/[a-z0-9_-]{1,40}/[a-z0-9_]{1,40}\z#', $t) === 1
                && is_string($f)) {
                $aus[$t] = $f;
            }
        }
    }
    return $aus;
}

function ew_haus_themen_schreiben(array $t)
{
    ksort($t);
    return ew_json_schreiben(ew_haus_datei(), array('themen' => $t ? $t : new stdClass()));
}

function ew_haus_lauf_lesen()
{
    $d = ew_json_lesen(ew_haus_lauf_datei());
    return is_array($d) ? $d : array();
}

/** Sperre fuer haus.json/haus_lauf.json (Minutentakt, Speichern im Reiter MQTT, Zurueckspielen, Deinstallation).
 *  Rueckgabe der Griff oder null (ohne Datenordner). */
function ew_haus_sperre()
{
    $p = ew_paths();
    if ($p['datadir'] === '') {
        return null;
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $s = @fopen($p['datadir'] . '/haus.lock', 'c');
    if ($s === false) {
        return null;
    }
    if (!flock($s, LOCK_EX)) {
        fclose($s);
        return null;
    }
    return $s;
}

function ew_haus_freigeben($s)
{
    if (is_resource($s)) {
        flock($s, LOCK_UN);
        fclose($s);
    }
}

/**
 * Eine kurze Sitzung am Broker (Abschrift von spot_haus_sitzung(), Spotpreis aWATTar 1.2.39): CONNECT, SUBSCRIBE der
 * genauen Themen in $lesen (QoS 0, SUBACK geprueft), EW_HAUS_LESEFENSTER s zurueckbehaltene Werte sammeln, dann
 * PUBLISH QoS 0 - $senden ist Thema => array(Wert, retain); eine leere Nutzlast nur mit retain (Abraeumen). Ist
 * $entscheiden ein Aufruf, bekommt er die gelesenen Werte (Thema => Wert) und gibt die Liste zurueck, die tatsaechlich
 * gesendet wird (Pruefung auf fremde Werte VOR dem Senden).
 * Rueckgabe array('lage' => ok|kein_broker|abgelehnt|zeit, 'werte' => Thema => Wert (nur Retain-Bit), 'gesendet' => n,
 * 'themen' => gesendete Themen). Zugang aus general.json (Mqtt.Brokerhost/-port/-user/-pass); das Kennwort steht nur
 * im CONNECT-Paket, nie in einem Protokoll und nie auf einer Befehlszeile (Regeln/05).
 */
function ew_haus_sitzung(array $senden, array $lesen, $entscheiden = null)
{
    $aus = array('lage' => 'kein_broker', 'werte' => array(), 'gesendet' => 0, 'themen' => array());
    $p = ew_paths();
    $gen = $p['lbhome'] !== '' ? ew_json_lesen($p['lbhome'] . '/config/system/general.json') : null;
    $m = array();
    if (is_array($gen) && isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    }
    if (!$m) {
        return $aus;
    }
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') {
        $host = '127.0.0.1';
    }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) {
        $port = 1883;
    }
    $benutzer = $hol('Brokeruser');
    $kennwort = $hol('Brokerpass');
    $soll = array();
    foreach ($lesen as $t) {
        $t = (string) $t;
        if ($t !== '' && strpbrk($t, "#+\0") === false) {
            $soll[$t] = true;
        }
    }
    $soll = array_slice(array_keys($soll), 0, 50);

    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) {
        return $aus;
    }
    stream_set_timeout($s, 1);
    $schreib = function ($daten) use ($s) {
        $daten = (string) $daten;
        return @fwrite($s, $daten) === strlen($daten);
    };
    $zk = function ($t) {
        return pack('n', strlen($t)) . $t;
    };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) {
                $b |= 128;
            }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    $lies_bis = microtime(true) + 10.0;
    $lies = function ($n) use ($s, $lies_bis) {
        $d = '';
        while (strlen($d) < $n) {
            if (microtime(true) >= $lies_bis) {
                return null;
            }
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) {
                    return null;
                }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) {
            return null;
        }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) {
                return null;
            }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) {
                break;
            }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    /* Nur ein zurueckbehaltener Wert (Retain-Bit) zaehlt: er ist das, was ein Abnehmer beim Anmelden bekommt. */
    $merke = function ($pk) use (&$aus, $soll) {
        if (strlen($pk[1]) < 2 || !($pk[0] & 1)) {
            return;
        }
        $tl = unpack('n', substr($pk[1], 0, 2));
        $t = substr($pk[1], 2, $tl[1]);
        $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
        if (in_array($t, $soll, true)) {
            $aus['werte'][$t] = (string) substr($pk[1], $versatz);
        }
    };

    $flags = 0x02;
    $nutz = $zk('ewhaus' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        if ($kennwort !== '') {
            $flags |= 0x40;
        }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 60);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') {
            $nutz .= $zk($kennwort);
        }
    }
    if (!$schreib(chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz)) {
        fclose($s);
        return $aus;
    }
    $ack = $paket();
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) {
        fclose($s);
        $aus['lage'] = 'zeit';
        return $aus;
    }
    if (ord($ack[1][1]) !== 0) {
        fclose($s);
        $aus['lage'] = 'abgelehnt';
        return $aus;
    }
    $lage = 'ok';
    if ($soll) {
        $sub = pack('n', 1);
        foreach ($soll as $t) {
            $sub .= $zk($t) . chr(0);
        }
        $schreib(chr(0x82) . $laenge(strlen($sub)) . $sub);
        $lage = 'zeit';
        $ende = microtime(true) + 3.0;
        while (microtime(true) < $ende) {
            $pk = $paket();
            if ($pk === null) {
                if (feof($s)) {
                    break;
                }
                continue;
            }
            $art = $pk[0] >> 4;
            if ($art === 9) {
                $rc = (string) substr($pk[1], 2);
                if (strlen($rc) !== count($soll)) {
                    break;
                }
                $lage = 'ok';
                for ($i = 0; $i < strlen($rc); $i++) {
                    if (ord($rc[$i]) >= 0x80) {
                        $lage = 'abgelehnt';
                    }
                }
                break;
            } elseif ($art === 3) {
                $merke($pk);
            }
        }
        if ($lage === 'ok') {
            $ende = microtime(true) + EW_HAUS_LESEFENSTER;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) {
                    if (feof($s)) {
                        break;
                    }
                    continue;
                }
                if (($pk[0] >> 4) === 3) {
                    $merke($pk);
                }
            }
        }
    }
    if ($lage === 'ok') {
        if (is_callable($entscheiden)) {
            $senden = call_user_func($entscheiden, $aus['werte']);
            if (!is_array($senden)) {
                $senden = array();
            }
        }
        foreach ($senden as $t => $wr) {
            $t = (string) $t;
            $w = (string) (is_array($wr) ? $wr[0] : $wr);
            $ret = is_array($wr) && !empty($wr[1]);
            if ($t === '' || strpbrk($t, "#+\0") !== false || ($w === '' && !$ret)) {
                continue;
            }
            $rumpf = $zk($t) . $w;
            if ($schreib(chr(0x30 | ($ret ? 1 : 0)) . $laenge(strlen($rumpf)) . $rumpf)) {
                $aus['gesendet']++;
                $aus['themen'][] = $t;
            }
        }
    }
    $schreib(chr(0xE0) . chr(0));
    fclose($s);
    $aus['lage'] = $lage;
    return $aus;
}

/**
 * Themen abraeumen (Abschrift von spot_haus_leeren(), Form von zng_haus_leeren()): erst zuruecklesen; ist nichts
 * belegt -> 'nichts'. Sonst leere Nutzlast mit Retain-Bit fuer die belegten Themen und danach in einer EIGENEN Sitzung
 * zuruecklesen, bis zu drei Runden. Nur die uebergebenen (gemerkten) Themen, nie haus/#. Rueckgabe array('lage' =>
 * geleert | nichts | rest | kein_broker, 'offen' => noch belegte Themen, 'grund' => Lage der gescheiterten Sitzung).
 */
function ew_haus_leeren(array $th, $runden = 3)
{
    $th = array_values(array_unique(array_map('strval', $th)));
    if (!$th) {
        return array('lage' => 'nichts', 'offen' => array(), 'grund' => '');
    }
    $belegt = function ($w) {
        $o = array();
        foreach ($w as $t => $v) {
            if ((string) $v !== '') {
                $o[] = $t;
            }
        }
        return $o;
    };
    $lesen = function ($liste) {
        $alle = array('lage' => 'ok', 'werte' => array());
        foreach (array_chunk($liste, 40) as $teil) {
            $r = ew_haus_sitzung(array(), $teil);
            if ($r['lage'] !== 'ok') {
                return $r;
            }
            $alle['werte'] += $r['werte'];
        }
        return $alle;
    };
    $r = $lesen($th);
    if ($r['lage'] !== 'ok') {
        return array('lage' => 'kein_broker', 'offen' => array(), 'grund' => $r['lage']);
    }
    $offen = $belegt($r['werte']);
    if (!$offen) {
        return array('lage' => 'nichts', 'offen' => array(), 'grund' => '');
    }
    $lage = 'rest';
    $grund = '';
    for ($i = 1; $i <= max(1, (int) $runden); $i++) {
        $senden = array();
        foreach ($offen as $t) {
            $senden[$t] = array('', true);
        }
        $r = ew_haus_sitzung($senden, array());
        if ($r['lage'] !== 'ok') {
            $lage = 'kein_broker';
            $grund = $r['lage'];
            continue;
        }
        usleep(300000);
        $r = $lesen($th);
        if ($r['lage'] !== 'ok') {
            $lage = 'kein_broker';
            $grund = $r['lage'];
            continue;
        }
        $offen = $belegt($r['werte']);
        if (!$offen) {
            return array('lage' => 'geleert', 'offen' => array(), 'grund' => '');
        }
        $lage = 'rest';
    }
    return array('lage' => $lage, 'offen' => $offen, 'grund' => $grund);
}

/** Gemerkte Themen, deren Familie und Name nicht mehr gesendet werden (Haken aus, Bereich gewechselt, Name
 *  geaendert, MQTT aus) - sie werden abgeraeumt. */
function ew_haus_verwaist(array $themen, array $liste)
{
    $an = array();
    foreach ($liste as $z) {
        $an['haus/' . $z['familie'] . '/' . $z['name'] . '/'] = true;
    }
    $weg = array();
    foreach (array_keys($themen) as $t) {
        $teile = explode('/', $t);
        if (count($teile) !== 4 || !isset($an[$teile[0] . '/' . $teile[1] . '/' . $teile[2] . '/'])) {
            $weg[] = $t;
        }
    }
    return $weg;
}

/** Verwaiste Themen abraeumen, wenn $erzwingen oder der letzte Versuch EW_HAUS_LEEREN_ABSTAND s zurueckliegt.
 *  Aendert $themen und $lauf; schreibt haus.json nur bei Erfolg. Rueckgabe das Ergebnis oder null (nichts zu tun). */
function ew_haus_abraeumen(array &$themen, array $liste, array &$lauf, $jetzt, $erzwingen)
{
    $weg = ew_haus_verwaist($themen, $liste);
    if (!$weg) {
        unset($lauf['leeren']);
        return null;
    }
    $vt = isset($lauf['leeren_ts']) ? (int) $lauf['leeren_ts'] : 0;
    if (!$erzwingen && $vt > 0 && $vt <= $jetzt && $jetzt - $vt < EW_HAUS_LEEREN_ABSTAND) {
        return null;
    }
    $e = ew_haus_leeren($weg);
    $lauf['leeren_ts'] = $jetzt;
    $lauf['leeren'] = array('zeit' => $jetzt, 'lage' => $e['lage'], 'offen' => $e['offen'], 'grund' => $e['grund'],
                            'themen' => count($weg));
    if ($e['lage'] === 'geleert' || $e['lage'] === 'nichts') {
        foreach ($weg as $t) {
            unset($themen[$t]);
            unset($lauf['werte'][$t]);
        }
        ew_haus_themen_schreiben($themen);
        unset($lauf['leeren']);
    }
    ew_log('Haus-Themen: Abraeumen von ' . count($weg) . ' Thema/Themen - ' . $e['lage']
        . ($e['offen'] ? ' (noch belegt: ' . implode(', ', $e['offen']) . ')' : '') . '.');
    return $e;
}

/**
 * Die Werte eines Laufs. $d = die dekodierte Stationsantwort (nur bei frischen Daten, sonst null), $alter =
 * ew_alter(). Rueckgabe array(werte: Thema => Wert (retained), felder: Fuehler => gelieferte Felder, namen: Basis =>
 * Fuehler). Regeln: ein Feld, das die Station liefert, geht mit Wert oder "ohne Aussage"; ein gemerktes Feld, das sie
 * nicht mehr liefert, geht "ohne Aussage"; liegt die letzte brauchbare Antwort ueber EW_HAUS_STUMM s zurueck oder ist
 * sie unbekannt, gehen alle gemerkten Felder "ohne Aussage" hinaus, zeit bleibt der echte Zeitpunkt (-1 unbekannt).
 * Ohne frische Daten und nicht stumm: keine Werte, nur der Zaehler.
 */
function ew_haus_werte(array $liste, $d, $alter, $jetzt, array $themen)
{
    $fu = ew_haus_fuehler();
    $stumm = ((int) $alter < 0 || (int) $alter > EW_HAUS_STUMM);
    $zeit = ((int) $alter >= 0) ? (string) ((int) $jetzt - (int) $alter) : '-1';
    $werte = array();
    $felder = array();
    $namen = array();
    foreach ($liste as $z) {
        $basis = 'haus/' . $z['familie'] . '/' . $z['name'] . '/';
        $namen[$basis] = $z['fuehler'];
        $w = array();
        if (is_array($d) && !$stumm) {
            $felder[$z['fuehler']] = array();
            foreach ($fu[$z['fuehler']][2] as $f) {
                $v = ew_haus_wert($f, ew_haus_suchen($d, $f[2], $f[1] === 'id'));
                if ($v !== null) {
                    $w[$basis . $f[0]] = $v;
                    $felder[$z['fuehler']][] = $f[0];
                }
            }
        }
        if (is_array($d) || $stumm) {
            foreach ($fu[$z['fuehler']][2] as $f) {
                if (!isset($w[$basis . $f[0]]) && isset($themen[$basis . $f[0]])) {
                    $w[$basis . $f[0]] = (string) $f[4];
                }
            }
        }
        if ($w) {
            $w[$basis . 'zeit'] = $zeit;
            $w[$basis . 'anbieter'] = EW_HAUS_ANBIETER;
        }
        $werte += $w;
    }
    return array($werte, $felder, $namen);
}

/** Was geht hinaus? Felder bei Aenderung (oder alle bei $voll), zeit nur zusammen mit einem Feld desselben Namens,
 *  anbieter bei Aenderung (also mit dem vollen Satz und beim ersten Mal). Rueckgabe Thema => array(Wert, true). */
function ew_haus_auswahl(array $werte, array $merk, $voll)
{
    $sel = array();
    $mit = array();
    foreach ($werte as $t => $v) {
        $feld = substr($t, strrpos($t, '/') + 1);
        if ($feld === 'zeit' || $feld === 'anbieter') {
            continue;
        }
        if ($voll || !array_key_exists($t, $merk) || (string) $merk[$t] !== (string) $v) {
            $sel[$t] = array((string) $v, true);
            $mit[substr($t, 0, strrpos($t, '/') + 1)] = true;
        }
    }
    foreach ($werte as $t => $v) {
        $basis = substr($t, 0, strrpos($t, '/') + 1);
        $feld = substr($t, strlen($basis));
        if ($feld === 'zeit' && isset($mit[$basis])) {
            $sel[$t] = array((string) $v, true);
        } elseif ($feld === 'anbieter' && ($voll || !array_key_exists($t, $merk) || (string) $merk[$t] !== (string) $v)) {
            $sel[$t] = array((string) $v, true);
        }
    }
    return $sel;
}

/**
 * Pruefung auf fremde Werte (Regeln/07 Nr. 15: ein Anbieter ueberschreibt nie einen retained Wert, den er nicht
 * selbst gesendet hat). $sel = Auswahl, $themen = gemerkte Themen, $gelesen = zurueckbehaltene Werte am Broker.
 * Traegt <name>/anbieter einen fremden Namen und ist das Thema nicht gemerkt, geht von diesem Namen nichts hinaus;
 * traegt es den eigenen Namen, gelten seine Themen als eigene (etwa nach einer Neuinstallation ohne Abraeumen); sonst
 * bleibt jedes nicht gemerkte Thema mit einem zurueckbehaltenen Wert stehen. Rueckgabe array(sel, konflikte).
 */
function ew_haus_fremd(array $sel, array $themen, array $gelesen)
{
    $konflikte = array();
    $basen = array();
    foreach (array_keys($sel) as $t) {
        if (strpos($t, 'haus/') === 0 && strpos($t, '/zaehler/') === false && !isset($themen[$t])) {
            $basen[substr($t, 0, strrpos($t, '/') + 1)] = true;
        }
    }
    foreach (array_keys($basen) as $b) {
        $anb = $b . 'anbieter';
        $fremd = (!isset($themen[$anb]) && isset($gelesen[$anb]) && $gelesen[$anb] !== '') ? $gelesen[$anb] : '';
        if ($fremd !== '' && $fremd !== EW_HAUS_ANBIETER) {
            foreach (array_keys($sel) as $t) {
                if (strpos($t, $b) === 0) {
                    unset($sel[$t]);
                }
            }
            $konflikte[] = $anb . ' = ' . ew_kurz($fremd, 40);
            continue;
        }
        if ($fremd === EW_HAUS_ANBIETER) {
            continue;
        }
        foreach (array_keys($sel) as $t) {
            if (strpos($t, $b) === 0 && !isset($themen[$t]) && isset($gelesen[$t]) && $gelesen[$t] !== '') {
                unset($sel[$t]);
                $konflikte[] = $t;
            }
        }
    }
    return array($sel, $konflikte);
}

/**
 * Ein Lauf des Anbieters (aus ew_takt(), nach dem Versand an das Gateway). $roh = die Stationsantwort, wenn sie in
 * diesem Lauf frisch war, sonst null; $zaehler = derselbe Zaehler wie status/zaehler. Ohne aktive Zuordnung raeumt
 * der Lauf nur verwaiste Themen ab. Rueckgabe: der neue Stand von haus_lauf.json oder null.
 */
function ew_haus_lauf(array $c, $roh, $alter, $zaehler, $jetzt)
{
    $p = ew_paths();
    if ($p['datadir'] === '' || $p['lbhome'] === '') {
        return null;
    }
    $sp = ew_haus_sperre();
    if ($sp === null) {
        return null;
    }
    list($liste, $schlecht) = ew_haus_zuordnung($c);
    $themen = ew_haus_themen_lesen();
    $lauf = ew_haus_lauf_lesen();
    $merk = (isset($lauf['werte']) && is_array($lauf['werte'])) ? $lauf['werte'] : array();
    $lauf['werte'] = $merk;
    ew_haus_abraeumen($themen, $liste, $lauf, $jetzt, false);
    $lauf['beanstandet'] = $schlecht;
    if (!$liste) {
        $lauf['aktiv'] = array();
        unset($lauf['werte'], $lauf['voll_ts']);
        ew_json_schreiben(ew_haus_lauf_datei(), $lauf);
        ew_haus_freigeben($sp);
        return $lauf;
    }
    $d = is_string($roh) ? json_decode($roh, true) : null;
    list($werte, $felder, $namen) = ew_haus_werte($liste, is_array($d) ? $d : null, $alter, $jetzt, $themen);
    $voll_ts = isset($lauf['voll_ts']) ? (int) $lauf['voll_ts'] : 0;
    $voll = !isset($lauf['voll_ts']) || $voll_ts <= 0 || $voll_ts > $jetzt || $jetzt - $voll_ts >= EW_HAUS_VOLL;
    $sel = ew_haus_auswahl($werte, $lauf['werte'], $voll);
    $zt = array();
    foreach ($liste as $z) {
        $zt['haus/' . $z['familie'] . '/zaehler/' . EW_HAUS_ANBIETER] = array((string) ((((int) $zaehler) % 1000 + 1000) % 1000), false);
    }
    $lese = array();
    foreach (array_keys($sel) as $t) {
        if (!isset($themen[$t])) {
            $b = substr($t, 0, strrpos($t, '/') + 1);
            $lese[$t] = true;
            $lese[$b . 'anbieter'] = true;
        }
    }
    $konflikte = array();
    $endgueltig = $sel;
    $entscheiden = function ($gelesen) use ($sel, $themen, $zt, &$konflikte, &$endgueltig) {
        list($endgueltig, $konflikte) = ew_haus_fremd($sel, $themen, $gelesen);
        return $endgueltig + $zt;
    };
    $r = ew_haus_sitzung(array(), array_keys($lese), $entscheiden);
    $lauf['sitzung'] = array('zeit' => $jetzt, 'lage' => $r['lage'], 'gesendet' => (int) $r['gesendet'],
                             'voll' => $voll ? 1 : 0, 'werte' => count($endgueltig));
    if ($r['lage'] === 'ok') {
        $neu = false;
        foreach ($r['themen'] as $t) {
            if (isset($endgueltig[$t])) {
                $lauf['werte'][$t] = $endgueltig[$t][0];
                if (!isset($themen[$t]) || $themen[$t] !== $namen[substr($t, 0, strrpos($t, '/') + 1)]) {
                    $themen[$t] = $namen[substr($t, 0, strrpos($t, '/') + 1)];
                    $neu = true;
                }
            }
        }
        if ($neu) {
            ew_haus_themen_schreiben($themen);
        }
        if ($voll) {
            $lauf['voll_ts'] = $jetzt;
        }
        $lauf['konflikte'] = $konflikte;
    }
    if (is_array($d)) {
        $lauf['felder'] = $felder;
    }
    $aktiv = array();
    foreach ($liste as $z) {
        $aktiv[] = 'haus/' . $z['familie'] . '/' . $z['name'];
    }
    $lauf['aktiv'] = $aktiv;
    $alt = isset($lauf['log_lage']) ? (string) $lauf['log_lage'] : '';
    if ($alt !== $r['lage']) {
        ew_log($r['lage'] === 'ok' ? 'Haus-Themen: Sitzung am Broker ok (' . implode(', ', $aktiv) . ').'
            : 'Haus-Themen: Broker nicht erreichbar (' . $r['lage'] . ') - naechster Versuch in einer Minute.');
        $lauf['log_lage'] = $r['lage'];
    }
    $kalt = isset($lauf['log_konflikte']) ? (string) $lauf['log_konflikte'] : '';
    if ($r['lage'] === 'ok' && implode(', ', $konflikte) !== $kalt) {
        if ($konflikte) {
            ew_log('Haus-Themen: nicht gesendet, ein anderer Anbieter haelt den Wert: ' . implode(', ', $konflikte) . '.');
        }
        $lauf['log_konflikte'] = implode(', ', $konflikte);
    }
    ew_json_schreiben(ew_haus_lauf_datei(), $lauf);
    ew_haus_freigeben($sp);
    return $lauf;
}

/** MQTT aus (ew_takt() endet frueh): gemerkte Themen trotzdem abraeumen, hoechstens alle EW_HAUS_LEEREN_ABSTAND s. */
function ew_haus_nachraeumen(array $c, $jetzt)
{
    $f = ew_haus_datei();
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $sp = ew_haus_sperre();
    if ($sp === null) {
        return null;
    }
    $themen = ew_haus_themen_lesen();
    $lauf = ew_haus_lauf_lesen();
    list($liste) = ew_haus_zuordnung($c);
    $e = $themen ? ew_haus_abraeumen($themen, $liste, $lauf, $jetzt, false) : null;
    if ($e !== null) {
        ew_json_schreiben(ew_haus_lauf_datei(), $lauf);
    }
    ew_haus_freigeben($sp);
    return $e;
}

/**
 * Folgen einer Aenderung (Speichern im Reiter MQTT, Zurueckspielen einer Sicherung): der naechste Minutenlauf schickt
 * den vollen Satz; was nicht mehr gesendet wird, wird sofort mit Ruecklesen abgeraeumt. Rueckgabe array(Meldung oder
 * '', ok|warn).
 */
function ew_haus_folgen(array $neu)
{
    $sp = ew_haus_sperre();
    if ($sp === null) {
        return array('', 'ok');
    }
    $themen = ew_haus_themen_lesen();
    $lauf = ew_haus_lauf_lesen();
    unset($lauf['werte'], $lauf['voll_ts']);
    list($liste) = ew_haus_zuordnung($neu);
    $n = count(ew_haus_verwaist($themen, $liste));
    $e = $themen ? ew_haus_abraeumen($themen, $liste, $lauf, time(), true) : null;
    ew_json_schreiben(ew_haus_lauf_datei(), $lauf);
    ew_haus_freigeben($sp);
    if ($e === null) {
        return array('', 'ok');
    }
    if ($e['lage'] === 'geleert') {
        return array(sprintf(ew_t('HAUS.M_GELEERT'), $n), 'ok');
    }
    if ($e['lage'] === 'nichts') {
        return array(ew_t('HAUS.M_NICHTS'), 'ok');
    }
    if ($e['lage'] === 'rest') {
        return array(sprintf(ew_t('HAUS.M_REST'), implode(', ', $e['offen'])), 'warn');
    }
    return array(sprintf(ew_t('HAUS.M_KEIN_BROKER'), $e['grund']), 'warn');
}

/** Deinstallation (bin/ew_takt.php --haus-leeren): alle gemerkten Themen mit Ruecklesen abraeumen. Ausgabe fuer das
 *  Installationsprotokoll; Rueckgabe immer 0 - die Deinstallation laeuft weiter. */
function ew_haus_leeren_ausgabe()
{
    $sp = ew_haus_sperre();
    $themen = ew_haus_themen_lesen();
    if (!$themen) {
        echo "<INFO> haus/wetter, haus/klima: diese Installation war nie Anbieter - nichts zu leeren.\n";
        ew_haus_freigeben($sp);
        return 0;
    }
    $e = ew_haus_leeren(array_keys($themen));
    if ($e['lage'] === 'geleert' || $e['lage'] === 'nichts') {
        ew_haus_themen_schreiben(array());
    }
    ew_haus_freigeben($sp);
    if ($e['lage'] === 'geleert') {
        echo '<OK> haus/wetter, haus/klima: geleert (vom Broker bestaetigt, ' . count($themen) . " Themen).\n";
    } elseif ($e['lage'] === 'nichts') {
        echo '<INFO> haus/wetter, haus/klima: nichts zu leeren (der Broker haelt keines der ' . count($themen)
            . " Themen zurueck).\n";
    } elseif ($e['lage'] === 'rest') {
        echo '<WARNING> haus/wetter, haus/klima: noch zurueckbehalten - bitte von Hand leeren: '
            . implode(', ', $e['offen']) . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo '<WARNING> haus/wetter, haus/klima: der Broker war nicht erreichbar (' . $e['grund']
            . ') - bitte von Hand leeren: ' . implode(', ', array_keys($themen)) . " (mosquitto_pub -r -n -t <thema>)\n";
    }
    return 0;
}

/** Die Themenliste fuer den Reiter MQTT: je aktivem Namen die Felder, zeit, anbieter und der Zaehler der Familie.
 *  Rueckgabe array von array(Thema, Sprachschluessel der Bedeutung, retained). */
function ew_haus_themenliste(array $c)
{
    list($liste) = ew_haus_zuordnung($c);
    $fu = ew_haus_fuehler();
    $aus = array();
    $zaehler = array();
    foreach ($liste as $z) {
        $b = 'haus/' . $z['familie'] . '/' . $z['name'] . '/';
        foreach ($fu[$z['fuehler']][2] as $f) {
            $aus[] = array($b . $f[0], 'HAUS.F_' . strtoupper($f[0]), true);
        }
        $aus[] = array($b . 'zeit', 'HAUS.F_ZEIT', true);
        $aus[] = array($b . 'anbieter', 'HAUS.F_ANBIETER', true);
        $zaehler['haus/' . $z['familie'] . '/zaehler/' . EW_HAUS_ANBIETER] = true;
    }
    foreach (array_keys($zaehler) as $t) {
        $aus[] = array($t, 'HAUS.F_ZAEHLER', false);
    }
    return $aus;
}

/**
 * Prueflinie "Haus-Themen" des Reiters Test. Mit $messen (nur bei geoeffnetem Reiter Test) liest sie am Broker zurueck,
 * ob je aktivem Namen haus/<familie>/<name>/anbieter = ecowittweiche und zeit = dem zuletzt gesendeten Wert
 * zurueckbehalten sind - die Wirkung, nicht der Rueckgabewert. Rueckgabe array(ok|fehl|hinweis, Text).
 */
function ew_pruef_haus(array $c, $messen)
{
    list($liste, $schlecht) = ew_haus_zuordnung($c);
    $themen = ew_haus_themen_lesen();
    $lauf = ew_haus_lauf_lesen();
    $offen = ew_haus_verwaist($themen, $liste);
    if ($offen) {
        $l = isset($lauf['leeren']['lage']) ? (string) $lauf['leeren']['lage'] : '-';
        return array('fehl', sprintf(ew_t('HAUS.T_OFFEN'), count($offen), implode(', ', array_slice($offen, 0, 6))
            . (count($offen) > 6 ? ' ...' : ''), $l));
    }
    $an = !empty($c['haus_wetter_ein']) || !empty($c['haus_klima_ein']);
    if (!$an) {
        return array('hinweis', ew_t('HAUS.T_AUS'));
    }
    if (empty($c['mqtt_ein'])) {
        return array('hinweis', ew_t('HAUS.T_MQTT_AUS'));
    }
    if ($schlecht) {
        return array('fehl', sprintf(ew_t('HAUS.T_NAME_SCHLECHT'), implode(', ', $schlecht)));
    }
    if (!$liste) {
        return array('fehl', ew_t('HAUS.T_KEIN_FUEHLER'));
    }
    $sz = (isset($lauf['sitzung']) && is_array($lauf['sitzung'])) ? $lauf['sitzung'] : array();
    if (!isset($sz['zeit'], $sz['lage'])) {
        return array('fehl', ew_t('HAUS.T_NIE'));
    }
    $alter = time() - (int) $sz['zeit'];
    if ((string) $sz['lage'] !== 'ok') {
        return array('fehl', sprintf(ew_t('HAUS.T_BROKER'), (string) $sz['lage'], ew_alter_text($alter)));
    }
    if ($alter < 0 || $alter > EW_TAKT_ALT) {
        return array('fehl', sprintf(ew_t('HAUS.T_ALT'), ew_alter_text($alter)));
    }
    if (!empty($lauf['konflikte']) && is_array($lauf['konflikte'])) {
        return array('fehl', sprintf(ew_t('HAUS.T_KONFLIKT'), implode(', ', $lauf['konflikte'])));
    }
    $namen = array();
    foreach ($liste as $z) {
        $namen[] = 'haus/' . $z['familie'] . '/' . $z['name'];
    }
    if (!$messen) {
        return array('hinweis', sprintf(ew_t('HAUS.T_NUR_OFFEN'), implode(', ', $namen)));
    }
    $lese = array();
    foreach ($namen as $b) {
        $lese[] = $b . '/anbieter';
        $lese[] = $b . '/zeit';
    }
    $r = ew_haus_sitzung(array(), $lese);
    if ($r['lage'] !== 'ok') {
        return array('fehl', sprintf(ew_t('HAUS.T_LESEN'), $r['lage']));
    }
    $merk = (isset($lauf['werte']) && is_array($lauf['werte'])) ? $lauf['werte'] : array();
    $fehlt = array();
    $gut = array();
    foreach ($namen as $b) {
        $a = isset($r['werte'][$b . '/anbieter']) ? $r['werte'][$b . '/anbieter'] : '';
        $zt = isset($r['werte'][$b . '/zeit']) ? $r['werte'][$b . '/zeit'] : '';
        if ($a !== EW_HAUS_ANBIETER || $zt === '' || (isset($merk[$b . '/zeit']) && (string) $merk[$b . '/zeit'] !== $zt)) {
            $fehlt[] = $b . ' (anbieter "' . ew_kurz($a, 30) . '", zeit "' . ew_kurz($zt, 12) . '")';
        } else {
            $gut[] = $b . ' (zeit ' . (ctype_digit($zt) ? date('d.m.Y H:i:s', (int) $zt) : $zt) . ')';
        }
    }
    if ($fehlt) {
        return array('fehl', sprintf(ew_t('HAUS.T_WIRKUNG_FEHLT'), implode(', ', $fehlt)));
    }
    return array('ok', sprintf(ew_t('HAUS.T_OK'), implode(', ', $gut), ew_alter_text($alter)));
}

/* ==================================================================
 * LOXONE-VORLAGEN (Welle Bild 13, 0.9.22)
 * ==================================================================
 * Entwurf Welle 13 ew, Entscheidung 10 (Hausherr 15.10.2026, wie
 * empfohlen): zwei Knoepfe im Reiter Einbindung in Loxone liefern die
 * Vorlagen der beiden Behaelter, Adresse mit Rechnername und Wortzeichen
 * eingesetzt. Bis 0.9.21 gab es keine Vorlage; die vier Zustandsbefehle
 * standen nur als Tabelle, und wer keinen Stationsbehaelter hatte, tippte
 * jeden Befehl ab. Titel bleiben fest deutsch (Regeln/07, Sprachregel
 * 13.10.2026), nur die Kommentare kommen aus der Sprachdatei. Einen Timeout
 * traegt eine Vorlage nicht (VirtualInHttp kennt nur Adresse und
 * Abfragezeit) - der Reiter nennt den Wert, der danach einzutragen ist.
 * Reine Oberflaeche: Abruf, Beurteilung und Endpunkt bleiben, wie sie waren.
 * ================================================================== */

/** Felder der Zustandszeile (live.php?status=1): Feld => array(MinVal,
 *  MaxVal, Signed, Einheit, Titel des Befehls, Kommentarschluessel). EINE
 *  Stelle fuer die Feldtabelle im Reiter, die Baustein-Liste und die Vorlage
 *  (bis 0.9.21 stand die Reihung als $ew_lox_felder in index.php, X-8). */
function ew_lox_felder()
{
    return array(
        'OK'      => array(0, 1, false, '<v>', 'Ecowitt-Weiche OK', 'VORLAGE.Z_OK'),
        'QUELLE'  => array(0, 2, false, '<v>', 'Ecowitt-Weiche Quelle', 'VORLAGE.Z_QUELLE'),
        'WECHSEL' => array(0, 100000, false, '<v>', 'Ecowitt-Weiche Wechsel', 'VORLAGE.Z_WECHSEL'),
        'ALTER'   => array(-1, 1000000, true, '<v> s', 'Ecowitt-Weiche Alter', 'VORLAGE.Z_ALTER'),
    );
}

/** Befehle der Beispielvorlage "Wetterstation Ecowitt": array(Titel,
 *  Befehlserkennung, Signed, MinVal, MaxVal, Einheit, Kommentarschluessel).
 *  Die Suchtexte laufen so an der Hausanlage (mit lebenden Werten am
 *  08.09.2026 bestaetigt); die Grenzen tragen einen Sicherheitsabstand, die
 *  Skalierung ist 1:1 - die Einheiten stellt man an der Konsole ein (Grad C,
 *  km/h, mm, hPa). Welche Felder ankommen, bestimmen Station und Sensoren;
 *  das Plugin selbst prueft nur 0x07 (Inhaltspruefung). */
function ew_wetter_befehle()
{
    return array(
        array("Au\u{00DF}entemperatur", '\i"0x02","val":"\i\v', true, -50, 70, "<v.1> \u{00B0}C", 'VORLAGE.W01'),
        array("Au\u{00DF}enfeuchte (Proz)", '\i"id":"0x07","val":"\i\v', false, 0, 100, '<v.0> %', 'VORLAGE.W02'),
        array('Taupunkt', '\i"id":"0x03","val":"\i\v', true, -60, 50, "<v.1> \u{00B0}C", 'VORLAGE.W03'),
        array('Windrichtung', '\i"id":"0x0A","val":"\i\v', false, 0, 360, "<v.0>\u{00B0}", 'VORLAGE.W04'),
        array('Windgeschwindigkeit', '\i"id":"0x0B","val":"\i\v', false, 0, 300, '<v.1> km/h', 'VORLAGE.W05'),
        array("Windb\u{00F6}e", '\i"id":"0x0C","val":"\i\v', false, 0, 300, '<v.1> km/h', 'VORLAGE.W06'),
        array("Windb\u{00F6}e Tagesh\u{00F6}chstwert", '\i"id":"0x19","val":"\i\v', false, 0, 300, '<v.1> km/h', 'VORLAGE.W07'),
        array('Solarstrahlung', '\i"id":"0x15","val":"\i\v', false, 0, 2000, "<v.0> W/m\u{00B2}", 'VORLAGE.W08'),
        array('UV-Index', '\i"id":"0x17","val":"\i\v', false, 0, 20, '<v.0>', 'VORLAGE.W09'),
        array('Regenrate', '"0x0E"\i:\i"\v', false, 0, 500, '<v.1> mm/h', 'VORLAGE.W10'),
        array('Regen seit Regenbeginn', '"0x0D"\i:\i"\v', false, 0, 1000, '<v.1> mm', 'VORLAGE.W11'),
        array('Regen heute', '"0x10"\i:\i"\v', false, 0, 1000, '<v.1> mm', 'VORLAGE.W12'),
        array('Regen Woche', '"0x11"\i:\i"\v', false, 0, 2000, '<v.1> mm', 'VORLAGE.W13'),
        array('Regen Monat', '"0x12"\i:\i"\v', false, 0, 5000, '<v.1> mm', 'VORLAGE.W14'),
        array('Regen Jahr', '"0x13"\i:\i"\v', false, 0, 10000, '<v.0> mm', 'VORLAGE.W15'),
        array('Innentemperatur', '"intemp"\i:\i"\v', true, -20, 60, "<v.1> \u{00B0}C", 'VORLAGE.W16'),
        array('Innenfeuchte (Proz)', '"inhumi"\i:\i"\v', false, 0, 100, '<v.0> %', 'VORLAGE.W17'),
        array('Luftdruck relativ', '"rel"\i:\i"\v', false, 0, 1200, '<v.1> hPa', 'VORLAGE.W18'),
        array('Luftdruck absolut', '"abs"\i:\i"\v', false, 0, 1200, '<v.1> hPa', 'VORLAGE.W19'),
        array('Blitz Entfernung', '"distance"\i:\i"\v', false, 0, 100, '<v.0> km', 'VORLAGE.W20'),
        array('Blitz Anzahl', '"count"\i:\i"\v', false, 0, 100000, '<v.0>', 'VORLAGE.W21'),
    );
}

/** Wert fuer ein XML-Merkmal (", <, >, & maskiert). */
function ew_xml($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Rechnername fuer die Adressen: so, wie der Browser den LoxBerry
 *  erreicht; ohne Angabe "loxberry". Bis 0.9.21 stand der Ausdruck in
 *  index.php - jetzt brauchen ihn auch die Vorlagen. */
function ew_rechner()
{
    return (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '')
        ? $_SERVER['HTTP_HOST'] : 'loxberry';
}

/** Adresse eines Behaelters in der Form, die der Reiter zeigt:
 *  live.php, beim Zustandsbehaelter ?status=1, dazu das Wortzeichen. */
function ew_lox_adresse($host, array $c, $status)
{
    $a = 'http://' . $host . '/plugins/' . ew_paths()['plugin'] . '/live.php';
    $q = array();
    if ($status) {
        $q[] = 'status=1';
    }
    if (isset($c['token']) && is_string($c['token']) && $c['token'] !== '') {
        $q[] = 'token=' . rawurlencode($c['token']);
    }
    return $q ? $a . '?' . implode('&', $q) : $a;
}

/** Eine Vorlage "Virtueller HTTP-Eingang" in der Form der Musterprojekt-
 *  Vorlagen (Info templateType 2, je Befehl Unit und HintText, LF). */
function ew_vorlage_xml($titel, $kommentar, $adresse, array $befehle)
{
    $o = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $o .= '<VirtualInHttp HintText="" Title="' . ew_xml($titel) . '" Comment="' . ew_xml($kommentar)
        . '" Address="' . ew_xml($adresse) . '" PollingTime="60">' . "\n";
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . "\n";
    foreach ($befehle as $b) {
        $o .= "\t" . '<VirtualInHttpCmd Title="' . ew_xml($b['titel']) . '" Comment="' . ew_xml($b['kommentar'])
            . '" Check="' . ew_xml($b['check']) . '" Signed="' . ($b['signed'] ? 'true' : 'false')
            . '" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0"'
            . ' MinVal="' . (int) $b['min'] . '" MaxVal="' . (int) $b['max'] . '" Unit="' . ew_xml($b['einheit'])
            . '" HintText=""/>' . "\n";
    }
    return $o . '</VirtualInHttp>' . "\n";
}

/** Vorlage des Zustandsbehaelters: array(Dateiname, Inhalt). */
function ew_vorlage_zustand($host, array $c)
{
    $b = array();
    foreach (ew_lox_felder() as $f => $x) {
        $b[] = array('titel' => $x[4], 'kommentar' => ew_t($x[5]), 'check' => ew_suchtext($f),
                     'signed' => $x[2], 'min' => $x[0], 'max' => $x[1], 'einheit' => $x[3]);
    }
    return array('VI_Ecowitt_Weiche_Zustand.xml',
                 ew_vorlage_xml('Ecowitt-Weiche Zustand', ew_t('VORLAGE.Z_KOPF'), ew_lox_adresse($host, $c, true), $b));
}

/** Beispielvorlage des Stationsbehaelters: array(Dateiname, Inhalt). */
function ew_vorlage_wetter($host, array $c)
{
    $b = array();
    foreach (ew_wetter_befehle() as $x) {
        $b[] = array('titel' => $x[0], 'kommentar' => ew_t($x[6]), 'check' => $x[1],
                     'signed' => $x[2], 'min' => $x[3], 'max' => $x[4], 'einheit' => $x[5]);
    }
    return array('VI_Wetterstation_Ecowitt_Beispiel.xml',
                 ew_vorlage_xml('Wetterstation Ecowitt', ew_t('VORLAGE.W_KOPF'), ew_lox_adresse($host, $c, false), $b));
}
