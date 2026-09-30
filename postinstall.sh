#!/bin/bash
# Ecowitt-Weiche - postinstall
#
# Der Installer ruft mit:  <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). $3 ist der Ordnername, $5 die
# LoxBerry-Wurzel, $6 der Arbeitsordner mit dem entpackten Archiv
# (Regeln/06). Gearbeitet wird mit $3 und $5.
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

# Upgrade-Marke (Entscheidung 1 vom 29.09.2026): nur wenn preupgrade.sh sie
# angelegt hat, ist dies eine Aktualisierung, und nur dann wird
# zurueckgespielt - ohne Altersvergleich. Die Marke gilt fuer genau diesen
# Einbau und wird auf jedem Weg aus diesem Skript abgeraeumt, auch bei einem
# Abbruch.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
UPGRADE=0
[ -f "$MARKE" ] && UPGRADE=1
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

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

# Sicherung zurueckspielen - NUR bei einer Aktualisierung (Upgrade-Marke).
# Bis 0.9.14 stand hier "uebersteht Update UND Neuinstallation": eine
# Neuinstallation uebernahm Wortzeichen und Stationsadressen einer frueheren
# Installation (Pruefer installer, Befund 1). Bei einer Neuinstallation hat
# preinstall.sh eine liegengebliebene Zweitschrift nach .alt gelegt; hier
# wird dann nichts eingespielt und nichts beiseitegelegt (ein zweites
# Beiseitelegen traefe eine frische Zweitschrift der neuen Installation).
# Zurueckgespielt wird nur, wenn die Konfiguration keinen Inhalt traegt -
# eine gefuellte wird nicht ueberschrieben.
#
# Entschieden wird nach INHALT, wie in ew_hat_inhalt(): ein JSON-Objekt, in
# dem das Wortzeichen schon einmal geschrieben wurde. Bis 0.9.12 genuegte
# ein Anfuehrungszeichen in der Datei - eine abgeschnittene Konfiguration galt
# damit als gefuellt und wurde nicht ersetzt (Fall H5, vorher rot), und die
# Meldung "wiederhergestellt" kam auch, wenn die Zweitschrift selbst nur "{}"
# war (Fall H7, vorher rot). Pruefung-Ecowitt-Weiche-0.9.13.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/ecowitt.json"
mit_inhalt() {   # 0 = ja, 1 = leer, 3 = ohne verwertbaren Stand, 2 = nicht pruefbar (kein php)
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
if [ "$UPGRADE" = 1 ] && [ -f "$BK" ]; then
    mit_inhalt "$CF"; CF_RC=$?
    if [ "$CF_RC" = 1 ] || [ "$CF_RC" = 3 ]; then
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

# Wechselzaehler: preupgrade.sh hat stand.json neben den Ordner gelegt. Nur
# bei Marke zurueck (Entscheidung 1); bei einer Neuinstallation liegt er
# schon als .alt. Ein Stand, den ein Abruf in der Luecke vor diesem Skript
# angelegt hat, zaehlt weniger als der gesicherte und wird ersetzt. Die
# Meldung in postupgrade.sh erscheint nur, wenn es hier gelang (Merker
# stand.zurueckgespielt im Datenordner, postupgrade.sh raeumt ihn ab).
STB="$BASE/data/plugins/$PFOLDER.stand.json"
if [ "$UPGRADE" = 1 ] && [ -f "$STB" ]; then
    if mv -f "$STB" "$PDATA/stand.json" 2>/dev/null; then
        : > "$PDATA/stand.zurueckgespielt" 2>/dev/null
        echo "<OK> Wechselzaehler aus der Sicherung uebernommen."
    else
        echo "<WARNING> Der gesicherte Wechselzaehler liess sich nicht zurueckspielen: $STB"
    fi
fi
# Eine unlesbare Konfiguration, die preupgrade.sh ohne Zweitschrift
# beiseitegelegt hat (I4), kommt als ecowitt.json.kaputt zurueck; der
# Reiter Test nennt sie.
KAP="$BASE/config/plugins/$PFOLDER.kaputt.json"
if [ "$UPGRADE" = 1 ] && [ -f "$KAP" ]; then
    if mv -f "$KAP" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null; then
        echo "<WARNING> Die unlesbare Konfiguration von vor dem Update liegt als $CF.kaputt."
    else
        echo "<WARNING> Die unlesbare Konfiguration von vor dem Update liegt weiter unter $KAP."
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

# Die Erstanleitung nur, wenn keine eingerichtete Konfiguration vorliegt.
# postinstall.sh laeuft auch bei jedem Upgrade; danach war der Rat, die
# Adressen einzutragen, falsch und legte nahe, sie seien verloren.
# "Eingerichtet" heisst: in ecowitt.json steht nach dem Zurueckspielen
# mindestens eine Adresse (primaer oder ersatz nicht leer). Das Wortzeichen,
# nach dem mit_inhalt() die Zweitschrift beurteilt, reicht dafuer nicht: es
# entsteht ohne jede Adresse. PHP ist hier sicher da - ohne PHP endet dieses
# Skript weiter oben.
ew_eingerichtet() {
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        $a = function ($k) use ($d) { return isset($d[$k]) && is_string($d[$k]) && trim($d[$k]) !== ""; };
        exit(is_array($d) && ($a("primaer") || $a("ersatz")) ? 0 : 1);' -- "$1" 2>/dev/null
}
if ew_eingerichtet "$CF"; then
    echo "<OK> Installation abgeschlossen, Einstellungen uebernommen."
    echo "<INFO> Der Reiter Test zeigt, ob beide Adressen der Station antworten."
    exit 0
fi
echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche. Bis sie einmal geoeffnet"
echo "<INFO> ist, antwortet der Endpunkt mit 503 - erst dann entsteht das Wortzeichen."
echo "<INFO>  1. Reiter Einstellungen: beide Adressen der Wetterstation"
echo "<INFO>     eintragen - ohne http:// und ohne Pfad."
echo "<INFO>  2. Reiter Test: beide Adressen pruefen. Stehen dort Striche"
echo "<INFO>     statt Zahlen, antwortet die Station zwar, hat aber den Funk"
echo "<INFO>     zum Aussensensor verloren."
echo "<INFO>  3. Reiter Einbindung in Loxone: die angezeigte Adresse in den"
echo "<INFO>     BEHAELTER des virtuellen HTTP-Eingangs uebernehmen. Die"
echo "<INFO>     Suchtexte bleiben unveraendert."
exit 0
