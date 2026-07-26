<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Support\AccountGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminReportAttendanceExcelExporter
{
    /**
     * Nama style warna sel. Sengaja diberi nama menurut warnanya, bukan
     * menurut kategori absensi seperti dulu — warna sekarang diturunkan dari
     * data konsul, bukan dari klaim kategori admin.
     */
    private const CELL_GREEN = 'statusAdaWa';
    private const CELL_YELLOW = 'statusNolWa';
    private const CELL_BLUE = 'statusLibur';
    private const CELL_RED = 'statusTidakLaporan';

    /** Kategori absensi yang berarti admin bekerja hari itu. */
    private const WORKING_CATEGORIES = ['ada_wa', 'nol_wa'];

    private const CATEGORY_DAY_OFF = 'libur_susulan';

    /** Batas kolom tanggal dalam satu lembar. */
    public const MAX_RANGE_DAYS = 92;

    /**
     * Grid rekap absensi: satu baris per admin, satu kolom per tanggal.
     *
     * `$end` opsional — kalau kosong, rentangnya satu bulan penuh milik
     * `$start` (perilaku lama sebelum filter rentang kustom ada).
     */
    public function buildWorkbook(Carbon $start, ?string $accountGroup = null, ?Carbon $end = null): string
    {
        [$rangeStart, $rangeEnd] = $this->resolveRange($start, $end);
        $dates = $this->dateKeys($rangeStart, $rangeEnd);
        $dayCount = count($dates);
        $selectedGroup = $this->normalizeAccountGroup($accountGroup);
        $admins = $this->buildRows($rangeStart, $rangeEnd, $selectedGroup);
        $columnCount = $dayCount + 3;

        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
            sprintf('<Worksheet ss:Name="%s">', $this->escapeSheetName('Rekap Admin ' . $this->rangeSheetName($rangeStart, $rangeEnd))),
            '<Table x:FullColumns="1" x:FullRows="1">',
            $this->columnsXml($dayCount),
            $this->titleRowsXml($rangeStart, $rangeEnd, $selectedGroup, $columnCount),
            $this->headerRowsXml($rangeStart, $rangeEnd, $dates),
            $this->bodyRowsXml($admins, $dates, $selectedGroup),
            $this->totalRowXml($admins, $dates),
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

    /**
     * Kalau tanggal akhir tidak diberikan, pakai satu bulan penuh dari
     * tanggal awal supaya export lama tetap menghasilkan grid yang sama.
     * Rentang dibatasi 92 hari — lebih dari itu jumlah kolomnya tidak lagi
     * terbaca sebagai satu lembar.
     */
    private function resolveRange(Carbon $start, ?Carbon $end): array
    {
        if ($end === null) {
            return [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()];
        }

        $rangeStart = $start->copy()->startOfDay();
        $rangeEnd = $end->copy()->startOfDay();

        if ($rangeEnd->lt($rangeStart)) {
            [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
        }

        $maxEnd = $rangeStart->copy()->addDays(self::MAX_RANGE_DAYS - 1);

        return [$rangeStart, $rangeEnd->gt($maxEnd) ? $maxEnd : $rangeEnd];
    }

    /**
     * @return list<string> daftar tanggal 'Y-m-d' inklusif kedua ujungnya
     */
    private function dateKeys(Carbon $start, Carbon $end): array
    {
        $keys = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $keys;
    }

    private function rangeSheetName(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end)) {
            return $this->monthName($start) . ' ' . $start->year;
        }

        return $start->format('d-m-Y') . ' sd ' . $end->format('d-m-Y');
    }

    /**
     * Satu baris = satu akun.
     *
     * Akun tanpa admin tetap ikut supaya daftar akun di lembar ini utuh —
     * barisnya wajar terbaca merah karena memang tak ada aktivitas.
     */
    private function buildRows(Carbon $rangeStart, Carbon $rangeEnd, ?string $accountGroup = null): Collection
    {
        $start = $rangeStart->toDateString();
        $end = $rangeEnd->toDateString();

        $accounts = Account::query()
            ->when($accountGroup, fn ($query) => $query->where('account_group', $accountGroup))
            ->get(['id', 'name', 'description', 'account_group'])
            ->map(fn (Account $account) => [
                'account_id' => (int) $account->id,
                'account_name' => $account->name,
                'account_group' => AccountGroup::normalize($account->account_group) ?? AccountGroup::PC,
            ])
            ->sortBy([
                fn (array $left, array $right) => $this->accountGroupSort($left['account_group']) <=> $this->accountGroupSort($right['account_group']),
                fn (array $left, array $right) => strcmp($left['account_name'], $right['account_name']),
            ])
            ->values();

        $accountIds = $accounts->pluck('account_id')->filter()->unique()->values();
        $consultationCounts = $this->consultationCountsByDate($accountIds, $start, $end);
        $attendanceCategories = $this->attendanceCategoriesByDate($accountIds, $start, $end);

        return $accounts
            ->map(function (array $row) use ($consultationCounts, $attendanceCategories) {
                $row['consultation_counts'] = $consultationCounts->get($row['account_id'], collect());
                $row['attendance_categories'] = $attendanceCategories->get($row['account_id'], collect());

                return $row;
            })
            ->values();
    }

    /**
     * Konsul per akun per tanggal, dipecah jadi laporan tepat waktu dan
     * susulan. Pembandingnya `DATE(created_at)` vs `consultation_date`:
     * dibuat di hari yang sama = tepat waktu, dibuat belakangan = susulan.
     *
     * @return Collection<int, Collection<string, array{normal:int, susulan:int}>>
     */
    private function consultationCountsByDate(Collection $accountIds, string $start, string $end): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DB::table('consultations')
            ->select([
                'account_id',
                DB::raw('DATE(consultation_date) as consultation_day'),
                DB::raw('SUM(CASE WHEN DATE(created_at) = DATE(consultation_date) THEN 1 ELSE 0 END) as normal_total'),
                DB::raw('SUM(CASE WHEN DATE(created_at) > DATE(consultation_date) THEN 1 ELSE 0 END) as susulan_total'),
            ])
            ->whereNull('deleted_at')
            ->whereIn('account_id', $accountIds->all())
            ->whereDate('consultation_date', '>=', $start)
            ->whereDate('consultation_date', '<=', $end)
            ->groupBy('account_id', DB::raw('DATE(consultation_date)'))
            ->get()
            ->groupBy(fn ($row) => (int) $row->account_id)
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [
                (string) $row->consultation_day => [
                    'normal' => (int) $row->normal_total,
                    'susulan' => (int) $row->susulan_total,
                ],
            ]));
    }

    /**
     * Kategori absensi per akun per tanggal. Satu akun bisa punya lebih dari
     * satu admin, jadi nilainya daftar — bukan satu kategori.
     *
     * @return Collection<int, Collection<string, list<string>>>
     */
    private function attendanceCategoriesByDate(Collection $accountIds, string $start, string $end): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DB::table('report_attendances')
            ->select(['account_id', 'report_date', 'report_category'])
            ->whereIn('account_id', $accountIds->all())
            ->whereDate('report_date', '>=', $start)
            ->whereDate('report_date', '<=', $end)
            ->get()
            ->groupBy(fn ($row) => (int) $row->account_id)
            ->map(fn (Collection $rows) => $rows
                ->groupBy(fn ($row) => Carbon::parse($row->report_date)->format('Y-m-d'))
                ->map(fn (Collection $dayRows) => $dayRows
                    ->pluck('report_category')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()));
    }

    private function columnsXml(int $dayCount): string
    {
        $columns = [
            '<Column ss:AutoFitWidth="0" ss:Width="36"/>',
            '<Column ss:AutoFitWidth="0" ss:Width="264"/>',
        ];

        for ($day = 1; $day <= $dayCount; $day++) {
            $columns[] = '<Column ss:AutoFitWidth="0" ss:Width="24"/>';
        }

        $columns[] = '<Column ss:AutoFitWidth="0" ss:Width="95"/>';

        return implode('', $columns);
    }

    private function titleRowsXml(Carbon $rangeStart, Carbon $rangeEnd, ?string $accountGroup, int $columnCount): string
    {
        // Nama payung grup, bukan daftar nama akun: satu lembar selalu mewakili
        // satu grup, dan daftar akunnya sudah tercetak sebagai baris.
        $subtitle = sprintf(
            '%s - %s',
            AccountGroup::subtitleLabel($accountGroup),
            $this->rangeLabel($rangeStart, $rangeEnd)
        );

        return $this->row([
            $this->cell('REKAP LAPORAN KONSUL HARIAN & MASUK WA BARU', 'reportTitle', mergeAcross: $columnCount - 1),
        ], 36)
            . $this->row([
                $this->cell($subtitle, 'reportSubtitle', mergeAcross: $columnCount - 1),
            ], 31)
            . $this->row([
                $this->cell('#', 'dateMarker'),
                $this->cell(
                    $this->fullDate($rangeStart) . ' sampai ' . $this->fullDate($rangeEnd),
                    'dateText',
                    mergeAcross: $columnCount - 2
                ),
            ], 22);
    }

    private function rangeLabel(Carbon $rangeStart, Carbon $rangeEnd): string
    {
        if ($rangeStart->isSameMonth($rangeEnd)) {
            return strtoupper($this->monthName($rangeStart)) . ' - ' . $rangeStart->year;
        }

        return strtoupper($this->monthName($rangeStart)) . ' ' . $rangeStart->year
            . ' - ' . strtoupper($this->monthName($rangeEnd)) . ' ' . $rangeEnd->year;
    }

    /**
     * @param list<string> $dates
     */
    private function headerRowsXml(Carbon $rangeStart, Carbon $rangeEnd, array $dates): string
    {
        $dayCount = count($dates);
        // Rentang lintas bulan butuh tanggal + bulan supaya kolomnya tidak
        // ambigu (dua "01" berbeda bulan di satu baris).
        $crossMonth = ! $rangeStart->isSameMonth($rangeEnd);

        $cells = [
            $this->cell('R', 'peachHeader', mergeDown: 1),
            $this->cell('AKUN', 'peachHeader', mergeDown: 1),
            $this->cell($this->rangeLabel($rangeStart, $rangeEnd), 'peachHeader', mergeAcross: $dayCount - 1),
            $this->cell('TOTAL', 'peachHeader', mergeDown: 1),
        ];

        $dayCells = [];
        foreach (array_values($dates) as $offset => $dateKey) {
            $day = Carbon::parse($dateKey);
            $dayCells[] = $this->cell(
                $crossMonth ? $day->format('d/m') : $day->format('d'),
                'dayHeader',
                index: $offset + 3
            );
        }

        return $this->row($cells, 27) . $this->row($dayCells, 23);
    }

    /**
     * @param list<string> $dates
     */
    private function bodyRowsXml(Collection $admins, array $dates, ?string $selectedGroup = null): string
    {
        $xml = '';
        $sequence = 1;
        $dayCount = count($dates);

        foreach ($admins->groupBy('account_group') as $group => $groupRows) {
            if ($selectedGroup === null) {
                $xml .= $this->row([
                    $this->cell($group, 'groupSeparator', mergeAcross: $dayCount + 2),
                ], 20);
            }

            foreach ($groupRows->values() as $row) {
                $cells = [
                    $this->cell($sequence++, 'bodyCenter', 'Number'),
                    $this->cell(strtoupper($row['account_name']), 'bodyAccount'),
                ];

                foreach ($dates as $dateKey) {
                    $split = $this->consultationSplitForDate($row, $dateKey);
                    [$style, $value] = $this->resolveCell(
                        $split['normal'],
                        $split['susulan'],
                        $row['attendance_categories']->get($dateKey, [])
                    );

                    $cells[] = $this->cell($value, $style, 'Number');
                }

                $cells[] = $this->cell($this->rowAdaWaTotal($row, $dates), 'bodyTotal', 'Number');
                $xml .= $this->row($cells, 19);
            }
        }

        return $xml . $this->row([
            $this->cell('', 'bodyCenter'),
            $this->cell('', 'bodyAccount', mergeAcross: $dayCount + 1),
        ], 20);
    }

    /**
     * @param list<string> $dates
     */
    private function totalRowXml(Collection $admins, array $dates): string
    {
        $cells = [
            $this->cell('TOTAL', 'totalLabel', mergeAcross: 1),
        ];

        foreach ($dates as $dateKey) {
            $cells[] = $this->cell($this->dayAdaWaTotal($admins, $dateKey), 'totalDay', 'Number');
        }

        $cells[] = $this->cell($this->grandAdaWaTotal($admins, $dates), 'totalGrand', 'Number');

        return $this->row($cells, 38);
    }

    private function legendRowsXml(int $columnCount): string
    {
        return $this->row([$this->cell('', 'blank', mergeAcross: $columnCount - 1)], 18)
            . $this->legendRow(self::CELL_GREEN, 'Laporan - ada WA Konsumen baru')
            . $this->legendRow(self::CELL_YELLOW, 'Laporan - 0 data WA Konsumen baru')
            . $this->legendRow(self::CELL_BLUE, 'Rekapan laporan susulan / Hari Libur')
            . $this->legendRow(self::CELL_RED, 'Tidak laporan');
    }

    private function legendRow(string $swatchStyle, string $label): string
    {
        return $this->row([
            $this->cell('', $swatchStyle),
            $this->cell($label, 'legendText', mergeAcross: 6),
        ], 18);
    }

    /**
     * @param list<string> $dates
     */
    private function rowAdaWaTotal(array $row, array $dates): int
    {
        $total = 0;

        foreach ($dates as $dateKey) {
            $total += $this->consultationCountForDate($row, $dateKey);
        }

        return $total;
    }

    private function dayAdaWaTotal(Collection $admins, string $dateKey): int
    {
        return $admins->sum(fn (array $row) => $this->consultationCountForDate($row, $dateKey));
    }

    /**
     * @param list<string> $dates
     */
    private function grandAdaWaTotal(Collection $admins, array $dates): int
    {
        return $admins->sum(fn (array $row) => $this->rowAdaWaTotal($row, $dates));
    }

    /**
     * Menentukan warna dan angka satu sel.
     *
     * Urutan prioritas, berhenti di kecocokan pertama:
     *   1. ada laporan tepat waktu  -> hijau, angka = tepat waktu + susulan
     *   2. hanya laporan susulan    -> biru,  angka = susulan
     *   3. absen & bekerja          -> kuning, angka 0
     *   4. absen & libur/susulan    -> biru,   angka 0
     *   5. tidak ada apa-apa        -> merah,  angka 0
     *
     * Aturan 1 yang menjaga tanggal yang sudah hijau tidak berubah jadi biru
     * ketika kemudian ditambah data susulan — susulan hanya menambah angka.
     * Aturan 3 didahulukan atas 4 supaya akun dengan dua admin, yang satu
     * melapor kerja dan satunya libur, tidak terbaca sebagai hari libur.
     *
     * @param  list<string>  $categories
     * @return array{0: string, 1: int}
     */
    private function resolveCell(int $normal, int $susulan, array $categories): array
    {
        if ($normal > 0) {
            return [self::CELL_GREEN, $normal + $susulan];
        }

        if ($susulan > 0) {
            return [self::CELL_BLUE, $susulan];
        }

        if (array_intersect(self::WORKING_CATEGORIES, $categories) !== []) {
            return [self::CELL_YELLOW, 0];
        }

        if (in_array(self::CATEGORY_DAY_OFF, $categories, true)) {
            return [self::CELL_BLUE, 0];
        }

        return [self::CELL_RED, 0];
    }

    /**
     * @return array{normal:int, susulan:int}
     */
    private function consultationSplitForDate(array $row, string $dateKey): array
    {
        $counts = $row['consultation_counts']->get($dateKey);

        return [
            'normal' => (int) ($counts['normal'] ?? 0),
            'susulan' => (int) ($counts['susulan'] ?? 0),
        ];
    }

    /**
     * Angka yang tampil di sel — mengikuti aturan warna, bukan total mentah.
     */
    private function consultationCountForDate(array $row, string $dateKey): int
    {
        $split = $this->consultationSplitForDate($row, $dateKey);

        return $this->resolveCell(
            $split['normal'],
            $split['susulan'],
            $row['attendance_categories']->get($dateKey, [])
        )[1];
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

    /** Urutan grup mengikuti urutan deklarasi di AccountGroup. */
    private function accountGroupSort(string $group): int
    {
        $order = array_search($group, AccountGroup::values(), true);

        return $order === false ? PHP_INT_MAX : $order;
    }

    private function normalizeAccountGroup(?string $group): ?string
    {
        return AccountGroup::normalize($group);
    }

    private function escapeSheetName(string $name): string
    {
        $normalized = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name), 0, 31);

        return htmlspecialchars($normalized, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
