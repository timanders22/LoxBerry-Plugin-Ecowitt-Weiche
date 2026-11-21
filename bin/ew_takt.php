<?php
/**
 * Ecowitt-Weiche - Minutentakt fuer MQTT (seit 0.9.25)
 *
 * Aufruf: cron/cron.01min, jede Minute, als Benutzer loxberry. Tut nur etwas,
 * wenn im Reiter MQTT "MQTT aktivieren" angehakt ist. Die ganze Arbeit steht
 * in ew_takt() in ew_lib.php - dieselbe Weiche wie live.php, keine zweite
 * Logik (Entwurf Gruppe 3 ew, Abschnitt 2.2). Ausgabe nur im Fehlerfall
 * (stderr, die Cron-Zeile haengt sie an log/plugins/<ordner>/cron.err).
 *
 *   php ew_takt.php               ein Lauf
 *   php ew_takt.php --selbsttest  prueft Namen, Werte und Suchtexte an der
 *                                 nachgebildeten Antwort; fragt keine Station,
 *                                 sendet nichts, schreibt nichts
 *
 * (c) Ecowitt-Weiche Plugin Authors - MIT-Lizenz
 */

/* Die Bibliothek liegt im ANDEREN Baum (wie index.php):
     installiert  $LB/bin/plugins/<ordner>/ew_takt.php
                  $LB/webfrontend/html/plugins/<ordner>/ew_lib.php
     im Archiv    <plugin>/bin/ew_takt.php
                  <plugin>/webfrontend/html/ew_lib.php
   Der Cron setzt keine LBP*-Variablen (Regeln/06); ew_paths() findet die
   Wurzel dann ueber die Suche aufwaerts vom Ort der Bibliothek. */
$ew_kandidaten = array();
$ew_html = getenv('LBPHTMLDIR');
if ($ew_html !== false && $ew_html !== '') {
    $ew_kandidaten[] = rtrim($ew_html, '/\\') . '/ew_lib.php';
}
$ew_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ew_lib.php';
$ew_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/ew_lib.php';
$ew_geladen = '';
foreach ($ew_kandidaten as $ew_k) {
    if (is_file($ew_k)) {
        require_once $ew_k;
        $ew_geladen = $ew_k;
        break;
    }
}
if ($ew_geladen === '' || !function_exists('ew_takt')) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ew_takt.php: ew_lib.php nicht gefunden: ' . implode(' | ', $ew_kandidaten) . "\n");
    exit(1);
}

/* 0.9.38: Deinstallation - die gemerkten Haus-Themen mit Ruecklesen am Broker abraeumen (uninstall/uninstall). */
if (isset($argv[1]) && $argv[1] === '--haus-leeren') {
    exit(ew_haus_leeren_ausgabe());
}

