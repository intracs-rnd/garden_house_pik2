<?php

namespace App\Support;

use ZipArchive;

/**
 * Minimal, dependency-free XLSX (Excel 2007+) writer.
 *
 * Produces a genuine .xlsx file (an Open XML spreadsheet) using only PHP's
 * built-in ZipArchive extension — no phpspreadsheet / maatwebsite required.
 *
 * Features intentionally kept small but sufficient for tabular reports:
 * - Multiple worksheets.
 * - Automatic string vs. number cell typing (inline strings, no shared table).
 * - Optional bold header row (first row) and bold "total" rows.
 *
 * Usage:
 *   $xlsx = new SimpleXlsxWriter();
 *   $xlsx->addSheet('Data', [['No', 'Nama'], [1, 'Budi']], ['headerRow' => true]);
 *   $binary = $xlsx->output();
 */
class SimpleXlsxWriter
{
    /** @var array<int, array{name:string, rows:array<int,array>, headerRow:bool, boldRows:array<int,bool>}> */
    private array $sheets = [];

    /**
     * Add a worksheet.
     *
     * @param  string  $name     Sheet tab name (auto-sanitised, max 31 chars).
     * @param  array   $rows     List of rows; each row is a list of cell values.
     * @param  array   $options  ['headerRow' => bool, 'boldRows' => [rowIndex => true]]
     */
    public function addSheet(string $name, array $rows, array $options = []): void
    {
        $this->sheets[] = [
            'name'      => $this->sanitizeSheetName($name),
            'rows'      => array_values($rows),
            'headerRow' => (bool) ($options['headerRow'] ?? false),
            'boldRows'  => (array) ($options['boldRows'] ?? []),
        ];
    }

    /**
     * Build the workbook and return the raw .xlsx binary content.
     */
    public function output(): string
    {
        if (empty($this->sheets)) {
            $this->addSheet('Sheet1', []);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->sheetXml($sheet));
        }

        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    /*
    |--------------------------------------------------------------------------
    | XML part builders
    |--------------------------------------------------------------------------
    */

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $index => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1)
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheetTags = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheetTags .= '<sheet name="' . $this->esc($sheet['name'])
                . '" sheetId="' . ($index + 1)
                . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetTags . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        $count = count($this->sheets);

        foreach ($this->sheets as $index => $sheet) {
            $rels .= '<Relationship Id="rId' . ($index + 1)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }

        // Styles relationship gets the next free id after the sheets.
        $rels .= '<Relationship Id="rId' . ($count + 1)
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    /**
     * Two cell formats: 0 = normal, 1 = bold.
     */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param  array{name:string, rows:array, headerRow:bool, boldRows:array}  $sheet
     */
    private function sheetXml(array $sheet): string
    {
        $rowsXml = '';

        foreach ($sheet['rows'] as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $bold = ($sheet['headerRow'] && $rowIndex === 0)
                || ! empty($sheet['boldRows'][$rowIndex]);

            $cellsXml = '';
            foreach (array_values((array) $cells) as $colIndex => $value) {
                $ref = $this->columnLetter($colIndex) . $rowNumber;
                $cellsXml .= $this->cellXml($ref, $value, $bold);
            }

            $rowsXml .= '<row r="' . $rowNumber . '">' . $cellsXml . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . '</worksheet>';
    }

    /**
     * Render a single cell, choosing a numeric or inline-string type.
     *
     * @param  mixed  $value
     */
    private function cellXml(string $ref, $value, bool $bold): string
    {
        $styleAttr = $bold ? ' s="1"' : '';

        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . $styleAttr . '/>';
        }

        // Treat genuine numbers as numeric cells (but keep leading-zero /
        // formatted strings, like card numbers, as text).
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"' . $styleAttr . '><v>' . $value . '</v></c>';
        }

        $text = $this->stripInvalidXmlChars((string) $value);

        return '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">'
            . $this->esc($text) . '</t></is></c>';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** Convert a 0-based column index into an Excel column letter (A, B, ..., AA). */
    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    private function esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Remove characters that are illegal in XML 1.0. */
    private function stripInvalidXmlChars(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/\\*\\?\\:\\[\\]]/', ' ', $name);
        $name = trim((string) $name);

        if ($name === '') {
            $name = 'Sheet';
        }

        return mb_substr($name, 0, 31);
    }
}
