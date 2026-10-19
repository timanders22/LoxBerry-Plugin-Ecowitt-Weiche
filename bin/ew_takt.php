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
    echo $ew_fehl ? "SELBSTTEST: $ew_fehl Befund(e)\n" : "SELBSTTEST: in Ordnung\n";
    exit($ew_fehl ? 1 : 0);
}

ew_takt();
exit(0);
