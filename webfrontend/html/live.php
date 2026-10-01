<?php
/**
 * Ecowitt-Weiche - Endpunkt fuer den Miniserver
 *
 * Aufruf:
 *   /plugins/<Ordner>/live.php              die Stationsdaten, unveraendert
 *   /plugins/<Ordner>/live.php?token=<T>    falls ein Token hinterlegt ist
 *   /plugins/<Ordner>/live.php?status=1     nur die Weiche selbst, als Textzeile
 *
 * Die Antwort ist das JSON der Station, WORTGETREU durchgereicht - ergaenzt um
 * zwei eigene Felder ganz vorn. Damit bleibt jeder Suchtext in Loxone
 * unveraendert gueltig; in der Projektdatei aendert sich nur die Adresse des
 * Behaelters.
 *
 *   "ew_quelle": "primaer" | "ersatz"     welche Schnittstelle getragen hat
 *   "ew_ok":     1                        steht in JEDER Antwort mit Daten
 *
 * ew_ok ist KEIN Ausfallmerker (C10): bei Ausfall kommt 503 ohne Daten, und
 * Loxone behaelt die letzte 1. Fuer den Ausfall zaehlen der Onlinestatus
 * des Behaelters oder OK= aus ?status=1. Das Feld bleibt, damit kein
 * vorhandener Suchtext bricht.
 *
 * FAELLT BEIDES AUS, kommt HTTP 503 OHNE Daten. Das ist Absicht: Loxone
 * behaelt dann seine letzten Werte und schaltet den Onlinestatus des
 * Behaelters ab. Wuerde hier ein zwischengespeicherter Stand geliefert,
 * rechnete das Haus stundenlang mit alten Zahlen weiter, ohne dass es jemand
 * merkt - und genau diese stille Sorte Fehler soll das Plugin beenden.
 *
 * Lesender Aufruf, deshalb ohne Zwang zum Token. Ist in der Oberflaeche eines
 * hinterlegt, gilt es auch hier.
 *
 * Der Endpunkt schreibt nichts, bevor die Anfrage angenommen ist (C7): die
 * Konfiguration wird ohne Heilung und ohne Anlegen gelesen. Fehlt sie oder
 * ist sie unlesbar, kommt 503 "nicht eingerichtet"; das erste Wortzeichen
 * legt die Oberflaeche an.
 *
 *   /plugins/<Ordner>/live.php?selftest=1   fuer den Reiter Test: prueft nur
 *                                           Token und Konfiguration, fragt
 *                                           keine Station
 *
 * (c) Ecowitt-Weiche Plugin Authors - MIT-Lizenz
 */

require_once __DIR__ . '/ew_lib.php';

$ew_cfg = ew_config(false);
if ($ew_cfg === null) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nicht eingerichtet: die Konfiguration fehlt oder ist unlesbar. Bitte die Plugin-Oberflaeche einmal oeffnen.\n";
    exit;
}

/* Token nur pruefen, wenn eines gesetzt ist. hash_equals statt == : ein
   zeichenweiser Vergleich verraet ueber die Antwortzeit Zeichen fuer Zeichen.
   Nur eine Zeichenkette ist ein Token (C2). Bis 0.9.14 machte (string) aus
   token[]=x ein "Array" - mit display_errors=1 ging die Warnung vor dem
   header() hinaus, und die Abweisung kam als HTTP 200 an (Pruefer code,
   Befund 5). ew_config(false) liefert das Soll nur als Zeichenkette. */
$ew_soll = $ew_cfg['token'];
if ($ew_soll !== '') {
    $ew_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
    if ($ew_ist === '' || !hash_equals($ew_soll, $ew_ist)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Zugriff verweigert: falsches oder fehlendes Token.\n";
        exit;
    }
}

/* Selbsttest fuer den Reiter Test: Token und Konfiguration stimmen, der
   Endpunkt laeuft. Keine Station, kein Schreiben. */
if (isset($_GET['selftest'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'SELFTEST;OK=1;TOKEN=' . ($ew_soll !== '' ? 'OK' : 'OHNE') . "\n";
    exit;
}

$ew_w = ew_weiche($ew_cfg, ew_stand_lesen());
$ew_stand = ew_stand_schreiben($ew_w);

/* Kurzform fuer die Diagnose und fuer einen eigenen Loxone-Eingang:
   WEICHE;OK=1;QUELLE=1;WECHSEL=3   (QUELLE 1 = primaer, 2 = ersatz, 0 = keine) */
if (isset($_GET['status'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $q = ($ew_w['quelle'] === 'primaer') ? 1 : (($ew_w['quelle'] === 'ersatz') ? 2 : 0);
    /* a1: traegt die letzte brauchbare Antwort einen Zeitstempel der
       Station, zaehlt er (ew_alter); das Format der Zeile bleibt. */
    $alter = ew_alter($ew_stand);
    printf("WEICHE;OK=%d;QUELLE=%d;WECHSEL=%d;ALTER=%d\n",
        $ew_w['ok'] ? 1 : 0, $q, (int) $ew_stand['wechsel'], $alter);
    exit;
}

if (!$ew_w['ok']) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Keine Station erreichbar.\n";
    foreach ($ew_w['grund'] as $seite => $grund) {
        echo '  ' . $seite . ': ' . $grund . "\n";
    }
    exit;
}

/* Die eigenen Felder VORN einsetzen, ohne das uebrige JSON anzufassen.
   Neu zu serialisieren waere riskant: json_encode formatiert Zahlen anders
   als die Station, und in Loxone haengen 32 Suchtexte an der genauen
   Schreibweise. Deshalb nur die oeffnende Klammer ersetzen. */
$ew_roh = $ew_w['roh'];
$ew_pos = strpos($ew_roh, '{');
if ($ew_pos === false) {
    header('HTTP/1.1 502 Bad Gateway');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unerwartete Antwort der Station.\n";
    exit;
}
$ew_kopf = '{"ew_quelle":"' . $ew_w['quelle'] . '","ew_ok":1,';
$ew_rest = ltrim(substr($ew_roh, $ew_pos + 1));
/* Ist das Objekt leer, darf kein Komma stehen bleiben. */
if ($ew_rest === '' || $ew_rest[0] === '}') {
    $ew_kopf = '{"ew_quelle":"' . $ew_w['quelle'] . '","ew_ok":1';
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo substr($ew_roh, 0, $ew_pos) . $ew_kopf . $ew_rest;
