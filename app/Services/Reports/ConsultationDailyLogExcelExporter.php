<?php

namespace App\Services\Reports;

use App\Support\AccountGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Log harian rekap konsul: satu baris per akun per tanggal (bukan matrix
 * seperti AdminReportAttendanceExcelExporter) — kolom NO, TANGGAL, AKUN,
 * TEAM, STATUS, KETERANGAN. Dirancang supaya bisa langsung disalin (copy
 * TANGGAL/AKUN/STATUS/KETERANGAN) ke sheet log warna manual yang sudah
 * dipakai user di luar sistem — makanya header, urutan kolom, dan nilai
 * STATUS sengaja dibuat sama persis dengan konvensi itu.
 *
 * Status & warnanya berasal dari [[ConsultationRecapStatusResolver]] yang
 * sama dipakai AdminReportAttendanceExcelExporter, supaya kedua format
 * export selalu konsisten untuk kombinasi akun+tanggal yang sama.
 */
class ConsultationDailyLogExcelExporter
{
    /** Batas rentang tanggal — sama dengan matrix, supaya perilakunya konsisten. */
    public const MAX_RANGE_DAYS = AdminReportAttendanceExcelExporter::MAX_RANGE_DAYS;

    public function __construct(
        private readonly ConsultationRecapStatusResolver $resolver = new ConsultationRecapStatusResolver(),
    ) {
    }

