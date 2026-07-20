<?php

namespace App\Services\Reports;

use InvalidArgumentException;

/**
 * Excel "Rekap Jadwal Surveyor": grid Seninâ€“Minggu + tabel jumlah per surveyor
 * di sebelah kanannya.
 *
 * Menerima apa adanya hasil SurveyorScheduleRecapService::buildForUser() â€”
 * subtitle, jumlah baris, dan urutan nama sudah final di sana, jadi angka di
 * layar dan di file ini tidak bisa berselisih.
 *
 * CATATAN PENTING soal converter (SpreadsheetXmlToXlsxConverter):
 *  - Baris di-key berdasarkan nomornya. Dua <Row> bernomor sama membuat yang
 *    pertama hilang TANPA error dan file tetap terbuka. Karena tabel ringkasan
 *    berada di baris yang sama dengan grid, baris TIDAK boleh dirakit sebagai
 *    dua aliran terpisah â€” makanya di sini dipakai peta baris.
 *  - Sel dalam satu baris harus urut naik menurut kolom, atau Excel menolaknya.
 *  - Warna border di-hardcode hitam oleh converter; jangan menulis border
 *    berwarna lalu berharap ia muncul.
 */
class SurveyorScheduleRecapExcelExporter
{
    /** Baris 1-2 judul, 3 nama hari, 4 tanggal; isi grid mulai baris 5. */
    private const ROW_TITLE = 1;
    private const ROW_SUBTITLE = 2;
    private const ROW_DAY_NAMES = 3;
    private const ROW_DATES = 4;
    private const ROW_BODY_START = 5;

    /** Kolom A=1, hari B..H=2..8, I=9 pemisah, J=10 nama, K=11 jumlah. */
    private const COL_ROW_NUMBER = 1;
    private const COL_FIRST_DAY = 2;
    private const COL_SUMMARY_NAME = 10;
    private const COL_SUMMARY_COUNT = 11;

    /** @var array<int, array<int, string>> baris => (kolom => XML sel) */
    private array $sheet = [];

