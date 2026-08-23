# LoxBerry-Plugin „Ecowitt-Weiche"

Version 0.9.3

Holt die Messwerte einer Ecowitt-Wetterstation über **zwei** Netzwerkschnittstellen
und reicht die Antwort derjenigen durch, die gerade trägt. In der Loxone-Projektdatei
ändert sich dafür genau **eine** Stelle: die Adresse des virtuellen HTTP-Eingangs.
Alle Suchtexte bleiben gültig, denn das JSON ist dasselbe.

* Fragt zuerst die erste Adresse, bei unbrauchbarer Antwort die Ausweichadresse.
* Beurteilt den **Inhalt**, nicht nur die Erreichbarkeit.
* Reicht die Stationsantwort **wortgetreu** durch und stellt nur zwei eigene Felder voran.
* Fallen beide Seiten aus, kommt HTTP 503 **ohne** Daten — der Ausfall bleibt sichtbar.
* Schaltet nichts, misst nichts, speichert keine Messwerte zwischen.

## Woraus es entstanden ist

Am 23.08.2026 meldete die Station dieses Hauses über ihre LAN-Schnittstelle
192.168.178.20 für Temperatur, Feuchte, Solarstrahlung und UV die Zeichenfolge
`---.-`. Loxone machte daraus 0. Im selben Augenblick lieferte dieselbe Station
über WLAN 192.168.178.21 einwandfrei 23,4 °C und 673,64 W/m².

Eine Loxone-Logik kann damit nichts anfangen: **0 ist ein gültiger Messwert, kein
Fehler.** Die Beschattung des Hauses rechnete stundenlang mit null Sonne, und der
Onlinestatus des Behälters blieb grün — die Station antwortete ja.

## Der entscheidende Punkt

**Die kaputte Seite war erreichbar.** HTTP 200, gültiges JSON — nur ohne Zahlen
darin. Ein Ausweichen, das bloß auf Verbindungsfehler achtet, wäre an diesem Tag
auf der kaputten Schnittstelle geblieben und hätte gar nichts geändert.

Deshalb prüft dieses Plugin den Inhalt: eine Antwort gilt erst dann als brauchbar,
wenn die **Außenfeuchte** (Kennung `0x07`) eine Zahl größer null ist. Reale
Außenluft hat nie 0 %, und die Platzhalter `--` und `---.-` fallen damit durch.

Die Prüfung lässt sich abschalten — für eine Station, die kein Feld `0x07` führt;
dann zählt wieder nur, dass überhaupt geantwortet wurde.

## Was hinausgeht

Der Endpunkt liegt unter

    http://<loxberry>/plugins/ecowittweiche/live.php?token=<wortzeichen>

und liefert das JSON der Station unverändert, ergänzt um zwei Felder ganz vorn:

| Feld | Bedeutung |
| --- | --- |
| `ew_quelle` | `primaer` oder `ersatz` — welche Schnittstelle getragen hat |
| `ew_ok` | 1, solange Daten kommen |

Daneben beantwortet `live.php?status=1` in **einer Textzeile** den Zustand der
Weiche selbst:

    WEICHE;OK=1;QUELLE=1;WECHSEL=3;ALTER=12

| Feld | Bedeutung |
| --- | --- |
| `OK` | 1 = eine Schnittstelle liefert brauchbare Daten |
| `QUELLE` | 0 = keine, 1 = primär, 2 = Ersatz |
| `WECHSEL` | wie oft seit dem Start umgeschaltet wurde |
| `ALTER` | Sekunden seit den letzten brauchbaren Daten, −1 = noch nie |

Diese Zeile gehört als eigener virtueller Eingang ins Haus. Sonst arbeitet die
Weiche unbemerkt, und dass eine Schnittstelle seit Wochen tot ist, fällt erst
auf, wenn auch die zweite ausfällt.

**Beim Anlegen auf MinVal achten:** `ALTER` ist −1, solange noch nie brauchbare
Daten kamen. Steht MinVal auf 0, macht Loxone daraus eine 0 — und 0 hieße dann
fälschlich taufrisch.

## Warum bei totalem Ausfall nichts kommt

Fallen beide Seiten aus, antwortet der Endpunkt mit **HTTP 503 ohne Daten**. Das
ist Absicht. Loxone behält dann seine letzten Werte und schaltet den Onlinestatus
des Behälters ab — der Ausfall ist also sichtbar und in der Logik auswertbar.

Würde hier ein zwischengespeicherter Stand geliefert, rechnete das Haus stundenlang
mit alten Zahlen weiter, ohne dass es jemand merkt. Genau diese stille Sorte
Fehler soll das Plugin beenden — nicht sie eine Ebene höher wiederholen.

## Einrichten in drei Schritten

1. **Reiter Einstellungen:** beide Adressen eintragen, ohne `http://` und ohne
   Pfad — hier 192.168.178.20 und 192.168.178.21. Wer eine leer lässt, schaltet
   diese Seite ab. Welche vorn steht, ist Geschmackssache: LAN ist im Normalfall
   die stabilere, WLAN hängt am Funk.
2. **Reiter Test:** beide Adressen prüfen. Der Test fragt **beide** an und stellt
   Temperatur, Feuchte, Solarstrahlung und UV nebeneinander. Striche statt Zahlen
   heißen: die Station antwortet, hat aber den Funk zum Außensensor verloren.
3. **Reiter Einbindung in Loxone:** die angezeigte Adresse in den **Behälter** des
   virtuellen HTTP-Eingangs übernehmen — den Behälter, nicht die einzelnen
   Befehle. Die Abfragezeit bleibt, wie sie war.

## Das Protokoll

Aufgeschrieben wird nur der **Wechsel** von einer Schnittstelle zur anderen,
mitsamt dem Grund, aus dem die verlassene Seite verworfen wurde. Nicht jeder
Abruf: eine Minutenabfrage erzeugte sonst 1440 Zeilen am Tag, in denen die eine
wichtige untergeht. Ein leeres Protokoll heißt, dass es noch keinen Wechsel gab.

## Was das Plugin nicht heilt

Es hält den Betrieb aufrecht, es repariert nichts. Springt die Weiche dauerhaft
auf die Ausweichadresse, ist die erste Schnittstelle defekt — Kabel, Verteiler
oder Station — und das gehört angesehen. Dafür steht der Zähler der Wechsel in
der Oberfläche und im Statusabruf.

## Voraussetzungen

* LoxBerry ab 3.0.0
* PHP 7.4 oder neuer (geprüft gegen 7.4 und 8.4)
* Eine Ecowitt-Konsole mit `/get_livedata_info` — hier eine GW3000A

Das Plugin ist reines PHP, braucht keine Nachinstallation, keinen Cron-Dienst und
keine Internetverbindung. Es spricht ausschließlich mit der Station im eigenen Netz.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
