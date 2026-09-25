<?php

namespace App\Services\Reports;

use App\Support\AccountGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AdminReportAttendanceExcelExporter
{
    /** Batas kolom tanggal dalam satu lembar. */
    public const MAX_RANGE_DAYS = 92;

    public function __construct(
        private readonly ConsultationRecapStatusResolver $resolver = new ConsultationRecapStatusResolver(),
    ) {
    }

    /**
     * Grid rekap absensi: satu baris per admin, satu kolom per tanggal.
     *
     * `$end` opsional — kalau kosong, rentangnya satu bulan penuh milik
     * `$start` (perilaku lama sebelum filter rentang kustom ada).
     */
    public function buildWorkbook(Carbon $start, ?string $accountGroup = null, ?Carbon $end = null, ?array $accountIds = null): string
    {
        [$rangeStart, $rangeEnd] = $this->resolveRange($start, $end);
        $dates = $this->dateKeys($rangeStart, $rangeEnd);
        $dayCount = count($dates);
        $selectedGroup = $this->normalizeAccountGroup($accountGroup);
        $admins = $this->buildRows($rangeStart, $rangeEnd, $selectedGroup, $accountIds);
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
    private function buildRows(Carbon $rangeStart, Carbon $rangeEnd, ?string $accountGroup = null, ?array $accountIds = null): Collection
    {
        return $this->resolver->accountRows($rangeStart, $rangeEnd, $accountGroup, $accountIds);
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
                    $status = $this->resolver->resolveStatusForDate($row, $dateKey);

                    $cells[] = $this->cell($status->count, $status->style, 'Number');
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
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_GREEN, 'Laporan - ada WA Konsumen baru')
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_YELLOW, 'Laporan - 0 data WA Konsumen baru')
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_BLUE, 'Rekapan laporan susulan / Hari Libur')
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_RED, 'Tidak laporan');
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
     * Angka yang tampil di sel — mengikuti aturan warna, bukan total mentah.
     */
    private function consultationCountForDate(array $row, string $dateKey): int
    {
        return $this->resolver->resolveStatusForDate($row, $dateKey)->count;
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
