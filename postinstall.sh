#!/bin/bash
# Ecowitt-Weiche - postinstall
#
# Der Installer ruft mit:  <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# FUENFTEN Argument, der Ordner mit dem entpackten Archiv im sechsten.
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar sein,
# ohne Schaden anzurichten.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ecowittweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PCONFIG="$BASE/config/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PHTML="$BASE/webfrontend/html/plugins/$PFOLDER"

mkdir -p "$PCONFIG" "$PLOG" "$PDATA" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
# Die Konfiguration traegt das Wortzeichen des Endpunkts - sie geht niemanden
# ausser dem Plugin etwas an.
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/ecowitt.json" ] || echo '{}' > "$PCONFIG/ecowitt.json"
chmod 600 "$PCONFIG/ecowitt.json" 2>/dev/null

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation). Nur, wenn
# die Konfiguration wirklich leer ist - eine gefuellte wird nicht ueberschrieben.
# Geprueft wird auf ein Anfuehrungszeichen und nicht auf den Text {} : der
# Textvergleich liess jede Variante mit Leerzeichen oder Zeilenumbruch durch.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/ecowitt.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || ! grep -q '"' "$CF" 2>/dev/null; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> PHP wurde nicht gefunden. Ohne PHP antwortet der Endpunkt nicht."
    exit 1
fi

# ---------- Laeuft der Unterbau ueberhaupt? ----------
# Hausregel: jeden Dienst nach der Installation einmal von Hand aufrufen und
# den Rueckgabewert ansehen. Ein require, das nur im entpackten Archiv aufgeht,
# laeuft installiert NIE - und der Miniserver holt sich dann schweigend eine
# 500, ohne dass es jemand bemerkt.
#
# Geprueft wird, dass sich die Bibliothek laden laesst UND dass sie denselben
# Konfigurationspfad errechnet, den dieses Skript eben angelegt hat. Beides
# zusammen, denn eine Bibliothek, die laedt und im falschen Ordner sucht,
# faellt sonst erst beim ersten Speichern auf.
#
# Abgefragt wird hier NICHTS: bei einer Neuinstallation steht noch keine
# Adresse in der Konfiguration, und ein Fehlschlag waere kein Fehler.
if [ -f "$PHTML/ew_lib.php" ]; then
    AUS=$(EWLIB="$PHTML/ew_lib.php" php -r 'require getenv("EWLIB"); $p = ew_pfade(); echo $p["cfgdatei"];' 2>&1)
    RC=$?
    if [ $RC -ne 0 ]; then
        echo "<FAIL> Der Unterbau laesst sich nicht laden:"
        echo "$AUS" | tail -10 | sed 's/^/<FAIL> /'
        echo "<INFO> Das Plugin ist installiert, antwortet aber nicht."
    elif [ "$AUS" != "$CF" ]; then
        echo "<FAIL> Der Unterbau sucht seine Konfiguration an der falschen Stelle."
        echo "<FAIL> erwartet: $CF"
        echo "<FAIL> errechnet: $AUS"
    else
        echo "<OK> Selbsttest: der Unterbau laedt und findet $AUS"
    fi
else
    echo "<INFO> ew_lib.php wurde unter $PHTML nicht gefunden - der Selbsttest entfaellt."
fi

if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$PCONFIG" "$PLOG" "$PDATA" 2>/dev/null
    [ -f "$BK" ] && chown loxberry:loxberry "$BK" 2>/dev/null
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: beide Adressen der Wetterstation"
echo "<INFO>     eintragen - ohne http:// und ohne Pfad."
echo "<INFO>  2. Reiter Test: beide Adressen pruefen. Stehen dort Striche"
echo "<INFO>     statt Zahlen, antwortet die Station zwar, hat aber den Funk"
echo "<INFO>     zum Aussensensor verloren."
echo "<INFO>  3. Reiter Einbindung in Loxone: die angezeigte Adresse in den"
echo "<INFO>     BEHAELTER des virtuellen HTTP-Eingangs uebernehmen. Die"
echo "<INFO>     Suchtexte bleiben unveraendert."
exit 0
