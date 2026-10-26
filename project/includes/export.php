<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// =====================================================================
// export.php — shared Export-to-Excel / Export-to-PDF helpers
// ---------------------------------------------------------------------
// Used by every "Export" button across the system (Inventory, Purchase
// Orders, Suppliers, Finance, Dashboard/Analytics, etc.) so the file
// format, styling, and permission logging stay consistent everywhere
// instead of every page rolling its own.
//
// HOW TO USE (from any page that already knows the visitor is allowed
// to see the data — i.e. AFTER your existing requireXxx() role gate):
//
//   require_once 'includes/export.php';
//
//   if (isset($_GET['export'])) {
//       $headers = ['Product', 'Category', 'Stock', 'Price'];
//       $rows    = [
//           ['Rice 25kg', 'Grains', 40, 1250.00],
//           ...
//       ];
//       if ($_GET['export'] === 'xlsx') {
//           exportToExcel('inventory_report', $headers, $rows); // POST + csrfField()
//       } elseif ($_GET['export'] === 'pdf') {
//           exportToPDF('inventory_report', 'Inventory Report', $headers, $rows); // POST + csrfField()
//       }
//   }
// Use exportPostForm('page.php?filter=value&export=xlsx', 'Export Excel', 'button-class')
// in rendered markup; it preserves the filters in the URL and supplies a CSRF token.
//
// Both functions call exit() once the file has been streamed, so put
// the export branch BEFORE any other HTML output on the page.
//
// PERMISSIONS: this file does NOT do its own role-checking — it trusts
// that the calling page already ran its normal requireXxx() guard
// (requireInventoryStaff(), requireFinance(), requireSupplierManager(),
// etc.) before calling exportToExcel()/exportToPDF(). This is
// intentional: it means an export can never show different data, or be
// reachable by different roles, than the on-screen report already
// enforces — one gate, not two copies of the rule to keep in sync.
// Every export is also written to the audit log (logExport()) with the
// user, role, report name, and row count.
// =====================================================================

require_once __DIR__ . '/fpdf/fpdf.php';

function exportPostForm(string $actionUrl, string $label, string $buttonClass): string
{
    $parts = parse_url($actionUrl);
    if (!is_array($parts) || empty($parts['path']) || isset($parts['scheme']) || isset($parts['host'])
        || preg_match('/[\r\n]/', $actionUrl)) {
        throw new InvalidArgumentException('Export form action must be a local application path.');
    }
    parse_str($parts['query'] ?? '', $query);
    $format = $query['export'] ?? $query['export_po'] ?? $query['export_summary'] ?? $query['export_financial'] ?? null;
    if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
        throw new InvalidArgumentException('Unsupported export format.');
    }
    ob_start();
    csrfField();
    $csrf = ob_get_clean();
    $formAction = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return '<form class="export-post-form" method="post" style="display:inline;margin:0" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '">'
        . $csrf
        . '<button type="submit" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}

function requireExportPostCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Exports must be requested with a CSRF-protected POST form.');
    }
    requireCSRF();
}

