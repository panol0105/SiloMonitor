<?php

declare(strict_types=1);

/**
 * SiloMonitor
 *
 * Visualisiert die Füllstände von bis zu 12 Silos und ermittelt aus der
 * Tonnen-Variable je Silo:
 *   - Verbrauch (nur Entnahme, also fallender Füllstand) je Schicht und Produktionstag
 *   - Befüllungen (steigender Füllstand) getrennt davon, inkl. Ereignisliste
 *
 * Anzeige: Vollbild-Dashboard (1920x1080) über WebHook  /hook/<HookName>
 *          + HTMLBox-Variable (iframe) für WebFront / Kachel-Visualisierung
 */
class SiloMonitor extends IPSModule
{
    private const MAX_SILOS = 12;
    private const WEBHOOK_MODULE = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const TREND_SECONDS = 600;
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}'; // Pfeil "befüllt/entnimmt" bleibt 10 min sichtbar

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Title', 'Silo-Übersicht');
        $this->RegisterPropertyString('HookName', 'silomonitor');
        $this->RegisterPropertyString('DashboardURL', '');
        $this->RegisterPropertyString('Silos', json_encode(self::DefaultSilos()));

        $this->RegisterPropertyInteger('ShiftEarly', 6);
        $this->RegisterPropertyInteger('ShiftLate', 14);
        $this->RegisterPropertyInteger('ShiftNight', 22);

        $this->RegisterPropertyInteger('SampleInterval', 30);
        $this->RegisterPropertyFloat('Deadband', 0.2);
        $this->RegisterPropertyFloat('MaxJump', 10.0);          // veraltet (bis 1.3), nicht mehr genutzt
        $this->RegisterPropertyInteger('FillMerge', 30);        // veraltet (bis 1.3), nicht mehr genutzt
        $this->RegisterPropertyFloat('FillStart', 1.0);         // Anstieg, ab dem eine Befüllung beginnt (t)
        $this->RegisterPropertyInteger('FillSettle', 10);       // Befüllung endet nach x min ohne weiteren Anstieg
        $this->RegisterPropertyBoolean('FillCompensate', true); // Entnahme während der Befüllung hinzurechnen
        $this->RegisterPropertyFloat('MaxRate', 5.0);           // plausible Änderung (t/min), darüber = Messfehler
        $this->RegisterPropertyInteger('MedianSamples', 5);     // Glättung (Anzahl Messwerte)
        $this->RegisterPropertyInteger('HistoryDays', 35);

        $this->RegisterPropertyInteger('WarnLevel', 30);
        $this->RegisterPropertyInteger('CriticalLevel', 15);
        $this->RegisterPropertyInteger('RefreshSeconds', 10);
        $this->RegisterPropertyString('SiloStyle', 'bulk');

        // Laufzeitdaten
        $this->RegisterAttributeString('State', '{}');                    // Referenzwert/Trend je Silo
        $this->RegisterAttributeString('Stats', '{"S":{},"D":{}}');       // S = Schichten, D = Tage
        $this->RegisterAttributeString('Events', '[]');                   // Befüllungsereignisse
        $this->RegisterAttributeString('LastShiftKey', '');

        $this->RegisterTimer('Tick', 0, 'SILO_Tick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Erfassung läuft per Timer – evtl. alte Ereignis-Registrierungen (Version 1.0) entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        if (!IPS_VariableProfileExists('SILO.Tons')) {
            IPS_CreateVariableProfile('SILO.Tons', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('SILO.Tons', 1);
            IPS_SetVariableProfileText('SILO.Tons', '', ' t');
            IPS_SetVariableProfileIcon('SILO.Tons', 'Factory');
        }

        $silos = $this->GetSilos();
        $usedPos = [];
        foreach ($silos as $silo) {
            $p = $silo['Pos'];
            $usedPos[] = $p;
            $this->RegisterVariableFloat('ConsShift' . $p, 'Verbrauch Schicht – ' . $silo['Name'], 'SILO.Tons', 100 + $p * 3);
            $this->RegisterVariableFloat('ConsDay' . $p, 'Verbrauch Tag – ' . $silo['Name'], 'SILO.Tons', 101 + $p * 3);
            $this->RegisterVariableFloat('FillDay' . $p, 'Befüllung Tag – ' . $silo['Name'], 'SILO.Tons', 102 + $p * 3);
        }
        // Variablen von Silos, die aus der Liste entfernt wurden, löschen
        for ($p = 1; $p <= self::MAX_SILOS; $p++) {
            if (!in_array($p, $usedPos, true)) {
                foreach (['ConsShift', 'ConsDay', 'FillDay'] as $prefix) {
                    if (@$this->GetIDForIdent($prefix . $p) !== false) {
                        $this->UnregisterVariable($prefix . $p);
                    }
                }
            }
        }
        $this->RegisterVariableFloat('ConsShiftTotal', 'Verbrauch Schicht – Gesamt', 'SILO.Tons', 10);
        $this->RegisterVariableFloat('ConsDayTotal', 'Verbrauch Tag – Gesamt', 'SILO.Tons', 11);
        $this->RegisterVariableFloat('FillDayTotal', 'Befüllung Tag – Gesamt', 'SILO.Tons', 12);
        $this->RegisterVariableString('Dashboard', 'Silo-Dashboard', '~HTMLBox', 1);

        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterHook('/hook/' . $this->GetHookName());
        $this->InitReferences(false);
        $url = trim($this->ReadPropertyString('DashboardURL'));
        if ($url === '') {
            $url = '/hook/' . $this->GetHookName();
        }
        $this->SetValue('Dashboard', '<iframe src="' . htmlspecialchars($url) . '" style="width:100%;height:100%;min-height:600px;border:0;background:#f0f0f0"></iframe>');
        $this->SetTimerInterval('Tick', max(5, $this->ReadPropertyInteger('SampleInterval')) * 1000);
        $this->Tick();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'HookInfo') {
                $action['caption'] = 'Dashboard (FullHD-Vollbild): http://<IP-Symcon-Server>:3777/hook/' . $this->GetHookName();
            }
        }
        return json_encode($form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (SILO_*)
    // ------------------------------------------------------------------

    /**
     * Timer (Standard alle 30 s): alle Tonnen-Werte lesen, Verbrauch/Befüllung buchen,
     * Schichtwechsel erkennen, Variablen nachführen und Dashboard-Daten zwischenspeichern.
     * Ein Durchlauf statt eines Skriptaufrufs je OPC-Aktualisierung → kaum Last.
     */
    public function Tick()
    {
        $t0 = microtime(true);
        $now = time();
        $state = $this->GetState();
        $stats = $this->GetStats();
        $events = json_decode($this->ReadAttributeString('Events'), true) ?: [];
        $dirty = false;
        foreach ($this->GetSilos() as $silo) {
            if ($silo['VarTons'] > 0 && IPS_VariableExists($silo['VarTons'])) {
                $age = $now - (int) IPS_GetVariable($silo['VarTons'])['VariableUpdated'];
                $dirty = $this->ProcessLevel($silo['Pos'], (float) GetValue($silo['VarTons']), $now, $state, $stats, $events, $age) || $dirty;
            }
        }
        if ($dirty) {
            $this->WriteAttributeString('State', json_encode($state));
            $this->WriteAttributeString('Stats', json_encode($stats));
            $this->WriteAttributeString('Events', json_encode($events));
        }

        $info = $this->ShiftInfo($now);
        $key = $info['day'] . '|' . $info['shift'];
        if ($this->ReadAttributeString('LastShiftKey') !== $key) {
            $this->WriteAttributeString('LastShiftKey', $key);
            $this->SendDebug('Schichtwechsel', $key, 0);
            $this->Prune();
        }
        $this->UpdateVariables();
        $this->RefreshCache();
        $this->SendDebug('Tick', sprintf('%.1f ms', (microtime(true) - $t0) * 1000), 0);
    }

    public function Update()
    {
        $this->Tick();
    }

    /** Referenzwerte aus den aktuellen Füllständen übernehmen (ohne Buchung) */
    public function ResetReference()
    {
        $this->InitReferences(true);
        echo 'Referenzwerte übernommen.';
    }

    public function ResetStatistics()
    {
        $this->WriteAttributeString('Stats', '{"S":{},"D":{}}');
        $this->WriteAttributeString('Events', '[]');
        $this->InitReferences(true);
        $this->Tick();
        echo 'Statistik gelöscht.';
    }

    /** Prüft Konfiguration, WebHook und Füllstands-Variablen und gibt einen Bericht aus */
    public function Diagnose(): string
    {
        $out = [];
        $hook = '/hook/' . $this->GetHookName();
        $ok = false;
        foreach (IPS_GetInstanceListByModuleID(self::WEBHOOK_MODULE) as $id) {
            foreach (json_decode(IPS_GetProperty($id, 'Hooks'), true) ?: [] as $h) {
                if ($h['Hook'] === $hook && $h['TargetID'] === $this->InstanceID) {
                    $ok = true;
                }
            }
        }
        $out[] = 'WebHook ' . $hook . ': ' . ($ok ? 'OK' : 'NICHT registriert – Instanz einmal speichern (Änderungen übernehmen)');
        $out[] = 'Erfassungstakt: alle ' . $this->ReadPropertyInteger('SampleInterval') . ' s · Totband ' . $this->ReadPropertyFloat('Deadband') . ' t';
        $info = $this->ShiftInfo(time());
        $out[] = 'Produktionstag ' . $info['day'] . ', Schicht ' . $info['shift'] . ' (' . $info['from'] . '–' . $info['to'] . ')';
        $out[] = '';
        $state = $this->GetState();
        $silos = $this->GetSilos();
        if (count($silos) === 0) {
            $out[] = 'Keine aktiven Silos in der Liste!';
        }
        foreach ($silos as $silo) {
            $k = (string) $silo['Pos'];
            $line = $silo['Name'] . ': ';
            if ($silo['VarTons'] <= 0 || !IPS_VariableExists($silo['VarTons'])) {
                $out[] = $line . 'FEHLER – Tonnen-Variable ' . $silo['VarTons'] . ' existiert nicht';
                continue;
            }
            $v = IPS_GetVariable($silo['VarTons']);
            $val = (float) GetValue($silo['VarTons']);
            $ref = $state[$k]['ref'] ?? null;
            $line .= sprintf('%.2f t (Var %d, zuletzt aktualisiert %s)', $val, $silo['VarTons'], date('d.m. H:i:s', $v['VariableUpdated']));
            $line .= $ref === null ? ' · noch keine Referenz' : sprintf(' · Referenz %.2f t, Differenz %+.2f t', $ref, $val - $ref);
            $acIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
            $line .= (count($acIDs) && @AC_GetLoggingStatus($acIDs[0], $silo['VarTons'])) ? ' · Archiv: ja' : ' · Archiv: NEIN';
            if (!empty($state[$k]['fill'])) {
                $line .= sprintf(' · Befüllung läuft seit %s (Basis %.2f t)', date('H:i', (int) $state[$k]['fill']['start']), $state[$k]['fill']['base']);
            }
            if (!empty($state[$k]['ts'])) {
                $line .= ' · letzte Buchung ' . date('H:i', (int) $state[$k]['ts']);
            }
            foreach (['VarPercent' => '%', 'VarMaterial' => 'Material'] as $f => $label) {
                if ($silo[$f] > 0 && !IPS_VariableExists($silo[$f])) {
                    $line .= ' · ' . $label . '-Variable ' . $silo[$f] . ' fehlt';
                }
            }
            $out[] = $line;
        }
        $out[] = '';
        $out[] = 'Letzte Befüllungen (Stand vorher → Höchststand + Entnahme während Befüllung = Menge):';
        $names = [];
        foreach ($silos as $silo) {
            $names[$silo['Pos']] = $silo['Name'];
        }
        $last = array_slice(json_decode($this->ReadAttributeString('Events'), true) ?: [], -8);
        if (count($last) === 0) {
            $out[] = '  keine';
        }
        foreach (array_reverse($last) as $e) {
            $out[] = sprintf('  %s %s–%s  %s: %s → %s t + %s t = %.2f t%s',
                date('d.m.', $e['start']), date('H:i', $e['start']), date('H:i', $e['end']), $names[$e['s']] ?? ('Silo ' . $e['s']),
                isset($e['base']) ? number_format($e['base'], 2, ',', '') : '?', isset($e['peak']) ? number_format($e['peak'], 2, ',', '') : '?',
                isset($e['comp']) ? number_format($e['comp'], 2, ',', '') : '?', $e['t'], !empty($e['open']) ? '  (läuft)' : '');
        }
        $text = implode("\n", $out);
        echo $text;
        return $text;
    }

    /**
     * Berechnet Verbrauch und Befüllungen der letzten $Days Produktionstage (inkl. heute) neu,
     * indem die archivierten Tonnen-Werte durch die aktuelle Erfassungslogik gespielt werden.
     * Silos ohne Archivierung bleiben unverändert.
     */
    public function RecalculateFromArchive(int $Days): string
    {
        $t0 = microtime(true);
        $Days = max(1, min(14, $Days));
        $acIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
        if (count($acIDs) === 0) {
            echo 'Kein Archiv gefunden.';
            return 'Kein Archiv gefunden.';
        }
        $ac = $acIDs[0];
        $now = time();
        $today = $this->ShiftInfo($now)['day'];
        $startDay = date('Y-m-d', strtotime($today . ' 12:00 -' . ($Days - 1) . ' days'));
        $from = strtotime($startDay . ' ' . sprintf('%02d:00', $this->ReadPropertyInteger('ShiftEarly')));
        $warm = $from - 3 * 3600;                         // Vorlauf für Referenz und Verbrauchsrate
        $step = max(30, $this->ReadPropertyInteger('SampleInterval'));
        if ($Days > 3) {
            $step = max($step, 60);
        }

        $stats = $this->GetStats();
        $events = json_decode($this->ReadAttributeString('Events'), true) ?: [];
        $state = $this->GetState();
        $tmpStats = ['S' => [], 'D' => []];
        $tmpEvents = [];
        $report = [];
        $done = [];

        foreach ($this->GetSilos() as $silo) {
            $var = $silo['VarTons'];
            $k = (string) $silo['Pos'];
            if ($var <= 0 || !IPS_VariableExists($var) || !@AC_GetLoggingStatus($ac, $var)) {
                $report[] = $silo['Name'] . ': nicht archiviert – unverändert';
                continue;
            }
            $series = $this->LoadSeries($ac, $var, $warm, $now);
            if (count($series) === 0) {
                $report[] = $silo['Name'] . ': keine Archivwerte im Zeitraum – unverändert';
                continue;
            }
            $st = [];
            $n = count($series);
            $idx = 0;
            $cur = null;
            $curTs = $warm;
            for ($t = $warm; $t <= $now; $t += $step) {
                while ($idx < $n && $series[$idx][0] <= $t) {
                    $cur = $series[$idx][1];
                    $curTs = $series[$idx][0];
                    $idx++;
                }
                if ($cur !== null) {
                    $this->ProcessLevel($silo['Pos'], $cur, $t, $st, $tmpStats, $tmpEvents, $t - $curTs);
                }
            }
            $state[$k] = $st[$k] ?? ($state[$k] ?? []);
            $done[$k] = $silo['Name'];
            $report[] = sprintf('%s: %d Archivwerte verarbeitet', $silo['Name'], $n);
        }

        if (count($done) > 0) {
            // alte Werte der neu berechneten Silos ab Starttag ersetzen
            foreach (['S', 'D'] as $type) {
                foreach ($stats[$type] as $key => $rows) {
                    if (substr((string) $key, 0, 10) >= $startDay) {
                        foreach (array_keys($done) as $k) {
                            unset($stats[$type][$key][$k]);
                        }
                    }
                }
                foreach ($tmpStats[$type] as $key => $rows) {
                    if (substr((string) $key, 0, 10) >= $startDay) {
                        foreach ($rows as $k => $row) {
                            $stats[$type][$key][$k] = $row;
                        }
                    }
                }
            }
            $events = array_values(array_filter($events, fn ($e) => !(isset($done[(string) $e['s']]) && $e['start'] >= $from)));
            foreach ($tmpEvents as $e) {
                if ($e['start'] >= $from) {
                    $events[] = $e;
                }
            }
            usort($events, fn ($a, $b) => $a['start'] <=> $b['start']);
            $this->WriteAttributeString('Stats', json_encode($stats));
            $this->WriteAttributeString('Events', json_encode($events));
            $this->WriteAttributeString('State', json_encode($state));
            $this->UpdateVariables();
            $this->RefreshCache();

            // Ergebnis je Tag
            $report[] = '';
            $report[] = 'Ergebnis (Befüllung / Verbrauch in t):';
            for ($d = $startDay; $d <= $today; $d = date('Y-m-d', strtotime($d . ' 12:00 +1 day'))) {
                $line = '  ' . date('d.m.', strtotime($d)) . ': ';
                $parts = [];
                foreach ($done as $k => $name) {
                    $r = $stats['D'][$d][$k] ?? ['c' => 0, 'f' => 0];
                    if ($r['f'] > 0.05 || $r['c'] > 0.05) {
                        $parts[] = sprintf('%s +%.1f / −%.1f', $name, $r['f'], $r['c']);
                    }
                }
                $report[] = $line . (count($parts) ? implode(' · ', $parts) : '–');
            }
        }
        $report[] = '';
        $report[] = sprintf('Dauer: %.1f s', microtime(true) - $t0);
        $text = implode("\n", $report);
        echo $text;
        return $text;
    }

    /** Archivwerte aufsteigend als [[ts, wert], …]; bei sehr vielen Rohwerten 1-Minuten-Mittel */
    private function LoadSeries(int $ac, int $var, int $from, int $to): array
    {
        $out = [];
        $before = @AC_GetLoggedValues($ac, $var, 0, $from - 1, 1) ?: [];
        if (count($before) > 0) {
            $out[] = [$from, (float) $before[0]['Value']];
        }
        $raw = [];
        $end = $to;
        for ($page = 0; $page < 20; $page++) {
            $chunk = @AC_GetLoggedValues($ac, $var, $from, $end, 10000) ?: [];
            foreach ($chunk as $v) {
                $raw[] = [(int) $v['TimeStamp'], (float) $v['Value']];
            }
            if (count($chunk) < 10000) {
                break;
            }
            $end = (int) $chunk[count($chunk) - 1]['TimeStamp'] - 1;
        }
        if (count($raw) >= 200000) {
            // zu viele Rohwerte → 1-Minuten-Aggregation des Archivs
            $raw = [];
            for ($d = $from; $d < $to; $d += 86400) {
                foreach (@AC_GetAggregatedValues($ac, $var, 6, $d, min($to, $d + 86399), 0) ?: [] as $v) {
                    $raw[] = [(int) $v['TimeStamp'] + 30, (float) $v['Avg']];
                }
            }
        }
        usort($raw, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_merge($out, $raw);
    }

    /** Liefert alle Daten des Dashboards als JSON (auch für eigene Skripte nutzbar) */
    public function GetDashboardData(): string
    {
        return json_encode($this->BuildData());
    }

    /** Verbrauch eines Silos (Pos 1..12) für Produktionstag (Y-m-d) und Schicht (1..3, 0 = ganzer Tag) */
    public function GetConsumption(int $Pos, string $Day, int $Shift): float
    {
        $stats = $this->GetStats();
        if ($Shift === 0) {
            return (float) ($stats['D'][$Day][(string) $Pos]['c'] ?? 0);
        }
        return (float) ($stats['S'][$Day . '|' . $Shift][(string) $Pos]['c'] ?? 0);
    }

    // ------------------------------------------------------------------
    // WebHook
    // ------------------------------------------------------------------

    protected function ProcessHookData()
    {
        if (isset($_GET['font'])) {
            // optionale Schriftdateien aus ./fonts (z. B. Poppins-Regular.ttf)
            $file = __DIR__ . '/fonts/' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $_GET['font']) . '.ttf';
            if (!is_file($file)) {
                http_response_code(404);
                return;
            }
            header('Content-Type: font/ttf');
            header('Cache-Control: public, max-age=604800');
            readfile($file);
            return;
        }
        if (isset($_GET['silo'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $range = (int) ($_GET['range'] ?? 7);
            echo json_encode($this->SiloDetail((int) $_GET['silo'], in_array($range, [1, 7, 30], true) ? $range : 7), JSON_HEX_TAG | JSON_HEX_AMP);
            return;
        }
        if (isset($_GET['data'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $day = (string) ($_GET['day'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day < $this->ShiftInfo(time())['day']) {
                echo json_encode($this->BuildData($day), JSON_HEX_TAG | JSON_HEX_AMP); // Rückschau
                return;
            }
            echo $this->CachedJson();
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->RenderPage();
    }

    // ------------------------------------------------------------------
    // Erfassung
    // ------------------------------------------------------------------

    /**
     * Verarbeitet einen Messwert eines Silos.
     *
     *  1. Glättung: gleitender Median über die letzten n Messwerte (filtert Ausreißer beim Einblasen).
     *  2. Plausibilität: Änderung schneller als MaxRate t/min gilt als Messfehler (nach 30 min wird der Wert übernommen).
     *  3. Ruhe/Entnahme: Abnahme ≥ Totband wird als Verbrauch gebucht, kleine Anstiege werden ignoriert.
     *  4. Befüllung: Anstieg ≥ FillStart startet einen Vorgang. Währenddessen wird nichts einzeln gebucht.
     *     Ende nach FillSettle min ohne neuen Höchststand. Menge = Höchststand − Stand vorher
     *     + geschätzte Entnahme während der Befüllung (Verbrauchsrate der 60 min davor).
     */
    private function ProcessLevel(int $pos, float $raw, int $ts, array &$state, array &$stats, array &$events, int $age = 0): bool
    {
        $k = (string) $pos;
        $s = $state[$k] ?? [];
        $deadband = $this->ReadPropertyFloat('Deadband');

        // 1. Glättung
        $buf = $s['buf'] ?? [];
        if (isset($s['last']) && $ts - (int) $s['last'] > 300) {
            $buf = []; // nach einer Lücke nicht mit alten Werten mitteln
        }
        if ($age > 120) {
            $s['stale'] = true;      // Wert wird nicht mehr aktualisiert
        } elseif (!empty($s['stale'])) {
            $buf = [];               // erster frischer Wert nach einer Lücke: eingefrorene Werte verwerfen
            unset($s['stale']);
        }
        $buf[] = $raw;
        $buf = array_slice($buf, -max(1, $this->ReadPropertyInteger('MedianSamples')));
        $sorted = $buf;
        sort($sorted);
        $v = $sorted[intdiv(count($sorted), 2)];
        $prevV = $s['v'] ?? null;
        $prevT = (int) ($s['last'] ?? $ts);
        $chgT = (int) ($s['vts'] ?? $prevT);   // Zeitpunkt der letzten Wertänderung
        $s['buf'] = $buf;
        $s['last'] = $ts;

        if (!isset($s['ref'])) {
            $s += ['ref' => $v, 'v' => $v, 'vts' => $ts, 'low' => $ts, 'dir' => 0, 'ts' => 0, 'rate' => null, 'lb' => null, 'fill' => null];
            $state[$k] = $s;
            return true;
        }

        // 2. Plausibilität (Rate statt fester Sprunggrenze → nach Ausfällen geht nichts verloren)
        $maxRate = $this->ReadPropertyFloat('MaxRate');
        if ($maxRate > 0 && $prevV !== null) {
            // OPC-Werte kommen evtl. nur selten → erlaubte Änderung ab der letzten Wertänderung rechnen
            $allowed = $maxRate * max(0.5, ($ts - $chgT) / 60) + $deadband;
            if (abs($v - $prevV) > $allowed) {
                $s['bad'] = $s['bad'] ?? $ts;
                if ($ts - $s['bad'] < 1800) {
                    $this->SendDebug('Unplausibel', sprintf('Silo %d: %.2f → %.2f t ignoriert', $pos, $prevV, $v), 0);
                    $s['buf'] = array_slice($buf, 0, -1);
                    $state[$k] = $s;
                    return true;
                }
                // Wert bleibt seit 30 min so → neue Basis (z. B. nach Kalibrierung)
                $this->SendDebug('Neue Basis', sprintf('Silo %d: %.2f t', $pos, $v), 0);
                $s['ref'] = $v;
                $s['fill'] = null;
                $s['low'] = $ts;
            }
        }
        unset($s['bad'], $s['cons']);
        if ($prevV === null || abs($v - $prevV) > 0.0001) {
            $s['vts'] = $ts;
        }
        $s['v'] = $v;

        if (empty($s['fill'])) {
            $d = $v - (float) $s['ref'];
            if ($d >= $this->ReadPropertyFloat('FillStart')) {
                // 4. Befüllung beginnt – Beginn = letzter Messwert ohne Anstieg
                $start = (int) min($ts, $s['low'] ?? $prevT);
                $rate = (float) ($s['rate'] ?? 0);
                if (!empty($s['lb']) && $start - (int) $s['lb'] > 900 && (float) $s['ref'] > 1.0) {
                    // seit > 15 min keine Entnahme, obwohl Material da war → Verbrauch höchstens Totband / Zeit.
                    // (Bei leerem Silo nicht: dort fehlte nur das Material, die Rate davor gilt weiter.)
                    $rate = min($rate, $deadband / (($start - (int) $s['lb']) / 60));
                }
                $s['fill'] = ['start' => $start, 'base' => (float) $s['ref'], 'peak' => $v, 'peakTs' => $ts, 'rate' => round($rate, 4)];
                $events[] = ['s' => $pos, 'start' => $start, 'end' => $ts, 't' => round($d, 3), 'open' => true, 'base' => round((float) $s['ref'], 2)];
                if (count($events) > 500) {
                    $events = array_slice($events, -500);
                }
                $s['dir'] = 1;
                $s['ts'] = $ts;
                $this->SendDebug('Befüllung Start', sprintf('Silo %d: Basis %.2f t, Rate %.3f t/min', $pos, $s['ref'], $s['fill']['rate']), 0);
            } elseif ($d <= -$deadband) {
                // 3. Entnahme
                $this->Book($stats, $k, 'c', -$d, $ts);
                if (!empty($s['lb']) && $ts - (int) $s['lb'] <= 1800 && $ts > (int) $s['lb']) {
                    // Verbrauchsrate (t/min) als gleitender Mittelwert – Basis für die Korrektur bei Befüllungen
                    // Menge und Zeit getrennt mitteln (Mittel von Einzelraten wäre bei Rauschen zu hoch)
                    $dt = ($ts - (int) $s['lb']) / 60;
                    $s['ra'] = isset($s['ra']) ? 0.9 * (float) $s['ra'] + 0.1 * -$d : -$d;
                    $s['rd'] = isset($s['rd']) ? 0.9 * (float) $s['rd'] + 0.1 * $dt : $dt;
                    $s['rate'] = round($s['ra'] / max(0.01, $s['rd']), 4);
                }
                $s['lb'] = $ts;
                $s['ref'] = $v;
                $s['dir'] = -1;
                $s['ts'] = $ts;
            }
            if ($v < (float) $s['ref'] + $deadband) {
                $s['low'] = $ts; // letzter Zeitpunkt ohne nennenswerten Anstieg
            }
            $state[$k] = $s;
            return true;
        }

        // Befüllung läuft
        $f = $s['fill'];
        if ($v > $f['peak'] + $deadband / 2) {
            $f['peak'] = $v;
            $f['peakTs'] = $ts;
        }
        $comp = $this->ReadPropertyBoolean('FillCompensate') ? $f['rate'] * max(0, $f['peakTs'] - $f['start']) / 60 : 0.0;
        $amount = $f['peak'] - $f['base'] + $comp;
        $idx = $this->OpenEventIndex($events, $pos);
        if ($idx !== null) {
            $events[$idx]['t'] = round($amount, 3);
            $events[$idx]['end'] = $f['peakTs'];
        }
        $s['dir'] = 1;
        $s['ts'] = $ts;

        $settleSec = $this->ReadPropertyInteger('FillSettle') * 60;
        $settled = $ts - $f['peakTs'] >= $settleSec;
        if ($settled && $age > 120 && $ts - $f['peakTs'] < $settleSec + 3600) {
            // Messwert seit > 2 min nicht aktualisiert (Ausfall?) → Befüllung noch nicht abschließen
            $settled = false;
            $s['fill'] = $f;
            $state[$k] = $s;
            return true;
        }
        if ($settled || $ts - $f['start'] > 6 * 3600) {
            if ($this->ReadPropertyBoolean('FillCompensate') && $ts > $f['peakTs']) {
                // tatsächliche Verbrauchsrate im Nachlauf messen und mit der Rate vorher mitteln
                $post = max(0.0, ($f['peak'] - $v) / (($ts - $f['peakTs']) / 60));
                $f['rate'] = ($f['rate'] + $post) / 2;
                $comp = $f['rate'] * max(0, $f['peakTs'] - $f['start']) / 60;
                $amount = $f['peak'] - $f['base'] + $comp;
                if ($post > 0) {
                    $s['rate'] = round(0.5 * (float) ($s['rate'] ?? $post) + 0.5 * $post, 4);
                }
            }
            if ($v - $f['base'] + $comp < $this->ReadPropertyFloat('FillStart')) {
                // Fehlalarm (z. B. Störung) – kein Befüllvorgang
                if ($idx !== null) {
                    array_splice($events, $idx, 1);
                }
                if ($f['base'] - $v >= $deadband) {
                    $this->Book($stats, $k, 'c', $f['base'] - $v, $ts);
                }
                $this->SendDebug('Befüllung verworfen', sprintf('Silo %d', $pos), 0);
            } else {
                // Befüllung buchen (auf den Zeitpunkt des Beginns) + Entnahme währenddessen und danach
                $this->Book($stats, $k, 'f', $amount, $f['start']);
                $consumed = $comp + max(0, $f['peak'] - $v);
                if ($consumed > 0.001) {
                    $this->Book($stats, $k, 'c', $consumed, $ts);
                }
                if ($idx !== null) {
                    unset($events[$idx]['open']);
                    $events[$idx] += ['peak' => round($f['peak'], 2), 'comp' => round($comp, 2), 'after' => round($v, 2)];
                }
                $this->SendDebug('Befüllung Ende', sprintf('Silo %d: %.2f → %.2f t, + %.2f t Entnahme = %.2f t', $pos, $f['base'], $f['peak'], $comp, $amount), 0);
            }
            $s['ref'] = $v;
            $s['fill'] = null;
            $s['dir'] = 0;
            $s['lb'] = $ts;
            $s['low'] = $ts;
        } else {
            $s['fill'] = $f;
        }
        $state[$k] = $s;
        return true;
    }

    private function OpenEventIndex(array $events, int $pos): ?int
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]['s'] === $pos) {
                return !empty($events[$i]['open']) ? $i : null;
            }
        }
        return null;
    }

    private function Book(array &$stats, string $k, string $field, float $amount, int $ts): void
    {
        $info = $this->ShiftInfo($ts);
        foreach ([['S', $info['day'] . '|' . $info['shift']], ['D', $info['day']]] as [$type, $key]) {
            if (!isset($stats[$type][$key][$k])) {
                $stats[$type][$key][$k] = ['c' => 0.0, 'f' => 0.0];
            }
            $stats[$type][$key][$k][$field] = round($stats[$type][$key][$k][$field] + $amount, 3);
        }
    }

    private function InitReferences(bool $force): void
    {
        $state = $this->GetState();
        foreach ($this->GetSilos() as $silo) {
            $k = (string) $silo['Pos'];
            if ($silo['VarTons'] > 0 && IPS_VariableExists($silo['VarTons']) && ($force || !isset($state[$k]['ref']))) {
                $v = (float) GetValue($silo['VarTons']);
                $state[$k] = ['ref' => $v, 'v' => $v, 'vts' => time(), 'low' => time(), 'buf' => [$v], 'last' => time(), 'dir' => 0, 'ts' => 0, 'rate' => $state[$k]['rate'] ?? null, 'lb' => null, 'fill' => null];
            }
        }
        $this->WriteAttributeString('State', json_encode($state));
    }

    private function Prune(): void
    {
        $limit = date('Y-m-d', strtotime('-' . $this->ReadPropertyInteger('HistoryDays') . ' days'));
        $stats = $this->GetStats();
        foreach (['S', 'D'] as $type) {
            foreach (array_keys($stats[$type]) as $key) {
                if (substr((string) $key, 0, 10) < $limit) {
                    unset($stats[$type][$key]);
                }
            }
        }
        $this->WriteAttributeString('Stats', json_encode($stats));

        $minTs = strtotime($limit);
        $events = array_values(array_filter(
            json_decode($this->ReadAttributeString('Events'), true) ?: [],
            fn ($e) => $e['end'] >= $minTs
        ));
        $this->WriteAttributeString('Events', json_encode($events));
    }

    private function UpdateVariables(): void
    {
        $info = $this->ShiftInfo(time());
        $stats = $this->GetStats();
        $sKey = $info['day'] . '|' . $info['shift'];
        $tot = ['cs' => 0.0, 'cd' => 0.0, 'fd' => 0.0];

        foreach ($this->GetSilos() as $silo) {
            $k = (string) $silo['Pos'];
            $cs = (float) ($stats['S'][$sKey][$k]['c'] ?? 0);
            $cd = (float) ($stats['D'][$info['day']][$k]['c'] ?? 0);
            $fd = (float) ($stats['D'][$info['day']][$k]['f'] ?? 0);
            $this->SetIfChanged('ConsShift' . $k, $cs);
            $this->SetIfChanged('ConsDay' . $k, $cd);
            $this->SetIfChanged('FillDay' . $k, $fd);
            $tot['cs'] += $cs;
            $tot['cd'] += $cd;
            $tot['fd'] += $fd;
        }
        $this->SetIfChanged('ConsShiftTotal', $tot['cs']);
        $this->SetIfChanged('ConsDayTotal', $tot['cd']);
        $this->SetIfChanged('FillDayTotal', $tot['fd']);
    }

    private function SetIfChanged(string $ident, float $value): void
    {
        $value = round($value, 2);
        if (@$this->GetIDForIdent($ident) !== false && abs((float) $this->GetValue($ident) - $value) > 0.0001) {
            $this->SetValue($ident, $value);
        }
    }

    // ------------------------------------------------------------------
    // Dashboard-Daten
    // ------------------------------------------------------------------

    /** @param string|null $viewDay Produktionstag (Y-m-d) für die Rückschau, null = heute */
    private function BuildData(?string $viewDay = null): array
    {
        $now = time();
        $info = $this->ShiftInfo($now);
        $stats = $this->GetStats();
        $state = $this->GetState();
        $liveDay = $info['day'];
        $day = $viewDay ?? $liveDay;
        $isToday = $day === $liveDay;

        // Produktionstage: heute + 7 zurück
        $days = [];
        for ($i = 7; $i >= 0; $i--) {
            $days[] = date('Y-m-d', strtotime($day . ' 12:00 -' . $i . ' days'));
        }
        $prevDays = array_slice($days, 0, 7); // abgeschlossene 7 Tage
        $yesterday = $days[6];

        $silos = [];
        $materials = [];
        $names = [];
        foreach ($this->GetSilos() as $silo) {
            $k = (string) $silo['Pos'];
            $names[$silo['Pos']] = $silo['Name'];
            $tons = $this->ReadVar($silo['VarTons'], 0.0);
            $pct = $this->ReadVar($silo['VarPercent'], null);
            $mat = trim((string) $this->ReadVar($silo['VarMaterial'], ''));
            $cap = (float) $silo['Capacity'];
            if ($cap <= 0 && $pct !== null && (float) $pct > 2) {
                $cap = $tons / ((float) $pct / 100);
            }
            if ($pct === null) {
                $pct = $cap > 0 ? $tons / $cap * 100 : 0;
            }

            $shifts = [];
            for ($s = 1; $s <= 3; $s++) {
                $shifts[$s] = (float) ($stats['S'][$day . '|' . $s][$k]['c'] ?? 0);
            }
            $sum7 = 0.0;
            $n7 = 0;
            foreach ($prevDays as $d) {
                if (isset($stats['D'][$d][$k])) {
                    $sum7 += (float) $stats['D'][$d][$k]['c'];
                    $n7++;
                }
            }
            $avg7 = $n7 > 0 ? $sum7 / $n7 : null;
            $consDay = (float) ($stats['D'][$day][$k]['c'] ?? 0);
            $basis = $avg7; // Reichweite = Bestand / Ø-Tagesverbrauch der letzten 7 Produktionstage

            $trend = 0;
            if ($isToday && isset($state[$k]['ts']) && $now - (int) $state[$k]['ts'] < self::TREND_SECONDS) {
                $trend = (int) $state[$k]['dir'];
            }

            $silos[] = [
                'pos'       => $silo['Pos'],
                'name'      => $silo['Name'],
                'material'  => $mat,
                'tons'      => round((float) $tons, 2),
                'percent'   => round(max(0, min(100, (float) $pct)), 1),
                'capacity'  => $cap > 0 ? round($cap, 1) : null,
                'shifts'    => $shifts,
                'consShift' => $shifts[$info['shift']],
                'consDay'   => $consDay,
                'fillDay'   => (float) ($stats['D'][$day][$k]['f'] ?? 0) + $this->OpenFillAmount($silo['Pos'], $day),
                'consPrev'  => (float) ($stats['D'][$yesterday][$k]['c'] ?? 0),
                'avg7'      => $avg7 !== null ? round($avg7, 2) : null,
                'reach'     => ($basis !== null && $basis > 0.05) ? round((float) $tons / $basis, 1) : null,
                'trend'     => $trend,
            ];

            if ($mat !== '') {
                if (!isset($materials[$mat])) {
                    $materials[$mat] = ['material' => $mat, 'silos' => [], 'tons' => 0.0, 'consShift' => 0.0, 'consDay' => 0.0, 'avg7' => 0.0, 'hasAvg' => false];
                }
                $materials[$mat]['silos'][] = $silo['Name'];
                $materials[$mat]['tons'] += (float) $tons;
                $materials[$mat]['consShift'] += $shifts[$info['shift']];
                $materials[$mat]['consDay'] += $consDay;
                if ($avg7 !== null) {
                    $materials[$mat]['avg7'] += $avg7;
                    $materials[$mat]['hasAvg'] = true;
                }
            }
        }
        foreach ($materials as &$m) {
            $m['reach'] = ($m['hasAvg'] && $m['avg7'] > 0.05) ? round($m['tons'] / $m['avg7'], 1) : null;
            if (!$m['hasAvg']) {
                $m['avg7'] = null;
            }
            unset($m['hasAvg']);
        }
        unset($m);

        // Tagesverlauf (Summen)
        $history = [];
        foreach ($days as $d) {
            $c = 0.0;
            $f = 0.0;
            foreach ($stats['D'][$d] ?? [] as $v) {
                $c += (float) $v['c'];
                $f += (float) $v['f'];
            }
            $history[] = ['day' => $d, 'cons' => round($c, 2), 'fill' => round($f, 2)];
        }

        // Befüllungen der letzten 2 Produktionstage
        $from = strtotime($yesterday . ' ' . sprintf('%02d:00', $this->ReadPropertyInteger('ShiftEarly')));
        $fills = [];
        foreach (array_reverse(json_decode($this->ReadAttributeString('Events'), true) ?: []) as $e) {
            if ($e['end'] < $from) {
                break;
            }
            if ($this->ShiftInfo((int) $e['start'])['day'] > $day) {
                continue; // liegt nach dem angezeigten Tag
            }
            $fills[] = [
                'silo'    => $names[$e['s']] ?? ('Silo ' . $e['s']),
                'pos'     => $e['s'],
                'start'   => $e['start'],
                'end'     => $e['end'],
                'tons'    => round((float) $e['t'], 2),
                'running' => $isToday && !empty($e['open']),
                'base'    => $e['base'] ?? null,
                'peak'    => $e['peak'] ?? null,
                'comp'    => $e['comp'] ?? null,
                'today'   => $this->ShiftInfo((int) $e['start'])['day'] === $day,
            ];
        }

        return [
            'title'      => $this->ReadPropertyString('Title'),
            'today'      => $liveDay,
            'isToday'    => $isToday,
            'minDay'     => date('Y-m-d', strtotime($liveDay . ' 12:00 -' . $this->ReadPropertyInteger('HistoryDays') . ' days')),
            'shiftTimes' => $this->ShiftTimes(),
            'now'        => $now,
            'day'        => $day,
            'shift'      => $info,
            'shiftNames' => [1 => 'Früh', 2 => 'Spät', 3 => 'Nacht'],
            'warn'       => $this->ReadPropertyInteger('WarnLevel'),
            'critical'   => $this->ReadPropertyInteger('CriticalLevel'),
            'refresh'    => $this->ReadPropertyInteger('RefreshSeconds'),
            'siloStyle'  => $this->ReadPropertyString('SiloStyle'),
            'silos'      => $silos,
            'materials'  => array_values($materials),
            'history'    => $history,
            'fills'      => $fills,
        ];
    }

    /**
     * Detaildaten eines Silos für das Popup: Füllstandsverlauf (aus dem Symcon-Archiv),
     * Verbrauch je Tag und Schicht, Befüllungen und Prognose.
     */
    private function SiloDetail(int $pos, int $days): array
    {
        $silo = null;
        foreach ($this->GetSilos() as $s) {
            if ($s['Pos'] === $pos) {
                $silo = $s;
            }
        }
        if ($silo === null) {
            return ['error' => 'Silo nicht gefunden'];
        }
        $k = (string) $pos;
        $now = time();
        $info = $this->ShiftInfo($now);
        $stats = $this->GetStats();
        $start = $now - $days * 86400;

        // Aktueller Stand
        $tons = (float) $this->ReadVar($silo['VarTons'], 0.0);
        $pct = $this->ReadVar($silo['VarPercent'], null);
        $cap = (float) $silo['Capacity'];
        if ($cap <= 0 && $pct !== null && (float) $pct > 2) {
            $cap = $tons / ((float) $pct / 100);
        }
        if ($pct === null) {
            $pct = $cap > 0 ? $tons / $cap * 100 : 0;
        }

        // Tage (mindestens 7 für die Balken)
        $barDays = max(7, $days);
        $dayRows = [];
        for ($i = $barDays - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime($info['day'] . ' 12:00 -' . $i . ' days'));
            $row = ['day' => $d, 'c' => (float) ($stats['D'][$d][$k]['c'] ?? 0), 'f' => (float) ($stats['D'][$d][$k]['f'] ?? 0), 'has' => isset($stats['D'][$d][$k])];
            for ($sh = 1; $sh <= 3; $sh++) {
                $row['s' . $sh] = (float) ($stats['S'][$d . '|' . $sh][$k]['c'] ?? 0);
            }
            $dayRows[] = $row;
        }

        // Durchschnitt der abgeschlossenen Tage (ohne heute) im gewählten Zeitraum, mind. 7
        $done = array_values(array_filter(array_slice($dayRows, 0, -1), fn ($r) => $r['has']));
        $n = count($done);
        $avg = ['day' => null, 's1' => null, 's2' => null, 's3' => null];
        if ($n > 0) {
            foreach (['c' => 'day', 's1' => 's1', 's2' => 's2', 's3' => 's3'] as $f => $key) {
                $avg[$key] = round(array_sum(array_column($done, $f)) / $n, 2);
            }
        }

        // Befüllungen des Silos im Zeitraum
        $fills = [];
        foreach (json_decode($this->ReadAttributeString('Events'), true) ?: [] as $e) {
            if ($e['s'] === $pos && $e['end'] >= $start) {
                $fills[] = ['start' => $e['start'], 'end' => $e['end'], 'tons' => round((float) $e['t'], 2), 'running' => !empty($e['open']),
                    'base' => $e['base'] ?? null, 'peak' => $e['peak'] ?? null, 'comp' => $e['comp'] ?? null];
            }
        }

        // Füllstandsverlauf aus dem Archiv
        $series = [];
        $archived = false;
        $acIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
        $var = $silo['VarTons'];
        if (count($acIDs) > 0 && $var > 0 && IPS_VariableExists($var) && @AC_GetLoggingStatus($acIDs[0], $var)) {
            $archived = true;
            $ac = $acIDs[0];
            if ($days <= 1) {
                $before = @AC_GetLoggedValues($ac, $var, 0, $start - 1, 1) ?: [];
                if (count($before) > 0) {
                    $series[] = [$start, round((float) $before[0]['Value'], 2)];
                }
                foreach (array_reverse(@AC_GetLoggedValues($ac, $var, $start, $now, 5000) ?: []) as $v) {
                    $series[] = [(int) $v['TimeStamp'], round((float) $v['Value'], 2)];
                }
                $series[] = [$now, round($tons, 2)];
                $series = $this->Downsample($series, 1500);
            } else {
                // Stundenwerte: Mittel + Min/Max
                foreach (array_reverse(@AC_GetAggregatedValues($ac, $var, 0, $start, $now, 0) ?: []) as $v) {
                    $series[] = [(int) $v['TimeStamp'] + 1800, round((float) $v['Avg'], 2), round((float) $v['Min'], 2), round((float) $v['Max'], 2)];
                }
                $series[] = [$now, round($tons, 2), round($tons, 2), round($tons, 2)];
            }
        }

        // Prognose auf Basis des Ø-Tagesverbrauchs
        $warnT = $cap > 0 ? $cap * $this->ReadPropertyInteger('WarnLevel') / 100 : null;
        $rate = ($avg['day'] ?? 0) > 0.05 ? $avg['day'] / 86400 : null; // t pro Sekunde
        $forecast = [
            'empty' => $rate ? $now + (int) ($tons / $rate) : null,
            'warn'  => ($rate && $warnT !== null && $tons > $warnT) ? $now + (int) (($tons - $warnT) / $rate) : null,
        ];

        return [
            'pos'        => $pos,
            'name'       => $silo['Name'],
            'material'   => trim((string) $this->ReadVar($silo['VarMaterial'], '')),
            'tons'       => round($tons, 2),
            'percent'    => round(max(0, min(100, (float) $pct)), 1),
            'capacity'   => $cap > 0 ? round($cap, 1) : null,
            'warnT'      => $warnT !== null ? round($warnT, 1) : null,
            'critT'      => $cap > 0 ? round($cap * $this->ReadPropertyInteger('CriticalLevel') / 100, 1) : null,
            'range'      => $days,
            'start'      => $start,
            'now'        => $now,
            'archived'   => $archived,
            'varTons'    => $var,
            'series'     => $series,
            'days'       => $dayRows,
            'avg'        => $avg,
            'avgDays'    => $n,
            'fills'      => array_reverse($fills),
            'forecast'   => $forecast,
            'shiftNames' => [1 => 'Früh', 2 => 'Spät', 3 => 'Nacht'],
            'shiftTimes' => $this->ShiftTimes(),
        ];
    }

    /** Reduziert eine Zeitreihe auf max. $max Punkte (Min/Max je Eimer bleiben erhalten) */
    private function Downsample(array $points, int $max): array
    {
        $n = count($points);
        if ($n <= $max) {
            return $points;
        }
        $bucket = (int) ceil($n / ($max / 2));
        $out = [$points[0]];
        for ($i = 1; $i < $n - 1; $i += $bucket) {
            $slice = array_slice($points, $i, $bucket);
            usort($slice, fn ($a, $b) => $a[1] <=> $b[1]);
            $pair = [$slice[0], $slice[count($slice) - 1]];
            usort($pair, fn ($a, $b) => $a[0] <=> $b[0]);
            $out[] = $pair[0];
            if ($pair[1] !== $pair[0]) {
                $out[] = $pair[1];
            }
        }
        $out[] = $points[$n - 1];
        return $out;
    }

    /** Dashboard-Daten einmal je Timer-Durchlauf berechnen; der WebHook liefert nur noch aus. */
    private function RefreshCache(): void
    {
        $this->SetBuffer('Data', json_encode($this->BuildData(), JSON_HEX_TAG | JSON_HEX_AMP));
    }

    private function CachedJson(): string
    {
        $json = $this->GetBuffer('Data');
        if ($json === '') {
            $this->RefreshCache();
            $json = $this->GetBuffer('Data');
        }
        return $json;
    }

    private function RenderPage(): string
    {
        // Schrift nur einbinden, wenn die Dateien im Ordner fonts/ liegen (sonst kein Warten auf 404)
        $fontFace = '';
        foreach ([400 => 'Poppins-Regular', 500 => 'Poppins-Medium', 700 => 'Poppins-Bold'] as $weight => $file) {
            $src = 'local("' . str_replace('-', ' ', $file) . '"), local("' . $file . '")';
            if (is_file(__DIR__ . '/fonts/' . $file . '.ttf')) {
                $src .= ', url("?font=' . $file . '") format("truetype")';
            }
            $fontFace .= '@font-face { font-family: "SiloPoppins"; font-weight: ' . $weight . '; font-display: swap; src: ' . $src . '; }' . "\n";
        }
        $html = file_get_contents(__DIR__ . '/dashboard.html');
        return str_replace(
            ['{{FONTFACE}}', '{{TITLE}}', '{{INITIAL}}'],
            [$fontFace, htmlspecialchars($this->ReadPropertyString('Title')), $this->CachedJson()],
            $html
        );
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /** Menge einer noch laufenden Befüllung (für die Anzeige, gebucht wird erst am Ende) */
    private function OpenFillAmount(int $pos, string $day): float
    {
        foreach (array_reverse(json_decode($this->ReadAttributeString('Events'), true) ?: []) as $e) {
            if ($e['s'] === $pos) {
                return (!empty($e['open']) && $this->ShiftInfo((int) $e['start'])['day'] === $day) ? (float) $e['t'] : 0.0;
            }
        }
        return 0.0;
    }

    private function ShiftTimes(): array
    {
        $e = $this->ReadPropertyInteger('ShiftEarly');
        $l = $this->ReadPropertyInteger('ShiftLate');
        $n = $this->ReadPropertyInteger('ShiftNight');
        return [
            1 => ['from' => sprintf('%02d:00', $e), 'to' => sprintf('%02d:00', $l)],
            2 => ['from' => sprintf('%02d:00', $l), 'to' => sprintf('%02d:00', $n)],
            3 => ['from' => sprintf('%02d:00', $n), 'to' => sprintf('%02d:00', $e)],
        ];
    }

    /** Schicht und Produktionstag zu einem Zeitpunkt */
    private function ShiftInfo(int $ts): array
    {
        $e = $this->ReadPropertyInteger('ShiftEarly');
        $l = $this->ReadPropertyInteger('ShiftLate');
        $n = $this->ReadPropertyInteger('ShiftNight');

        $mod = (int) date('G', $ts) * 60 + (int) date('i', $ts);
        $off = ($mod - $e * 60 + 1440) % 1440;
        $lateOff = (($l - $e + 24) % 24) * 60;
        $nightOff = (($n - $e + 24) % 24) * 60;
        $shift = $off < $lateOff ? 1 : ($off < $nightOff ? 2 : 3);

        $dayTs = $mod < $e * 60
            ? mktime(12, 0, 0, (int) date('n', $ts), (int) date('j', $ts) - 1, (int) date('Y', $ts))
            : $ts;

        $starts = [1 => $e, 2 => $l, 3 => $n];
        $ends = [1 => $l, 2 => $n, 3 => $e];
        $startTs = mktime($starts[$shift], 0, 0, (int) date('n', $dayTs), (int) date('j', $dayTs) + ($shift === 3 && $n < $e ? 1 : 0), (int) date('Y', $dayTs));
        $durH = (($ends[$shift] - $starts[$shift] + 24) % 24) ?: 24;

        return [
            'day'     => date('Y-m-d', $dayTs),
            'shift'   => $shift,
            'from'    => sprintf('%02d:00', $starts[$shift]),
            'to'      => sprintf('%02d:00', $ends[$shift]),
            'startTs' => $startTs,
            'endTs'   => $startTs + $durH * 3600,
        ];
    }

    private function GetSilos(): array
    {
        $list = json_decode($this->ReadPropertyString('Silos'), true) ?: [];
        $result = [];
        foreach ($list as $row) {
            if (empty($row['Active'])) {
                continue;
            }
            $pos = (int) ($row['Pos'] ?? 0);
            if ($pos < 1 || $pos > self::MAX_SILOS) {
                continue;
            }
            $result[$pos] = [
                'Pos'         => $pos,
                'Name'        => (string) ($row['Name'] ?? ('Silo ' . $pos)),
                'VarPercent'  => (int) ($row['VarPercent'] ?? 0),
                'VarTons'     => (int) ($row['VarTons'] ?? 0),
                'VarMaterial' => (int) ($row['VarMaterial'] ?? 0),
                'Capacity'    => (float) ($row['Capacity'] ?? 0),
            ];
        }
        ksort($result);
        return array_values($result);
    }

    private function ReadVar(int $id, $default)
    {
        if ($id > 0 && IPS_VariableExists($id)) {
            return GetValue($id);
        }
        return $default;
    }

    private function GetStats(): array
    {
        $s = json_decode($this->ReadAttributeString('Stats'), true);
        if (!is_array($s)) {
            $s = [];
        }
        $s['S'] = $s['S'] ?? [];
        $s['D'] = $s['D'] ?? [];
        return $s;
    }

    private function GetState(): array
    {
        return json_decode($this->ReadAttributeString('State'), true) ?: [];
    }

    private function GetHookName(): string
    {
        $name = preg_replace('/[^a-z0-9_\-]/i', '', $this->ReadPropertyString('HookName'));
        return $name !== '' ? $name : 'silomonitor';
    }

    private function RegisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_MODULE);
        if (count($ids) === 0) {
            $this->SendDebug('WebHook', 'Keine WebHook-Control-Instanz gefunden', 0);
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        $found = false;
        foreach ($hooks as $i => $h) {
            if ($h['Hook'] === $hook) {
                if ($h['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                $found = true;
            }
        }
        if (!$found) {
            $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        }
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /** Vorbelegung mit den Variablen aus dem bestehenden Objektbaum */
    private static function DefaultSilos(): array
    {
        $ids = [
            1 => [10572, 41473, 42604],
            2 => [15285, 54459, 22185],
            3 => [27447, 13798, 11162],
            4 => [58512, 49400, 56712],
            5 => [36961, 32693, 33378],
            6 => [53841, 54662, 47412],
            7 => [44630, 45546, 31911],
            8 => [45900, 17690, 52949],
        ];
        $rows = [];
        foreach ($ids as $pos => [$p, $t, $m]) {
            $rows[] = ['Pos' => $pos, 'Active' => true, 'Name' => 'Silo ' . $pos, 'VarPercent' => $p, 'VarTons' => $t, 'VarMaterial' => $m, 'Capacity' => 0];
        }
        return $rows;
    }
}
