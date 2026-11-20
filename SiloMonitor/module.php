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
        $this->RegisterPropertyFloat('MaxJump', 10.0);
        $this->RegisterPropertyInteger('FillMerge', 30);
        $this->RegisterPropertyInteger('HistoryDays', 35);

        $this->RegisterPropertyInteger('WarnLevel', 30);
        $this->RegisterPropertyInteger('CriticalLevel', 15);
        $this->RegisterPropertyInteger('RefreshSeconds', 10);

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
                $dirty = $this->ProcessLevel($silo['Pos'], (float) GetValue($silo['VarTons']), $now, $state, $stats, $events) || $dirty;
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
        $text = implode("\n", $out);
        echo $text;
        return $text;
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

    private function ProcessLevel(int $pos, float $value, int $ts, array &$state, array &$stats, array &$events): bool
    {
        $k = (string) $pos;

        if (!isset($state[$k]['ref'])) {
            $state[$k] = ['ref' => $value, 'dir' => 0, 'ts' => 0];
            return true;
        }

        $delta = $value - (float) $state[$k]['ref'];
        if (abs($delta) < $this->ReadPropertyFloat('Deadband')) {
            return false; // Messrauschen
        }

        $maxJump = $this->ReadPropertyFloat('MaxJump');
        if ($maxJump > 0 && abs($delta) > $maxJump) {
            $this->SendDebug('Sprung ignoriert', sprintf('Silo %d: %.2f t -> %.2f t', $pos, $state[$k]['ref'], $value), 0);
            $state[$k]['ref'] = $value;
            return true;
        }

        $info = $this->ShiftInfo($ts);
        $sKey = $info['day'] . '|' . $info['shift'];
        $field = $delta < 0 ? 'c' : 'f';
        $amount = abs($delta);

        foreach ([['S', $sKey], ['D', $info['day']]] as [$type, $key]) {
            if (!isset($stats[$type][$key][$k])) {
                $stats[$type][$key][$k] = ['c' => 0.0, 'f' => 0.0];
            }
            $stats[$type][$key][$k][$field] = round($stats[$type][$key][$k][$field] + $amount, 3);
        }

        if ($delta > 0) {
            $this->AddFillEvent($pos, $amount, $ts, $events);
        }

        $state[$k] = ['ref' => $value, 'dir' => $delta < 0 ? -1 : 1, 'ts' => $ts];
        $this->SendDebug($delta < 0 ? 'Entnahme' : 'Befüllung', sprintf('Silo %d: %.2f t', $pos, $amount), 0);
        return true;
    }

    private function AddFillEvent(int $pos, float $amount, int $ts, array &$events): void
    {
        $merge = $this->ReadPropertyInteger('FillMerge') * 60;

        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]['s'] === $pos) {
                if ($ts - $events[$i]['end'] <= $merge) {
                    $events[$i]['t'] = round($events[$i]['t'] + $amount, 3);
                    $events[$i]['end'] = $ts;
                    return;
                }
                break;
            }
        }
        $events[] = ['s' => $pos, 'start' => $ts, 'end' => $ts, 't' => round($amount, 3)];
        if (count($events) > 500) {
            $events = array_slice($events, -500);
        }
    }

    private function InitReferences(bool $force): void
    {
        $state = $this->GetState();
        foreach ($this->GetSilos() as $silo) {
            $k = (string) $silo['Pos'];
            if ($silo['VarTons'] > 0 && IPS_VariableExists($silo['VarTons']) && ($force || !isset($state[$k]['ref']))) {
                $state[$k] = ['ref' => (float) GetValue($silo['VarTons']), 'dir' => 0, 'ts' => 0];
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
                'fillDay'   => (float) ($stats['D'][$day][$k]['f'] ?? 0),
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
                'running' => $isToday && ($now - $e['end']) < self::TREND_SECONDS,
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
                $fills[] = ['start' => $e['start'], 'end' => $e['end'], 'tons' => round((float) $e['t'], 2), 'running' => ($now - $e['end']) < self::TREND_SECONDS];
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
