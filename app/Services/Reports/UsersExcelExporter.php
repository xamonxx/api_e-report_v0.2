<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Excel daftar semua user (master data akun). Format SpreadsheetML 2003 yang
 * dikonversi ke xlsx oleh [[SpreadsheetXmlToXlsxConverter]] — pola sama dengan
 * SurveyorScheduleRecapExcelExporter.
 *
 * CATATAN KEAMANAN: TIDAK ADA kolom password. Password disimpan sebagai hash
 * bcrypt satu-arah; plaintext tidak ada di database dan tidak boleh diekspor.
 * Menaruh hash pun berbahaya (bisa di-crack offline bila file bocor).
 */
class UsersExcelExporter
{
    private const ROW_TITLE = 1;
    private const ROW_SUBTITLE = 2;
    private const ROW_HEADER = 3;
    private const ROW_BODY_START = 4;

    /** NO, NAMA, EMAIL, ROLE, TAUTAN AKUN, TANGGAL DIBUAT. */
    private const HEADERS = ['NO', 'NAMA LENGKAP', 'EMAIL', 'ROLE', 'TAUTAN AKUN', 'TANGGAL DIBUAT'];
    private const WIDTHS = [40, 220, 260, 120, 240, 150];

    private const ROLE_LABELS = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager_surveyor' => 'Manager Surveyor',
        'surveyor' => 'Surveyor',
    ];

    /** @param Collection<int, User> $users */
    public function buildWorkbook(Collection $users): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
            '<Worksheet ss:Name="Daftar User">',
            '<Table x:FullColumns="1" x:FullRows="1">',
            $this->columnsXml(),
            $this->titleRows($users->count()),
            $this->headerRow(),
            $this->bodyRows($users),
            '</Table>',
            '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/><FrozenNoSplit/>'
            . '<SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane>'
            . '<ActivePane>2</ActivePane></WorksheetOptions>',
            '</Worksheet>',
            '</Workbook>',
        ]);
    }

    private function titleRows(int $total): string
    {
        return sprintf(
            '<Row ss:Height="34"><Cell ss:StyleID="title" ss:MergeAcross="5"><Data ss:Type="String">DAFTAR PENGGUNA SISTEM</Data></Cell></Row>'
            . '<Row ss:Height="22"><Cell ss:StyleID="subtitle" ss:MergeAcross="5"><Data ss:Type="String">%s pengguna terdaftar &#183; diekspor %s</Data></Cell></Row>',
            $total,
            htmlspecialchars(now()->format('d/m/Y H:i'), ENT_XML1 | ENT_COMPAT, 'UTF-8')
        );
    }

    private function headerRow(): string
    {
        $cells = '';
        foreach (self::HEADERS as $label) {
            $cells .= $this->cell($label, 'header');
        }

        return sprintf('<Row ss:Index="%d" ss:Height="24">%s</Row>', self::ROW_HEADER, $cells);
    }

    /** @param Collection<int, User> $users */
    private function bodyRows(Collection $users): string
    {
        $xml = '';
        $number = 1;

        foreach ($users->values() as $user) {
            $zebra = $number % 2 === 0 ? 'Alt' : '';
            $cells = $this->cell($number, 'rowNumber' . $zebra, 'Number')
                . $this->cell($user->name ?? '', 'text' . $zebra)
                . $this->cell($user->email ?? '', 'text' . $zebra)
                . $this->cell(self::ROLE_LABELS[$user->role?->value ?? ''] ?? ($user->role?->value ?? '-'), 'text' . $zebra)
                . $this->cell($user->account?->name ?? '—', 'text' . $zebra)
                . $this->cell($user->created_at?->format('d/m/Y') ?? '-', 'textCenter' . $zebra);

            $xml .= sprintf(
                '<Row ss:Index="%d" ss:Height="19">%s</Row>',
                self::ROW_BODY_START + $number - 1,
                $cells
            );
            $number++;
        }

        return $xml;
    }

    private function columnsXml(): string
    {
        return collect(self::WIDTHS)
            ->map(fn (int $width) => sprintf('<Column ss:AutoFitWidth="0" ss:Width="%s"/>', (float) $width))
            ->implode('');
    }

    private function cell(mixed $value, string $style, string $type = 'String'): string
    {
        return sprintf(
            '<Cell ss:StyleID="%s"><Data ss:Type="%s">%s</Data></Cell>',
            $style,
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
            . '</Styles>';
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
}
