# SiloMonitor – IP-Symcon Modul

Zeigt die Füllstände von bis zu **12 Silos** als FullHD-Dashboard und berechnet
**Verbrauch (nur Entnahme)** je Schicht/Tag sowie **Befüllungen getrennt** davon.

## Installation

1. Ordner `SiloMonitor` als Git-Repository bereitstellen **oder** direkt nach
   `<Symcon>/modules/SiloMonitor` kopieren (Linux: `/var/lib/symcon/modules/`,
   Windows: `C:\ProgramData\Symcon\modules\`), Modules Control → Aktualisieren.
2. Instanz anlegen: *Instanz hinzufügen → „SiloMonitor"*.
3. Die Silo-Liste ist mit den Variablen aus eurem Objektbaum vorbelegt
   (Silo 1–8: Prozent-, Tonnen- und Material-„Wert"-Variablen). Bitte einmal kontrollieren.
   Silo 9–12 einfach per „Hinzufügen" ergänzen.
4. Dashboard im Browser öffnen: `http://<Symcon-Server>:3777/hook/silomonitor`
   → am Anzeige-PC im Kiosk-Modus (z. B. `chrome --kiosk <URL>`).
   Die Seite skaliert automatisch auf jede Auflösung (ausgelegt für 1920×1080).

## Schrift

Das Dashboard nutzt **Poppins** (wie die Trafo-Übersicht). Ist sie auf dem
Anzeige-PC nicht installiert, die Dateien `Poppins-Regular.ttf`, `Poppins-Medium.ttf`
und `Poppins-Bold.ttf` (kostenlos bei Google Fonts) in den Ordner
`SiloMonitor/fonts/` legen – der WebHook liefert sie dann mit aus.
Ohne Poppins wird Segoe UI/Roboto verwendet.

## Funktionsweise

| Thema | Umsetzung |
|---|---|
| Erfassung | Die **Tonnen-Variablen** werden im Takt gelesen (Standard 30 s) und über einen **Median von 5 Werten** geglättet (Ausreißer beim Einblasen). |
| Verbrauch | Abnahme ≥ **Totband** (0,2 t) wird als Entnahme gebucht. Kleine Anstiege durch Messrauschen werden ignoriert. |
| Befüllung | Anstieg ≥ **1 t** startet einen Befüllvorgang, währenddessen wird nichts einzeln gebucht. Ende nach **10 min** ohne weiteren Anstieg. **Menge = Höchststand − Stand vorher + Entnahme während der Befüllung** (hochgerechnet aus der aktuellen Verbrauchsrate). |
| Plausibilität | Änderungen schneller als **5 t/min** (ab der letzten Wertänderung gerechnet) gelten als Messfehler. Bleibt ein Wert 30 min bestehen, wird er übernommen. |
| Schichten | Früh/Spät/Nacht frei einstellbar (Standard 6/14/22 Uhr). Produktionstag beginnt mit der Frühschicht, die Nachtschicht zählt zum Vortag. |
| Reichweite | Bestand ÷ Ø-Tagesverbrauch der letzten 7 abgeschlossenen Produktionstage (je Silo und je Material). |

**Abgleich mit dem Lieferschein:** Im Silo-Popup und unter „Diagnose“ steht zu jeder Befüllung
Stand vorher → Höchststand und die hinzugerechnete Entnahme. Weicht die Menge dauerhaft um einen
festen Prozentsatz vom Lieferschein ab, liegt es meist an der Umrechnung Füllstand → Tonnen in der
Messung (Schüttdichte), nicht an der Erfassung.

## Bedienung des Dashboards

- **Schicht wählen:** oben auf Früh/Spät/Nacht klicken. Der grüne Punkt markiert die laufende Schicht.
- **Tag wählen:** mit ‹ › durch die Produktionstage blättern, „Live“ springt zurück.
- **Silo-Details:** Silo-Karte oder Tabellenzeile anklicken. Das Popup zeigt den Füllstandsverlauf
  (24 h / 7 / 30 Tage) mit den Befüllungen, den Verbrauch je Tag und Schicht, die Liste der Befüllungen
  und eine Prognose (Warnschwelle / leer). Schließen mit ×, Esc oder Klick daneben.
- Nach 5 Minuten ohne Bedienung kehrt die Anzeige automatisch zur Live-Ansicht zurück.

Für die Füllstandslinie im Popup muss die **Tonnen-Variable** jedes Silos archiviert werden
(Variable auswählen → Archivierung aktivieren). Balken, Befüllungsliste und Prognose funktionieren auch ohne.

## Neu berechnen aus dem Archiv

Ist die Tonnen-Variable eines Silos archiviert, lassen sich Verbrauch und Befüllungen rückwirkend
(bis 14 Produktionstage) mit der aktuellen Erfassungslogik neu berechnen:
Instanz → **„Aus Archiv neu berechnen …“**. Silos ohne Archivierung bleiben unverändert.
Deshalb: **Archivierung für alle Tonnen-Variablen einschalten.**

## Variablen (archivierbar)

Je Silo: *Verbrauch Schicht*, *Verbrauch Tag*, *Befüllung Tag*; dazu Summen und
die HTMLBox *Silo-Dashboard* (iframe auf den WebHook, für WebFront/Kachel-Visu).
Für Langzeitauswertungen einfach die Archivierung der Tag-Variablen aktivieren.

## Funktionen

```php
SILO_Update($id);                              // sofort erfassen und Anzeige neu berechnen
SILO_Diagnose($id);                            // Prüfbericht (WebHook, Variablen, Archiv, letzte Befüllungen)
SILO_RecalculateFromArchive($id, 2);           // letzte 2 Produktionstage aus dem Archiv neu berechnen
SILO_ResetReference($id);                      // aktuelle Füllstände als Referenz übernehmen
SILO_ResetStatistics($id);                     // komplette Historie löschen
SILO_GetDashboardData($id);                    // alle Daten als JSON
SILO_GetConsumption($id, 4, '2026-11-20', 1);  // Silo 4, Frühschicht (0 = ganzer Tag)
```

JSON-Endpunkte für andere Systeme (ERP/MES):
`/hook/silomonitor?data=1` (heute) · `?data=1&day=2026-11-19` (Rückschau) · `?silo=4&range=7` (Silo-Details)