    public function buildWorkbook(array $report): string
    {
        $this->sheet = [];

        $days = $report['days'] ?? [];
        $summary = $report['summary'] ?? [];
        $rowCount = max(1, (int) ($report['rowCount'] ?? 10));

        // Tata letak ini mengunci hari ke kolom B..H dan ringkasan ke J..K.
        // Lebih dari 7 hari akan menempatkan hari ke-9 tepat di kolom ringkasan
        // dan saling menimpa di peta baris â€” tanpa error, file tetap terbuka,
        // datanya diam-diam hilang. Lebih baik gagal keras di sini.
        if (count($days) !== SurveyorScheduleRecapService::DAYS_IN_WEEK) {
            throw new InvalidArgumentException(sprintf(
                'Rekap jadwal butuh tepat %d hari, diberi %d. Tata letak Excel-nya hanya muat satu minggu Seninâ€“Minggu.',
                SurveyorScheduleRecapService::DAYS_IN_WEEK,
                count($days)
            ));
        }

        $this->putTitle($report);
        $this->putGrid($days, $rowCount);
        $this->putSummary($summary, (int) ($report['total'] ?? 0));

        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
            sprintf('<Worksheet ss:Name="%s">', $this->escapeSheetName($this->sheetName($report))),
            '<Table x:FullColumns="1" x:FullRows="1">',
            $this->columnsXml(),
            $this->renderRows(),
            '</Table>',
            '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/><FrozenNoSplit/>'
            . '<SplitHorizontal>4</SplitHorizontal><TopRowBottomPane>4</TopRowBottomPane>'
            . '<ActivePane>2</ActivePane><ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios>'
            . '</WorksheetOptions>',
            '</Worksheet>',
            '</Workbook>',
        ]);
    }

    /**
     * Judul hanya membentang A:H (grid), bukan sampai K.
     * Melebarkannya akan bertabrakan dengan header SURVEYOR/JML, dan Excel
     * membuang sheet yang merge-nya tumpang tindih tanpa memberi pesan.
     */
    private function putTitle(array $report): void
    {
        $this->put(self::ROW_TITLE, self::COL_ROW_NUMBER, $this->cell(
            'REKAP JADWAL SURVEYOR',
            'reportTitle',
            mergeAcross: 7
        ));

        $this->put(self::ROW_SUBTITLE, self::COL_ROW_NUMBER, $this->cell(
            (string) ($report['subtitle'] ?? ''),
            'reportSubtitle',
            mergeAcross: 7
        ));
    }

    private function putGrid(array $days, int $rowCount): void
    {
        $this->put(self::ROW_DAY_NAMES, self::COL_ROW_NUMBER, $this->cell('Hari', 'cornerLabel'));
        $this->put(self::ROW_DATES, self::COL_ROW_NUMBER, $this->cell('Tanggal', 'cornerLabel'));

        foreach ($days as $index => $day) {
            $column = self::COL_FIRST_DAY + $index;
            $suffix = $this->dayStyleSuffix($day);

            $this->put(self::ROW_DAY_NAMES, $column, $this->cell($day['dayName'], 'dayHeader' . $suffix));
            $this->put(self::ROW_DATES, $column, $this->cell($day['dateLabel'], 'dateHeader' . $suffix));

            for ($slot = 0; $slot < $rowCount; $slot++) {
                // Selalu tulis sel bergaya, walau kosong: melewatkannya membuat
                // kotak-kotak border grid bolong di hari yang sepi.
                $this->put(
                    self::ROW_BODY_START + $slot,
                    $column,
                    $this->cell($day['surveyorNames'][$slot] ?? '', 'nameCell')
                );
            }
        }

        for ($slot = 0; $slot < $rowCount; $slot++) {
            $this->put(
                self::ROW_BODY_START + $slot,
                self::COL_ROW_NUMBER,
                $this->cell($slot + 1, 'rowNumber', 'Number')
            );
        }
    }

    /**
     * Ringkasan mulai baris 4 (sejajar baris tanggal) lalu TOTAL. Panjangnya
     * bebas terhadap grid â€” bila surveyornya lebih banyak dari baris grid,
     * tabel ini memang menjulur ke bawah, dan peta baris menanganinya sendiri.
     */
    private function putSummary(array $summary, int $total): void
    {
        $this->put(self::ROW_DAY_NAMES, self::COL_SUMMARY_NAME, $this->cell('SURVEYOR', 'summaryHeader'));
        $this->put(self::ROW_DAY_NAMES, self::COL_SUMMARY_COUNT, $this->cell('JML', 'summaryHeader'));

        $row = self::ROW_DATES;

        foreach ($summary as $item) {
            $this->put($row, self::COL_SUMMARY_NAME, $this->cell($item['surveyorName'], 'summaryName'));
            $this->put($row, self::COL_SUMMARY_COUNT, $this->cell($item['count'], 'summaryCount', 'Number'));
            $row++;
        }

        $this->put($row, self::COL_SUMMARY_NAME, $this->cell('TOTAL', 'totalLabel'));
        $this->put($row, self::COL_SUMMARY_COUNT, $this->cell($total, 'totalCount', 'Number'));
    }

    private function dayStyleSuffix(array $day): string
    {
        if ($day['isFirstDay'] ?? false) {
            return 'First';
        }

        return ($day['isLastDay'] ?? false) ? 'Last' : '';
    }

    private function put(int $row, int $column, string $cellXml): void
    {
        $this->sheet[$row][$column] = $cellXml;
    }

    /**
     * Merender peta baris jadi <Row>/<Cell>.
     *
     * ss:Index dipasang hanya saat ada lompatan kolom â€” inilah yang membuat
     * tabel ringkasan bisa duduk di baris yang sama dengan grid.
     */
    private function renderRows(): string
    {
        if ($this->sheet === []) {
            return '';
        }

        ksort($this->sheet);
        $xml = '';
        $previousRow = 0;

        foreach ($this->sheet as $rowNumber => $cells) {
            ksort($cells);

            $rowCells = '';
            $expectedColumn = 1;

            foreach ($cells as $column => $cellXml) {
                $rowCells .= $column === $expectedColumn
                    ? $cellXml
                    : $this->withIndex($cellXml, $column);

                // Sel merge memakan kolom berikutnya; lompati sebanyak itu.
                $expectedColumn = $column + 1 + $this->mergeAcrossOf($cellXml);
            }

            // Baris yang kosong sama sekali tetap harus ditandai nomornya, atau
            // baris sesudahnya akan naik mengisi celah.
            $indexAttribute = $rowNumber === $previousRow + 1
                ? ''
                : sprintf(' ss:Index="%d"', $rowNumber);

            $xml .= sprintf(
                '<Row%s%s>%s</Row>',
                $indexAttribute,
                $this->rowHeightAttribute($rowNumber),
                $rowCells
            );

            $previousRow = $rowNumber;
        }

        return $xml;
    }

    private function withIndex(string $cellXml, int $column): string
    {
        return preg_replace(
            '/^<Cell /',
            sprintf('<Cell ss:Index="%d" ', $column),
            $cellXml,
            1
        );
    }

    private function mergeAcrossOf(string $cellXml): int
    {
        return preg_match('/ss:MergeAcross="(\d+)"/', $cellXml, $matches)
            ? (int) $matches[1]
            : 0;
    }

    private function rowHeightAttribute(int $rowNumber): string
    {
        $height = match ($rowNumber) {
            self::ROW_TITLE => 36,
            self::ROW_SUBTITLE => 31,
            self::ROW_DAY_NAMES => 27,
            self::ROW_DATES => 23,
            default => 19,
        };

        return sprintf(' ss:Height="%s"', (float) $height);
    }

    private function columnsXml(): string
    {
        // Lebar SpreadsheetML; converter membaginya 7 untuk dapat satuan xlsx.
        $widths = [40, 140, 140, 140, 140, 140, 140, 140, 20, 160, 50];

        return collect($widths)
            ->map(fn (int $width) => sprintf('<Column ss:AutoFitWidth="0" ss:Width="%s"/>', (float) $width))
            ->implode('');
    }

    private function sheetName(array $report): string
    {
        $start = $report['period']['start'] ?? '';
        $end = $report['period']['end'] ?? '';

        return trim(sprintf('Rekap Jadwal %s sd %s', $start, $end));
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
            . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#000000"/></Style>'
            . $this->style('reportTitle', '#DDEBF7', true, 16, 'Center', borderWeight: 2)
            . $this->style('reportSubtitle', '#DDEBF7', true, 12, 'Center', borderWeight: 2)
            . $this->style('cornerLabel', '#DDEBF7', true, 11, 'Center', borderWeight: 2)
            // Senin kuning & Minggu oranye mengikuti lembar manual yang ditiru.
            . $this->style('dayHeaderFirst', '#FFFF00', true, 11, 'Center', borderWeight: 2)
            . $this->style('dayHeaderLast', '#FFC000', true, 11, 'Center', borderWeight: 2)
            . $this->style('dayHeader', '#DDEBF7', true, 11, 'Center', borderWeight: 2)
            . $this->style('dateHeaderFirst', '#FFFF00', true, 11, 'Center')
            . $this->style('dateHeaderLast', '#FFC000', true, 11, 'Center')
            . $this->style('dateHeader', '#DDEBF7', true, 11, 'Center')
            . $this->style('rowNumber', '#FFFFFF', false, 11, 'Center')
            . $this->style('nameCell', '#FFFFFF', false, 11, 'Left')
            . $this->style('summaryHeader', '#DDEBF7', true, 11, 'Center', borderWeight: 2)
            . $this->style('summaryName', '#FFFFFF', false, 11, 'Left')
            . $this->style('summaryCount', '#FFFFFF', false, 11, 'Center')
            . $this->style('totalLabel', '#4472C4', true, 11, 'Center', borderWeight: 2, fontColor: '#FFFFFF')
            . $this->style('totalCount', '#D9D9D9', true, 11, 'Center', borderWeight: 2)
            . '</Styles>';
    }

    /**
     * Salinan lokal dari pola AdminReportAttendanceExcelExporter, ditambah
     * $fontColor: sel TOTAL butuh teks putih di atas biru, sementara versi
     * aslinya meng-hardcode font hitam.
     */
    private function style(
        string $id,
        string $background,
        bool $bold,
        int $size,
        string $horizontal,
        int $borderWeight = 1,
        bool $border = true,
        string $fontColor = '#000000'
    ): string {
        $borders = $border
            ? sprintf(
                '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/>'
                . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/>'
                . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/>'
                . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/></Borders>',
                $borderWeight,
                $borderWeight,
                $borderWeight,
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
        return mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name), 0, 31);
    }
}