// ---------------------------------------------------------------------
// Audit trail — who exported what, and how many rows. Same idea as the
// other *_history / audit tables already in this codebase; kept in its
// own small table so it doesn't collide with unrelated modules.
// ---------------------------------------------------------------------
function ensureExportLogTable($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS export_log (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NULL,
                username     VARCHAR(100) NULL,
                role         VARCHAR(30)  NULL,
                report_name  VARCHAR(100) NOT NULL,
                format       ENUM('xlsx','pdf','csv') NOT NULL,
                row_count    INT NOT NULL DEFAULT 0,
                ip_address   VARCHAR(45) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $formatType = $pdo->query("
            SELECT column_type
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'export_log'
              AND column_name = 'format'
        ")->fetchColumn();
        if (is_string($formatType) && strpos($formatType, "'csv'") === false) {
            $pdo->exec("ALTER TABLE export_log MODIFY format ENUM('xlsx','pdf','csv') NOT NULL");
        }
    } catch (PDOException $e) {
        error_log("ensureExportLogTable skipped: " . $e->getMessage());
    }
}

function logExport($reportName, $format, $rowCount) {
    global $pdo;
    if (!isset($pdo)) return;
    try {
        ensureExportLogTable($pdo);
        $pdo->prepare("
            INSERT INTO export_log (user_id, username, role, report_name, format, row_count, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            currentPosUserId(),
            $_SESSION['username'] ?? null,
            $_SESSION['role'] ?? null,
            $reportName,
            $format,
            $rowCount,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        error_log("logExport failed: " . $e->getMessage());
    }
}

function exportSafeFilename($name) {
    $name = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name);
    return trim($name, '_') . '_' . date('Y-m-d_His');
}

// =====================================================================
// EXCEL (.xlsx) EXPORT
// ---------------------------------------------------------------------
// Builds a real, minimal-but-valid .xlsx (Office Open XML) file using
// only ZipArchive + hand-written XML — no Composer/PhpSpreadsheet
// needed, so it works on a bare shared-hosting PHP install. Handles
// header-row bolding, auto column widths, and basic type detection
// (numbers vs text) so the exported sheet is usable for further Excel
// work, not just a flat printout.
// =====================================================================
/**
 * Small ZIP writer used when the XAMPP PHP build does not have ZipArchive.
 * XLSX is an XML document package, and the stored ZIP method is valid for
 * Office files while keeping this fallback dependency-free.
 */
class GroceryZipFallback {
    private $filename;
    private $files = [];

    public function __construct($filename) {
        $this->filename = $filename;
    }

    public function addEmptyDir($directory) {
        // Empty folders are optional in ZIP packages; the file entries create
        // the required package structure.
    }

    public function addFromString($name, $contents) {
        $this->files[$name] = (string) $contents;
    }

    public function close() {
        $body = '';
        $central = '';
        $offset = 0;

        foreach ($this->files as $name => $contents) {
            $name = str_replace('\\', '/', $name);
            $nameLength = strlen($name);
            $size = strlen($contents);
            $crc = (int) sprintf('%u', crc32($contents));

            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0);
            $body .= $name . $contents;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            );
            $central .= $name;
            $offset = strlen($body);
        }

        $centralOffset = strlen($body);
        $centralSize = strlen($central);
        $count = count($this->files);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0);

        return file_put_contents($this->filename, $body . $central . $end) !== false;
    }
}

