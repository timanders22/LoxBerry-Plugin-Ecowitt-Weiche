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
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts gesichert."; exit 0 ;;
esac

# Als Erstes die Upgrade-Marke (Entscheidung 1 vom 29.09.2026). preinstall.sh
# und postinstall.sh erkennen eine Aktualisierung allein an dieser Datei,
# ohne Altersvergleich. postinstall.sh raeumt sie ab, uninstall ebenfalls.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -d "$BASE/data/plugins" ] && date '+%Y-%m-%d %H:%M:%S' > "$MARKE" 2>/dev/null; then
    echo "<OK> Upgrade-Marke gesetzt: $MARKE"
else
    echo "<WARNING> Die Upgrade-Marke liess sich nicht anlegen: $MARKE"
    echo "<WARNING> postinstall.sh spielt dann keine Einstellungen zurueck; die Zweitschrift bleibt liegen."
fi

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
    if cp -p "$ST" "$STB.neu.$$" 2>/dev/null && mv -f "$STB.neu.$$" "$STB" 2>/dev/null; then
        echo "<OK> Wechselzaehler gesichert: $STB"
    else
        rm -f "$STB.neu.$$" 2>/dev/null
        echo "<WARNING> Der Wechselzaehler liess sich nicht sichern; er beginnt nach dem Update bei 0."
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
    NEU="$BK.neu.$$"
    if cp -p "$CF" "$NEU" 2>/dev/null && chmod 600 "$NEU" 2>/dev/null \
        && mv -f "$NEU" "$BK" 2>/dev/null; then
        echo "<OK> Konfiguration gesichert: $BK"
    else
        rm -f "$NEU" 2>/dev/null
        echo "<WARNING> Die Konfiguration liess sich nicht sichern. Die vorhandene"
        echo "<WARNING> Zweitschrift bleibt unveraendert: $BK"
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
        if cp -p "$CF" "$KAP.neu.$$" 2>/dev/null && chmod 600 "$KAP.neu.$$" 2>/dev/null \
            && mv -f "$KAP.neu.$$" "$KAP" 2>/dev/null; then
            echo "<WARNING> ecowitt.json ist unlesbar oder traegt kein Wortzeichen, und es gibt"
            echo "<WARNING> keine Zweitschrift. Der Stand ist NICHT verloren: er liegt als $KAP"
            echo "<WARNING> und nach dem Update als ecowitt.json.kaputt im Konfigurationsordner."
            echo "<WARNING> Wortzeichen und Adressen bitte in der Oberflaeche neu eintragen."
        else
            rm -f "$KAP.neu.$$" 2>/dev/null
            echo "<WARNING> ecowitt.json ist unlesbar, es gibt keine Zweitschrift, und der Stand liess"
            echo "<WARNING> sich nicht beiseitelegen - das Update loescht ihn: $CF"
        fi
    fi
    ;;
*)
    echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?)."
    echo "<WARNING> Die Zweitschrift bleibt unveraendert: $BK"
    ;;
esac
echo "<OK> preupgrade abgeschlossen."
exit 0
