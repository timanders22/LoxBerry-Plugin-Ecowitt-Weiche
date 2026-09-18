#!/bin/bash
# Ecowitt-Weiche - preupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers ist:
#   preupgrade -> config/* aus dem Archiv ueber config/plugins/<ordner>
#              -> postinstall -> postupgrade -> Cleaning
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das auf dem LoxBerry
# fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# fuenften Argument. Deshalb wird hier ausschliesslich mit $3 und $5 gearbeitet.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ecowittweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

CF="$BASE/config/plugins/$PFOLDER/ecowitt.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

# Gesichert wird nach INHALT, und nie direkt ueber die alte Zweitschrift.
#
# Bis 0.9.12 stand hier ein cp -p ueber die Zweitschrift, sobald ecowitt.json
# ueberhaupt da war. Eine abgeschnittene Datei oder die leere Vorlage "{}"
# verdraengte damit die heile Zweitschrift - den einzigen Rueckweg
# (Pruefung-Ecowitt-Weiche-0.9.13, Faelle H1 und H2, vorher rot).
#
# Inhalt heisst dasselbe wie in ew_hat_inhalt(): ein JSON-Objekt, in dem das
# Wortzeichen schon einmal geschrieben wurde. Rueckgabe 0 = ja, 1 = nein,
# 2 = nicht pruefbar (kein php). Im Zweifel bleibt die Zweitschrift, wie sie ist.
mit_inhalt() {
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && array_key_exists("token", $d) && is_string($d["token"]) ? 0 : 1);' -- "$1" 2>/dev/null
    RC=$?
    [ "$RC" = 0 ] || [ "$RC" = 1 ] || return 2
    return "$RC"
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
*)
    echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?)."
    echo "<WARNING> Die Zweitschrift bleibt unveraendert: $BK"
    ;;
esac
echo "<OK> preupgrade abgeschlossen."
exit 0
