#!/bin/bash
# Ecowitt-Weiche - preupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers beim Upgrade (sbin/plugininstall.pl,
# gelesen in Geraet/2026-09-05/08_plugininstall.pl):
#   preupgrade :846 -> purge_installation :874 (loescht config/plugins/<ordner>/
#   UND data/plugins/<ordner>/) -> preinstall :877 -> config :916 -> html :1064
#   -> postinstall :1305 -> postupgrade :1330
# Was das Upgrade ueberleben soll, muss also HIER gerettet werden, vor dem
# Loeschen, und zwar NEBEN die Ordner (Name mit Punkt) - nicht nach /tmp,
# das auf dem LoxBerry fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). $3 ist der Ordnername, $5 die
# LoxBerry-Wurzel, $6 der Arbeitsordner mit dem entpackten Archiv
# (Regeln/06). Gearbeitet wird ausschliesslich mit $3 und $5.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ecowittweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts gesichert."; exit 2 ;;
esac

# Als Erstes die Upgrade-Marke (Entscheidung 1 vom 29.09.2026). preinstall.sh
# und postinstall.sh erkennen eine Aktualisierung allein an dieser Datei,
# ohne Altersvergleich. postinstall.sh raeumt sie ab, uninstall ebenfalls.
# 05.11.2026: ohne Wurzel wird nichts gesichert; Rueckgabewert 2, sonst liefe der Purge weiter.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts gesichert, das Update wird abgebrochen."
    exit 2
fi
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -d "$BASE/data/plugins" ] && date '+%Y-%m-%d %H:%M:%S' > "$MARKE" 2>/dev/null; then
    echo "<OK> Upgrade-Marke gesetzt: $MARKE"
else
    # 05.11.2026: ohne Marke haelt die Installation das Update fuer eine Neuinstallation und legt die Einstellungen beiseite; bis hierher nur eine Warnung.
    rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# ---------- Update-Schutz (05.11.2026, Bauart Govee 0.9.34) ----------
# plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600,
# BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    rm -f "$MARKE" 2>/dev/null
    exit 2
}

CF="$BASE/config/plugins/$PFOLDER/ecowitt.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
KAP="$BASE/config/plugins/$PFOLDER.kaputt.json"

# Der Wechselzaehler (data/plugins/<ordner>/stand.json) ueberlebt
# purge_installation nicht. README und postupgrade.sh sagen seit jeher zu,
# dass er ein Update uebersteht; bis 0.9.14 stimmte das nicht (in WSL
# gemessen: wechsel=7 vorher, keine Datei nachher - Pruefer installer,
# Befund 3). Er geht NEBEN den Ordner und kommt in postinstall.sh nur bei
# Marke zurueck. Ein Bestand aus einem frueheren Vorgang wird vorher
# weggeraeumt (Entscheidung 1).
ST="$BASE/data/plugins/$PFOLDER/stand.json"
STB="$BASE/data/plugins/$PFOLDER.stand.json"
rm -f "$STB" "$STB.neu."* 2>/dev/null
if [ -s "$ST" ]; then
    if zs_hinlegen "$ST" "$STB"; then
        echo "<OK> Wechselzaehler gesichert: $STB"
    else
        rm -f "$STB.neu.$$" 2>/dev/null
        echo "<WARNING> Der Wechselzaehler liess sich nicht sichern; er beginnt nach dem Update bei 0."
        ZS_FEHL="$ZS_FEHL Wechselzaehler"
    fi
else
    echo "<INFO> Noch kein Wechselzaehler vorhanden - nichts zu sichern."
fi

