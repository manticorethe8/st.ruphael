<?php
/* ============================================================
   lib/xlsx.php — dependency-free .xlsx writer (SpreadsheetML)
   ------------------------------------------------------------
   WHY THIS EXISTS
   The school server has no Composer and no PhpSpreadsheet, so
   exports are built by hand. Only ZipArchive + string building
   are used. UTF-8 throughout, so Amharic text and Ge'ez
   numerals survive into Excel intact.

   WHAT AN .XLSX ACTUALLY IS
   A ZIP archive of XML parts. The minimum Excel will open:

     [Content_Types].xml   MIME type of every part (Excel
                           refuses the file if one is missing)
     _rels/.rels           points at the workbook
     xl/workbook.xml       lists the sheets
     xl/_rels/workbook.xml.rels
                           maps sheet name -> sheet XML file
     xl/styles.xml         cell formats; index 1 = our bold header
     xl/sharedStrings.xml  de-duplicated string pool
     xl/worksheets/sheetN.xml
                           the cells themselves

   THE SHARED-STRINGS TRICK
   Text cells do not hold text. They hold t="s" plus an integer
   index into sharedStrings.xml. Every distinct string is stored
   once, which is what keeps a 3,000-row export small. Numbers
   are written inline instead (no t attribute) so Excel treats
   them as numbers, not text — see isNumeric().

   USAGE
     $x = new SrXlsx();
     $x->sheet('Students', $rows, [8, 30, 14]);  // widths optional
     $x->save('/path/to/file.xlsx');
   Row 1 of each sheet is styled as a bold header automatically.

   EXTENDING
   To add a cell format, append an <xf> to styles() and use its
   zero-based position as the cell's s="" index.
   ============================================================ */

final class SrXlsx
{
    private array $sheets = [];   // [ ['name'=>, 'rows'=>[[cell,...]], 'widths'=>[]] ]

    public function sheet(string $name, array $rows, array $widths = []): void
    {
        /* Excel sheet names: max 31 chars, no : \ / ? * [ ] */
        $name = preg_replace('/[:\\\\\/\?\*\[\]]/u', '-', $name);
        $name = mb_substr($name === '' ? 'Sheet' : $name, 0, 31, 'UTF-8');
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths];
    }

    public function save(string $path): string
    {
        if (!$this->sheets) $this->sheet('Sheet1', []);

        /* ---- shared strings table ---- */
        $si = [];      // string => index
        $siList = [];
        foreach ($this->sheets as $sh) {
            foreach ($sh['rows'] as $row) {
                foreach ($row as $v) {
                    if ($this->isNumeric($v)) continue;
                    $s = (string)$v;
                    if (!isset($si[$s])) { $si[$s] = count($siList); $siList[] = $s; }
                }
            }
        }

        $zip = new ZipArchive();
        @unlink($path);
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create the Excel file at ' . $path);
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
          . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings($siList));

        foreach ($this->sheets as $i => $sh) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sh, $si));
        }
        $zip->close();
        return $path;
    }

    /* ------------------------------------------------------------ */

    /* True only for values Excel should store as a number. Deliberately
       strict: anything with a leading zero, a plus sign, spaces or a letter
       stays text, so student codes like 18-0042 and phone numbers
       such as 0911... are not silently mangled into 911. */
    private function isNumeric($v): bool
    {
        if (is_int($v) || is_float($v)) return true;
        if (!is_string($v) || $v === '') return false;
        /* keep leading-zero codes (18-0042, 007) as text */
        return (bool)preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $v);
    }

    private function esc(string $s): string
    {
        /* strip control chars Excel rejects */
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /* 0 -> A, 25 -> Z, 26 -> AA. Excel's bijective base-26 column names;
       note there is no "zero" digit, hence the intdiv($n - 1, 26). */
    private function colName(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)) . $s;
        }
        return $s;
    }

    /* Build one worksheet part. $si is the shared-string index built in
       save(). Row 1 gets style s="1" (the maroon bold header), is frozen so
       it stays visible while scrolling, and carries the autofilter. */
    private function sheetXml(array $sh, array $si): string
    {
        $cols = '';
        if ($sh['widths']) {
            $cols = '<cols>';
            foreach ($sh['widths'] as $i => $w) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $xml = '';
        foreach ($sh['rows'] as $r => $row) {
            $rn = $r + 1;
            $xml .= '<row r="' . $rn . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = $this->colName($c) . $rn;
                $style = $r === 0 ? ' s="1"' : '';
                if ($v === null || $v === '') { $xml .= '<c r="' . $ref . '"' . $style . '/>'; continue; }
                if ($this->isNumeric($v)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="s"><v>' . $si[(string)$v] . '</v></c>';
                }
            }
            $xml .= '</row>';
        }

        $lastRef = $this->colName(max(0, count($sh['rows'] ? $sh['rows'][0] : []) - 1)) . max(1, count($sh['rows']));
        $freeze = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<dimension ref="A1:' . $lastRef . '"/>' . $freeze . $cols
             . '<sheetData>' . $xml . '</sheetData>'
             . '<autoFilter ref="A1:' . $lastRef . '"/>'
             . '</worksheet>';
    }

    /* The string pool. xml:space="preserve" matters: without it Excel
       trims leading/trailing spaces out of cell values. */
    private function sharedStrings(array $list): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($list) . '" uniqueCount="' . count($list) . '">';
        foreach ($list as $s) $x .= '<si><t xml:space="preserve">' . $this->esc($s) . '</t></si>';
        return $x . '</sst>';
    }

    private function workbook(): string
    {
        $s = '';
        foreach ($this->sheets as $i => $sh) {
            $s .= '<sheet name="' . $this->esc($sh['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
             . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets>' . $s . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $s = '';
        $n = count($this->sheets);
        foreach ($this->sheets as $i => $sh) {
            $s .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $s .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $s .= '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $s . '</Relationships>';
    }

    /* Two cell formats only. Index 0 = default body cell; index 1 = the
       header (white bold Calibri on the school maroon FF7A1F2B). Add new
       formats by appending an <xf> to cellXfs and bumping its count. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<fonts count="2">'
             . '<font><sz val="11"/><name val="Calibri"/></font>'
             . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
             . '</fonts>'
             . '<fills count="3">'
             . '<fill><patternFill patternType="none"/></fill>'
             . '<fill><patternFill patternType="gray125"/></fill>'
             . '<fill><patternFill patternType="solid"><fgColor rgb="FF7A1F2B"/><bgColor indexed="64"/></patternFill></fill>'
             . '</fills>'
             . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="2">'
             . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
             . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
             . '</cellXfs>'
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    /* Every part needs a declared content type or Excel calls the file
       corrupt and refuses to open it — the most common cause of a
       hand-built xlsx failing is a sheet missing from this list. */
    private function contentTypes(): string
    {
        $s = '';
        foreach ($this->sheets as $i => $sh) {
            $s .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . $s
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
             . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
             . '</Types>';
    }
}

/* ------------------------------------------------------------------
   CSV with a UTF-8 BOM so Excel on Windows opens Amharic correctly
   ------------------------------------------------------------------ */
function sr_csv_string(array $rows, string $delimiter = ','): string
{
    $fh = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        $clean = [];
        foreach ($row as $v) {
            $v = (string)$v;
            /* neutralise spreadsheet formula injection */
            if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
            $clean[] = $v;
        }
        fputcsv($fh, $clean, $delimiter, '"', '\\');
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF" . $out;
}