    public function buildWorkbook(Carbon $start, ?string $accountGroup = null, ?Carbon $end = null, ?array $accountIds = null): string
    {
        [$rangeStart, $rangeEnd] = $this->resolveRange($start, $end);
        $dates = $this->dateKeys($rangeStart, $rangeEnd);
        $selectedGroup = AccountGroup::normalize($accountGroup);
        $accounts = $this->resolver->accountRows($rangeStart, $rangeEnd, $selectedGroup, $accountIds);

        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
            sprintf('<Worksheet ss:Name="%s">', $this->escapeSheetName('Log ' . $this->rangeSheetName($rangeStart, $rangeEnd))),
            '<Table x:FullColumns="1" x:FullRows="1">',
            $this->columnsXml(),
            $this->titleRowsXml($rangeStart, $rangeEnd, $selectedGroup),
            $this->headerRowXml(),
            $this->bodyRowsXml($accounts, $dates),
            $this->legendRowsXml(),
            '</Table>',
            '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane>'
            . '<ActivePane>2</ActivePane></WorksheetOptions>',
            '</Worksheet>',
            '</Workbook>',
        ]);
    }

    /** Sama persis perilakunya dengan AdminReportAttendanceExcelExporter::resolveRange(). */
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

    /** @return list<string> */
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
        return $start->isSameMonth($end)
            ? $start->translatedFormat('F') . ' ' . $start->year
            : $start->format('d-m-Y') . ' sd ' . $end->format('d-m-Y');
    }

    private function titleRowsXml(Carbon $rangeStart, Carbon $rangeEnd, ?string $accountGroup): string
    {
        $subtitle = sprintf(
            '%s - %s sampai %s',
            AccountGroup::subtitleLabel($accountGroup),
            $rangeStart->format('d/m/Y'),
            $rangeEnd->format('d/m/Y')
        );

        return $this->row([
            $this->cell('LOG HARIAN LAPORAN KONSUL (per Tanggal & Akun)', 'title', mergeAcross: 5),
        ], 34)
            . $this->row([
                $this->cell($subtitle, 'subtitle', mergeAcross: 5),
            ], 22);
    }

    private function headerRowXml(): string
    {
        $cells = array_map(
            fn (string $label) => $this->cell($label, 'header'),
            ['NO', 'TANGGAL', 'AKUN', 'TEAM', 'STATUS', 'KETERANGAN']
        );

        return $this->row($cells, 24);
    }

    /**
     * @param  Collection<int, array>  $accounts
     * @param  list<string>  $dates
     */
    private function bodyRowsXml(Collection $accounts, array $dates): string
    {
        $xml = '';
        $number = 1;

        // Tanggal jadi loop luar (bukan akun) supaya urutan baris kronologis
        // per tanggal — sama seperti sheet log warna manual yang jadi acuan.
        foreach ($dates as $dateKey) {
            $date = Carbon::parse($dateKey);

            foreach ($accounts as $account) {
                $status = $this->resolver->resolveStatusForDate($account, $dateKey);
                $zebra = $number % 2 === 0 ? 'Alt' : '';

                $cells = [
                    $this->cell($number, 'rowNumber' . $zebra, 'Number'),
                    $this->cell($date->format('Y-m-d\T00:00:00.000'), 'dateCell' . $zebra, 'DateTime'),
                    $this->cell(strtoupper($account['account_name']), 'text' . $zebra),
                    $this->cell(strtoupper(AccountGroup::label($account['account_group']) ?? ''), 'textCenter' . $zebra),
                    $this->cell($status->label, $status->style),
                    $this->cell($status->description, 'text' . $zebra),
                ];

                $xml .= $this->row($cells, 19);
                $number++;
            }
        }

        return $xml;
    }

    private function legendRowsXml(): string
    {
        return $this->row([$this->cell('', 'blank', mergeAcross: 5)], 14)
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_GREEN, 'Laporan - ada WA Konsumen baru')
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_YELLOW, 'Laporan - 0 data WA Konsumen baru')
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_BLUE, 'Rekapan laporan susulan / Hari Libur')
            . $this->legendRow(ConsultationRecapStatusResolver::CELL_RED, 'Tidak laporan');
    }

    private function legendRow(string $swatchStyle, string $label): string
    {
        return $this->row([
            $this->cell('', $swatchStyle),
            $this->cell($label, 'legendText', mergeAcross: 4),
        ], 18);
    }

    private function columnsXml(): string
    {
        return collect([40, 110, 260, 90, 130, 260])
            ->map(fn (int $width) => sprintf('<Column ss:AutoFitWidth="0" ss:Width="%s"/>', (float) $width))
            ->implode('');
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
        ?int $mergeAcross = null
    ): string {
        $attributes = [sprintf('ss:StyleID="%s"', $style)];

        if ($mergeAcross !== null) {
            $attributes[] = sprintf('ss:MergeAcross="%d"', $mergeAcross);
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
            . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#111827"/></Style>'
            . $this->style('title', '#0E7490', true, 16, 'Center', fontColor: '#FFFFFF', border: false)
            . $this->style('subtitle', '#CFFAFE', false, 11, 'Center', fontColor: '#155E75', border: false)
            . $this->style('header', '#164E63', true, 11, 'Center', fontColor: '#FFFFFF', borderWeight: 2)
            . $this->style('rowNumber', '#FFFFFF', false, 11, 'Center')
            . $this->style('rowNumberAlt', '#F1F5F9', false, 11, 'Center')
            . $this->style('text', '#FFFFFF', false, 11, 'Left')
            . $this->style('textAlt', '#F1F5F9', false, 11, 'Left')
            . $this->style('textCenter', '#FFFFFF', false, 11, 'Center')
            . $this->style('textCenterAlt', '#F1F5F9', false, 11, 'Center')
            . $this->dateStyle('dateCell', '#FFFFFF')
            . $this->dateStyle('dateCellAlt', '#F1F5F9')
            . $this->style(ConsultationRecapStatusResolver::CELL_GREEN, '#92D050', false, 11, 'Center')
            . $this->style(ConsultationRecapStatusResolver::CELL_YELLOW, '#FFFF00', false, 11, 'Center')
            . $this->style(ConsultationRecapStatusResolver::CELL_BLUE, '#00B0F0', false, 11, 'Center')
            . $this->style(ConsultationRecapStatusResolver::CELL_RED, '#FF0000', false, 11, 'Center')
            . $this->style('legendText', '#FFFFFF', false, 11, 'Left', border: false)
            . '<Style ss:ID="blank"><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '</Styles>';
    }

    private function dateStyle(string $id, string $background): string
    {
        return sprintf(
            '<Style ss:ID="%s"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
            . '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>'
            . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders>'
            . '<Font ss:FontName="Calibri" ss:Size="11" ss:Color="#111827"/>'
            . '<Interior ss:Color="%s" ss:Pattern="Solid"/><NumberFormat ss:Format="dd/mm/yyyy"/></Style>',
            $id,
            $background
        );
    }

    private function style(
        string $id,
        string $background,
        bool $bold,
        int $size,
        string $horizontal,
        int $borderWeight = 1,
        bool $border = true,
        string $fontColor = '#111827'
    ): string {
        $borders = $border
            ? sprintf(
                '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="%1$d" ss:Color="#CBD5E1"/>'
                . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="%1$d" ss:Color="#CBD5E1"/>'
                . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="%1$d" ss:Color="#CBD5E1"/>'
                . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="%1$d" ss:Color="#CBD5E1"/></Borders>',
                $borderWeight
            )
            : '';

        return sprintf(
            '<Style ss:ID="%s"><Alignment ss:Horizontal="%s" ss:Vertical="Center"/>%s'
            . '<Font ss:FontName="Calibri" ss:Size="%d" ss:Color="%s"%s/>'
            . '<Interior ss:Color="%s" ss:Pattern="Solid"/></Style>',
            $id,
            $horizontal,
            $borders,
            $size,
            $fontColor,
            $bold ? ' ss:Bold="1"' : '',
            $background
        );
    }

    private function escapeSheetName(string $name): string
    {
        $normalized = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name), 0, 31);

        return htmlspecialchars($normalized, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