# Gesichert wird nach INHALT, und nie direkt ueber die alte Zweitschrift.
#
# Bis 0.9.12 stand hier ein cp -p ueber die Zweitschrift, sobald ecowitt.json
# ueberhaupt da war. Eine abgeschnittene Datei oder die leere Vorlage "{}"
# verdraengte damit die heile Zweitschrift - den einzigen Rueckweg
# (Pruefung-Ecowitt-Weiche-0.9.13, Faelle H1 und H2, vorher rot).
#
# Inhalt heisst dasselbe wie in ew_hat_inhalt(): ein JSON-Objekt, in dem das
# Wortzeichen schon einmal geschrieben wurde. Rueckgabe 0 = ja, 1 = leer
# (fehlt, nur Leerraum oder "{}"), 3 = etwas steht darin, aber kein
# verwertbarer Stand (abgeschnitten, kein JSON, ohne Wortzeichen),
# 2 = nicht pruefbar (kein php). Im Zweifel bleibt die Zweitschrift, wie sie ist.
mit_inhalt() {
    [ -s "$1" ] || return 1
    php -r '$r = (string) @file_get_contents($argv[1]); $d = json_decode($r, true);
        if (is_array($d) && array_key_exists("token", $d) && is_string($d["token"])) { exit(0); }
        exit((trim($r) === "" || (is_array($d) && count($d) === 0)) ? 1 : 3);' -- "$1" 2>/dev/null
    RC=$?
    case "$RC" in
        0|1|3) return "$RC" ;;
    esac
    return 2
}

mit_inhalt "$CF"
case "$?" in
0)
    # Erst eine Nebendatei, dann umbenennen: ein Abbruch mitten im Kopieren
    # laesst die alte Zweitschrift stehen (Bestand-2026-09-18/klasse-D).
    if zs_hinlegen "$CF" "$BK"; then
        echo "<OK> Konfiguration gesichert: $BK"
    else
        echo "<WARNING> Die Konfiguration liess sich nicht sichern. Die vorhandene"
        echo "<WARNING> Zweitschrift bleibt unveraendert: $BK"
        ZS_FEHL="$ZS_FEHL ecowitt.json"
    fi
    ;;
1)
    if [ -f "$BK" ]; then
        echo "<WARNING> ecowitt.json traegt keinen verwertbaren Stand. Die vorhandene"
        echo "<WARNING> Zweitschrift bleibt unveraendert; aus ihr holt postinstall.sh"
        echo "<WARNING> die Einstellungen zurueck: $BK"
    else
        echo "<INFO> Keine Einstellungen vorhanden - es gibt nichts zu sichern."
    fi
    ;;
3)
    if [ -f "$BK" ]; then
        echo "<WARNING> ecowitt.json ist unlesbar oder traegt kein Wortzeichen. Die vorhandene"
        echo "<WARNING> Zweitschrift bleibt unveraendert; aus ihr holt postinstall.sh"
        echo "<WARNING> die Einstellungen zurueck: $BK"
    else
        # I4: Bis 0.9.14 hiess dieser Fall "Keine Einstellungen vorhanden", und
        # purge_installation loeschte den Stand ersatzlos (in WSL gemessen,
        # Pruefer installer, Befund 6). Er geht NEBEN den Ordner; postinstall.sh
        # legt ihn als ecowitt.json.kaputt zurueck, und der Reiter Test nennt ihn.
        rm -f "$KAP.neu."* 2>/dev/null
        if zs_hinlegen "$CF" "$KAP"; then
            echo "<WARNING> ecowitt.json ist unlesbar oder traegt kein Wortzeichen, und es gibt"
            echo "<WARNING> keine Zweitschrift. Der Stand ist NICHT verloren: er liegt als $KAP"
            echo "<WARNING> und nach dem Update als ecowitt.json.kaputt im Konfigurationsordner."
            echo "<WARNING> Wortzeichen und Adressen bitte in der Oberflaeche neu eintragen."
        else
            rm -f "$KAP.neu.$$" 2>/dev/null
            echo "<WARNING> ecowitt.json ist unlesbar, es gibt keine Zweitschrift, und der Stand liess"
            echo "<WARNING> sich nicht beiseitelegen - das Update loescht ihn: $CF"
            ZS_FEHL="$ZS_FEHL ecowitt.json"
        fi
    fi
    ;;
*)
    echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?)."
    echo "<WARNING> Die Zweitschrift bleibt unveraendert: $BK"
    ;;
esac
zs_schluss
echo "<OK> preupgrade abgeschlossen."
exit 0
