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
# die Konfiguration keinen Inhalt traegt - eine gefuellte wird nicht
# ueberschrieben.
#
# Entschieden wird nach INHALT, wie in ew_hat_inhalt(): ein JSON-Objekt, in
# dem das Wortzeichen schon einmal geschrieben wurde. Bis 0.9.12 genuegte
# ein Anfuehrungszeichen in der Datei - eine abgeschnittene Konfiguration galt
# damit als gefuellt und wurde nicht ersetzt (Fall H5, vorher rot), und die
# Meldung "wiederhergestellt" kam auch, wenn die Zweitschrift selbst nur "{}"
# war (Fall H7, vorher rot). Pruefung-Ecowitt-Weiche-0.9.13.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/ecowitt.json"
mit_inhalt() {   # 0 = ja, 1 = nein, 2 = nicht pruefbar (kein php)
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && array_key_exists("token", $d) && is_string($d["token"]) ? 0 : 1);' -- "$1" 2>/dev/null
    RC=$?
    [ "$RC" = 0 ] || [ "$RC" = 1 ] || return 2
    return "$RC"
}
if [ -f "$BK" ]; then
    mit_inhalt "$CF"; CF_RC=$?
    if [ "$CF_RC" = 1 ]; then
        mit_inhalt "$BK"; BK_RC=$?
        if [ "$BK_RC" = 0 ]; then
            # Der verdraengte Stand bleibt liegen, wenn er mehr ist als die
            # leere Vorlage.
            if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF")" != '{}' ]; then
                cp -p "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
            fi
            # Direkt kopiert: gelesen wird nur die Zweitschrift, und das Ziel
            # traegt ohnehin keinen Inhalt - ein Abbruch verliert nichts.
            if cp -p "$BK" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null; then
                echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
            else
                echo "<WARNING> Die Zweitschrift liess sich nicht zurueckspielen: $BK"
            fi
        else
            echo "<WARNING> Die Zweitschrift traegt keinen verwertbaren Stand - nichts zurueckgespielt:"
            echo "<WARNING>   $BK"
        fi
    elif [ "$CF_RC" = 2 ]; then
        echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Nichts zurueckgespielt; die Zweitschrift liegt weiter unter $BK"
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
    # ew_paths(), nicht ew_pfade(): die gibt es nicht. Bis 0.9.12 endete der
    # Selbsttest deshalb bei jeder Installation mit "<FAIL> Der Unterbau
    # laesst sich nicht laden" (Fall H8, vorher rot).
    AUS=$(EWLIB="$PHTML/ew_lib.php" php -r 'require getenv("EWLIB"); $p = ew_paths(); echo $p["cfgdatei"];' 2>&1)
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
