#!/bin/bash
# Ecowitt-Weiche - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer auf.
# Was hier passiert, darf deshalb nicht dort noch einmal stehen.
#
# Hier passiert mit Absicht fast nichts. stand.json ist KEIN Zwischenspeicher
# von Messwerten, sondern das Gedaechtnis darueber, welche Schnittstelle
# zuletzt getragen hat, wie oft gewechselt wurde und wann zuletzt brauchbare
# Daten kamen. Wer das beim Update wegraeumt, setzt den Wechselzaehler auf
# null - und genau der ist die Zahl, an der man sieht, dass eine Schnittstelle
# seit Wochen nur noch sporadisch antwortet.
#
# Das Protokoll bleibt aus demselben Grund liegen: es verzeichnet nur Wechsel,
# waechst also langsam, und ist die einzige Aufzeichnung darueber, wann der
# Ausfall begonnen hat.
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
rm -f "$BASE/data/plugins/$PFOLDER/stand.json.tmp"
rm -f "$BASE/config/plugins/$PFOLDER/ecowitt.json.tmp"

echo "<OK> postupgrade abgeschlossen."
echo "<INFO> Wechselzaehler und Protokoll bleiben erhalten - sie sind die"
echo "<INFO> einzige Aufzeichnung darueber, seit wann eine Schnittstelle schwaechelt."
exit 0
