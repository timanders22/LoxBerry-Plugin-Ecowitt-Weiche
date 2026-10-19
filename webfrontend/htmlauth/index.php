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

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
$ew_geladen = '';
foreach ($ew_kandidaten as $ew_k) {
    if (is_file($ew_k)) {
        require_once $ew_k;
        $ew_geladen = $ew_k;
        break;
    }
}
if ($ew_geladen === '' || !function_exists('ew_paths')) {
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
   loxberry_system.php zuerst: loxberry_web.php baut darauf auf.
   Die Wurzel kommt aus ew_paths() (LBHOMEDIR, sonst Suche aufwaerts); bis
   0.9.12 stand hier ein Rueckfall auf den festen Geraetepfad, und ohne LBHOMEDIR
   blieb in jedem anderen Baum der Rahmen weg (Fall W4, vorher rot). */
$ew_lb = ew_paths()['lbhome'];
if ($ew_lb !== '' && is_file($ew_lb . '/libs/phplib/loxberry_system.php')) {
    require_once $ew_lb . '/libs/phplib/loxberry_system.php';
    if (is_file($ew_lb . '/libs/phplib/loxberry_web.php')) {
        require_once $ew_lb . '/libs/phplib/loxberry_web.php';
    }
}

/* ---------- Sprache ------------------------------------------------------ */


/* ---------- Reiter: Positivliste, ids und Leiste gehoeren zusammen -------- */
$ew_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test');
$ew_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $ew_reiter, true)) {
    $ew_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && in_array('tab-' . $_GET['form'], $ew_reiter, true)) {
    $ew_tab = 'tab-' . $_GET['form'];
}

/* ---------- Eingaben verarbeiten ----------------------------------------- */
/* ew_config() merkt sich beim ERSTEN Lesen den Zustand der Datei, vor der
   Heilung - der Reiter Test zeigt ihn (ew_config_zustand, O5). */
$ew_cfg = ew_config();
$ew_meldung = '';
$ew_hinweise = array();
$ew_fehler = array();
$ew_probe = null;
$ew_eingaben = null;
$ew_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

/* Das Ergebnis der vorigen Anfrage (Einmalmeldung, O1) - NUR beim GET
   gelesen. Beim POST hat es nichts zu suchen: ein Fehlertext von vorhin
   landete sonst in der Sammelliste und verhinderte lautlos das naechste
   Speichern (Regeln/04, Docker NG). */
if (!$ew_post) {
    $ew_einmal = ew_einmal_lesen();
    if (is_array($ew_einmal)) {
        if (isset($ew_einmal['meldung']) && is_string($ew_einmal['meldung'])) {
            $ew_meldung = $ew_einmal['meldung'];
        }
        if (isset($ew_einmal['hinweise']) && is_array($ew_einmal['hinweise'])) {
            $ew_hinweise = array_values($ew_einmal['hinweise']);
        }
        if (isset($ew_einmal['fehler']) && is_array($ew_einmal['fehler'])) {
            $ew_fehler = array_values($ew_einmal['fehler']);
        }
        if (isset($ew_einmal['probe']) && is_array($ew_einmal['probe'])) {
            $ew_probe = $ew_einmal['probe'];
        }
        /* X-2: die eingetippten Werte nach einer Beanstandung. */
        if (isset($ew_einmal['eingaben'])) {
            ew_eingaben_setzen($ew_einmal['eingaben']);
        }
    }
}

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$ew_wache = ew_wachposten();
if ($ew_wache !== '') {
    $ew_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($ew_reiter_merk !== null) {
        $_POST['activetab'] = $ew_reiter_merk;
    }
    $ew_fehler[] = $ew_wache;
}

/* Ein Formularfeld als Zeichenkette, am Rand gekuerzt; ein Feld (name[]=)
   ergibt '' statt "Array". */
function ew_feld($name)
{
    return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
}

/* ---------------- Einstellungen speichern (O2) ----------------
 *
 * Abweisen und benennen, nie still zurechtbiegen (Regeln/05). Bis 0.9.14
 * wurde gespeichert und "gespeichert" gemeldet: Wartezeit leer -> 1 s,
 * 100.4 -> 30, abc -> 1; Pfad ohne / -> /get_livedata_info; aus dem
 * Wortzeichen abc def"x'y wurde still abcdefxy; Port 99999 ging durch
 * (Pruefer oberflaeche, Befund 4). Jetzt werden ALLE Beanstandungen
 * gesammelt, und bei einer einzigen wird GAR NICHTS gespeichert. Leer
 * heisst bei Wartezeit und Pfad: die Vorgabe - und das wird gesagt. */
if ($ew_post && isset($_POST['speichern'])) {
    $ew_neu = $ew_cfg;
    $ew_mangel = array();
    $ew_falsch = array();
    $ew_vg = ew_vorgaben();
    foreach (array('primaer' => 'TEXT.L_PRIMAER', 'ersatz' => 'TEXT.L_ERSATZ') as $ew_s => $ew_l) {
        $ew_roh = ew_feld($ew_s);
        if ($ew_roh !== '' && ew_adresse_pruefen($ew_roh) === '') {
            $ew_mangel[] = sprintf(ew_t('TEXT.ADRESSE_FELD_UNGUELTIG'), ew_t($ew_l), ew_kurz($ew_roh, 60));
            $ew_falsch[] = $ew_s;
        } else {
            $ew_neu[$ew_s] = $ew_roh;
        }
    }
    $ew_roh = ew_feld('pfad');
    if ($ew_roh === '') {
        $ew_neu['pfad'] = $ew_vg['pfad'];
        $ew_hinweise[] = ew_t('TEXT.PFAD_VORGABE');
    } elseif (!ew_pfad_taugt($ew_roh)) {
        $ew_mangel[] = sprintf(ew_t('TEXT.PFAD_UNGUELTIG'), ew_kurz($ew_roh, 60));
        $ew_falsch[] = 'pfad';
    } else {
        $ew_neu['pfad'] = $ew_roh;
    }
    $ew_roh = ew_feld('timeout');
    if ($ew_roh === '') {
        $ew_neu['timeout'] = $ew_vg['timeout'];
        $ew_hinweise[] = sprintf(ew_t('TEXT.TIMEOUT_VORGABE'), (int) $ew_vg['timeout']);
    } elseif (ew_timeout_wert($ew_roh) === null) {
        $ew_mangel[] = sprintf(ew_t('TEXT.TIMEOUT_UNGUELTIG'), ew_kurz($ew_roh, 20), EW_WARTEZEIT_MAX,
            EW_LOX_TIMEOUT_MAX);
        $ew_falsch[] = 'timeout';
    } else {
        $ew_neu['timeout'] = ew_timeout_wert($ew_roh);
    }
    $ew_neu['pruefe_wert'] = empty($_POST['pruefe_wert']) ? 0 : 1;
    /* Ein LEERES Feld heisst hier NICHT "loeschen". Ein Feld, das nichts
       anzuzeigen hat, sieht genauso aus wie eines, das jemand absichtlich
       geleert hat - und ein Geheimnis darf sich nicht als Nebenwirkung
       des Speicherns aendern. Zum Entfernen gibt es einen eigenen Knopf,
       der im Namen sagt, was er tut. Ein Wert mit fremden Zeichen wird
       abgewiesen, nicht gekuerzt (Regeln/05 Z. 186). */
    $ew_roh = ew_feld('token');
    if ($ew_roh !== '') {
        if (!ew_token_taugt($ew_roh)) {
            $ew_mangel[] = sprintf(ew_t('TEXT.TOKEN_UNGUELTIG'), strlen($ew_roh));
            $ew_falsch[] = 'token';
        } else {
            $ew_neu['token'] = $ew_roh;
        }
    }
    if ($ew_mangel) {
        $ew_hinweise = array();
        $ew_fehler[] = array('kopf' => ew_t('TEXT.NICHT_GESPEICHERT'), 'punkte' => $ew_mangel);
        /* X-2: die eingetippten Werte reisen mit (nie das Wortzeichen). */
        $ew_eingaben = ew_eingaben_sammeln($ew_falsch);
        if ($ew_eingaben !== null) {
            $ew_hinweise[] = ew_t('TEXT.EINGABEN_ZURUECK');
        }
    } elseif (ew_config_speichern($ew_neu)) {
        /* Gemeldet wird, was geschah: bis 0.9.12 stand hier "gespeichert"
           auch dann, wenn die Datei nicht geschrieben war (Fall O1). */
        $ew_meldung = ew_t('TEXT.GESPEICHERT');
    } else {
        $ew_fehler[] = ew_t('TEXT.SICH_SCHREIBFEHLER');
    }
    $ew_cfg = ew_config();
}

if ($ew_post && isset($_POST['token_neu'])) {
    $ew_cfg['token'] = ew_token_erzeugen();
    if (ew_config_speichern($ew_cfg)) {
        $ew_meldung = ew_t('TEXT.TOKEN_ERZEUGT');
    } else {
        $ew_fehler[] = ew_t('TEXT.SICH_SCHREIBFEHLER');
    }
    $ew_cfg = ew_config();
}

if ($ew_post && isset($_POST['token_weg'])) {
    $ew_cfg['token'] = '';
    if (ew_config_speichern($ew_cfg)) {
        $ew_meldung = ew_t('TEXT.TOKEN_ENTFERNT');
    } else {
        $ew_fehler[] = ew_t('TEXT.SICH_SCHREIBFEHLER');
    }
    $ew_cfg = ew_config();
}

/* ---------------- MQTT speichern (Gruppe 3, 0.9.25) ----------------
 * Eigenes Formular, eigener Handler (Regeln/07). Abweisen und benennen wie beim
 * Einstellungsformular: ein unzulaessiges Praefix speichert GAR NICHTS. Danach geht
 * beim naechsten Minutenlauf der volle Satz hinaus (Merker takt.voll), und die
 * Abo-Datei fuer Gateway V1 wird auf <praefix>/# nachgezogen (Entwurf 2.3/2.7). */