if (isset($argv[1]) && $argv[1] === '--selbsttest') {
    $ew_fehl = 0;
    $ew_zeile = function ($ok, $text) use (&$ew_fehl) {
        echo ($ok ? '[OK]   ' : '[FEHL] ') . $text . "\n";
        if (!$ok) {
            $ew_fehl++;
        }
    };
    $ew_r = ew_pruef_mqtt_namen(ew_vorgaben());
    $ew_zeile($ew_r[0] === 'ok', 'Namen = Themen: ' . $ew_r[1]);
    $ew_v = ew_mqtt_http_vergleich(ew_mqtt_probeantwort());
    $ew_zeile(!$ew_v['abweichend'], 'MQTT = HTTP: ' . $ew_v['geprueft'] . ' Kernfelder, abweichend: '
        . ($ew_v['abweichend'] ? implode(', ', $ew_v['abweichend']) : '-'));
    $ew_b = ew_wetter_befehle();
    $ew_t = $ew_b[0];
    $ew_b[0] = $ew_b[2];
    $ew_b[2] = $ew_t;
    $ew_e = ew_mqtt_http_vergleich(ew_mqtt_probeantwort(), null, $ew_b);
    $ew_zeile(count($ew_e['abweichend']) === 2, 'Eichung (Suchtexte 1 und 3 vertauscht) muss abweichen: '
        . implode(', ', $ew_e['abweichend']));
    $ew_w = ew_mqtt_werte(ew_mqtt_probeantwort());
    $ew_n = 0;
    foreach ($ew_w['werte'] as $ew_th => $ew_wert) {
        if (ew_mqtt_zahl($ew_wert) !== $ew_wert) {
            $ew_zeile(false, 'keine Zahl: ' . $ew_th);
        }
        $ew_n++;
    }
    $ew_zeile($ew_n === 37, 'Messwerte der nachgebildeten Antwort: ' . $ew_n
        . ' (erwartet 37 = 21 Kern + 5 wahlweise + 7 Batterie + 4 Bodensensor)');
    $ew_zeile(ew_mqtt_gateway_name('status/zaehler') === 'ecowittweiche_status_zaehler', 'Gateway-Name: '
        . ew_mqtt_gateway_name('status/zaehler'));
    /* 0.9.38: Haus-Themen an der nachgebildeten Antwort - Werte, Rundung und Namensregel. */
    list($ew_hw) = ew_haus_werte(array(array('fuehler' => 'aussen', 'familie' => 'wetter', 'name' => 'ecowitt'),
        array('fuehler' => 'basis', 'familie' => 'klima', 'name' => 'ecowitt_basis')),
        json_decode(ew_mqtt_probeantwort(), true), 0, 1700000000, array());
    $ew_hs = array('haus/wetter/ecowitt/temperatur' => '12.3', 'haus/wetter/ecowitt/feuchte' => '78',
        'haus/wetter/ecowitt/druck' => '985.3', 'haus/wetter/ecowitt/wind_kmh' => '4.3',
        'haus/wetter/ecowitt/boeen_kmh' => '7.2', 'haus/wetter/ecowitt/windrichtung_grad' => '215',
        'haus/wetter/ecowitt/regen_mm_h' => '1.2', 'haus/wetter/ecowitt/regen_heute_mm' => '0.4',
        'haus/wetter/ecowitt/uv' => '1', 'haus/wetter/ecowitt/strahlung_wm2' => '123',
        'haus/wetter/ecowitt/zeit' => '1700000000', 'haus/wetter/ecowitt/anbieter' => 'ecowittweiche',
        'haus/klima/ecowitt_basis/temperatur' => '21.4', 'haus/klima/ecowitt_basis/feuchte' => '52',
        'haus/klima/ecowitt_basis/druck' => '985.3', 'haus/klima/ecowitt_basis/zeit' => '1700000000',
        'haus/klima/ecowitt_basis/anbieter' => 'ecowittweiche');
    $ew_zeile($ew_hw === $ew_hs, 'Haus-Themen der nachgebildeten Antwort: ' . count($ew_hw) . ' Werte (erwartet '
        . count($ew_hs) . ', alle gleich)');
    $ew_zeile(ew_haus_name("Garten S\u{00FC}d/Ost") === 'garten_sued_ost' && !ew_haus_name_taugt('Garten')
        && !ew_haus_name_taugt('zaehler') && ew_haus_name_taugt('ecowitt_basis'), 'Haus-Themen, Namensregel');
    echo $ew_fehl ? "SELBSTTEST: $ew_fehl Befund(e)\n" : "SELBSTTEST: in Ordnung\n";
    exit($ew_fehl ? 1 : 0);
}

/* 0.9.32 (Regeln/05): fehlende Vorgaben einmal in ecowitt.json schreiben - vor ew_takt() und damit vor dem ersten
   Lesen der Konfiguration in diesem Lauf. Waehrend einer Aktualisierung (Marke) schreibt die Funktion nichts. */
ew_config_vervollstaendigen();
ew_takt();
exit(0);