function exportToExcel($reportName, array $headers, array $rows, array $meta = []) {
    requireExportPostCsrf();

    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            http_response_code(500);
            die('Excel export could not create the workbook file.');
        }
    } else {
        $zip = new GroceryZipFallback($tmpFile);
    }

    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('docProps');
    $zip->addEmptyDir('xl');
    $zip->addEmptyDir('xl/_rels');
    $zip->addEmptyDir('xl/worksheets');

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    // style 0 = default, style 1 = bold header, style 2 = number
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts>' .
        '<fills count="2"><fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF123C5C"/><bgColor indexed="64"/></patternFill></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="3">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' .
        '</cellXfs>' .
        '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>' .
        '</styleSheet>'
    );

    $zip->addFromString('docProps/core.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" ' .
        'xmlns:dc="http://purl.org/dc/elements/1.1/">' .
        '<dc:title>' . xlEsc($reportName) . '</dc:title>' .
        '<dc:creator>HCI GroceryOne</dc:creator>' .
        '<dcterms:created xmlns:dcterms="http://purl.org/dc/terms/" xsi:type="dcterms:W3CDTF" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . date('c') . '</dcterms:created>' .
        '</cp:coreProperties>'
    );

    $zip->addFromString('docProps/app.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">' .
        '<Application>HCI GroceryOne</Application></Properties>'
    );

    // ---- worksheet body ----
    $colCount = count($headers);
    $colLetters = [];
    for ($i = 0; $i < $colCount; $i++) $colLetters[] = xlColLetter($i);

    $sheet  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    // Optional meta lines (e.g. "Generated by: ...", "Date range: ...")
    // rendered as plain rows above the header row.
    $rowNum = 1;
    $colWidths = array_fill(0, $colCount, 10);

    $sheet .= '<sheetData>';

    foreach ($meta as $line) {
        $sheet .= '<row r="' . $rowNum . '"><c r="A' . $rowNum . '" t="inlineStr"><is><t>' . xlEsc($line) . '</t></is></c></row>';
        $colWidths[0] = max($colWidths[0], min(80, strlen($line)));
        $rowNum++;
    }
    if (!empty($meta)) $rowNum++; // blank spacer row

    // header row
    $sheet .= '<row r="' . $rowNum . '">';
    foreach ($headers as $i => $h) {
        $ref = $colLetters[$i] . $rowNum;
        $sheet .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . xlEsc($h) . '</t></is></c>';
        $colWidths[$i] = max($colWidths[$i], strlen((string) $h) + 2);
    }
    $sheet .= '</row>';
    $rowNum++;

    foreach ($rows as $row) {
        $sheet .= '<row r="' . $rowNum . '">';
        foreach ($row as $i => $val) {
            if (!isset($colLetters[$i])) continue;
            $ref = $colLetters[$i] . $rowNum;
            $colWidths[$i] = max($colWidths[$i], strlen((string) $val) + 2);
            if (is_numeric($val)) {
                $sheet .= '<c r="' . $ref . '" s="2"><v>' . $val . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . xlEsc((string) $val) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
        $rowNum++;
    }
    $sheet .= '</sheetData>';

    $sheet .= '<cols>';
    foreach ($colWidths as $i => $w) {
        $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, $w + 2) . '" customWidth="1"/>';
    }
    $sheet .= '</cols>';

    $sheet .= '</worksheet>';

    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    if ($zip->close() !== true && class_exists('ZipArchive')) {
        @unlink($tmpFile);
        http_response_code(500);
        die('Excel export could not finalize the workbook file.');
    }

    logExport($reportName, 'xlsx', count($rows));

    $filename = exportSafeFilename($reportName) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: max-age=0');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

function xlEsc($s) {
    return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlColLetter($index) {
    $letter = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = (int) (($index - $mod) / 26);
    }
    return $letter;
}

// =====================================================================
// PDF EXPORT
// ---------------------------------------------------------------------
// Uses the bundled FPDF library (includes/fpdf/) — pure PHP, no
// external dependencies. Renders a titled, paginated table with a
// repeating header row and footer (page number + generated-by/date),
// which is what every report in this system needs.
// =====================================================================
class GroceryReportPDF extends FPDF {
    public $reportTitle = '';
    public $generatedBy = '';
    public $colWidths   = [];
    public $tableHeaders = [];

    function Header() {
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(18, 60, 92); // brand navy
        $this->Cell(0, 8, $this->reportTitle, 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 5, 'Generated by: ' . $this->generatedBy . '   |   ' . date('F j, Y g:i A'), 0, 1, 'L');
        $this->Ln(3);

        $this->drawTableHeader();
    }

    function drawTableHeader() {
        if (!empty($this->tableHeaders)) {
            $this->SetFillColor(18, 60, 92);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', 8);
            $lineHeight = 4.2;
            $lineCounts = [];
            $maxLines = 1;
            foreach ($this->tableHeaders as $i => $h) {
                $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $h);
                $width = max(1, ($this->colWidths[$i] ?? 1) - 4);
                $lines = 1;
                $line = '';
                foreach (preg_split('/\s+/', trim($text)) as $word) {
                    if ($word === '') continue;
                    if ($line !== '' && $this->GetStringWidth($line . ' ' . $word) <= $width) {
                        $line .= ' ' . $word;
                    } else {
                        if ($line !== '') $lines++;
                        $line = $word;
                    }
                }
                $lineCounts[$i] = $lines;
                $maxLines = max($maxLines, $lines);
            }
            $headerHeight = max(8, $maxLines * $lineHeight + 3);
            $startX = $this->GetX();
            $startY = $this->GetY();
            foreach ($this->tableHeaders as $i => $h) {
                $width = $this->colWidths[$i] ?? 1;
                $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $h);
                $x = $startX + array_sum(array_slice($this->colWidths, 0, $i));
                $this->Rect($x, $startY, $width, $headerHeight, 'DF');
                $textHeight = $lineCounts[$i] * $lineHeight;
                $this->SetXY($x + 1, $startY + max(1, ($headerHeight - $textHeight) / 2));
                $this->MultiCell($width - 2, $lineHeight, $text, 0, 'C');
            }
            $this->SetXY($startX, $startY + $headerHeight);
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Helvetica', '', 8);
        }
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 8, 'Page ' . $this->PageNo() . ' of {nb}   |   HCI GroceryOne — Confidential', 0, 0, 'C');
    }
}

