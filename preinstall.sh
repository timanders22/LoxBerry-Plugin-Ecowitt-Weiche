#!/bin/bash
# Ecowitt-Weiche - preinstall
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Neu in 0.9.15 (I1, Entscheidung 1 vom 29.09.2026), Bauform
# audi_bau/preinstall.sh (AudiConnect 0.9.22). Der Installer ruft dieses
# Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR
# dem Kopieren von Konfiguration und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, config :916, html :1064,
# postinstall :1305 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und gesicherter
# Wechselzaehler braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json, mit dem Wortzeichen und den
# Stationsadressen), eine beiseitegelegte unlesbare Konfiguration
# (config/plugins/<ordner>.kaputt.json) und der gesicherte Wechselzaehler
# (data/plugins/<ordner>.stand.json) einer frueheren Installation gehen nach
# <name>.alt, gemeldet mit genau einer <WARNING>. Bis 0.9.14 spielte
# postinstall.sh die Zweitschrift ungefragt zurueck (in WSL gemessen,
# Pruefer installer, Befund 1).
#
# Warum HIER und nicht erst in postinstall.sh: zwischen dem Kopieren der
# Oberflaeche (:1064) und postinstall.sh (:1305) fragt der Miniserver den
# Endpunkt weiter ab. Bis 0.9.14 heilte ein solcher Abruf die Konfiguration
# aus der liegengebliebenen Zweitschrift, bevor postinstall.sh sie haette
# beiseitelegen koennen (Pruefer installer, Befund 2, Fall C-mit_pre-1).
# Ein zweites Beiseitelegen in postinstall.sh traefe dagegen eine FRISCHE
# Zweitschrift der neuen Installation - deshalb nur hier.
# Die Selbstheilung der Bibliothek liest .alt nie; die Deinstallation
# raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ecowittweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.kaputt.json" \
            "$BASE/data/plugins/$PFOLDER.stand.json"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
for A in "$BASE/config/plugins/$PFOLDER.backup.json.alt" "$BASE/config/plugins/$PFOLDER.kaputt.json.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation (Wortzeichen, Stationsadressen, Wechselzaehler) werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    T="$T Die Loxone-Adresse braucht danach das NEUE Wortzeichen aus dem Reiter Einbindung in Loxone; es entsteht beim ersten Oeffnen der Oberflaeche. Bis dahin antwortet der Endpunkt mit 503, danach mit dem alten Wortzeichen mit 403."
    echo "$T"
fi
exit 0
