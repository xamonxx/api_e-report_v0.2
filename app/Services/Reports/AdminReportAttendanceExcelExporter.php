<?php

namespace App\Services\Reports;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminReportAttendanceExcelExporter
{
    private const STATUS_STYLES = [
        'ada_wa' => 'statusAdaWa',
        'nol_wa' => 'statusNolWa',
        'libur_susulan' => 'statusLibur',
        'belum_laporan' => 'statusTidakLaporan',
    ];

    public function buildWorkbook(Carbon $date, ?string $accountGroup = null): string
    {
        $month = $date->copy()->startOfMonth();
        $daysInMonth = $month->daysInMonth;
        $selectedGroup = $this->normalizeAccountGroup($accountGroup);
        $admins = $this->buildRows($month, $selectedGroup);
        $columnCount = $daysInMonth + 3;

        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
            sprintf('<Worksheet ss:Name="%s">', $this->escapeSheetName('Rekap Admin ' . $this->monthName($month) . ' ' . $month->year)),
            '<Table x:FullColumns="1" x:FullRows="1">',
            $this->columnsXml($daysInMonth),
            $this->titleRowsXml($month, $date, $admins, $columnCount),
            $this->headerRowsXml($month, $daysInMonth),
            $this->bodyRowsXml($admins, $month, $daysInMonth, $selectedGroup),
            $this->totalRowXml($admins, $month, $daysInMonth),
            $this->legendRowsXml($columnCount),
            '</Table>',
            '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>5</SplitHorizontal><TopRowBottomPane>5</TopRowBottomPane>'
            . '<ActivePane>2</ActivePane><ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios>'
            . '</WorksheetOptions>',
            '</Worksheet>',
            '</Workbook>',
        ]);
    }

    private function buildRows(Carbon $month, ?string $accountGroup = null): Collection
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $rows = User::query()
            ->where('role', UserRole::Admin)
            ->with([
                'account:id,name,description',
                'reportAttendances' => fn ($query) => $query
                    ->whereBetween('report_date', [$start, $end])
                    ->orderBy('report_date'),
            ])
            ->orderBy('account_id')
            ->orderBy('name')
            ->get()
            ->map(function (User $admin) {
                $attendances = $admin->reportAttendances
                    ->keyBy(fn ($attendance) => $attendance->report_date->format('Y-m-d'));

                return [
                    'admin' => $admin,
                    'account_name' => $admin->account?->name ?? $admin->name,
                    'account_group' => $this->accountGroupLabel($admin->account?->description),
                    'attendances' => $attendances,
                ];
            })
            ->sortBy([
                fn (array $left, array $right) => $this->accountGroupSort($left['account_group']) <=> $this->accountGroupSort($right['account_group']),
                fn (array $left, array $right) => strcmp($left['account_name'], $right['account_name']),
            ])
            ->when($accountGroup, fn (Collection $rows) => $rows->where('account_group', $accountGroup))
            ->values();

        $consultationCounts = $this->monthlyConsultationCounts(
            $rows->pluck('admin.account_id')->filter()->unique()->values(),
            $start,
            $end
        );

        return $rows
            ->map(function (array $row) use ($consultationCounts) {
                $accountId = (int) ($row['admin']->account_id ?? 0);
                $row['consultation_counts'] = $consultationCounts->get($accountId, collect());

                return $row;
            })
            ->values();
    }

    private function monthlyConsultationCounts(Collection $accountIds, string $start, string $end): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DB::table('consultations')
            ->select([
                'account_id',
                DB::raw('DATE(consultation_date) as consultation_day'),
                DB::raw('COUNT(*) as total'),
            ])
            ->whereIn('account_id', $accountIds->all())
            ->whereDate('consultation_date', '>=', $start)
            ->whereDate('consultation_date', '<=', $end)
            ->groupBy('account_id', DB::raw('DATE(consultation_date)'))
            ->get()
            ->groupBy(fn ($row) => (int) $row->account_id)
            ->map(fn (Collection $rows) => $rows->mapWithKeys(
                fn ($row) => [(string) $row->consultation_day => (int) $row->total]
            ));
    }

    private function columnsXml(int $daysInMonth): string
    {
        $columns = [
            '<Column ss:AutoFitWidth="0" ss:Width="36"/>',
            '<Column ss:AutoFitWidth="0" ss:Width="264"/>',
        ];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $columns[] = '<Column ss:AutoFitWidth="0" ss:Width="24"/>';
        }

        $columns[] = '<Column ss:AutoFitWidth="0" ss:Width="95"/>';

        return implode('', $columns);
    }

    private function titleRowsXml(Carbon $month, Carbon $date, Collection $admins, int $columnCount): string
    {
        $subtitle = sprintf(
            '%s - %s - %s',
            $this->accountTitle($admins),
            strtoupper($this->monthName($month)),
            $month->year
        );

        return $this->row([
            $this->cell('REKAP LAPORAN KONSUL HARIAN & MASUK WA BARU', 'reportTitle', mergeAcross: $columnCount - 1),
        ], 36)
            . $this->row([
                $this->cell($subtitle, 'reportSubtitle', mergeAcross: $columnCount - 1),
            ], 31)
            . $this->row([
                $this->cell('#', 'dateMarker'),
                $this->cell($this->fullDate($date), 'dateText', mergeAcross: $columnCount - 2),
            ], 22);
    }

    private function headerRowsXml(Carbon $month, int $daysInMonth): string
    {
        $cells = [
            $this->cell('R', 'peachHeader', mergeDown: 1),
            $this->cell('AKUN', 'peachHeader', mergeDown: 1),
            $this->cell(strtoupper($this->monthName($month)), 'peachHeader', mergeAcross: $daysInMonth - 1),
            $this->cell('TOTAL', 'peachHeader', mergeDown: 1),
        ];

        $dayCells = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dayCells[] = $this->cell(str_pad((string) $day, 2, '0', STR_PAD_LEFT), 'dayHeader', index: $day + 2);
        }

        return $this->row($cells, 27) . $this->row($dayCells, 23);
    }

    private function bodyRowsXml(Collection $admins, Carbon $month, int $daysInMonth, ?string $selectedGroup = null): string
    {
        $xml = '';
        $sequence = 1;

        foreach ($admins->groupBy('account_group') as $group => $groupRows) {
            if ($selectedGroup === null) {
                $xml .= $this->row([
                    $this->cell($group, 'groupSeparator', mergeAcross: $daysInMonth + 2),
                ], 20);
            }

            foreach ($groupRows->values() as $row) {
                $cells = [
                    $this->cell($sequence++, 'bodyCenter', 'Number'),
                    $this->cell(strtoupper($row['account_name']), 'bodyAccount'),
                ];

                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $dateKey = $month->copy()->day($day)->format('Y-m-d');
                    $attendance = $row['attendances']->get($dateKey);
                    $category = $attendance?->report_category ?? 'belum_laporan';
                    $cells[] = $this->cell(
                        $this->consultationCountForDate($row, $dateKey),
                        self::STATUS_STYLES[$category] ?? self::STATUS_STYLES['belum_laporan'],
                        'Number'
                    );
                }

                $cells[] = $this->cell($this->rowAdaWaTotal($row, $month, $daysInMonth), 'bodyTotal', 'Number');
                $xml .= $this->row($cells, 19);
            }
        }

        return $xml . $this->row([
            $this->cell('', 'bodyCenter'),
            $this->cell('', 'bodyAccount', mergeAcross: $daysInMonth + 1),
        ], 20);
    }

    private function totalRowXml(Collection $admins, Carbon $month, int $daysInMonth): string
    {
        $cells = [
            $this->cell('TOTAL', 'totalLabel', mergeAcross: 1),
        ];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $cells[] = $this->cell($this->dayAdaWaTotal($admins, $month, $day), 'totalDay', 'Number');
        }

        $cells[] = $this->cell($this->grandAdaWaTotal($admins, $month, $daysInMonth), 'totalGrand', 'Number');

        return $this->row($cells, 38);
    }

    private function legendRowsXml(int $columnCount): string
    {
        return $this->row([$this->cell('', 'blank', mergeAcross: $columnCount - 1)], 18)
            . $this->legendRow('statusAdaWa', 'Laporan - ada WA Konsumen baru')
            . $this->legendRow('statusNolWa', 'Laporan - 0 data WA Konsumen baru')
            . $this->legendRow('statusLibur', 'Rekapan laporan susulan / Hari Libur')
            . $this->legendRow('statusTidakLaporan', 'Tidak laporan');
    }

    private function legendRow(string $swatchStyle, string $label): string
    {
        return $this->row([
            $this->cell('', $swatchStyle),
            $this->cell($label, 'legendText', mergeAcross: 6),
        ], 18);
    }

    private function rowAdaWaTotal(array $row, Carbon $month, int $daysInMonth): int
    {
        $total = 0;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateKey = $month->copy()->day($day)->format('Y-m-d');
            $total += $this->consultationCountForDate($row, $dateKey);
        }

        return $total;
    }

    private function dayAdaWaTotal(Collection $admins, Carbon $month, int $day): int
    {
        $dateKey = $month->copy()->day($day)->format('Y-m-d');

        return $admins->sum(fn (array $row) => $this->consultationCountForDate($row, $dateKey));
    }

    private function grandAdaWaTotal(Collection $admins, Carbon $month, int $daysInMonth): int
    {
        return $admins->sum(fn (array $row) => $this->rowAdaWaTotal($row, $month, $daysInMonth));
    }

    private function consultationCountForDate(array $row, string $dateKey): int
    {
        return (int) ($row['consultation_counts']->get($dateKey, 0) ?? 0);
    }

    private function accountTitle(Collection $admins): string
    {
        $accounts = $admins
            ->pluck('account_name')
            ->filter()
            ->unique()
            ->values();

        if ($accounts->isEmpty()) {
            return 'SEMUA AKUN';
        }

        return $accounts
            ->take(3)
            ->map(fn (string $name) => strtoupper($name))
            ->implode(' X ');
    }

    private function row(array $cells, ?int $height = null): string
    {
        $heightAttribute = $height !== null ? sprintf(' ss:Height="%s"', (float) $height) : '';

        return sprintf('<Row%s>%s</Row>', $heightAttribute, implode('', $cells));
    }

    private function cell(
        mixed $value,
        string $style,
        string $type = 'String',
        ?int $mergeAcross = null,
        ?int $mergeDown = null,
        ?int $index = null
    ): string {
        $attributes = [sprintf('ss:StyleID="%s"', $style)];

        if ($index !== null) {
            $attributes[] = sprintf('ss:Index="%d"', $index);
        }

        if ($mergeAcross !== null) {
            $attributes[] = sprintf('ss:MergeAcross="%d"', $mergeAcross);
        }

        if ($mergeDown !== null) {
            $attributes[] = sprintf('ss:MergeDown="%d"', $mergeDown);
        }

        return sprintf(
            '<Cell %s><Data ss:Type="%s">%s</Data></Cell>',
            implode(' ', $attributes),
            $type,
            htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        );
    }

    private function stylesXml(): string
    {
        return '<Styles>'
            . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#000000"/></Style>'
            . $this->style('reportTitle', '#F8CBAD', true, 16, 'Center', borderWeight: 2)
            . $this->style('reportSubtitle', '#F8CBAD', true, 14, 'Center', borderWeight: 2)
            . $this->style('dateMarker', '#FFFFFF', true, 11, 'Center', border: false)
            . $this->style('dateText', '#FFFFFF', true, 11, 'Left', border: false)
            . $this->style('peachHeader', '#F8CBAD', true, 11, 'Center', borderWeight: 2)
            . $this->style('dayHeader', '#F8CBAD', true, 11, 'Center', borderWeight: 1)
            . $this->style('groupSeparator', '#D9EAD3', true, 12, 'Left', borderWeight: 2)
            . $this->style('bodyCenter', '#FFFFFF', false, 11, 'Center')
            . $this->style('bodyAccount', '#FFFFFF', false, 11, 'Left')
            . $this->style('bodyTotal', '#FFFFFF', true, 11, 'Center')
            . $this->style('totalLabel', '#F8CBAD', true, 11, 'Center', borderWeight: 2)
            . $this->style('totalDay', '#F8CBAD', true, 11, 'Center')
            . $this->style('totalGrand', '#F8CBAD', true, 11, 'Center', borderWeight: 2)
            . $this->style('statusAdaWa', '#92D050', false, 11, 'Center')
            . $this->style('statusNolWa', '#FFFF00', false, 11, 'Center')
            . $this->style('statusLibur', '#00B0F0', false, 11, 'Center')
            . $this->style('statusTidakLaporan', '#FF0000', false, 11, 'Center')
            . $this->style('legendText', '#FFFFFF', false, 11, 'Left', border: false)
            . '<Style ss:ID="blank"><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '</Styles>';
    }

    private function style(
        string $id,
        string $background,
        bool $bold,
        int $size,
        string $horizontal,
        int $borderWeight = 1,
        bool $border = true
    ): string {
        $borders = $border
            ? sprintf(
                '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/></Borders>',
                $borderWeight,
                $borderWeight,
                $borderWeight,
                $borderWeight
            )
            : '';

        return sprintf(
            '<Style ss:ID="%s"><Alignment ss:Horizontal="%s" ss:Vertical="Center"/>%s<Font ss:FontName="Calibri" ss:Size="%d" ss:Color="#000000"%s/><Interior ss:Color="%s" ss:Pattern="Solid"/></Style>',
            $id,
            $horizontal,
            $borders,
            $size,
            $bold ? ' ss:Bold="1"' : '',
            $background
        );
    }

    private function fullDate(Carbon $date): string
    {
        return sprintf(
            '%s, %s %s %s',
            $this->dayName($date),
            $date->format('d'),
            $this->monthName($date),
            $date->format('Y')
        );
    }

    private function dayName(Carbon $date): string
    {
        return [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ][$date->format('l')] ?? $date->format('l');
    }

    private function monthName(Carbon $date): string
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ][(int) $date->format('n')] ?? $date->format('F');
    }

    private function accountGroupLabel(?string $description): string
    {
        $normalized = str((string) $description)->upper()->squish()->toString();

        if (str_contains($normalized, 'PUTRA') || $normalized === 'PC') {
            return 'PC';
        }

        if (str_contains($normalized, 'NPP')) {
            return 'NPP';
        }

        return 'NPP';
    }

    private function accountGroupSort(string $group): int
    {
        return $group === 'PC' ? 0 : 1;
    }

    private function normalizeAccountGroup(?string $group): ?string
    {
        $normalized = str((string) $group)->upper()->squish()->toString();

        return in_array($normalized, ['PC', 'NPP'], true) ? $normalized : null;
    }

    private function escapeSheetName(string $name): string
    {
        $normalized = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name), 0, 31);

        return htmlspecialchars($normalized, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
