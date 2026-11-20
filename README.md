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
| Erfassung | Auf jede Änderung der **Tonnen-Variable** (VM_UPDATE). Fällt der Wert → Verbrauch, steigt er → Befüllung. |
| Messrauschen | **Totband** (Standard 0,2 t): erst ab dieser Änderung wird gebucht, Referenz wird mitgeführt – es geht nichts verloren. |
| Messfehler | Sprünge > **Max. Sprung** (Standard 10 t) je Messung werden nicht gebucht (z. B. Sensor fällt kurz auf 0). |
| Schichten | Früh/Spät/Nacht frei einstellbar (Standard 6/14/22 Uhr). Produktionstag beginnt mit der Frühschicht, die Nachtschicht zählt zum Vortag. |
| Befüllungen | Steigende Werte innerhalb von 30 min werden zu **einem Ereignis** zusammengefasst (Start–Ende, Menge). |
| Reichweite | Bestand ÷ Ø-Tagesverbrauch der letzten 7 abgeschlossenen Produktionstage (je Silo und je Material). |

**Hinweis:** Wird während einer Befüllung gleichzeitig entnommen, ist nur die
Netto-Änderung messbar – die Entnahme in diesem Zeitraum wird dann nicht als
Verbrauch, sondern als geringere Befüllmenge erfasst.

## Variablen (archivierbar)

Je Silo: *Verbrauch Schicht*, *Verbrauch Tag*, *Befüllung Tag*; dazu Summen und
die HTMLBox *Silo-Dashboard* (iframe auf den WebHook, für WebFront/Kachel-Visu).
Für Langzeitauswertungen einfach die Archivierung der Tag-Variablen aktivieren.

## Funktionen

```php
SILO_Update($id);                              // Variablen/Anzeige neu berechnen
SILO_ResetReference($id);                      // aktuelle Füllstände als Referenz übernehmen
SILO_ResetStatistics($id);                     // komplette Historie löschen
SILO_GetDashboardData($id);                    // alle Daten als JSON
SILO_GetConsumption($id, 4, '2026-11-20', 1);  // Silo 4, Frühschicht (0 = ganzer Tag)
```

JSON-Endpunkt für andere Systeme (ERP/MES): `/hook/silomonitor?data=1`