function exportToPDF($reportName, $title, array $headers, array $rows, array $meta = []) {
    requireExportPostCsrf();
    $pdf = new GroceryReportPDF('L', 'mm', 'A4'); // Landscape fits wide tables better
    $pdf->AliasNbPages();
    $pdf->reportTitle = trim($title, ' —-');
    $pdf->generatedBy = ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown user') . ' (' . ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? '')) . ')';
    $pdf->tableHeaders = [];

    $pdf->SetFont('Helvetica', '', 8);
    $pageWidth = $pdf->GetPageWidth() - 20; // 10mm margins each side
    $weights = [];
    foreach ($headers as $i => $header) {
        $maxWidth = $pdf->GetStringWidth(iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $header)) + 5;
        foreach ($rows as $row) {
            if (!array_key_exists($i, $row)) continue;
            $cellWidth = $pdf->GetStringWidth(iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $row[$i])) + 5;
            $maxWidth = max($maxWidth, $cellWidth);
        }
        $weights[] = min(58, max(16, $maxWidth));
    }
    $totalWeight = array_sum($weights);
    $colWidths = [];
    foreach ($weights as $w) $colWidths[] = ($w / $totalWeight) * $pageWidth;
    $pdf->colWidths = $colWidths;

    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    if (!empty($meta)) {
        $pdf->SetFont('Helvetica', '', 8);
        foreach ($meta as $line) {
            $pdf->MultiCell(0, 5, iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $line), 0, 'L');
        }
        $pdf->Ln(2);
    }
    $pdf->tableHeaders = $headers;
    $pdf->drawTableHeader();

    $pdf->SetFont('Helvetica', '', 8);
    $lineHeight = 4.5;
    $wrapLines = static function ($text, $width) use ($pdf): int {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') return 1;

        $lineCount = 1;
        $line = '';
        foreach (explode(' ', $text) as $word) {
            if ($line !== '' && $pdf->GetStringWidth($line . ' ' . $word) <= $width - 4) {
                $line .= ' ' . $word;
                continue;
            }
            if ($line !== '') {
                $lineCount++;
                $line = '';
            }
            if ($pdf->GetStringWidth($word) <= $width - 4) {
                $line = $word;
                continue;
            }
            $chunk = '';
            foreach (str_split($word) as $character) {
                if ($chunk !== '' && $pdf->GetStringWidth($chunk . $character) > $width - 4) {
                    $lineCount++;
                    $chunk = '';
                }
                $chunk .= $character;
            }
            $line = $chunk;
        }
        return $lineCount;
    };
    foreach ($rows as $row) {
        $cellTexts = [];
        $rowLines = 1;
        foreach ($headers as $i => $header) {
            $rawText = (string) ($row[$i] ?? '');
            $cellTexts[$i] = iconv('UTF-8', 'Windows-1252//TRANSLIT', $rawText);
            $rowLines = max($rowLines, $wrapLines($cellTexts[$i], $colWidths[$i]));
        }
        $rowHeight = $rowLines * $lineHeight + 2;
        if ($pdf->GetY() + $rowHeight > $pdf->GetPageHeight() - 15) {
            $pdf->AddPage();
        }
        $rowY = $pdf->GetY();
        foreach ($headers as $i => $header) {
            $x = $pdf->GetX();
            $pdf->Rect($x, $rowY, $colWidths[$i], $rowHeight);
            $pdf->SetXY($x + 1, $rowY + 1);
            $alignment = is_numeric($row[$i] ?? null) ? 'R' : 'L';
            $pdf->MultiCell($colWidths[$i] - 2, $lineHeight, $cellTexts[$i], 0, $alignment);
            $pdf->SetXY($x + $colWidths[$i], $rowY);
        }
        $pdf->SetXY(10, $rowY + $rowHeight);
    }

    if (empty($rows)) {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->Cell(array_sum($colWidths), 10, 'No records to display.', 1, 1, 'C');
    }

    logExport($reportName, 'pdf', count($rows));

    $filename = exportSafeFilename($reportName) . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}
