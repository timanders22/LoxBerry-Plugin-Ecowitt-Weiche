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
    return array(
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
         * hier weiter; ew_sicherung_lesen() gibt den Hinweis. */
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
 * JEDER WERT wird wie beim Speichern geprueft (ew_wert_pruefen). Bis 0.9.14
 * stand hier "$neu[$k] = $w;" ohne Pruefung (Bauart E, Pruefer code und
 * oberflaeche, gemessen unter 7.4, 8.4 und 8.5): ein Wortzeichen als Liste
 * wurde gespeichert, live.php nahm danach token=Array an, die Oberflaeche
 * starb unter PHP 8 mit einem TypeError, und mit Zweitschrift heilte
 * ew_config() den Stand im selben Aufruf zurueck, waehrend die Seite
 * "uebernommen" meldete. Adresse "http://...", Pfad ohne /, timeout 999 und
 * pruefe_wert "nein" gingen ebenso durch.
 *
 * Ein LEERES Wortzeichen ist zulaessig - "ohne Wortzeichen" ist ein
 * gewollter Zustand (live.php-Kopf; Regeln/05, VolkswagenID 03.09.2026:
 * ein zu enges Muster weist die eigene Sicherung ab). Es kommt aber nie
 * still: der vierte Rueckgabewert traegt dann einen Hinweis, den die
 * Oberflaeche zeigt.
 *
 * Maskiert wird hier nichts; das tut einmal die Ausgabe (ew_e). Bis 0.9.14
 * stand hier htmlspecialchars, und die Seite zeigte "&lt;b&gt;" (Pruefer
 * oberflaeche, Befund 3).
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 * Hinweise[]).
 *
 * X-3 (Verbesserungsbau 01.10.2026): $namen sammelt die NAMEN der
 * beanstandeten Schluessel (nie ihre Werte) fuer die Warnung beim Sichern.
 * Schluessel, die mit "_" beginnen, sind Kopfzeilen (etwa "_warnung" der
 * eigenen Sicherung) und werden uebergangen.
 */
function ew_sicherung_lesen($roh, &$namen = null)
{
    $namen = array();
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(ew_t('TEXT.SICH_KEIN_JSON')), 0, array());
    }
    $neu = ew_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ew_t('TEXT.SICH_FREMD'), ew_kurz($k, 60));
            $namen[] = ew_kurz($k, 60);
            continue;
        }
        list($ok, $wert, $grund) = ew_wert_pruefen($k, $w);
        if (!$ok) {
            $mangel[] = $grund;
            $namen[] = $k;
            continue;
        }
        $neu[$k] = $wert;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ew_t('TEXT.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    /* Gruppe 3 (0.9.25): mqtt_ein und mqtt_praefix sind "spaete" Schluessel. Eine Sicherung aus 0.9.24 traegt
     * sie nicht und bleibt zurueckspielbar; es gilt dieselbe Regel wie beim Update: MQTT aus, Praefix ab Werk
     * (Entwurf 2.7). Unbekannte Schluessel bleiben eine Beanstandung. */
    if (!array_key_exists('mqtt_ein', $daten)) {
        $neu['mqtt_ein'] = 0;
    }
    foreach (array_keys(ew_vorgaben()) as $fk) {
        if ($fk === 'mqtt_ein' || $fk === 'mqtt_praefix') {
            continue;
        }
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(ew_t('TEXT.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
        $namen = array_merge($namen, $fehlend);
    }
    if (!$mangel && $neu['token'] === '') {
        $hinweise[] = ew_t('TEXT.SICH_OHNE_TOKEN');
    }
    /* k2: eine Wartezeit ueber EW_WARTEZEIT_MAX wird uebernommen, wie sie
     * ist, und gesagt - nie still gekuerzt. */
    if (!$mangel && ew_wartezeit_zu_lang($neu)) {
        $hinweise[] = sprintf(ew_t('TEXT.SICH_WARTEZEIT_ALT'), (int) $neu['timeout'],
            ew_behaelter_timeout_noetig_ms($neu), EW_LOX_TIMEOUT_MAX, EW_WARTEZEIT_MAX);
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
}

/**
 * X-3: Welche gespeicherten Werte bestuenden das eigene Zurueckspielen
 * nicht? Die Sicherung (dieselbe, die "Einstellungen sichern" ausgibt) wird
 * durch ew_sicherung_lesen() geschickt. Rueckgabe: Liste der NAMEN, nie der
 * Werte; leer = die Sicherung liesse sich zurueckspielen.
 */
function ew_rueckspiel_altwerte(array $sich)
{
    $js = json_encode($sich, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        /* Nicht kodierbar: das meldet der Knopf selbst (SICH_SCHREIBFEHLER). */
        return array();
    }
    $namen = array();
    list($neu) = ew_sicherung_lesen($js, $namen);
    if ($neu !== null) {
        return array();
    }
    return $namen ? array_values(array_unique($namen)) : array('?');
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
    clearstatcache(true, $marke);
    if (is_file($marke) && abs(time() - (int) @filemtime($marke)) < EW_MARKE_FRIST) {
        return array('ende' => 'upgrade');
    }
    $c = ew_config(false);
    if ($c === null) {
        return array('ende' => 'nicht_eingerichtet');
    }
    if (empty($c['mqtt_ein'])) {
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