if ($ew_post && isset($_POST['mqtt_speichern'])) {
    $ew_neu = $ew_cfg;
    $ew_neu['mqtt_ein'] = empty($_POST['mqtt_ein']) ? 0 : 1;
    $ew_roh = ew_feld('mqtt_praefix');
    if ($ew_roh === '') {
        $ew_neu['mqtt_praefix'] = EW_MQTT_PRAEFIX_VORGABE;
        $ew_hinweise[] = sprintf(ew_t('MQTT.PRAEFIX_VORGABE'), EW_MQTT_PRAEFIX_VORGABE);
    } elseif (!ew_mqtt_praefix_taugt($ew_roh)) {
        $ew_fehler[] = array('kopf' => ew_t('TEXT.NICHT_GESPEICHERT'),
            'punkte' => array(sprintf(ew_t('MQTT.PRAEFIX_UNGUELTIG'), ew_kurz($ew_roh, 60))));
        $ew_neu = null;
    } else {
        $ew_neu['mqtt_praefix'] = $ew_roh;
    }
    if ($ew_neu !== null) {
        if (ew_config_speichern($ew_neu)) {
            $ew_meldung = ew_t('MQTT.GESPEICHERT');
            ew_takt_voll_merken();
            if (!ew_abo_schreiben($ew_neu)) {
                $ew_hinweise[] = ew_t('MQTT.ABO_NICHT_GESCHRIEBEN');
            }
        } else {
            $ew_fehler[] = ew_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
    $ew_cfg = ew_config();
}

/* ---------------- Abruftest (O4) ----------------
 * Derselbe Abruf und dieselbe Beurteilung wie im Endpunkt, mit demselben
 * Schalter "Inhalt pruefen". Bis 0.9.14 pruefte der Test die Aussenfeuchte
 * immer - bei ausgeschaltetem Schalter stand er rot, waehrend live.php
 * Daten lieferte (Pruefer oberflaeche, Befund 7). Der Grund wird getrennt
 * genannt: keine Verbindung, Zeitueberschreitung, HTTP-Status, Umleitung,
 * HTML statt JSON, Platzhalter. */
if ($ew_post && isset($_POST['pruefen'])) {
    $ew_probe = array();
    $ew_inhalt = !empty($ew_cfg['pruefe_wert']);
    foreach (array('primaer', 'ersatz') as $seite) {
        $adr = ew_adresse_sauber($ew_cfg[$seite]);
        if ($adr === '') {
            $ew_probe[$seite] = array('adr' => '', 'ok' => false, 'text' => ew_t('TEXT.KEINE_ADRESSE'));
            continue;
        }
        $info = null;
        $roh = ew_abrufen($adr, $ew_cfg['pfad'], $ew_cfg['timeout'], $info);
        if ($roh === false) {
            $ew_probe[$seite] = array('adr' => $adr, 'ok' => false, 'ms' => $info['ms'],
                                      'text' => ew_abruf_text($info));
            continue;
        }
        $grund = '';
        $gut = ew_brauchbar($roh, $grund, $ew_inhalt);
        $werte = array();
        $d = json_decode($roh, true);
        if (is_array($d) && isset($d['common_list']) && is_array($d['common_list'])) {
            foreach ($d['common_list'] as $x) {
                if (is_array($x) && isset($x['id']) && in_array($x['id'], array('0x02', '0x07', '0x15', '0x17'), true)) {
                    $werte[$x['id']] = (isset($x['val']) && !is_array($x['val'])) ? (string) $x['val'] : '';
                }
            }
        }
        $ew_probe[$seite] = array('adr' => $adr, 'ok' => $gut, 'ms' => $info['ms'],
            'text' => $gut ? ew_t($ew_inhalt ? 'TEXT.BRAUCHBAR' : 'TEXT.BRAUCHBAR_OHNE_PRUEFUNG') : $grund,
            'werte' => $werte);
    }
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 * Der Download ist die eine Antwort ohne Umleitung (Regeln/04). */
if ($ew_post && isset($_POST['ew_sichern'])) {
    /* X-3 (Verbesserungsbau 01.10.2026): bestuende ein gespeicherter Wert das
       eigene Zurueckspielen nicht, sagt es der Kopf der Datei - mit den
       NAMEN, nie den Werten. Geliefert wird trotzdem, vollstaendig. */
    $ew_sich = ew_config();
    $ew_altw = ew_rueckspiel_altwerte($ew_sich);
    if ($ew_altw) {
        $ew_sich = array('_warnung' => sprintf(ew_t('TEXT.SICH_ALTWERT_KOPF'), implode(', ', $ew_altw))) + $ew_sich;
    }
    $ew_js = json_encode($ew_sich,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ew_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ecowitt_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $ew_js;
        exit;
    }
    $ew_fehler[] = ew_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen (C1) ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen.
 *
 * "Uebernommen" heisst seit 0.9.15: Konfiguration UND Zweitschrift sind
 * geschrieben, und ein erneutes Lesen zeigt den zurueckgespielten Stand.
 * Bis 0.9.14 kam die Meldung, auch wenn ew_config() den Stand im selben
 * Aufruf aus der Zweitschrift zurueckgeheilt hatte (Pruefer code, Befund 2). */
if ($ew_post && isset($_POST['ew_zurueck'])) {
    if (!isset($_FILES['ew_sicherung']) || !is_array($_FILES['ew_sicherung'])
        || !isset($_FILES['ew_sicherung']['tmp_name']) || !is_string($_FILES['ew_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ew_sicherung']['tmp_name'])) {
        $ew_fehler[] = ew_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ew_sicherung']['size'] > 262144) {
        $ew_fehler[] = ew_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($ew_neu, $ew_mangel, $ew_n, $ew_hinw) = ew_sicherung_lesen(
            (string) @file_get_contents($_FILES['ew_sicherung']['tmp_name']));
        if ($ew_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $ew_fehler[] = array('kopf' => ew_t('TEXT.SICH_ABGELEHNT'), 'punkte' => $ew_mangel);
        } else {
            $ew_zweit = false;
            if (!ew_config_speichern($ew_neu, $ew_zweit)) {
                $ew_fehler[] = ew_t('TEXT.SICH_SCHREIBFEHLER');
            } elseif (!$ew_zweit) {
                $ew_fehler[] = ew_t('TEXT.SICH_ZWEIT_FEHLT');
            } else {
                $ew_datei = ew_json_lesen(ew_paths()['cfgdatei']);
                $ew_cfg = ew_config();
                $ew_gleich = is_array($ew_datei);
                foreach (array_keys(ew_vorgaben()) as $ew_k) {
                    if (!$ew_gleich) {
                        break;
                    }
                    $ew_gleich = array_key_exists($ew_k, $ew_datei)
                        && $ew_datei[$ew_k] === $ew_neu[$ew_k] && $ew_cfg[$ew_k] === $ew_neu[$ew_k];
                }
                if ($ew_gleich) {
                    $ew_meldung = sprintf(ew_t('TEXT.SICH_UEBERNOMMEN'), $ew_n);
                    $ew_hinweise = array_merge($ew_hinweise, $ew_hinw);
                } else {
                    $ew_fehler[] = ew_t('TEXT.SICH_NICHT_WIRKSAM');
                }
            }
        }
    }
}

/* ---------------- Loxone-Vorlagen (Welle Bild 13, 0.9.22) ----------------
 * Entwurf Welle 13 ew, Entscheidung 10: die Vorlagen der beiden Behaelter als Download
 * (ew_vorlage_zustand(), ew_vorlage_wetter() in ew_lib.php). Lesen nur die Konfiguration und
 * schreiben nichts; wie das Sichern die eine Antwort ohne Umleitung (Regeln/04). Der Wachposten
 * oben hat einen fremden POST schon abgewiesen und $_POST geleert. */
if ($ew_post && (isset($_POST['vorlage_zustand']) || isset($_POST['vorlage_wetter']))) {
    $ew_vl = isset($_POST['vorlage_zustand']) ? ew_vorlage_zustand(ew_rechner(), $ew_cfg)
                                               : ew_vorlage_wetter(ew_rechner(), $ew_cfg);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $ew_vl[0] . '"');
    echo $ew_vl[1];
    exit;
}

/* ---------------- Umleitung nach jedem POST (O1, PRG) ----------------
 * Jeder POST endet mit 303 auf index.php?form=<reiter>; das Ergebnis reist
 * als Einmalmeldung. Neuladen holt dann nur die Seite, es wiederholt keine
 * Handlung. Laesst sich die Einmalmeldung nicht ablegen (Datenordner nicht
 * beschreibbar), wird die Seite wie bis 0.9.14 gleich gezeigt, mit einem
 * Hinweis: lieber ein Neuladen, das nachfragt, als eine verlorene Meldung. */
if ($ew_post) {
    $ew_m = array('meldung' => $ew_meldung, 'hinweise' => $ew_hinweise,
                  'fehler' => $ew_fehler, 'probe' => $ew_probe);
    if ($ew_eingaben !== null) {
        $ew_m['eingaben'] = $ew_eingaben;
    }
    if (ew_einmal_schreiben($ew_m)) {
        header('Location: index.php?form=' . rawurlencode(substr($ew_tab, 4)), true, 303);
        exit;
    }
    $ew_hinweise[] = ew_t('TEXT.EINMAL_FEHLT');
}

$ew_stand = ew_stand_lesen();
/* b1: die abgelegte Projektdatei nur lesen, wenn einer der beiden Reiter
   offen ist, die sie zeigen - umgeschaltet wird ueber den Server, ein
   geschlossener Reiter wird nie angesehen. */
$ew_proj = ($ew_tab === 'tab-loxone' || $ew_tab === 'tab-test') ? ew_projekt_pruefen($ew_cfg) : null;
$ew_host = ew_rechner();
$ew_plugin = ew_paths()['plugin'];
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
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
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
/* Eigene Zutat (X-2, Verbesserungsbau 01.10.2026): das beanstandete Feld
   nach einer Abweisung, wie Heimkino 1.3.16. */
.sm-wrap input.sm-beanstandet { border: 2px solid #c62828 !important; background-color: #fff5f5; }
/* Ergaenzung (Welle Bild, Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
/* Welle Bild 13 (0.9.22): Abschnitte, Schritte und Hinweise je Baustein-Liste (wie Fensterbilanz 0.12.16). */
.sm-wrap h4.sm-h4 { color: #4f7d17; font-size: 0.98em; font-weight: 700; margin: 22px 0 4px;
    padding-top: 10px; border-top: 1px solid #e0e0e0; }
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }
/* Gruppe 3 (0.9.25): Kopierknopf der Namenstabelle und zugeklappter Abschnitt "Bestehende Anlagen"
   (wie Bewaesserung 0.9.44). */
.sm-wrap button.sm-kopier { font-size: 0.8em; padding: 1px 6px; margin-left: 4px; border: 1px solid #ccc;
    border-radius: 4px; background: #f5f5f5; color: #333; cursor: pointer; }
.sm-wrap details { margin: 16px 0; }
.sm-wrap details > summary { cursor: pointer; font-weight: 600; color: #4f7d17; margin: 6px 0; }
</style>

<div class="sm-wrap">

<?php if ($ew_meldung !== '') { ?><div class="sm-hinweis"><?= ew_e($ew_meldung) ?></div><?php } ?>
<?php foreach ($ew_hinweise as $ew_h) { if (is_string($ew_h)) { ?><div class="sm-warnung"><?= ew_e($ew_h) ?></div><?php } } ?>
<?php foreach ($ew_fehler as $ew_fehler_m) {
    if (is_array($ew_fehler_m)) {
        $ew_kopf = isset($ew_fehler_m['kopf']) && is_string($ew_fehler_m['kopf']) ? $ew_fehler_m['kopf'] : '';
        $ew_punkte = isset($ew_fehler_m['punkte']) && is_array($ew_fehler_m['punkte']) ? $ew_fehler_m['punkte'] : array(); ?>
<div class="sm-fehler"><?= ew_e($ew_kopf) ?><ul><?php foreach ($ew_punkte as $ew_p) { if (is_string($ew_p)) { ?><li><?= ew_e($ew_p) ?></li><?php } } ?></ul></div>
<?php } elseif (is_string($ew_fehler_m)) { ?><div class="sm-fehler"><?= ew_e($ew_fehler_m) ?></div><?php }
} ?>

<?php /* Kopf (Entscheidung Nr. 43, seit 0.9.19): Statusuebersicht ueber den
   Reitern, immer sichtbar. Nur der Stand des letzten Abrufs aus stand.json
   ($ew_stand, oben schon gelesen) - die Seite fragt die Station dafuer NICHT
   an. Die Weiche hat keinen Dienst: live.php antwortet auf jede Anfrage des
   Miniservers. Die Kacheln im Reiter Test bleiben dort. */
$ew_k_lg = isset($ew_stand['letzte_gute']) ? (int) $ew_stand['letzte_gute'] : 0;
$ew_k_ts = isset($ew_stand['ts']) ? (int) $ew_stand['ts'] : 0;
$ew_k_q = isset($ew_stand['quelle']) ? (string) $ew_stand['quelle'] : ''; ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?php echo ew_t('TEXT.KOPF_EIGENSCHAFT'); ?></th><th><?php echo ew_t('TEXT.KOPF_WERT'); ?></th></tr>
<?php /* Gruppe 3 (0.9.25): die Zeile "Dienst" nennt den Minutentakt (MQTT) und seinen letzten Lauf aus
         takt.json; ohne MQTT bleibt es beim Abruf durch den Miniserver. */
$ew_k_takt = ew_takt_lesen();
$ew_k_tts = isset($ew_k_takt['ts']) ? (int) $ew_k_takt['ts'] : 0; ?>
<tr><td><?php echo ew_t('TEXT.KOPF_DIENST'); ?></td><td><?php
if (empty($ew_cfg['mqtt_ein'])) {
    echo ew_t('TEXT.KOPF_OHNE_DIENST');
} elseif ($ew_k_tts > 0) {
    echo ew_e(sprintf(ew_t('TEXT.KOPF_TAKT'), ew_alter_text(time() - $ew_k_tts)));
} else {
    echo ew_e(ew_t('TEXT.KOPF_TAKT_NIE'));
} ?></td></tr>
<tr><td><?php echo ew_t('TEXT.TRAEGT_GERADE'); ?></td>
<?php if (empty($ew_stand)) { ?>
  <td><?php echo ew_t('TEXT.KOPF_NOCH_KEIN_ABRUF'); ?></td>
<?php } elseif ($ew_k_q === 'primaer' || $ew_k_q === 'ersatz') { ?>
  <td class="sm-an"><?= $ew_k_q === 'primaer' ? ew_t('TEXT.PRIMAER') : ew_t('TEXT.ERSATZ') ?></td>
<?php } else { ?>
  <td class="sm-aus"><?php echo ew_t('TEXT.KOPF_KEINE_SEITE'); ?></td>
<?php } ?></tr>
<tr><td><?php echo ew_t('TEXT.LETZTE_GUTE'); ?></td>
  <td><?= $ew_k_lg > 0 ? ew_e(date('d.m.Y H:i:s', $ew_k_lg)) . ' (' . ew_e(ew_alter_text(time() - $ew_k_lg)) . ')' : '—' ?></td></tr>
<tr><td><?php echo ew_t('TEXT.LETZTER_ABRUF'); ?></td>
  <td><?= $ew_k_ts > 0 ? ew_e(date('d.m.Y H:i:s', $ew_k_ts)) . ' (' . ew_e(ew_alter_text(time() - $ew_k_ts)) . ')' : '—' ?></td></tr>
<tr><td><?php echo ew_t('TEXT.WECHSEL_GESAMT'); ?></td>
  <td><?= (int) (isset($ew_stand['wechsel']) ? $ew_stand['wechsel'] : 0) ?></td></tr>
</table>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da, nicht in einer Schleife
     erzeugt. Umgeschaltet wird ueber den Server, damit jeder Reiter
     verlinkbar und die Seite ohne Skript bedienbar bleibt; das Merkmal am
     Verweis nennt daneben den Bereich, zu dem er gehoert, und macht das
     Paar fuer die Hauspruefung lesbar. Ob Leiste, Bereiche und Positivliste
     wirklich dieselben Namen fuehren, zaehlt der Reiter Test nach. -->
<div class="sm-tabs">
  <a class="sm-tab<?= $ew_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
     href="index.php?form=settings"><?php echo ew_t('REITER.EINSTELLUNGEN'); ?></a>
  <a class="sm-tab<?= $ew_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
     href="index.php?form=mqtt"><?php echo ew_t('REITER.MQTT'); ?></a>
  <a class="sm-tab<?= $ew_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
     href="index.php?form=loxone"><?php echo ew_t('REITER.LOXONE'); ?></a>
  <a class="sm-tab<?= $ew_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
     href="index.php?form=test"><?php echo ew_t('REITER.TEST'); ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $ew_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?php echo ew_t('TEXT.WAS_IST_DAS'); ?></div>
<h2><?php echo ew_t('TEXT.H_EINSTELLUNGEN'); ?></h2>

<div class="sm-step"><?php echo ew_t('TEXT.WARUM'); ?></div>

<form action="index.php" method="post">
  <?php echo ew_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_PRIMAER'); ?></label>
  <input data-role="none" type="text" name="primaer" value="<?= ew_e(ew_eingabe('primaer', $ew_cfg['primaer'])) ?>" placeholder="192.168.178.20"<?= ew_markierung('primaer') ?>>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_PRIMAER'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_ERSATZ'); ?></label>
  <input data-role="none" type="text" name="ersatz" value="<?= ew_e(ew_eingabe('ersatz', $ew_cfg['ersatz'])) ?>" placeholder="192.168.178.21"<?= ew_markierung('ersatz') ?>>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_ERSATZ'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_PFAD'); ?></label>
  <input data-role="none" type="text" name="pfad" value="<?= ew_e(ew_eingabe('pfad', $ew_cfg['pfad'])) ?>"<?= ew_markierung('pfad') ?>>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_PFAD'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_TIMEOUT'); ?></label>
  <input data-role="none" type="number" min="1" max="<?= (int) EW_WARTEZEIT_MAX ?>" name="timeout" value="<?= ew_e(ew_eingabe('timeout', $ew_cfg['timeout'])) ?>"<?= ew_markierung('timeout') ?>>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_TIMEOUT'); ?></p>
<?php /* k2 (X-3): eine gespeicherte Wartezeit ueber der Grenze wirkt weiter,
         das naechste Speichern weist sie ab - das steht hier VORHER. */
if (ew_wartezeit_zu_lang($ew_cfg)) { ?>
  <div class="sm-warnung"><?php printf(ew_t('TEXT.TIMEOUT_ALT'), (int) $ew_cfg['timeout'], EW_WARTEZEIT_MAX,
      EW_LOX_TIMEOUT_MAX, ew_behaelter_timeout_noetig_ms($ew_cfg)); ?></div>
<?php } ?>
</div>

<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="pruefe_wert" value="1"<?= ew_eingabe_an('pruefe_wert', $ew_cfg['pruefe_wert']) ? ' checked' : '' ?>>
    <?php echo ew_t('TEXT.L_PRUEFE'); ?></label>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_PRUEFE'); ?></p>
</div>

<div class="sm-feld">
  <label><?php echo ew_t('TEXT.L_TOKEN'); ?></label>
  <input data-role="none" type="text" name="token" value="<?= ew_e($ew_cfg['token']) ?>"<?= ew_markierung('token') ?>>
  <p class="sm-hilfe"><?php echo ew_t('TEXT.H_TOKEN'); ?></p>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo ew_t('TEXT.SPEICHERN'); ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo ew_t('TEXT.TOKEN_NEU'); ?></button>
<?php if ($ew_cfg['token'] !== '') { ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_weg" value="1"><?php echo ew_t('TEXT.TOKEN_WEG'); ?></button>
<?php } ?>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo ew_t('LEGENDE.AKTION'); ?></span> <span><i class="sm-punkt sm-b-lesen"></i> <?php echo ew_t('LEGENDE.LESEN'); ?></span></div>
<p class="sm-hilfe"><?php echo ew_t('TEXT.TOKEN_KNOEPFE'); ?></p>
</form>

<h2><?= ew_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= ew_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ew_t('TEXT.SICH_WARNUNG') ?></div>
<?php $ew_altwerte = ew_rueckspiel_altwerte($ew_cfg); if ($ew_altwerte) { ?>
<div class="sm-warnung"><?php printf(ew_t('TEXT.SICH_ALTWERT'), ew_e(implode(', ', $ew_altwerte))); ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo ew_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ew_sichern" value="1"><?= ew_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo ew_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="ew_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ew_zurueck" value="1"><?= ew_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= MQTT (Gruppe 3, 0.9.25) ================= -->
<?php /* Entwurf Gruppe 3 ew, Abschnitt 2.7 (Regeln/07 7: eigenes Formular, eigener Handler): Haken und
         Praefix; dazu das Gateway laut general.json, die Abo-Datei fuer V1, die Themenliste aus
         ew_mqtt_namen() und der letzte Lauf des Minutentakts aus takt.json. Die Seite fragt die Station
         dafuer nicht an. */
$ew_mq_gw = ew_mqtt_gateway();
$ew_mq_pf = ew_mqtt_praefix($ew_cfg);
$ew_mq_takt = ew_takt_lesen();
list($ew_mq_abo_da, $ew_mq_abo_ok) = ew_abo_pruefen($ew_cfg); ?>
<div class="sm-seite<?= $ew_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?php echo ew_t('MQTT.H'); ?></h2>
<div class="sm-step"><?php echo ew_t('MQTT.ERKLAERUNG'); ?></div>

<form action="index.php" method="post">
  <?php echo ew_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($ew_cfg['mqtt_ein']) ? ' checked' : '' ?>>
    <?php echo ew_t('MQTT.L_EIN'); ?></label>
  <p class="sm-hilfe"><?php echo ew_t('MQTT.H_EIN'); ?></p>
</div>
<div class="sm-feld">
  <label><?php echo ew_t('MQTT.L_PRAEFIX'); ?></label>
  <input data-role="none" type="text" name="mqtt_praefix" value="<?= ew_e(isset($ew_cfg['mqtt_praefix']) ? $ew_cfg['mqtt_praefix'] : EW_MQTT_PRAEFIX_VORGABE) ?>" placeholder="<?= ew_e(EW_MQTT_PRAEFIX_VORGABE) ?>">
  <p class="sm-hilfe"><?php echo ew_t('MQTT.H_PRAEFIX'); ?></p>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtt_speichern" value="1"><?php echo ew_t('MQTT.K_SPEICHERN'); ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo ew_t('LEGENDE.AKTION'); ?></span></div>
</form>

<h3><?php echo ew_t('MQTT.H_GATEWAY'); ?></h3>
<table class="sm-tbl" style="max-width:620px">
<tr><td><?php echo ew_t('MQTT.T_FASSUNG'); ?></td><td><?= $ew_mq_gw['fassung'] > 0 ? 'V' . (int) $ew_mq_gw['fassung'] : ew_e(ew_t('MQTT.FASSUNG_UNBEKANNT')) ?></td></tr>
<tr><td><?php echo ew_t('MQTT.T_AUTOSTART'); ?></td><td class="<?= $ew_mq_gw['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?php echo ew_t($ew_mq_gw['autostart'] ? 'MQTT.AUTOSTART_EIN' : 'MQTT.AUTOSTART_AUS'); ?></td></tr>
<tr><td><?php echo ew_t('MQTT.T_UDP'); ?></td><td class="sm-mono"><?= (int) $ew_mq_gw['udpport'] ?></td></tr>
<tr><td><?php echo ew_t('MQTT.T_ABO'); ?></td><td><?= $ew_mq_abo_ok ? '<span class="sm-an">' . ew_e(sprintf(ew_t('MQTT.ABO_TRAEGT'), $ew_mq_pf . '/#')) . '</span>'
    : '<span class="sm-aus">' . ew_e(sprintf(ew_t($ew_mq_abo_da ? 'MQTT.ABO_FALSCH' : 'MQTT.ABO_FEHLT'), $ew_mq_pf . '/#')) . '</span>' ?></td></tr>
</table>
<?php if (!empty($ew_cfg['mqtt_ein']) && !$ew_mq_gw['autostart']) { ?>
<div class="sm-warnung"><?php echo ew_t('MQTT.AUTOSTART_WARNUNG'); ?></div>
<?php } ?>
<p class="sm-hilfe"><?php echo ew_t('MQTT.ABO_HILFE'); ?></p>

<h3><?php echo ew_t('MQTT.H_LAUF'); ?></h3>
<?php $ew_mq_ts = isset($ew_mq_takt['ts']) ? (int) $ew_mq_takt['ts'] : 0;
if (empty($ew_cfg['mqtt_ein'])) { ?>
<div class="sm-warnung"><?php echo ew_t('MQTT.LAUF_AUS'); ?></div>
<?php } elseif ($ew_mq_ts <= 0) { ?>
<div class="sm-warnung"><?php echo ew_t('MQTT.LAUF_NIE'); ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ew_e(sprintf(ew_t('MQTT.LAUF_TEXT'), date('d.m.Y H:i:s', $ew_mq_ts), ew_alter_text(time() - $ew_mq_ts),
    isset($ew_mq_takt['zaehler']) ? (int) $ew_mq_takt['zaehler'] : 0, isset($ew_mq_takt['gesendet']) ? (int) $ew_mq_takt['gesendet'] : 0,
    ew_t(!empty($ew_mq_takt['vollversand']) ? 'MQTT.LAUF_VOLL' : 'MQTT.LAUF_AENDERUNG'),
    ew_t(!empty($ew_mq_takt['ok']) ? 'MQTT.LAUF_OK' : 'MQTT.LAUF_NICHT_OK'))) ?></div>
<?php } ?>

<h3><?php echo ew_t('MQTT.H_THEMEN'); ?></h3>
<p class="sm-hilfe"><?php echo ew_t('MQTT.THEMEN_HILFE'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo ew_t('MQTT.T_THEMA'); ?></th><th><?php echo ew_t('MQTT.T_BEDEUTUNG'); ?></th><th><?php echo ew_t('MQTT.T_KLASSE'); ?></th><th><?php echo ew_t('MQTT.T_RETAIN'); ?></th></tr>
<?php foreach (ew_mqtt_namen($ew_cfg) as $ew_mq_e) { ?>
  <tr><td class="sm-mono"><?= ew_e($ew_mq_pf . '/' . $ew_mq_e['thema']) ?></td><td><?php echo ew_t($ew_mq_e['bedeutung']); ?></td>
      <td><?php echo ew_t('MQTT.KLASSE_' . strtoupper($ew_mq_e['klasse'])); ?></td><td><?php echo ew_t('MQTT.RETAIN_NIE'); ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ew_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo ew_t('TEXT.H_LOXONE'); ?></h2>

<div class="sm-step"><?php echo ew_t('TEXT.LOX_ERKLAERUNG'); ?></div>

<?php /* Gruppe 3 / MQTT (0.9.25; Entwurf Gruppe 3 ew 2.8, Nachtrag 2 Gruppe 1): MQTT ist der Weg des
   Musterprojekts (Schritte 1-6); der bisherige HTTP-Weg steht zugeklappt unter "Bestehende Anlagen", Inhalt
   unveraendert. Alle Namen aus ew_mqtt_gateway_name(), Namenstabelle und Hakenliste aus ew_mqtt_namen() - derselben
   Liste, nach der der Minutentakt sendet. Keine MQTT-Importvorlage fuer Werte (R2_078). */
$ew_eb_gw = ew_mqtt_gateway();
$ew_eb_pf = ew_mqtt_praefix($ew_cfg);
$ew_eb_namen = ew_mqtt_namen($ew_cfg);
list($ew_eb_abo_da, $ew_eb_abo_ok) = ew_abo_pruefen($ew_cfg); ?>

<div class="sm-step" id="eb-mqtt">
<h3><?php echo ew_t('EINBINDUNG.S1'); ?></h3>
<p class="sm-hilfe"><?php echo ew_t('EINBINDUNG.S1_TEXT'); ?></p>
<?php if (!empty($ew_cfg['mqtt_ein'])) { ?>
<p class="sm-hilfe" id="eb-mqtt-ein"><?= ew_e(sprintf(ew_t('EINBINDUNG.S1_EIN'), $ew_eb_pf)) ?></p>
<?php } else { ?>
<div class="sm-fehler" id="eb-mqtt-aus"><?php echo ew_t('EINBINDUNG.S1_AUS'); ?></div>
<?php } ?>
</div>

<div class="sm-step" id="eb-gateway">
<h3><?php echo ew_t('EINBINDUNG.S2'); ?></h3>
<?php if ($ew_eb_gw['fassung'] < 2) { ?>
<p class="sm-hilfe" id="eb-v1"><?= sprintf(ew_t($ew_eb_gw['fassung'] === 1 ? 'EINBINDUNG.S2_V1' : 'EINBINDUNG.S2_UNBEKANNT'), '<span class="sm-mono">' . ew_e($ew_eb_pf . '/#') . '</span>') ?></p>
<?php if (!$ew_eb_abo_ok) { ?>
<div class="sm-warnung"><?= ew_e(ew_t('MQTT.T_ABO')) ?>: <?= ew_e(sprintf(ew_t($ew_eb_abo_da ? 'MQTT.ABO_FALSCH' : 'MQTT.ABO_FEHLT'), $ew_eb_pf . '/#')) ?></div>
<?php } ?>
<?php } ?>
<?php if ($ew_eb_gw['fassung'] === 0 || $ew_eb_gw['fassung'] >= 2) {
    $ew_eb_n = array('kern' => 0, 'wahl' => 0, 'batterie' => 0, 'batterie_wahl' => 0);
    foreach ($ew_eb_namen as $ew_eb_e) {
        if (isset($ew_eb_n[$ew_eb_e['klasse']])) { $ew_eb_n[$ew_eb_e['klasse']]++; }
    }
    $ew_eb_gruppen = array('kern' => sprintf(ew_t('EINBINDUNG.HAKEN_KERN'), $ew_eb_n['kern']),
                           'wahl' => sprintf(ew_t('EINBINDUNG.HAKEN_WAHL'), $ew_eb_n['wahl']),
                           'batterie' => sprintf(ew_t('EINBINDUNG.HAKEN_BATTERIE'), $ew_eb_n['batterie']),
                           'batterie_wahl' => sprintf(ew_t('EINBINDUNG.HAKEN_EC'), $ew_eb_n['batterie_wahl']));
    $ew_eb_zahl = 0; ?>
<p class="sm-hilfe" id="eb-v2"><?= sprintf(ew_t('EINBINDUNG.S2_V2'), '<span class="sm-mono">' . ew_e($ew_eb_pf) . '</span>') ?></p>
<?php foreach ($ew_eb_gruppen as $ew_eb_k => $ew_eb_t) {
    $ew_eb_h = array();
    foreach ($ew_eb_namen as $ew_eb_e) {
        if ($ew_eb_e['klasse'] === $ew_eb_k) { $ew_eb_h[] = $ew_eb_e; }
    }
    $ew_eb_zahl += count($ew_eb_h); ?>
<p class="sm-hilfe"><b><?= ew_e($ew_eb_t) ?></b></p>
<div class="sm-breit"><table class="sm-tbl" id="eb-haken-<?= ew_e($ew_eb_k) ?>">
  <tr><th>#</th><th><?php echo ew_t('EINBINDUNG.T_THEMA'); ?></th><th><?php echo ew_t('EINBINDUNG.T_NAME'); ?></th><th><?php echo ew_t('EINBINDUNG.T_SEITE'); ?></th></tr>
<?php foreach ($ew_eb_h as $ew_eb_i => $ew_eb_e) { ?>
  <tr><td><?= (int) $ew_eb_i + 1 ?></td><td class="sm-mono"><?= ew_e($ew_eb_pf . '/' . $ew_eb_e['thema']) ?></td><td class="sm-mono"><?= ew_e($ew_eb_e['name']) ?></td>
      <td><?= $ew_eb_e['seite'] > 0 ? ew_e(ew_t('EINBINDUNG.SEITE' . (int) $ew_eb_e['seite'])) : '&ndash;' ?></td></tr>
<?php } ?>
</table></div>
<?php } ?>
<p class="sm-hilfe"><?= ew_e(sprintf(ew_t('EINBINDUNG.HAKEN_ZAHL'), $ew_eb_zahl)) ?> <?php echo ew_t('EINBINDUNG.HAKEN_RESET'); ?></p>
<?php } ?>
<p class="sm-hilfe" id="eb-reconnect"><?php echo ew_t('EINBINDUNG.RECONNECT'); ?></p>
<p class="sm-hilfe"><?php echo ew_t('EINBINDUNG.KEINE_BEFEHLE'); ?></p>
</div>

<?php /* Kopierknopf ohne navigator.clipboard (LoxBerry laeuft ueber http, dort fehlt es): der Name wird in einem
   Hilfsfeld markiert und mit execCommand kopiert; geht beides nicht, steht danach der Name auf dem Knopf
   (Form wie Bewaesserung 0.9.44). */ ?>
<div class="sm-step" id="eb-eingaenge">
<h3><?php echo ew_t('EINBINDUNG.S3'); ?></h3>
<p class="sm-hilfe"><?php echo ew_t('EINBINDUNG.S3_TEXT'); ?></p>
<ul class="sm-hilfe">
<li><?php echo ew_t('EINBINDUNG.S3_DIGITAL'); ?></li>
<li><?php echo ew_t('EINBINDUNG.S3_RECHTE'); ?></li>
<li><?php echo ew_t('EINBINDUNG.S3_MUSTER'); ?></li>
<li><?php echo ew_t('EINBINDUNG.S3_PRAEFIX'); ?></li>
<li><?php echo ew_t('EINBINDUNG.S3_EINHEITEN'); ?></li>
<li><?php echo ew_t('EINBINDUNG.S3_WAHL'); ?></li>
</ul>
<div class="sm-breit"><table class="sm-tbl" id="eb-namen">
  <tr><th><?php echo ew_t('EINBINDUNG.T_NAME'); ?></th><th><?php echo ew_t('EINBINDUNG.T_BEDEUTUNG'); ?></th><th><?php echo ew_t('EINBINDUNG.T_EINHEIT'); ?></th><th><?php echo ew_t('EINBINDUNG.T_FEHLWERT'); ?></th><th><?php echo ew_t('EINBINDUNG.T_ART'); ?></th></tr>
<?php foreach ($ew_eb_namen as $ew_eb_e) { if ($ew_eb_e['klasse'] === 'weitere') { continue; } ?>
  <tr><td style="white-space:nowrap"><span class="sm-mono"><?= ew_e($ew_eb_e['name']) ?></span> <button data-role="none" type="button" class="sm-kopier" data-name="<?= ew_e($ew_eb_e['name']) ?>" title="<?= ew_e(ew_t('EINBINDUNG.KOPIEREN')) ?>"><?= ew_e(ew_t('EINBINDUNG.KOPIEREN')) ?></button></td>
      <td><?php echo ew_t($ew_eb_e['bedeutung']); ?><?php if ($ew_eb_e['klasse'] !== 'kern') { ?> <span class="sm-grau">(<?php echo ew_t('MQTT.KLASSE_' . strtoupper($ew_eb_e['klasse'])); ?>)</span><?php } ?></td>
      <td class="sm-mono"><?= ew_e($ew_eb_e['einheit']) ?>, Min <?= (int) $ew_eb_e['min'] ?>, Max <?= (int) $ew_eb_e['max'] ?></td>
      <td><?= $ew_eb_e['fehlwert'] === null ? '&ndash;' : (int) $ew_eb_e['fehlwert'] ?></td>
      <td><?php echo ew_t('EINBINDUNG.ART_ZAHL'); ?></td></tr>
<?php } ?>
</table></div>
<script>
(function () {
  var knoepfe = document.querySelectorAll('#eb-namen button.sm-kopier');
  for (var i = 0; i < knoepfe.length; i++) {
    knoepfe[i].addEventListener('click', function () {
      var f = document.createElement('textarea');
      f.value = this.getAttribute('data-name');
      f.setAttribute('readonly', '');
      f.style.position = 'absolute'; f.style.left = '-9999px';
      document.body.appendChild(f);
      f.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(f);
      this.textContent = ok ? '\u2713' : this.getAttribute('data-name');
    });
  }
})();
</script>
</div>

<div class="sm-step" id="eb-ausfall">
<h3><?php echo ew_t('EINBINDUNG.S4'); ?></h3>
<p class="sm-hilfe"><?= sprintf(ew_t('EINBINDUNG.S4_TEXT'), '<span class="sm-mono">' . ew_e(ew_mqtt_gateway_name('status/zaehler', $ew_cfg)) . '</span>',
    '<span class="sm-mono">' . ew_e(ew_mqtt_gateway_name('status/ok', $ew_cfg)) . '</span>',
    '<span class="sm-mono">' . ew_e(ew_mqtt_gateway_name('quelle', $ew_cfg)) . '</span>') ?></p>
</div>

<?php /* Baustein-Listen der drei Musterprojekt-Seiten (Musterprojekt/baustein_listen.txt, Abschnitte
   "Ecowitt-Weiche" 28, "Ecowitt-Weiche Wetterwerte" 46 und "Ecowitt-Weiche Batterien" 8 Zeilen, wortgleich).
   Typ, Parameter und Verbindung stehen in [BAUSTEIN] der Sprachdateien; der Name eines Virtuellen Eingangs kommt
   aus ew_mqtt_gateway_name() (Zeile array(..., 'N:<thema>', ...)), sonst aus [BAUSTEIN] ('K:<schluessel>').
   {Bn}/{Wn}/{BATn} wird zur laufenden Nummer "#n" der Seite, {N:<thema>} zum Namen des Gateways. Das Bild einer
   Seite erscheint nur, wenn die Datei im Plugin liegt (das Bild der Seite "Ecowitt-Weiche Batterien" folgt nach
   dem Anordnen in Config). Zeile: array(Kennung, Typ, Name, Parameter, Verbindung). */
$ew_bs_seiten = array(
    array('kopf' => 'SEITE1_H', 'seite' => 'Ecowitt-Weiche', 'bild' => 'einbindung_loxone.png',
          'schritte' => array('S1_1', 'S1_2', 'S1_3', 'S1_4', 'S1_5', 'S1_6', 'S1_7', 'S1_8', 'S1_9'),
          'hinweise' => array('ERLAEUTERUNG', 'N1_1', 'N1_2', 'N1_3', 'N1_4', 'N1_5'),
          'zeilen' => array(
        array('B1', 'B1_TYP', 'K:B1_NAME', 'B1_PARAM', 'B1_VERB'),
        array('B2', 'B2_TYP', 'K:B2_NAME', 'B2_PARAM', 'B2_VERB'),
        array('B3', 'B3_TYP', 'K:B3_NAME', 'B3_PARAM', 'B3_VERB'),
        array('B4', 'T_VI', 'N:status/ok', 'B4_PARAM', 'B4_VERB'),
        array('B5', 'T_VI', 'N:quelle', 'B5_PARAM', 'B5_VERB'),
        array('B6', 'T_VI', 'N:wechsel', 'B6_PARAM', 'B6_VERB'),
        array('B7', 'B7_TYP', 'K:B7_NAME', 'B7_PARAM', 'B7_VERB'),
        array('B8', 'B8_TYP', 'K:B8_NAME', 'B8_PARAM', 'B8_VERB'),
        array('B9', 'B9_TYP', 'K:B9_NAME', 'B9_PARAM', 'B9_VERB'),
        array('B10', 'B10_TYP', 'K:B10_NAME', 'B10_PARAM', 'B10_VERB'),
        array('B11', 'B11_TYP', 'K:B11_NAME', 'B11_PARAM', 'B11_VERB'),
        array('B12', 'B12_TYP', 'K:B12_NAME', 'B12_PARAM', 'B12_VERB'),
        array('B13', 'B13_TYP', 'K:B13_NAME', 'B13_PARAM', 'B13_VERB'),
        array('B14', 'B14_TYP', 'K:B14_NAME', 'B14_PARAM', 'B14_VERB'),
        array('B15', 'B15_TYP', 'K:B15_NAME', 'B15_PARAM', 'B15_VERB'),
        array('B16', 'B16_TYP', 'K:B16_NAME', 'B16_PARAM', 'B16_VERB'),
        array('B17', 'B17_TYP', 'K:B17_NAME', 'B17_PARAM', 'B17_VERB'),
        array('B18', 'B18_TYP', 'K:B18_NAME', 'B18_PARAM', 'B18_VERB'),
        array('B19', 'B19_TYP', 'K:B19_NAME', 'B19_PARAM', 'B19_VERB'),
        array('B20', 'B20_TYP', 'K:B20_NAME', 'B20_PARAM', 'B20_VERB'),
        array('B21', 'B21_TYP', 'K:B21_NAME', 'B21_PARAM', 'B21_VERB'),
        array('B22', 'B22_TYP', 'K:B22_NAME', 'B22_PARAM', 'B22_VERB'),
        array('B23', 'B23_TYP', 'K:B23_NAME', 'B23_PARAM', 'B23_VERB'),
        array('B24', 'B24_TYP', 'K:B24_NAME', 'B24_PARAM', 'B24_VERB'),
        array('B25', 'B25_TYP', 'K:B25_NAME', 'B25_PARAM', 'B25_VERB'),
        array('B26', 'T_VI', 'N:status/zaehler', 'B26_PARAM', 'B26_VERB'),
        array('B27', 'B27_TYP', 'K:B27_NAME', 'B27_PARAM', 'B27_VERB'),
        array('B28', 'B28_TYP', 'K:B28_NAME', 'B28_PARAM', 'B28_VERB'),
    )),
    array('kopf' => 'SEITE2_H', 'seite' => 'Ecowitt-Weiche Wetterwerte', 'bild' => 'einbindung_loxone_wetterwerte.png',
          'schritte' => array('S2_1', 'S2_2', 'S2_3', 'S2_4', 'S2_5', 'S2_6'),
          'hinweise' => array('N2_1', 'N2_2', 'N2_3', 'N2_4'),
          'zeilen' => array(
        array('W1', 'T_VI', 'N:aussentemperatur', 'W1_PARAM', 'W1_VERB'),
        array('W2', 'T_VI', 'N:aussenfeuchte', 'W2_PARAM', 'W2_VERB'),
        array('W3', 'T_VI', 'N:taupunkt', 'W3_PARAM', 'W3_VERB'),
        array('W4', 'T_VI', 'N:windrichtung', 'W4_PARAM', 'W4_VERB'),
        array('W5', 'T_VI', 'N:wind', 'W5_PARAM', 'W5_VERB'),
        array('W6', 'T_VI', 'N:boee', 'W6_PARAM', 'W6_VERB'),
        array('W7', 'T_VI', 'N:boee_max_heute', 'W7_PARAM', 'W7_VERB'),
        array('W8', 'T_VI', 'N:solar', 'W8_PARAM', 'W8_VERB'),
        array('W9', 'T_VI', 'N:uv', 'W9_PARAM', 'W9_VERB'),
        array('W10', 'T_VI', 'N:regenrate', 'W10_PARAM', 'W10_VERB'),
        array('W11', 'T_VI', 'N:regen_ereignis', 'W11_PARAM', 'W11_VERB'),
        array('W12', 'T_VI', 'N:regen_heute', 'W12_PARAM', 'W12_VERB'),
        array('W13', 'T_VI', 'N:regen_woche', 'W13_PARAM', 'W13_VERB'),
        array('W14', 'T_VI', 'N:regen_monat', 'W14_PARAM', 'W14_VERB'),
        array('W15', 'T_VI', 'N:regen_jahr', 'W15_PARAM', 'W15_VERB'),
        array('W16', 'T_VI', 'N:innentemperatur', 'W16_PARAM', 'W16_VERB'),
        array('W17', 'T_VI', 'N:innenfeuchte', 'W17_PARAM', 'W17_VERB'),
        array('W18', 'T_VI', 'N:luftdruck_rel', 'W18_PARAM', 'W18_VERB'),
        array('W19', 'T_VI', 'N:luftdruck_abs', 'W19_PARAM', 'W19_VERB'),
        array('W20', 'T_VI', 'N:blitz_entfernung', 'W20_PARAM', 'W20_VERB'),
        array('W21', 'T_VI', 'N:blitz_anzahl', 'W21_PARAM', 'W21_VERB'),
        array('W22', 'W22_TYP', 'K:W22_NAME', 'W22_PARAM', 'W22_VERB'),
        array('W23', 'W23_TYP', 'K:W23_NAME', 'W23_PARAM', 'W23_VERB'),
        array('W24', 'W24_TYP', 'K:W24_NAME', 'W24_PARAM', 'W24_VERB'),
        array('W25', 'W25_TYP', 'K:W25_NAME', 'W25_PARAM', 'W25_VERB'),
        array('W26', 'W26_TYP', 'K:W26_NAME', 'W26_PARAM', 'W26_VERB'),
        array('W27', 'W27_TYP', 'K:W27_NAME', 'W27_PARAM', 'W27_VERB'),
        array('W28', 'W28_TYP', 'K:W28_NAME', 'W28_PARAM', 'W28_VERB'),
        array('W29', 'W29_TYP', 'K:W29_NAME', 'W29_PARAM', 'W29_VERB'),
        array('W30', 'W30_TYP', 'K:W30_NAME', 'W30_PARAM', 'W30_VERB'),
        array('W31', 'W31_TYP', 'K:W31_NAME', 'W31_PARAM', 'W31_VERB'),
        array('W32', 'W32_TYP', 'K:W32_NAME', 'W32_PARAM', 'W32_VERB'),
        array('W33', 'W33_TYP', 'K:W33_NAME', 'W33_PARAM', 'W33_VERB'),
        array('W34', 'W34_TYP', 'K:W34_NAME', 'W34_PARAM', 'W34_VERB'),
        array('W35', 'W35_TYP', 'K:W35_NAME', 'W35_PARAM', 'W35_VERB'),
        array('W36', 'W36_TYP', 'K:W36_NAME', 'W36_PARAM', 'W36_VERB'),
        array('W37', 'W37_TYP', 'K:W37_NAME', 'W37_PARAM', 'W37_VERB'),
        array('W38', 'W38_TYP', 'K:W38_NAME', 'W38_PARAM', 'W38_VERB'),
        array('W39', 'W39_TYP', 'K:W39_NAME', 'W39_PARAM', 'W39_VERB'),
        array('W40', 'T_VI', 'N:gefuehlt', 'W40_PARAM', 'W40_VERB'),
        array('W41', 'T_VI', 'N:vpd', 'W41_PARAM', 'W41_VERB'),
        array('W42', 'T_VI', 'N:laufzeit', 'W42_PARAM', 'W42_VERB'),
        array('W43', 'T_VI', 'N:regnet', 'W43_PARAM', 'W43_VERB'),
        array('W44', 'T_VI', 'N:windrichtung_10min', 'W44_PARAM', 'W44_VERB'),
        array('W45', 'W45_TYP', 'K:W45_NAME', 'W45_PARAM', 'W45_VERB'),
        array('W46', 'W46_TYP', 'K:W46_NAME', 'W46_PARAM', 'W46_VERB'),
    )),
    array('kopf' => 'SEITE3_H', 'seite' => 'Ecowitt-Weiche Batterien', 'bild' => 'einbindung_loxone_batterien.png',
          'schritte' => array('S3_1', 'S3_2', 'S3_3'),
          'hinweise' => array('N3_1', 'N3_2', 'N3_3'),
          'zeilen' => array(
        array('BAT1', 'T_VI', 'N:batterie_ws90', 'BAT1_PARAM', 'BAT1_VERB'),
        array('BAT2', 'T_VI', 'N:spannung_ws90', 'BAT2_PARAM', 'BAT2_VERB'),
        array('BAT3', 'T_VI', 'N:kondensator_ws90', 'BAT3_PARAM', 'BAT3_VERB'),
        array('BAT4', 'T_VI', 'N:batterie_wh40', 'BAT4_PARAM', 'BAT4_VERB'),
        array('BAT5', 'T_VI', 'N:spannung_wh40', 'BAT5_PARAM', 'BAT5_VERB'),
        array('BAT6', 'T_VI', 'N:batterie_wh57', 'BAT6_PARAM', 'BAT6_VERB'),
        array('BAT7', 'T_VI', 'N:batterie_wh25', 'BAT7_PARAM', 'BAT7_VERB'),
        array('BAT8', 'BAT8_TYP', 'K:BAT8_NAME', 'BAT8_PARAM', 'BAT8_VERB'),
    )),
);
$ew_bs_nr = array();
foreach ($ew_bs_seiten as $ew_s) {
    foreach ($ew_s['zeilen'] as $ew_i => $ew_z) { $ew_bs_nr[$ew_z[0]] = $ew_i + 1; }
}
$ew_bs_cfg = $ew_cfg;
$ew_bs_t = function ($schluessel) use ($ew_bs_nr, $ew_bs_cfg) {
    $t = preg_replace_callback('/\{((?:BAT|B|W)\d+)\}/', function ($m) use ($ew_bs_nr) {
        return isset($ew_bs_nr[$m[1]]) ? '#' . $ew_bs_nr[$m[1]] : $m[0];
    }, (string) ew_t('BAUSTEIN.' . $schluessel));
    return preg_replace_callback('#\{N:([a-z0-9_/]+)\}#', function ($m) use ($ew_bs_cfg) {
        return ew_e(ew_mqtt_gateway_name($m[1], $ew_bs_cfg));
    }, $t);
};
$ew_bs_name = function ($spec) use ($ew_bs_t, $ew_bs_cfg) {
    return strpos($spec, 'N:') === 0 ? ew_e(ew_mqtt_gateway_name(substr($spec, 2), $ew_bs_cfg)) : $ew_bs_t(substr($spec, 2));
}; ?>
<div class="sm-step" id="eb-bausteine">
<h3><?php echo ew_t('BAUSTEIN.H'); ?></h3>
<p class="sm-hilfe"><?php echo $ew_bs_t('TEXT'); ?></p>
</div>
<?php foreach ($ew_bs_seiten as $ew_s) { ?>
<h4 class="sm-h4"><?= ew_e(ew_t('BAUSTEIN.' . $ew_s['kopf'])) ?></h4>
<div class="sm-hinweis"><b><?= ew_e(ew_t('BAUSTEIN.SCHRITTE_TITEL')) ?></b>
<ol class="sm-schritte">
<?php foreach ($ew_s['schritte'] as $ew_x) { ?>
<li><?php echo $ew_bs_t($ew_x); ?></li>
<?php } ?>
</ol></div>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo ew_t('BAUSTEIN.T_TYP'); ?></th><th><?php echo ew_t('BAUSTEIN.T_NAME'); ?></th><th><?php echo ew_t('BAUSTEIN.T_PARAM'); ?></th><th><?php echo ew_t('BAUSTEIN.T_VERB'); ?></th></tr>
<?php foreach ($ew_s['zeilen'] as $ew_i => $ew_z) { ?>
  <tr><td><?= $ew_i + 1 ?></td><td><?php echo $ew_bs_t($ew_z[1]); ?></td><td><span class="sm-mono"><?php echo $ew_bs_name($ew_z[2]); ?></span></td><td><?php echo $ew_bs_t($ew_z[3]); ?></td><td><?php echo $ew_bs_t($ew_z[4]); ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><b><?= ew_e(ew_t('BAUSTEIN.HINWEISE_TITEL')) ?></b>
<ul class="sm-schritte">
<?php foreach ($ew_s['hinweise'] as $ew_x) { ?>
<li><?php echo $ew_bs_t($ew_x); ?></li>
<?php } ?>
</ul></div>
<?php if (is_file(__DIR__ . '/' . $ew_s['bild'])) { ?>
<figure class="sm-bild">
<img src="<?= ew_e($ew_s['bild']) ?>" alt="<?= ew_e(ew_t('BAUSTEIN.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= ew_e(sprintf(ew_t('BAUSTEIN.BILD_UNTERSCHRIFT'), $ew_s['seite'])) ?></figcaption>
</figure>
<?php } ?>
<p class="sm-hilfe"><?php echo ew_t('BAUSTEIN.MUSTERPROJEKT'); ?></p>
<?php } ?>

<div class="sm-step" id="eb-probe">
<h3><?php echo ew_t('EINBINDUNG.S6'); ?></h3>
<p class="sm-hilfe"><?= sprintf(ew_t('EINBINDUNG.S6_TEXT'), '<span class="sm-mono">' . ew_e(ew_mqtt_gateway_name('status/zaehler', $ew_cfg)) . '</span>') ?></p>
</div>

<details id="eb-http">
<summary><?php echo ew_t('EINBINDUNG.HTTP_TITEL'); ?></summary>
<p class="sm-hilfe"><?php echo ew_t('EINBINDUNG.HTTP_TEXT'); ?></p>
<h3><?php echo ew_t('TEXT.LOX_ADRESSE'); ?></h3>
<p><span class="sm-mono">http://<?= ew_e($ew_host) ?>/plugins/<?= ew_e($ew_plugin) ?>/live.php<?= ew_e($ew_tokenteil) ?></span></p>
<p class="sm-hilfe"><?php echo ew_t('TEXT.LOX_ADRESSE_HILFE'); ?></p>

<h3><?php echo ew_t('TEXT.LOX_STATUS'); ?></h3>
<p><span class="sm-mono">http://<?= ew_e($ew_host) ?>/plugins/<?= ew_e($ew_plugin) ?>/live.php?status=1<?= $ew_cfg['token'] !== '' ? '&amp;token=' . ew_e(rawurlencode($ew_cfg['token'])) : '' ?></span></p>
<?php /* X-8 (02.10.2026): die Felder des Zustandsbehaelters mit Min/Max an EINER
         Stelle - die Tabelle hier und die Baustein-Liste unten lesen dieselbe Reihung. */
$ew_lox_felder = ew_lox_felder(); /* seit 0.9.22 in ew_lib.php - die Vorlage liest dieselbe Reihung */ ?>
<table class="sm-tbl">
  <tr><th><?php echo ew_t('TEXT.FELD'); ?></th><th><?php echo ew_t('TEXT.BEDEUTUNG'); ?></th><th><?php echo ew_t('TEXT.LOX_SUCHTEXT'); ?></th><th>Min</th><th>Max</th></tr>
<?php foreach ($ew_lox_felder as $ew_lf => $ew_lg) { ?>
  <tr><td class="sm-mono"><?= ew_e($ew_lf) ?></td><td><?php echo ew_t('FELD.' . $ew_lf); ?></td><td class="sm-mono"><?= ew_e(ew_suchtext($ew_lf)) ?></td><td><?= (int) $ew_lg[0] ?></td><td><?= (int) $ew_lg[1] ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?php printf(ew_t('TEXT.LOX_SUCHTEXT_HILFE'), '<span class="sm-mono">' . ew_e('OK=\v') . '</span>'); ?></p>
<div class="sm-warnung"><?php echo ew_t('TEXT.LOX_MINVAL'); ?></div>

<?php /* Welle Bild 13 (0.9.22, Entwurf Welle 13 ew, Entscheidung 10): die Vorlagen beider Behaelter als
         Download, dazu die Befehle der Stationsvorlage als Tabelle fuer alle, die ihren Behaelter schon
         haben und nur fehlende Befehle von Hand ergaenzen (ein zweiter Import gaebe einen zweiten
         Behaelter). Titel, Suchtext, Grenzen und Einheit aus ew_wetter_befehle() - derselben Quelle wie
         die Vorlage. */ ?>
<h3><?php echo ew_t('VORLAGE.H'); ?></h3>
<div class="sm-step"><?php echo ew_t('VORLAGE.TEXT'); ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ew_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage_zustand" value="1"><?php echo ew_t('VORLAGE.K_ZUSTAND'); ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo ew_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage_wetter" value="1"><?php echo ew_t('VORLAGE.K_WETTER'); ?></button>
  </form>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?php echo ew_t('LEGENDE.LESEN'); ?></span></div>
<p class="sm-hilfe"><?php printf(ew_t('VORLAGE.HINWEIS'), ew_behaelter_timeout_ms($ew_cfg)); ?></p>
<p class="sm-hilfe"><b><?php echo ew_t('VORLAGE.T_BEFEHLE'); ?></b></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?php echo ew_t('VORLAGE.T_TITEL'); ?></th><th><?php echo ew_t('TEXT.LOX_SUCHTEXT'); ?></th><th>Min</th><th>Max</th><th><?php echo ew_t('VORLAGE.T_EINHEIT'); ?></th></tr>
<?php foreach (ew_wetter_befehle() as $ew_i => $ew_wb) { ?>
  <tr><td><?= $ew_i + 1 ?></td><td><?= ew_e($ew_wb[0]) ?></td><td class="sm-mono"><?= ew_e($ew_wb[1]) ?></td><td><?= (int) $ew_wb[3] ?></td><td><?= (int) $ew_wb[4] ?></td><td class="sm-mono"><?= ew_e($ew_wb[5]) ?></td></tr>
<?php } ?>
</table>
</div>

<h3><?php echo ew_t('TEXT.LOX_AUSFALL_H'); ?></h3>
<div class="sm-step"><?php echo ew_t('TEXT.LOX_503'); ?></div>
<?php /* k2: hoechstens EW_LOX_TIMEOUT_MAX - mehr nimmt Loxone nicht an. */
if (ew_wartezeit_zu_lang($ew_cfg)) { ?>
<div class="sm-fehler"><?php printf(ew_t('TEXT.LOX_TIMEOUT_ZU_LANG'), (int) $ew_cfg['timeout'], ew_behaelter_timeout_noetig_ms($ew_cfg),
    EW_LOX_TIMEOUT_MAX, EW_WARTEZEIT_MAX, (2 * EW_WARTEZEIT_MAX + 1) * 1000); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php printf(ew_t('TEXT.LOX_TIMEOUT'), ew_behaelter_timeout_ms($ew_cfg), ew_gesamtfrist($ew_cfg), (int) $ew_cfg['timeout'], EW_GESAMTFRIST, EW_LOX_TIMEOUT_MAX, EW_WARTEZEIT_MAX); ?></div>
<?php } ?>
<?php /* b1: die Behaelter aus der abgelegten Projektdatei. */
if (is_array($ew_proj)) {
    $ew_pr = ew_pruef_behaelter($ew_proj);
    $ew_pk = $ew_pr[0] === 'fehl' ? 'sm-fehler' : ($ew_pr[0] === 'ok' ? 'sm-hinweis' : 'sm-warnung'); ?>
<h3><?php echo ew_t('TEXT.PROJ_H'); ?></h3>
<div class="<?= $ew_pk ?>"><?= ew_e($ew_pr[1]) ?></div>
<?php if ($ew_proj['art'] === 'geprueft') { ?>
<table class="sm-tbl">
  <tr><th><?php echo ew_t('TEXT.PROJ_BEHAELTER'); ?></th><th><?php echo ew_t('TEXT.PROJ_ART'); ?></th><th><?php echo ew_t('TEXT.PROJ_TIMEOUT'); ?></th><th><?php echo ew_t('TEXT.ERGEBNIS'); ?></th></tr>
<?php foreach ($ew_proj['behaelter'] as $ew_b) { ?>
  <tr><td><?= ew_e($ew_b['titel']) ?></td>
      <td><?php echo ew_t($ew_b['status'] ? 'TEXT.PROJ_ART_STATUS' : 'TEXT.PROJ_ART_DATEN'); ?></td>
      <td class="sm-mono"><?= $ew_b['timeout'] === null ? '—' : (int) $ew_b['timeout'] . ' ms' ?></td>
      <td><?= $ew_b['urteil'] === 'ok' ? '<span class="sm-an">' . ew_e(ew_t('TEXT.S_OK')) . '</span>'
            : ($ew_b['urteil'] === 'fehl' ? '<span class="sm-aus">' . ew_e(sprintf(ew_t('TEXT.PROJ_ZU_KURZ'), (int) $ew_proj['mindest'])) . '</span>'
                                           : '<span class="sm-grau">' . ew_e(ew_t('TEXT.PROJ_NICHT_ANGEGEBEN')) . '</span>') ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<p class="sm-hilfe"><?php printf(ew_t('TEXT.PROJ_HILFE'), '<span class="sm-mono">' . ew_e(ew_projekt_ordner()) . '</span>'); ?></p>
<?php } ?>
<div class="sm-hinweis"><?php echo ew_t('TEXT.LOX_EWOK'); ?></div>
</details>
</div>

<!-- ================= Test und Protokoll ================= -->
<div class="sm-seite<?= $ew_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo ew_t('TEXT.H_TEST'); ?></h2>

<?php if (!empty($ew_stand)) {
    /* Datum UND Alter, nicht nur die Uhrzeit: drei Tage alte Daten sahen
       bis 0.9.14 frisch aus (Pruefer oberflaeche, Befund 9). */
    $ew_lg = isset($ew_stand['letzte_gute']) ? (int) $ew_stand['letzte_gute'] : 0;
    $ew_ts = isset($ew_stand['ts']) ? (int) $ew_stand['ts'] : 0;
    $ew_q = isset($ew_stand['quelle']) ? $ew_stand['quelle'] : '';
    /* a1: der Zeitstempel der Station aus der letzten brauchbaren Antwort. */
    $ew_sz = isset($ew_stand['stationszeit']) ? (int) $ew_stand['stationszeit'] : 0; ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><b><?= $ew_q === 'primaer' ? ew_t('TEXT.PRIMAER') : ($ew_q === 'ersatz' ? ew_t('TEXT.ERSATZ') : '—') ?></b><span><?php echo ew_t('TEXT.TRAEGT_GERADE'); ?></span></div>
  <div class="sm-kachel"><b><?= (int) (isset($ew_stand['wechsel']) ? $ew_stand['wechsel'] : 0) ?></b><span><?php echo ew_t('TEXT.WECHSEL_GESAMT'); ?></span></div>
  <div class="sm-kachel"><b><?= $ew_lg > 0 ? ew_e(date('d.m.Y H:i:s', $ew_lg)) : '—' ?></b><span><?php echo ew_t('TEXT.LETZTE_GUTE'); ?><?= $ew_lg > 0 ? ' (' . ew_e(ew_alter_text(time() - $ew_lg)) . ')' : '' ?></span></div>
  <div class="sm-kachel"><b><?= $ew_ts > 0 ? ew_e(date('d.m.Y H:i:s', $ew_ts)) : '—' ?></b><span><?php echo ew_t('TEXT.LETZTER_ABRUF'); ?><?= $ew_ts > 0 ? ' (' . ew_e(ew_alter_text(time() - $ew_ts)) . ')' : '' ?></span></div>
  <div class="sm-kachel"><b><?= $ew_sz > 0 ? ew_e(date('d.m.Y H:i:s', $ew_sz)) : '—' ?></b><span><?php echo ew_t('TEXT.STATIONSZEIT'); ?><?= $ew_sz > 0 ? ' (' . ew_e(ew_alter_text(time() - $ew_sz)) . ')' : ' (' . ew_e(ew_t('TEXT.STATIONSZEIT_KEINE')) . ')' ?></span></div>
</div>
<?php } ?>

<form action="index.php" method="post">
  <?php echo ew_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="pruefen" value="1"><?php echo ew_t('TEXT.BEIDE_PRUEFEN'); ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?php echo ew_t('LEGENDE.LESEN'); ?></span></div>
</form>

<?php if (is_array($ew_probe)) { ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?php echo ew_t('TEXT.SCHNITTSTELLE'); ?></th><th><?php echo ew_t('TEXT.ADRESSE'); ?></th>
      <th><?php echo ew_t('TEXT.ERGEBNIS'); ?></th><th>0x02</th><th>0x07</th><th>0x15</th><th>0x17</th></tr>
<?php foreach ($ew_probe as $seite => $r) { if (!is_array($r)) { continue; } ?>
  <tr>
    <td><?= $seite === 'primaer' ? ew_t('TEXT.PRIMAER') : ew_t('TEXT.ERSATZ') ?></td>
    <td class="sm-mono"><?= ew_e(isset($r['adr']) ? $r['adr'] : '') ?></td>
    <td><?= !empty($r['ok']) ? '<span class="sm-an">' . ew_e(isset($r['text']) ? $r['text'] : '') . '</span>'
                             : '<span class="sm-aus">' . ew_e(isset($r['text']) ? $r['text'] : '') . '</span>' ?>
        <?= isset($r['ms']) ? ' <span class="sm-grau">(' . (int) $r['ms'] . ' ms)</span>' : '' ?></td>
<?php   foreach (array('0x02', '0x07', '0x15', '0x17') as $id) { ?>
    <td class="sm-mono"><?= (isset($r['werte'][$id]) && is_string($r['werte'][$id])) ? ew_e($r['werte'][$id]) : '—' ?></td>
<?php   } ?>
  </tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?php echo ew_t('TEXT.PROBE_HILFE'); ?></p>
<?php } ?>

<?php /* Gruppe 3 (0.9.25, Entwurf 12.3): welche Zusatz- und Batteriefelder die Station liefert - aus dem letzten
         Lauf des Minutentakts (takt.json), nicht aus einer eigenen Abfrage. */
$ew_tf = ew_takt_lesen(); ?>
<div class="sm-hinweis"><?php
if (empty($ew_tf['ts'])) {
    echo ew_t('TEST.FELDER_UNBEKANNT');
} else {
    echo ew_e(sprintf(ew_t('TEST.FELDER'), (isset($ew_tf['felder']) && is_array($ew_tf['felder']) && $ew_tf['felder'])
        ? implode(', ', array_map('strval', $ew_tf['felder'])) : ew_t('TEST.FELDER_KEINE')));
} ?></div>

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
    'ok'  => ($ew_leiste && $ew_leiste === $ew_flaechen && $ew_leiste === $ew_soll),
    'wie' => sprintf('%d / %d / %d', count($ew_leiste), count($ew_flaechen), count($ew_soll)),
);

/* Die Konfiguration, wie ew_config() sie beim ERSTEN Lesen in diesem
   Aufruf vorfand - vor der Heilung (Regeln/05, Robonect 1.1.0). */
$ew_r = ew_pruef_konfiguration(ew_config_zustand());
$ew_selbst[] = array('was' => ew_t('TEXT.S_KONFIG'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);

/* Jedes POST-Formular dieser Datei traegt das Merkmal (gezaehlt, nicht
   angenommen; eine leere Menge ist ein Kreuz). */
$ew_r = ew_pruef_formulare($ew_quelle);
$ew_selbst[] = array('was' => ew_t('TEXT.S_FORMULARE'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);

/* Der Endpunkt liegt in webfrontend/html, die Oberflaeche in htmlauth -
   zwei getrennte Baeume. Ein Plugin, dessen Oberflaeche laeuft und dessen
   Endpunkt fehlt, sieht in der Oberflaeche vollstaendig aus, und der
   Miniserver holt sich schweigend eine 404. */
$ew_endp = getenv('LBPHTMLDIR');
if ($ew_endp === false || $ew_endp === '') {
    /* Drei Stufen bis webfrontend: <ordner> -> plugins -> htmlauth. Zwei
       Stufen blieben bei htmlauth stehen und suchten darunter ein html/. */
    $ew_endp = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . ew_paths()['plugin'];
}
$ew_da = is_file($ew_endp . '/live.php') && is_file($ew_endp . '/ew_lib.php');
$ew_selbst[] = array(
    'was' => ew_t('TEXT.S_ENDPUNKT'),
    'ok'  => $ew_da,
    'wie' => $ew_da ? $ew_endp : ew_t('TEXT.S_NICHT_GEFUNDEN'),
);

/* Antwortet der Endpunkt wirklich? Ein echter Aufruf ueber 127.0.0.1
   (?selftest=1, fragt keine Station). Nur wenn der Reiter Test der offene
   ist - alle Reiter werden mitgerendert (Regeln/04). */
$ew_r = ($ew_tab === 'tab-test') ? ew_pruef_endpunkt($ew_cfg['token'])
      : array('hinweis', ew_t('TEST.EP_NUR_OFFEN'));
$ew_selbst[] = array('was' => ew_t('TEXT.S_ANTWORTET'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);

/* b1: Timeout der Loxone-Behaelter - nur aus einer abgelegten Projektdatei;
   ohne sie "nicht feststellbar" mit dem noetigen Mindestwert. */
$ew_r = is_array($ew_proj) ? ew_pruef_behaelter($ew_proj) : array('hinweis', ew_t('TEST.EP_NUR_OFFEN'));
$ew_selbst[] = array('was' => ew_t('TEXT.S_BEHAELTER'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);

/* k2: passt die Wartezeit zu dem Timeout, den Loxone annimmt (hoechstens
   EW_LOX_TIMEOUT_MAX)? Gemessen wird die gespeicherte Einstellung. */
$ew_selbst[] = array('was' => ew_t('TEXT.S_WARTEZEIT'), 'lage' => ew_wartezeit_zu_lang($ew_cfg) ? 'fehl' : 'ok',
    'wie' => sprintf(ew_t(ew_wartezeit_zu_lang($ew_cfg) ? 'TEST.WARTEZEIT_FEHL' : 'TEST.WARTEZEIT_OK'),
        (int) $ew_cfg['timeout'], ew_behaelter_timeout_noetig_ms($ew_cfg), EW_LOX_TIMEOUT_MAX, EW_WARTEZEIT_MAX));

/* Gruppe 3 (0.9.25, Entwurf 2.9): Minutentakt und MQTT. */
$ew_r = ew_pruef_mqtt_takt($ew_cfg);
$ew_selbst[] = array('was' => ew_t('TEXT.S_MQTT_TAKT'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);
$ew_r = ew_pruef_mqtt_namen($ew_cfg);
$ew_selbst[] = array('was' => ew_t('TEXT.S_MQTT_NAMEN'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);
$ew_r = ew_pruef_mqtt_http();
$ew_selbst[] = array('was' => ew_t('TEXT.S_MQTT_HTTP'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);
$ew_r = ew_pruef_mqtt_abo($ew_cfg);
$ew_selbst[] = array('was' => ew_t('TEXT.S_MQTT_ABO'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);
$ew_r = ew_pruef_mqtt_einheiten();
$ew_selbst[] = array('was' => ew_t('TEXT.S_MQTT_EINHEIT'), 'lage' => $ew_r[0], 'wie' => $ew_r[1]);

$ew_cfgdatei = ew_paths()['cfgdatei'];
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
<?php foreach ($ew_selbst as $ew_z) {
    $ew_l = isset($ew_z['lage']) ? $ew_z['lage'] : ($ew_z['ok'] ? 'ok' : 'fehl'); ?>
  <tr>
    <td><?= ew_e($ew_z['was']) ?></td>
    <td><?= $ew_l === 'ok' ? '<span class="sm-an">' . ew_e(ew_t('TEXT.S_OK')) . '</span>'
          : ($ew_l === 'hinweis' ? '<span class="sm-grau">' . ew_e(ew_t('TEXT.S_HINWEIS')) . '</span>'
                                 : '<span class="sm-aus">' . ew_e(ew_t('TEXT.S_NOK')) . '</span>') ?></td>
    <td class="sm-mono"><?= ew_e($ew_z['wie']) ?></td>
  </tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?php echo ew_t('TEXT.SELBST_HILFE'); ?></p>

<h3><?php echo ew_t('TEXT.PROTOKOLL'); ?></h3>
<?php
$ew_logdatei = ew_paths()['log'] . '/ecowitt.log';
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
