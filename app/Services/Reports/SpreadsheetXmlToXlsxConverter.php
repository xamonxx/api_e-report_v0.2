<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SpreadsheetXmlToXlsxConverter
{
    private const SS_NS = 'urn:schemas-microsoft-com:office:spreadsheet';

    public function convert(string $spreadsheetXml): string
    {
        $workbook = @simplexml_load_string($spreadsheetXml);

        if (! $workbook instanceof SimpleXMLElement) {
            throw new RuntimeException('Workbook XML tidak valid.');
        }

        $styles = $this->parseStyles($workbook);
        $styleIndexes = $this->styleIndexes($styles);
        $sheets = $this->parseSheets($workbook, $styleIndexes);

        if ($sheets === []) {
            throw new RuntimeException('Workbook tidak memiliki worksheet.');
        }

        return $this->buildXlsx($sheets, $styles);
    }

    private function parseStyles(SimpleXMLElement $workbook): array
    {
        $styles = [];
        $styleNodes = $workbook->children(self::SS_NS)->Styles->children(self::SS_NS)->Style ?? [];

        foreach ($styleNodes as $style) {
            $attrs = $style->attributes(self::SS_NS);
            $id = (string) ($attrs['ID'] ?? '');

            if ($id === '' || $id === 'Default') {
                continue;
            }

            $children = $style->children(self::SS_NS);
            $alignment = $children->Alignment?->attributes(self::SS_NS);
            $font = $children->Font?->attributes(self::SS_NS);
            $interior = $children->Interior?->attributes(self::SS_NS);
            $numberFormat = $children->NumberFormat?->attributes(self::SS_NS);
            $borderWeight = 0;

            foreach ($children->Borders?->children(self::SS_NS)->Border ?? [] as $border) {
                $borderAttrs = $border->attributes(self::SS_NS);
                $borderWeight = max($borderWeight, (int) ($borderAttrs['Weight'] ?? 1));
            }

            $styles[$id] = [
                'font' => [
                    'name' => (string) ($font['FontName'] ?? 'Calibri'),
                    'size' => (float) ($font['Size'] ?? 11),
                    'color' => $this->normalizeColor((string) ($font['Color'] ?? '#111827')),
                    'bold' => ((string) ($font['Bold'] ?? '0')) === '1',
                ],
                'fill' => $this->normalizeColor((string) ($interior['Color'] ?? '')),
                'borderWeight' => $borderWeight,
                'alignment' => [
                    'horizontal' => $this->xlsxAlignment((string) ($alignment['Horizontal'] ?? 'Left')),
                    'vertical' => $this->xlsxVerticalAlignment((string) ($alignment['Vertical'] ?? 'Center')),
                    'wrap' => ((string) ($alignment['WrapText'] ?? '0')) === '1',
                ],
                'numberFormat' => (string) ($numberFormat['Format'] ?? ''),
            ];
        }

        return $styles;
    }

    private function styleIndexes(array $styles): array
    {
        $indexes = [];
        $index = 1;

        foreach (array_keys($styles) as $styleId) {
            $indexes[$styleId] = $index++;
        }

        return $indexes;
    }

    private function parseSheets(SimpleXMLElement $workbook, array $styleIndexes): array
    {
        $sheets = [];

        // Collect named ranges from <Names> element at workbook level
        $workbookNamedRanges = [];
        $namesNode = $workbook->children(self::SS_NS)->Names;
        if ($namesNode) {
            foreach ($namesNode->children(self::SS_NS)->NamedRange as $namedRange) {
                $attrs = $namedRange->attributes(self::SS_NS);
                $name    = (string) ($attrs['Name'] ?? '');
                $refersTo = (string) ($attrs['RefersTo'] ?? '');
                if ($name !== '' && $refersTo !== '') {
                    $workbookNamedRanges[] = ['name' => $name, 'refers_to' => $refersTo];
                }
            }
        }

        foreach ($workbook->children(self::SS_NS)->Worksheet as $worksheet) {
            $sheetAttributes = $worksheet->attributes(self::SS_NS);
            $name = trim((string) ($sheetAttributes['Name'] ?? 'Sheet' . (count($sheets) + 1)));
            $table = $worksheet->children(self::SS_NS)->Table;
            $columns = [];
            $rows = [];
            $merges = [];

            foreach ($table->children(self::SS_NS)->Column as $column) {
                $attrs = $column->attributes(self::SS_NS);
                $repeat = max(1, (int) ($attrs['Span'] ?? 0) + 1);
                $width = max(6, ((float) ($attrs['Width'] ?? 64)) / 7);

                for ($i = 0; $i < $repeat; $i++) {
                    $columns[] = $width;
                }
            }

            $rowNumber = 1;
            foreach ($table->children(self::SS_NS)->Row as $row) {
                $rowAttrs = $row->attributes(self::SS_NS);
                $rowNumber = isset($rowAttrs['Index']) ? (int) $rowAttrs['Index'] : $rowNumber;
                $cells = [];
                $columnNumber = 1;

                foreach ($row->children(self::SS_NS)->Cell as $cell) {
                    $cellAttrs = $cell->attributes(self::SS_NS);
                    $columnNumber = isset($cellAttrs['Index']) ? (int) $cellAttrs['Index'] : $columnNumber;
                    $data = $cell->children(self::SS_NS)->Data;
                    $dataAttrs = $data->attributes(self::SS_NS);
                    $mergeAcross = (int) ($cellAttrs['MergeAcross'] ?? 0);
                    $mergeDown = (int) ($cellAttrs['MergeDown'] ?? 0);
                    $styleId = (string) ($cellAttrs['StyleID'] ?? '');
                    $formula = (string) ($cellAttrs['Formula'] ?? '');

                    $cells[$columnNumber] = [
                        'type'    => (string) ($dataAttrs['Type'] ?? 'String'),
                        'value'   => (string) $data,
                        'style'   => $styleIndexes[$styleId] ?? 0,
                        'formula' => $formula,
                    ];

                    if ($mergeAcross > 0 || $mergeDown > 0) {
                        $merges[] = sprintf(
                            '%s%d:%s%d',
                            $this->columnName($columnNumber),
                            $rowNumber,
                            $this->columnName($columnNumber + $mergeAcross),
                            $rowNumber + $mergeDown
                        );
                    }

                    $columnNumber += $mergeAcross + 1;
                }

                $rows[$rowNumber] = [
                    'height' => isset($rowAttrs['Height']) ? (float) $rowAttrs['Height'] : null,
                    'cells'  => $cells,
                ];
                $rowNumber++;
            }

            // Parse data validations from Excel namespace
            $dataValidations = [];
            $excelNs = 'urn:schemas-microsoft-com:office:excel';
            foreach ($worksheet->children($excelNs)->DataValidation as $dv) {
                $range = (string) $dv->children($excelNs)->Range;
                $value = (string) $dv->children($excelNs)->Value;
                if ($range !== '' && $value !== '') {
                    $dataValidations[] = [
                        'sqref'   => $this->convertSqRef($range),
                        'formula' => $this->convertNamedRangeFormula($value),
                    ];
                }
            }

            // Parse WorksheetOptions for freeze rows and hidden flag
            $freezeRows = 0;
            $hidden = false;
            $wsOptions = $worksheet->children($excelNs)->WorksheetOptions;
            if ($wsOptions) {
                $splitH = (int) ($wsOptions->SplitHorizontal ?? 0);
                if ($splitH > 0) {
                    $freezeRows = $splitH;
                }
                $visible = strtolower((string) ($wsOptions->Visible ?? ''));
                if ($visible === 'sheethidden' || $visible === 'hidden') {
                    $hidden = true;
                }
            }

            $sheets[] = [
                'name'            => $this->sanitizeSheetName($name !== '' ? $name : 'Sheet' . (count($sheets) + 1)),
                'columns'         => $columns,
                'rows'            => $rows,
                'merges'          => $merges,
                'dataValidations' => $dataValidations,
                'freezeRows'      => $freezeRows,
                'hidden'          => $hidden,
            ];
        }

        // Attach workbook-level named ranges to the first sheet (they will be written to workbook.xml)
        if ($sheets !== [] && $workbookNamedRanges !== []) {
            $sheets[0]['namedRanges'] = $workbookNamedRanges;
        }

        return $sheets;
    }

    private function buildXlsx(array $sheets, array $styles): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat arsip XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('docProps/core.xml', $this->coreXml());
        $zip->addFromString('docProps/app.xml', $this->appXml(count($sheets)));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->stylesXml($styles));

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->worksheetXml($sheet));
        }

        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new RuntimeException('Gagal membaca file XLSX.');
        }

        return $contents;
    }

    private function worksheetXml(array $sheet): string
    {
        $maxRow = $sheet['rows'] === [] ? 1 : max(array_keys($sheet['rows']));
        $maxColumn = max(1, count($sheet['columns']));

        foreach ($sheet['rows'] as $row) {
            if ($row['cells'] !== []) {
                $maxColumn = max($maxColumn, max(array_keys($row['cells'])));
            }
        }

        $freezeRows = (int) ($sheet['freezeRows'] ?? 0);
        $hidden = (bool) ($sheet['hidden'] ?? false);

        // Build sheetView with freeze pane
        $sheetViewContent = '';
        if ($freezeRows > 0) {
            $sheetViewContent = sprintf(
                '<sheetView workbookViewId="0"><pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/></sheetView>',
                $freezeRows,
                $freezeRows + 1
            );
        } else {
            $sheetViewContent = '<sheetView workbookViewId="0"/>';
        }

        // sheetState attribute for hidden sheets
        $sheetStateAttr = $hidden ? ' state="hidden"' : '';

        $xml = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            sprintf('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"%s>', $sheetStateAttr),
            sprintf('<dimension ref="A1:%s%d"/>', $this->columnName($maxColumn), $maxRow),
            '<sheetViews>' . $sheetViewContent . '</sheetViews><sheetFormatPr defaultRowHeight="15"/>',
        ];

        if ($sheet['columns'] !== []) {
            $xml[] = '<cols>';
            foreach ($sheet['columns'] as $index => $width) {
                $column = $index + 1;
                $xml[] = sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $column, $column, $this->number($width));
            }
            $xml[] = '</cols>';
        }

        $xml[] = '<sheetData>';
        foreach ($sheet['rows'] as $rowNumber => $row) {
            $height = $row['height'] !== null ? sprintf(' ht="%s" customHeight="1"', $this->number($row['height'])) : '';
            $xml[] = sprintf('<row r="%d"%s>', $rowNumber, $height);

            foreach ($row['cells'] as $columnNumber => $cell) {
                $xml[] = $this->xlsxCell($columnNumber, $rowNumber, $cell);
            }

            $xml[] = '</row>';
        }
        $xml[] = '</sheetData>';

        if ($sheet['merges'] !== []) {
            $xml[] = sprintf('<mergeCells count="%d">', count($sheet['merges']));
            foreach ($sheet['merges'] as $merge) {
                $xml[] = sprintf('<mergeCell ref="%s"/>', $merge);
            }
            $xml[] = '</mergeCells>';
        }

        // Data validations (dropdown lists)
        $dataValidations = $sheet['dataValidations'] ?? [];
        if ($dataValidations !== []) {
            $xml[] = sprintf('<dataValidations count="%d">', count($dataValidations));
            foreach ($dataValidations as $dv) {
                $xml[] = sprintf(
                    '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="%s"><formula1>%s</formula1></dataValidation>',
                    $this->xml($dv['sqref']),
                    $this->xml($dv['formula'])
                );
            }
            $xml[] = '</dataValidations>';
        }

        $xml[] = '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';

        return implode('', $xml);
    }

    private function xlsxCell(int $columnNumber, int $rowNumber, array $cell): string
    {
        $ref = $this->columnName($columnNumber) . $rowNumber;
        $style = $cell['style'] > 0 ? sprintf(' s="%d"', $cell['style']) : '';
        $value = $cell['value'];
        $formula = $cell['formula'] ?? '';

        // Formula cell (R1C1 → A1 style already stored, emit as formula)
        if ($formula !== '') {
            // Convert R1C1 formula to A1 notation for XLSX
            $a1Formula = $this->convertR1C1Formula($formula, $columnNumber, $rowNumber);

            // Safety net: convertR1C1Formula leaves a token untouched when it
            // can't resolve it (e.g. an out-of-bounds relative reference). Such
            // a token still contains a square bracket, which never appears in a
            // valid A1 formula here — so if one survives, the formula is broken
            // and Excel would silently strip it ("Removed Records: Formula").
            // In that case skip the <f> and keep just the cached value below.
            if (! str_contains($a1Formula, '[')) {
                if ($value !== '') {
                    // Provide cached value for formula cells
                    if ($cell['type'] === 'Number' && is_numeric($value)) {
                        return sprintf('<c r="%s"%s><f>%s</f><v>%s</v></c>', $ref, $style, $this->xml($a1Formula), $this->number((float) $value));
                    }
                    return sprintf('<c r="%s"%s t="str"><f>%s</f><v>%s</v></c>', $ref, $style, $this->xml($a1Formula), $this->xml($value));
                }
                return sprintf('<c r="%s"%s><f>%s</f></c>', $ref, $style, $this->xml($a1Formula));
            }
            // else: fall through and emit the cached value as a plain cell.
        }

        if ($value === '') {
            return sprintf('<c r="%s"%s/>', $ref, $style);
        }

        if ($cell['type'] === 'Number' && is_numeric($value)) {
            return sprintf('<c r="%s"%s><v>%s</v></c>', $ref, $style, $this->number((float) $value));
        }

        if ($cell['type'] === 'DateTime') {
            return sprintf('<c r="%s"%s><v>%s</v></c>', $ref, $style, $this->number($this->excelDateSerial($value)));
        }

        return sprintf('<c r="%s"%s t="inlineStr"><is><t>%s</t></is></c>', $ref, $style, $this->xml($value));
    }

    private function stylesXml(array $styles): string
    {
        $fonts = [['name' => 'Calibri', 'size' => 11, 'color' => 'FF111827', 'bold' => false]];
        $fills = [null, 'gray125'];
        $borders = [0];
        $numFmts = [];
        $fontMap = [$this->key($fonts[0]) => 0];
        $fillMap = ['none' => 0, 'gray125' => 1];
        $borderMap = ['0' => 0];
        $numFmtMap = [];
        $xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];

        foreach ($styles as $style) {
            $fontKey = $this->key($style['font']);
            if (! isset($fontMap[$fontKey])) {
                $fontMap[$fontKey] = count($fonts);
                $fonts[] = $style['font'];
            }

            $fillColor = $style['fill'] ?: null;
            $fillKey = $fillColor ?: 'none';
            if (! isset($fillMap[$fillKey])) {
                $fillMap[$fillKey] = count($fills);
                $fills[] = $fillColor;
            }

            $borderKey = (string) $style['borderWeight'];
            if (! isset($borderMap[$borderKey])) {
                $borderMap[$borderKey] = count($borders);
                $borders[] = $style['borderWeight'];
            }

            $numFmtId = 0;
            if ($style['numberFormat'] !== '') {
                if (! isset($numFmtMap[$style['numberFormat']])) {
                    $numFmtMap[$style['numberFormat']] = 164 + count($numFmts);
                    $numFmts[] = ['id' => $numFmtMap[$style['numberFormat']], 'format' => $style['numberFormat']];
                }
                $numFmtId = $numFmtMap[$style['numberFormat']];
            }

            $alignment = sprintf(
                '<alignment horizontal="%s" vertical="%s"%s/>',
                $style['alignment']['horizontal'],
                $style['alignment']['vertical'],
                $style['alignment']['wrap'] ? ' wrapText="1"' : ''
            );

            $xfs[] = sprintf(
                '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="%d" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"%s>%s</xf>',
                $numFmtId,
                $fontMap[$fontKey],
                $fillMap[$fillKey],
                $borderMap[$borderKey],
                $numFmtId > 0 ? ' applyNumberFormat="1"' : '',
                $alignment
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $this->numFmtsXml($numFmts)
            . $this->fontsXml($fonts)
            . $this->fillsXml($fills)
            . $this->bordersXml($borders)
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . sprintf('<cellXfs count="%d">%s</cellXfs>', count($xfs), implode('', $xfs))
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function numFmtsXml(array $numFmts): string
    {
        if ($numFmts === []) {
            return '';
        }

        $xml = sprintf('<numFmts count="%d">', count($numFmts));
        foreach ($numFmts as $numFmt) {
            $xml .= sprintf('<numFmt numFmtId="%d" formatCode="%s"/>', $numFmt['id'], $this->xml($numFmt['format']));
        }

        return $xml . '</numFmts>';
    }

    private function fontsXml(array $fonts): string
    {
        $xml = sprintf('<fonts count="%d">', count($fonts));
        foreach ($fonts as $font) {
            $xml .= '<font>';
            $xml .= $font['bold'] ? '<b/>' : '';
            $xml .= sprintf('<sz val="%s"/><color rgb="%s"/><name val="%s"/></font>', $this->number((float) $font['size']), $font['color'], $this->xml($font['name']));
        }

        return $xml . '</fonts>';
    }

    private function fillsXml(array $fills): string
    {
        $xml = sprintf('<fills count="%d">', count($fills));
        foreach ($fills as $fill) {
            if ($fill === null) {
                $xml .= '<fill><patternFill patternType="none"/></fill>';
                continue;
            }

            if ($fill === 'gray125') {
                $xml .= '<fill><patternFill patternType="gray125"/></fill>';
                continue;
            }

            $xml .= sprintf('<fill><patternFill patternType="solid"><fgColor rgb="%s"/><bgColor indexed="64"/></patternFill></fill>', $fill);
        }

        return $xml . '</fills>';
    }

    private function bordersXml(array $borders): string
    {
        $xml = sprintf('<borders count="%d">', count($borders));
        foreach ($borders as $weight) {
            if ($weight <= 0) {
                $xml .= '<border><left/><right/><top/><bottom/><diagonal/></border>';
                continue;
            }

            $style = $weight >= 2 ? 'medium' : 'thin';
            $edge = sprintf('<left style="%s"><color rgb="FF000000"/></left><right style="%s"><color rgb="FF000000"/></right><top style="%s"><color rgb="FF000000"/></top><bottom style="%s"><color rgb="FF000000"/></bottom>', $style, $style, $style, $style);
            $xml .= '<border>' . $edge . '<diagonal/></border>';
        }

        return $xml . '</borders>';
    }

    private function workbookXml(array $sheets): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'];

        foreach ($sheets as $index => $sheet) {
            $sheetId = $index + 1;
            $stateAttr = ($sheet['hidden'] ?? false) ? ' state="hidden"' : '';
            $xml[] = sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"%s/>', $this->xml($sheet['name']), $sheetId, $sheetId, $stateAttr);
        }

        $xml[] = '</sheets>';

        // Emit definedNames from named ranges stored in any sheet
        $allNamedRanges = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet['namedRanges'] ?? [] as $nr) {
                $allNamedRanges[] = $nr;
            }
        }

        if ($allNamedRanges !== []) {
            $xml[] = '<definedNames>';
            foreach ($allNamedRanges as $nr) {
                // Convert SpreadsheetML formula (=Opsi!R2C1:R100C1) to A1 notation
                $a1Refers = $this->convertDefinedNameFormula($nr['refers_to']);
                $xml[] = sprintf('<definedName name="%s">%s</definedName>', $this->xml($nr['name']), $this->xml($a1Refers));
            }
            $xml[] = '</definedNames>';
        }

        $xml[] = '<calcPr calcId="0"/></workbook>';

        return implode('', $xml);
    }

    private function workbookRelsXml(int $sheetCount): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'];

        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml[] = sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>', $i, $i);
        }

        $xml[] = sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>', $sheetCount + 1);
        $xml[] = '</Relationships>';

        return implode('', $xml);
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $xml = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>',
        ];

        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml[] = sprintf('<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $i);
        }

        $xml[] = '</Types>';

        return implode('', $xml);
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function coreXml(): string
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>E-REPORT</dc:creator><cp:lastModifiedBy>E-REPORT</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified></cp:coreProperties>';
    }

    private function appXml(int $sheetCount): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>E-REPORT</Application><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . $sheetCount . '</vt:i4></vt:variant></vt:vector></HeadingPairs><TitlesOfParts><vt:vector size="0" baseType="lpstr"/></TitlesOfParts></Properties>';
    }

    private function excelDateSerial(string $value): float
    {
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0.0;
        }

        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));

        return ($date->getTimestamp() - $base->getTimestamp()) / 86400;
    }

    private function sanitizeSheetName(string $name): string
    {
        return mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name) ?: 'Sheet', 0, 31);
    }

    /**
     * Convert a SpreadsheetML sqref like "R5C4:R306C4" to A1 notation like "D5:D306".
     * Handles both R1C1 range pairs and named-range style references.
     */
    private function convertSqRef(string $sqref): string
    {
        // Try to match R1C1 range like R5C4:R306C4
        if (preg_match('/^R(\d+)C(\d+):R(\d+)C(\d+)$/', $sqref, $m)) {
            return $this->columnName((int) $m[2]) . $m[1] . ':' . $this->columnName((int) $m[4]) . $m[3];
        }
        // Single cell R1C1
        if (preg_match('/^R(\d+)C(\d+)$/', $sqref, $m)) {
            return $this->columnName((int) $m[2]) . $m[1];
        }
        // Already in A1 notation or unknown — return as-is
        return $sqref;
    }

    /**
     * Convert a SpreadsheetML DataValidation value like "=AccountOptions" to
     * an OOXML formula1 value like "AccountOptions" (drop leading "=").
     * If the value is a quoted list like "\"A,B,C\"", keep it.
     */
    private function convertNamedRangeFormula(string $value): string
    {
        // SpreadsheetML stores named range refs as "=NamedRange"
        if (str_starts_with($value, '=')) {
            return substr($value, 1);
        }
        return $value;
    }

    /**
     * Convert a SpreadsheetML NamedRange RefersTo like
     * "=Opsi!R2C1:R100C1" to OOXML A1 notation "Opsi!$A$2:$A$101".
     */
    private function convertDefinedNameFormula(string $refersTo): string
    {
        // Strip leading "="
        $formula = str_starts_with($refersTo, '=') ? substr($refersTo, 1) : $refersTo;

        // Match: SheetName!R(r1)C(c1):R(r2)C(c2)
        if (preg_match('/^([^!]+)!R(\d+)C(\d+):R(\d+)C(\d+)$/', $formula, $m)) {
            $sheet = $m[1];
            $r1 = (int) $m[2]; $c1 = (int) $m[3];
            $r2 = (int) $m[4]; $c2 = (int) $m[5];
            return sprintf("%s!\$%s\$%d:\$%s\$%d",
                $sheet,
                $this->columnName($c1), $r1,
                $this->columnName($c2), $r2
            );
        }

        // Match: SheetName!R(r1)C(c1) (single cell)
        if (preg_match('/^([^!]+)!R(\d+)C(\d+)$/', $formula, $m)) {
            $sheet = $m[1];
            $r1 = (int) $m[2]; $c1 = (int) $m[3];
            return sprintf("%s!\$%s\$%d", $sheet, $this->columnName($c1), $r1);
        }

        // Return as-is (might already be A1 or just a name)
        return $formula;
    }

    /**
     * Convert an R1C1 formula (from SpreadsheetML ss:Formula) to A1 notation
     * for use in OOXML <f> elements. The context cell (col, row) is used for
     * relative references like RC[2] or R[-1]C.
     */
    private function convertR1C1Formula(string $formula, int $contextCol, int $contextRow): string
    {
        // Strip leading "="
        if (str_starts_with($formula, '=')) {
            $formula = substr($formula, 1);
        }

        // Replace R1C1 references like R5C4, RC[2], R[-1]C, RC, etc.
        $formula = preg_replace_callback(
            '/R(\[(-?\d+)\]|(-?\d*))C(\[(-?\d+)\]|(-?\d*))/',
            function (array $m) use ($contextRow, $contextCol): string {
                // Row part
                $rowPart = $m[1];
                if ($rowPart === '') {
                    // RC... means current row (relative)
                    $row = $contextRow;
                    $rowAbs = false;
                } elseif (str_starts_with($rowPart, '[')) {
                    $row = $contextRow + (int) $m[2];
                    $rowAbs = false;
                } else {
                    $row = (int) $rowPart;
                    $rowAbs = $row > 0;
                }

                // Column part
                $colPart = $m[4];
                if ($colPart === '') {
                    $col = $contextCol;
                    $colAbs = false;
                } elseif (str_starts_with($colPart, '[')) {
                    $col = $contextCol + (int) $m[5];
                    $colAbs = false;
                } else {
                    $col = (int) $colPart;
                    $colAbs = $col > 0;
                }

                if ($row <= 0 || $col <= 0) {
                    return $m[0]; // can't convert, leave as-is
                }

                return ($colAbs ? '$' : '') . $this->columnName($col)
                    . ($rowAbs ? '$' : '') . $row;
            },
            $formula
        ) ?? $formula;

        return $formula;
    }

    private function columnName(int $columnNumber): string
    {
        $name = '';
        while ($columnNumber > 0) {
            $columnNumber--;
            $name = chr(65 + ($columnNumber % 26)) . $name;
            $columnNumber = intdiv($columnNumber, 26);
        }

        return $name;
    }

    private function normalizeColor(string $color): string
    {
        $color = strtoupper(trim($color));

        if (! preg_match('/^#[0-9A-F]{6}$/', $color)) {
            return '';
        }

        return 'FF' . substr($color, 1);
    }

    private function xlsxAlignment(string $value): string
    {
        return match (strtolower($value)) {
            'center' => 'center',
            'right' => 'right',
            default => 'left',
        };
    }

    private function xlsxVerticalAlignment(string $value): string
    {
        return match (strtolower($value)) {
            'top' => 'top',
            'bottom' => 'bottom',
            default => 'center',
        };
    }

    private function key(array $value): string
    {
        return md5(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
