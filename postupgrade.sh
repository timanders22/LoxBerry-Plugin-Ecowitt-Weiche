#!/bin/bash
# Ecowitt-Weiche - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer auf.
# Was hier passiert, darf deshalb nicht dort noch einmal stehen.
#
# stand.json ist KEIN Zwischenspeicher von Messwerten, sondern das
# Gedaechtnis darueber, welche Schnittstelle zuletzt getragen hat, wie oft
# gewechselt wurde und wann zuletzt brauchbare Daten kamen. Der Wechselzaehler
# ist die Zahl, an der man sieht, dass eine Schnittstelle seit Wochen nur noch
# sporadisch antwortet.
#
# purge_installation loescht data/plugins/<ordner>/ bei jedem Upgrade. Bis
# 0.9.14 versprach dieses Skript trotzdem "Wechselzaehler bleiben erhalten"
# (Pruefer installer, Befund 3). Seit 0.9.15 rettet preupgrade.sh den Stand
# neben den Ordner, postinstall.sh legt ihn bei Upgrade-Marke zurueck und
# hinterlaesst dann den Merker stand.zurueckgespielt. Die Zusage steht hier
# nur, wenn dieser Merker da ist.
#
# Das Protokoll (log/plugins/<ordner>/) loescht purge_installation nicht.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ecowittweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

# Eine halb geschriebene Nebendatei aus einem Stromausfall mitten im Schreiben
# waere das Einzige, was hier stoert.
rm -f "$BASE/data/plugins/$PFOLDER/stand.json.tmp" "$BASE/data/plugins/$PFOLDER/stand.json.tmp."* 2>/dev/null
rm -f "$BASE/config/plugins/$PFOLDER/ecowitt.json.tmp"
# Seit 0.9.13 tragen die Nebendateien die Prozessnummer (ew_json_schreiben()).
rm -f "$BASE/config/plugins/$PFOLDER/ecowitt.json.tmp."* \
      "$BASE/config/plugins/$PFOLDER.backup.json.tmp."* 2>/dev/null

echo "<OK> postupgrade abgeschlossen."
MERK="$BASE/data/plugins/$PFOLDER/stand.zurueckgespielt"
if [ -f "$MERK" ] && [ -s "$BASE/data/plugins/$PFOLDER/stand.json" ]; then
    echo "<INFO> Wechselzaehler und Protokoll sind erhalten - sie sind die"
    echo "<INFO> einzige Aufzeichnung darueber, seit wann eine Schnittstelle schwaechelt."
else
    echo "<INFO> Es gab keinen gesicherten Wechselzaehler; er beginnt bei 0. Das Protokoll bleibt erhalten."
fi
rm -f "$MERK" 2>/dev/null
exit 0
