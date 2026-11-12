<?php
/* Capture any stray output (spaces, warnings) so a download is never contaminated */
ob_start();

session_start();
require_once '../db/connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* =========================================================
   NOTIFICATIONS
========================================================= */
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();


/* =========================================================
   TABLES AVAILABLE FOR BACKUP
========================================================= */
const BK_TABLES = [
    'students'     => 'Students',
    'violations'   => 'Violations',
    'disciplinary' => 'Disciplinary Actions',
    'risk'         => 'Risk Levels',
];

function bkRiskLabel($t)
{
    $t = (int)$t;
    return $t >= 5 ? 'Critical' : ($t >= 4 ? 'High' : ($t >= 2 ? 'Moderate' : 'Low'));
}

/* One place that defines what each exported sheet contains */
function bkSheet(PDO $pdo, string $key)
{
    switch ($key) {

        case 'students':
            return [
                'title'   => 'Students',
                'headers' => ['Student ID', 'Full Name', 'Course', 'Year Level', 'Department'],
                'rows'    => $pdo->query("
                    SELECT s.student_id, s.full_name, c.name, s.year_level, d.name
                    FROM students s
                    JOIN courses c ON s.course_id = c.id
                    JOIN departments d ON s.department_id = d.id
                    ORDER BY s.student_id
                ")->fetchAll(PDO::FETCH_NUM),
            ];

        case 'violations':
            return [
                'title'   => 'Violations',
                'headers' => ['ID', 'Student ID', 'Full Name', 'Category', 'Violation', 'Description', 'Status', 'Date Submitted'],
                'rows'    => $pdo->query("
                    SELECT v.id, v.student_id, s.full_name, v.category, v.violation, v.description, v.status, v.date_submitted
                    FROM violations v
                    JOIN students s ON v.student_id = s.student_id
                    ORDER BY v.date_submitted DESC
                ")->fetchAll(PDO::FETCH_NUM),
            ];

        case 'disciplinary':
            return [
                'title'   => 'Disciplinary Actions',
                'headers' => ['ID', 'Student ID', 'Full Name', 'Violation', 'Sanction', 'Status', 'Start Date', 'End Date'],
                'rows'    => $pdo->query("
                    SELECT da.id, da.student_id, s.full_name, v.violation, da.sanction, da.status, da.start_date, da.end_date
                    FROM disciplinary_actions da
                    JOIN students s ON da.student_id = s.student_id
                    JOIN violations v ON da.violation_id = v.id
                    ORDER BY da.id DESC
                ")->fetchAll(PDO::FETCH_NUM),
            ];

        case 'risk':
            $raw = $pdo->query("
                SELECT s.student_id, s.full_name,
                    SUM(CASE WHEN v.category = 'minor' THEN 1 ELSE 0 END) AS minor_c,
                    SUM(CASE WHEN v.category = 'major' THEN 1 ELSE 0 END) AS major_c,
                    COUNT(v.id) AS total
                FROM students s
                LEFT JOIN violations v ON s.student_id = v.student_id
                GROUP BY s.student_id, s.full_name
                ORDER BY total DESC
            ")->fetchAll();

            $rows = [];
            foreach ($raw as $r) {
                $rows[] = [$r['student_id'], $r['full_name'], $r['minor_c'], $r['major_c'], (int)$r['total'], bkRiskLabel($r['total'])];
            }

            return [
                'title'   => 'Risk Levels',
                'headers' => ['Student ID', 'Full Name', 'Minor Count', 'Major Count', 'Total', 'Risk Level'],
                'rows'    => $rows,
            ];
    }

    return null;
}


/* =========================================================
   EXPORT HELPERS
========================================================= */
/* Work with or without the mbstring extension */
function bkStrlen($s)
{
    return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s);
}

function bkSubstr($s, $start, $len)
{
    return function_exists('mb_substr') ? mb_substr((string)$s, $start, $len, 'UTF-8') : substr((string)$s, $start, $len);
}

/* Minimal ZIP writer: no ZipArchive extension needed */
function bkZip(array $files): string
{
    $out = '';
    $central = '';
    $offset = 0;
    $count = 0;

    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

    foreach ($files as $name => $content) {

        $size = strlen($content);
        $crc  = crc32($content);

        $method = 0;
        $body   = $content;

        if (function_exists('gzdeflate')) {
            $comp = gzdeflate($content, 6);
            if ($comp !== false && strlen($comp) < $size) {
                $method = 8;
                $body   = $comp;
            }
        }

        $csize = strlen($body);
        $nlen  = strlen($name);

        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate, $crc, $csize, $size, $nlen, 0) . $name;

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate, $crc, $csize, $size, $nlen, 0, 0, 0, 0, 0, $offset) . $name;

        $out    .= $local . $body;
        $offset += strlen($local) + $csize;
        $count++;
    }

    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);

    return $out . $central . $end;
}

function bkColLetter(int $n): string
{
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

/* Real numbers only: keeps IDs like "007" as text */
function bkIsNumber($v): bool
{
    if (is_int($v) || is_float($v)) return true;
    return is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v) === 1;
}

/* Remove characters that are not allowed inside XML */
function bkXml($v): string
{
    $v = (string)($v ?? '');
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function bkSheetName(string $name, array $used): string
{
    $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));
    $name = bkSubstr($name === '' ? 'Sheet' : $name, 0, 31);
    $base = $name;
    $i = 2;
    while (in_array($name, $used, true)) {
        $name = bkSubstr($base, 0, 28) . ' ' . $i++;
    }
    return $name;
}


/* =========================================================
   EXCEL EXPORT (styled header, frozen row, filters, widths)
========================================================= */
function excelExport($filename, $sheets)
{
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

    $sheetXmls = [];
    $sheetTags = [];
    $overrides = [];
    $names     = [];
    $filters   = [];

    foreach ($sheets as $i => $sheet) {

        $sheetId  = $i + 1;
        $headers  = $sheet['headers'];
        $rows     = $sheet['rows'];
        $colCount = count($headers);
        $lastCol  = bkColLetter(max(1, $colCount));
        $lastRow  = count($rows) + 1;

        $title = bkSheetName($sheet['title'], $names);
        $names[] = $title;

        /* Column widths from the data */
        $widths = [];
        foreach ($headers as $c => $h) {
            $widths[$c] = bkStrlen((string)$h);
        }
        foreach (array_slice($rows, 0, 300) as $row) {
            foreach ($row as $c => $cell) {
                $len = bkStrlen((string)($cell ?? ''));
                if (!isset($widths[$c]) || $len > $widths[$c]) $widths[$c] = $len;
            }
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        /* Freeze header row */
        $xml .= '<sheetViews><sheetView workbookViewId="0"' . ($i === 0 ? ' tabSelected="1"' : '') . '>';
        $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        $xml .= '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
        $xml .= '</sheetView></sheetViews>';

        /* Column widths */
        $xml .= '<cols>';
        foreach ($headers as $c => $_) {
            $w = min(60, max(12, ($widths[$c] ?? 10) + 3));
            $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        /* Header row */
        $xml .= '<row r="1" ht="22" customHeight="1">';
        foreach ($headers as $c => $h) {
            $xml .= '<c r="' . bkColLetter($c + 1) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">' . bkXml($h) . '</t></is></c>';
        }
        $xml .= '</row>';

        /* Data rows */
        foreach ($rows as $r => $row) {

            $rowNum = $r + 2;
            $xml .= '<row r="' . $rowNum . '">';

            foreach ($row as $c => $cell) {

                $ref = bkColLetter($c + 1) . $rowNum;

                if ($cell === null || $cell === '') {
                    continue;
                }

                if (bkIsNumber($cell)) {
                    $xml .= '<c r="' . $ref . '"><v>' . bkXml($cell) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . bkXml($cell) . '</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        $xml .= '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>';
        $xml .= '</worksheet>';

        $sheetXmls[$sheetId] = $xml;

        $sheetTags[] = '<sheet name="' . bkXml($title) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';

        $filters[] = '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
            . bkXml(str_replace("'", "''", $title)) . '\'!$A$1:$' . $lastCol . '$' . $lastRow . '</definedName>';

        $overrides[] = '<Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $stylesId = count($sheets) + 1;

    /* Workbook */
    $wbXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $wbXml .= '<sheets>' . implode('', $sheetTags) . '</sheets>';
    $wbXml .= '<definedNames>' . implode('', $filters) . '</definedNames>';
    $wbXml .= '</workbook>';

    /* Workbook relationships */
    $wbRels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheetXmls as $id => $_) {
        $wbRels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
    }
    $wbRels .= '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wbRels .= '</Relationships>';

    /* Styles: 0 = normal, 1 = navy header with white bold text */
    $styles  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $styles .= '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>';
    $styles .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>';
    $styles .= '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
    $styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF091B4B"/><bgColor indexed="64"/></patternFill></fill></fills>';
    $styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $styles .= '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
    $styles .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf></cellXfs>';
    $styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
    $styles .= '</styleSheet>';

    /* Content types */
    $ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $ct .= '<Default Extension="xml" ContentType="application/xml"/>';
    $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $ct .= implode('', $overrides);
    $ct .= '</Types>';

    /* Root relationships */
    $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $rels .= '</Relationships>';

    /* Build the whole file in memory first */
    $parts = [
        '[Content_Types].xml'        => $ct,
        '_rels/.rels'                => $rels,
        'xl/workbook.xml'            => $wbXml,
        'xl/_rels/workbook.xml.rels' => $wbRels,
        'xl/styles.xml'              => $styles,
    ];
    foreach ($sheetXmls as $id => $xml) {
        $parts['xl/worksheets/sheet' . $id . '.xml'] = $xml;
    }

    $binary = bkZip($parts);

    /* Throw away any stray output (warnings, spaces) so the download is a clean file */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    header('Content-Length: ' . strlen($binary));
    echo $binary;

    exit;
}


/* =========================================================
   CSV EXPORT (one table, opens fine in Excel)
========================================================= */
function csvExport($filename, $sheet)
{
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

    /* Build the whole CSV in memory first */
    $mem = fopen('php://memory', 'w+');
    fputcsv($mem, $sheet['headers']);
    foreach ($sheet['rows'] as $row) {
        fputcsv($mem, array_map(fn($v) => $v ?? '', $row));
    }
    rewind($mem);
    $csv = "\xEF\xBB\xBF" . stream_get_contents($mem);   // UTF-8 BOM so Excel reads accents correctly
    fclose($mem);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . strlen($csv));

    echo $csv;
    exit;
}


/* =========================================================
   EXPORT REQUEST
   ?export=students | violations | disciplinary | risk | all
   ?export=custom&tables=students,violations
   add &format=csv for a single table as CSV
========================================================= */
if (isset($_GET['export'])) {

    ini_set('display_errors', '0');

    $type   = (string)$_GET['export'];
    $date   = date('Ymd_His');
    $format = (($_GET['format'] ?? 'xlsx') === 'csv') ? 'csv' : 'xlsx';

    if ($type === 'all') {

        $keys = array_keys(BK_TABLES);
        $name = 'full_export_' . $date;

    } elseif ($type === 'custom') {

        $req  = explode(',', (string)($_GET['tables'] ?? ''));
        $keys = array_values(array_unique(array_filter($req, fn($k) => isset(BK_TABLES[$k]))));
        $name = 'backup_selected_' . $date;

    } elseif (isset(BK_TABLES[$type])) {

        $keys = [$type];
        $name = $type . '_export_' . $date;

    } else {
        $keys = [];
    }

    if (!$keys) {
        header('Location: data-backup.php');
        exit;
    }

    $sheets = array_map(fn($k) => bkSheet($pdo, $k), $keys);

    if ($format === 'csv' && count($sheets) === 1) {
        csvExport($name, $sheets[0]);
    }

    excelExport($name, $sheets);
}


/* =========================================================
   COUNTS
========================================================= */
$studentCount      = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$violationCount    = (int)$pdo->query("SELECT COUNT(*) FROM violations")->fetchColumn();
$disciplinaryCount = (int)$pdo->query("SELECT COUNT(*) FROM disciplinary_actions")->fetchColumn();
$riskCount         = $studentCount;
$totalRecords      = $studentCount + $violationCount + $disciplinaryCount;
$allRecords        = $studentCount + $violationCount + $disciplinaryCount + $riskCount;

const BK_PREVIEW = 10;

/* =========================================================
   PREVIEW DATA
========================================================= */
$previewStudents = $pdo->query("
    SELECT s.student_id, s.full_name, c.name AS cname, s.year_level, d.name AS dname
    FROM students s
    JOIN courses c ON s.course_id = c.id
    JOIN departments d ON s.department_id = d.id
    ORDER BY s.student_id
    LIMIT " . BK_PREVIEW)->fetchAll();

$previewViolations = $pdo->query("
    SELECT v.student_id, s.full_name, v.category, v.violation, v.status, v.date_submitted
    FROM violations v
    JOIN students s ON v.student_id = s.student_id
    ORDER BY v.date_submitted DESC
    LIMIT " . BK_PREVIEW)->fetchAll();

$previewDisciplinary = $pdo->query("
    SELECT da.student_id, s.full_name, v.violation, da.sanction, da.status
    FROM disciplinary_actions da
    JOIN students s ON da.student_id = s.student_id
    JOIN violations v ON da.violation_id = v.id
    ORDER BY da.id DESC
    LIMIT " . BK_PREVIEW)->fetchAll();

$previewRisk = $pdo->query("
    SELECT s.student_id, s.full_name,
        SUM(CASE WHEN v.category = 'minor' THEN 1 ELSE 0 END) AS minor_c,
        SUM(CASE WHEN v.category = 'major' THEN 1 ELSE 0 END) AS major_c,
        COUNT(v.id) AS total
    FROM students s
    LEFT JOIN violations v ON s.student_id = v.student_id
    GROUP BY s.student_id, s.full_name
    ORDER BY total DESC
    LIMIT " . BK_PREVIEW)->fetchAll();

?>
<?php require_once 'theme-head.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSDV | Data Backup</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- Page CSS (sidebar, navbar, notifications, preview tables) -->
    <link rel="stylesheet" href="../assets/css/admin_data_backup.css">

    <style>
        /* All colors below come from the theme variables used across the admin panel,
           so they change with the theme chosen in Theme Settings (including dark mode).
           The value after each comma is only a fallback. */

        /* ── Page header ── */
        .bk-head { display:flex; align-items:center; justify-content:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
        .bk-head-actions { display:flex; gap:10px; flex-wrap:wrap; }

        .bk-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:8px; font-size:13px; font-weight:500;
            font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; border:1px solid; line-height:1.2; }
        .bk-btn.primary { background:var(--accent,#2563eb); color:#fff; border-color:var(--accent,#2563eb); }
        .bk-btn.primary:hover { filter:brightness(.9); color:#fff; }
        .bk-btn.ghost { background:var(--surface,#fff); color:var(--text-2,#6b7280); border-color:var(--border,#eaecf0); }
        .bk-btn.ghost:hover { background:var(--bg,#f6f7f9); color:var(--text,#111827); }
        .bk-btn.sm { padding:7px 12px; font-size:12px; }

        /* ── Stats ── */
        .bk-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
        .bk-stat { background:var(--surface,#fff); border:1px solid var(--border,#eaecf0); border-radius:12px; padding:16px 18px; display:flex; align-items:center; gap:14px; }
        .bk-stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
        .bk-stat-num { font-size:22px; font-weight:600; line-height:1.1; color:var(--text,#111827); }
        .bk-stat-lbl { font-size:12px; color:var(--text-3,#9ca3af); margin-top:2px; }

        /* Icon chips follow the theme accent */
        .c-blue, .c-red, .c-amber, .c-teal, .c-navy {
            background:var(--accent-light, color-mix(in srgb, var(--accent,#2563eb) 14%, transparent));
            color:var(--accent,#2563eb);
        }

        /* ── Toolbar ── */
        .bk-toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:12px; flex-wrap:wrap; }
        .bk-selectall { display:flex; align-items:center; gap:9px; font-size:13px; color:var(--text-2,#6b7280); cursor:pointer; user-select:none; }
        .bk-toolbar-hint { font-size:12px; color:var(--text-3,#9ca3af); }
        .bk-cb { width:17px; height:17px; accent-color:var(--accent,#2563eb); cursor:pointer; flex-shrink:0; }

        /* ── Table cards ── */
        .bk-card { background:var(--surface,#fff); border:1px solid var(--border,#eaecf0); border-radius:12px; margin-bottom:14px; overflow:hidden; transition:border-color .15s, box-shadow .15s; }
        .bk-card.selected { border-color:color-mix(in srgb, var(--accent,#2563eb) 55%, transparent); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent,#2563eb) 12%, transparent); }
        .bk-card-head { display:flex; align-items:center; gap:14px; padding:14px 18px; }

        .bk-toggle { flex:1; min-width:0; display:flex; align-items:center; gap:14px; background:none; border:none; padding:0; text-align:left; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; color:inherit; }
        .bk-toggle:focus-visible { outline:2px solid var(--accent,#2563eb); outline-offset:4px; border-radius:8px; }
        .bk-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
        .bk-meta { min-width:0; flex:1; }
        .bk-title { font-size:14.5px; font-weight:600; color:var(--text,#111827); display:flex; align-items:center; gap:8px; }
        .bk-toggle:hover .bk-title { color:var(--accent,#2563eb); }
        .bk-pill { font-size:11px; font-weight:600; background:var(--bg,#f4f5f7); color:var(--text-2,#6b7280); padding:2px 9px; border-radius:20px; }
        .bk-desc { font-size:12px; color:var(--text-3,#9ca3af); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .bk-chev { font-size:11px; color:var(--text-3,#9ca3af); transition:transform .2s ease; margin-left:4px; }
        .bk-toggle[aria-expanded="true"] .bk-chev { transform:rotate(180deg); }
        .bk-hint { font-size:11.5px; color:var(--text-3,#9ca3af); white-space:nowrap; }
        .bk-toggle[aria-expanded="true"] .bk-hint { display:none; }

        .bk-actions { display:flex; gap:8px; flex-shrink:0; }

        /* ── Preview body ── */
        .bk-body { border-top:1px solid var(--border-soft,#f2f4f7); background:var(--bg,#fcfcfd); padding:14px 18px 16px; }
        .bk-filter-row { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        .bk-filter { position:relative; }
        .bk-filter i { position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:11px; color:var(--text-3,#9ca3af); }
        .bk-filter input { height:34px; width:260px; max-width:100%; padding:0 12px 0 30px; border:1px solid var(--border,#eaecf0); border-radius:8px; font-size:12.5px;
            font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:var(--surface,#fff); color:var(--text,#111827); }
        .bk-filter input::placeholder { color:var(--text-3,#9ca3af); }
        .bk-filter input:focus { border-color:var(--accent,#2563eb); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent,#2563eb) 15%, transparent); }
        .bk-count { font-size:12px; color:var(--text-3,#9ca3af); }
        .bk-scroll { overflow-x:auto; border:1px solid var(--border,#eaecf0); border-radius:10px; background:var(--surface,#fff); }
        .bk-scroll .data-table { width:100%; margin:0; }
        .bk-nomatch td { text-align:center; padding:22px; color:var(--text-3,#9ca3af); font-size:12.5px; }
        .bk-foot { font-size:12px; color:var(--text-3,#9ca3af); margin-top:10px; }

        /* ── Selection bar ── */
        .bk-bar-wrap { position:fixed; left:260px; right:0; bottom:22px; display:flex; justify-content:center; pointer-events:none; z-index:900; }
        .bk-bar { pointer-events:auto; background:var(--sidebar,#091b4b); color:#fff; border-radius:14px; padding:10px 12px 10px 20px; display:flex; align-items:center; gap:18px;
            box-shadow:0 12px 36px rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.08); transform:translateY(140%); opacity:0; transition:transform .25s ease, opacity .25s ease; }
        .bk-bar.show { transform:translateY(0); opacity:1; }
        .bk-bar-text { font-size:13px; }
        .bk-bar-text strong { font-weight:600; }
        .bk-bar-sub { color:rgba(255,255,255,.6); font-size:12px; margin-left:6px; }
        .bk-bar .bk-btn.clear { background:transparent; color:rgba(255,255,255,.8); border-color:rgba(255,255,255,.22); }
        .bk-bar .bk-btn.clear:hover { background:rgba(255,255,255,.1); color:#fff; }
        .bk-bar .bk-btn.go { background:var(--sidebar-red,#c8322b); border-color:var(--sidebar-red,#c8322b); color:#fff; }
        .bk-bar .bk-btn.go:hover { filter:brightness(.9); }

        /* ── Backup loading ── */
        .bk-overlay { position:fixed; inset:0; background:rgba(9,27,75,.45); backdrop-filter:blur(2px); display:flex; align-items:center; justify-content:center;
            opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:1200; }
        .bk-overlay.show { opacity:1; pointer-events:auto; }
        .bk-modal { width:min(420px, calc(100vw - 32px)); background:var(--surface,#fff); border:1px solid var(--border,#eaecf0); border-radius:16px;
            padding:26px 28px 22px; text-align:center; box-shadow:0 24px 64px rgba(0,0,0,.3); transform:translateY(8px) scale(.98); transition:transform .2s ease; }
        .bk-overlay.show .bk-modal { transform:none; }
        .bk-modal-icon { width:52px; height:52px; border-radius:50%; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; font-size:20px;
            background:var(--accent-light, color-mix(in srgb, var(--accent,#2563eb) 14%, transparent)); color:var(--accent,#2563eb); }
        .bk-modal-title { font-size:16px; font-weight:600; color:var(--text,#111827); }
        .bk-modal-sub { font-size:12.5px; color:var(--text-3,#9ca3af); margin-top:4px; word-break:break-word; }
        .bk-progress { height:8px; border-radius:99px; background:var(--border,#eaecf0); overflow:hidden; margin:20px 0 8px; }
        .bk-progress-bar { height:100%; width:0; border-radius:99px; background:var(--accent,#2563eb); transition:width .25s ease; }
        .bk-progress-meta { display:flex; justify-content:space-between; gap:10px; font-size:12px; color:var(--text-2,#6b7280); }
        .bk-modal-actions { margin-top:18px; }
        .bk-modal:not(.is-done):not(.is-error) .bk-modal-icon i { animation:bkpulse 1.2s ease-in-out infinite; }
        @keyframes bkpulse { 50% { transform:translateY(3px); opacity:.55; } }
        .bk-modal.is-done .bk-modal-icon  { background:color-mix(in srgb, #16a34a 16%, transparent); color:#16a34a; }
        .bk-modal.is-done .bk-progress-bar { background:#16a34a; }
        .bk-modal.is-error .bk-modal-icon { background:color-mix(in srgb, #dc2626 16%, transparent); color:#dc2626; }
        .bk-modal.is-error .bk-progress-bar { background:#dc2626; }

        @media (max-width: 1100px) { .bk-stats { grid-template-columns:repeat(2,1fr); } }
        @media (max-width: 800px)  {
            .bk-card-head { flex-wrap:wrap; }
            .bk-actions { width:100%; padding-left:31px; }
            .bk-hint { display:none; }
            .bk-bar-wrap { left:0; }
        }
    </style>
</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->
<div class="sidebar">

    <div class="adm_logo">
        <img src="../assets/images/mccLogo.png" alt="MCC Logo">
        <div class="logotext">
            <h2>Admin Panel</h2>
            <p>MCC Student Violation System</p>
        </div>
    </div>

    <div class="sidebar_content">
        <ul>
            <h3>Overview</h3>
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></li>

            <h3>Students</h3>
            <li><a href="student-records.php"><i class="fas fa-user-graduate"></i><span>Student Records</span></a></li>
            <li><a href="violation-records.php"><i class="fas fa-history"></i><span>Violation Records</span></a></li>
            <li><a href="student-appeals.php"><i class="fa-solid fa-pen"></i><span>Student Appeals</span></a></li>

            <h3>Discipline</h3>
            <li><a href="disciplinary-action.php"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
            <li><a href="risk-level.php"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>

            <h3>Backup &amp; Reports</h3>
            <li><a href="data-backup.php" class="active"><i class="fas fa-cloud-download-alt"></i><span>Data Backup</span></a></li>

            <h3>System</h3>
            <li><a href="user-management.php"><i class="fas fa-user-cog"></i><span>User Management</span></a></li>
            <li><a href="theme-settings.php"><i class="fas fa-palette"></i><span>Theme Settings</span></a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a></li>
        </ul>
    </div>

</div>


<!-- =====================================================
     MAIN CONTENT
===================================================== -->
<div class="main-content">

    <!-- NAVBAR -->
    <nav class="navbar">

        <h1>Data Backup &amp; Export</h1>

        <div class="navbar-right">

            <div class="dropdown bell-wrap">

                <button class="bell-btn" id="bellBtn" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </button>

                <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">

                    <div class="notif-head">
                        <div class="notif-head-left">
                            <span>Notifications</span>
                            <span class="unread-pill <?= $unreadCount > 0 ? 'visible' : '' ?>" id="notifPill"><?= $unreadCount ?> new</span>
                        </div>
                        <button class="mark-all-btn" onclick="markAllRead()">Mark all read</button>
                    </div>

                    <div class="notif-scroll" id="notifList">

                        <?php if (empty($notifList)): ?>

                            <div class="notif-empty">
                                <i class="fas fa-bell-slash"></i>
                                <p>You're all caught up</p>
                            </div>

                        <?php else: ?>

                            <?php foreach ($notifList as $n): ?>

                                <a class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>"
                                   href="<?= htmlspecialchars($n['link'] ?? '#') ?>"
                                   onclick="markRead(<?= $n['id'] ?>,this);return true;">

                                    <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>

                                    <div class="notif-body-text">
                                        <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                                        <div class="notif-ts">
                                            <i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i>
                                            <?= date('M d, Y · h:i A', strtotime($n['created_at'])) ?>
                                        </div>
                                    </div>

                                    <?php if (!$n['is_read']): ?>
                                        <div class="notif-dot-ind"></div>
                                    <?php endif; ?>

                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <div class="user-chip">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?></div>
                <span class="user-name"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

        </div>
    </nav>


    <!-- =================================================
         PAGE CONTENT
    ================================================= -->
    <div class="content">

        <!-- Actions -->
        <div class="bk-head">
            <div class="bk-head-actions">
                <button type="button" class="bk-btn ghost" id="toggleAllBtn" onclick="toggleAll()">
                    <i class="fas fa-angles-down" style="font-size:12px;"></i><span>Expand all</span>
                </button>
                <a href="?export=all" class="bk-btn primary" data-dl data-records="<?= $allRecords ?>" data-label="all data">
                    <i class="fas fa-file-excel" style="font-size:13px;"></i> Export All Data
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="bk-stats">
            <div class="bk-stat">
                <div class="bk-stat-icon c-blue"><i class="fas fa-user-graduate"></i></div>
                <div><div class="bk-stat-num"><?= number_format($studentCount) ?></div><div class="bk-stat-lbl">Students</div></div>
            </div>
            <div class="bk-stat">
                <div class="bk-stat-icon c-red"><i class="fas fa-exclamation-triangle"></i></div>
                <div><div class="bk-stat-num"><?= number_format($violationCount) ?></div><div class="bk-stat-lbl">Violations</div></div>
            </div>
            <div class="bk-stat">
                <div class="bk-stat-icon c-amber"><i class="fas fa-gavel"></i></div>
                <div><div class="bk-stat-num"><?= number_format($disciplinaryCount) ?></div><div class="bk-stat-lbl">Disciplinary actions</div></div>
            </div>
            <div class="bk-stat">
                <div class="bk-stat-icon c-navy"><i class="fas fa-database"></i></div>
                <div><div class="bk-stat-num"><?= number_format($totalRecords) ?></div><div class="bk-stat-lbl">Total records</div></div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="bk-toolbar">
            <label class="bk-selectall">
                <input type="checkbox" class="bk-cb" id="selectAll">
                Select all tables
            </label>
            <span class="bk-toolbar-hint">Tick tables to export them together in one Excel file.</span>
        </div>


        <!-- =============================================
             STUDENTS
        ============================================= -->
        <section class="bk-card" data-key="students" data-count="<?= $studentCount ?>">

            <div class="bk-card-head">

                <input type="checkbox" class="bk-cb bk-sel" value="students" aria-label="Select Student Records">

                <button type="button" class="bk-toggle" data-bs-toggle="collapse" data-bs-target="#sec-students" aria-expanded="false" aria-controls="sec-students">
                    <div class="bk-icon c-blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="bk-meta">
                        <div class="bk-title">Student Records <span class="bk-pill"><?= number_format($studentCount) ?></span></div>
                        <div class="bk-desc">Student ID, name, course, year level, department</div>
                    </div>
                    <span class="bk-hint">Click to preview</span>
                    <i class="fas fa-chevron-down bk-chev"></i>
                </button>

                <div class="bk-actions">
                    <a href="?export=students" class="bk-btn primary sm" data-dl data-records="<?= $studentCount ?>" data-label="Student Records"><i class="fas fa-file-excel" style="font-size:11px;"></i> Excel</a>
                    <a href="?export=students&amp;format=csv" class="bk-btn ghost sm" data-dl data-records="<?= $studentCount ?>" data-label="Student Records"><i class="fas fa-file-csv" style="font-size:11px;"></i> CSV</a>
                </div>

            </div>

            <div class="collapse" id="sec-students">
                <div class="bk-body">

                    <div class="bk-filter-row">
                        <div class="bk-filter">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Filter these rows…" data-filter="tbl-students">
                        </div>
                        <span class="bk-count" data-count-for="tbl-students"></span>
                    </div>

                    <div class="bk-scroll">
                        <table class="data-table" id="tbl-students">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Course</th>
                                    <th>Year</th>
                                    <th>Department</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($previewStudents)): ?>
                                    <tr class="empty-row"><td colspan="5">No students yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($previewStudents as $s): ?>
                                        <tr>
                                            <td class="sid"><?= htmlspecialchars($s['student_id']) ?></td>
                                            <td class="name"><?= htmlspecialchars($s['full_name']) ?></td>
                                            <td class="muted"><?= htmlspecialchars($s['cname']) ?></td>
                                            <td class="muted">Year <?= (int)$s['year_level'] ?></td>
                                            <td class="muted"><?= htmlspecialchars($s['dname']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($studentCount > BK_PREVIEW): ?>
                        <div class="bk-foot"><i class="fas fa-info-circle" style="margin-right:5px;"></i>Showing <?= BK_PREVIEW ?> of <?= number_format($studentCount) ?> records. Export to get all of them.</div>
                    <?php endif; ?>

                </div>
            </div>

        </section>


        <!-- =============================================
             VIOLATIONS
        ============================================= -->
        <section class="bk-card" data-key="violations" data-count="<?= $violationCount ?>">

            <div class="bk-card-head">

                <input type="checkbox" class="bk-cb bk-sel" value="violations" aria-label="Select Violation Records">

                <button type="button" class="bk-toggle" data-bs-toggle="collapse" data-bs-target="#sec-violations" aria-expanded="false" aria-controls="sec-violations">
                    <div class="bk-icon c-red"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="bk-meta">
                        <div class="bk-title">Violation Records <span class="bk-pill"><?= number_format($violationCount) ?></span></div>
                        <div class="bk-desc">Category, violation, description, status, date submitted</div>
                    </div>
                    <span class="bk-hint">Click to preview</span>
                    <i class="fas fa-chevron-down bk-chev"></i>
                </button>

                <div class="bk-actions">
                    <a href="?export=violations" class="bk-btn primary sm" data-dl data-records="<?= $violationCount ?>" data-label="Violation Records"><i class="fas fa-file-excel" style="font-size:11px;"></i> Excel</a>
                    <a href="?export=violations&amp;format=csv" class="bk-btn ghost sm" data-dl data-records="<?= $violationCount ?>" data-label="Violation Records"><i class="fas fa-file-csv" style="font-size:11px;"></i> CSV</a>
                </div>

            </div>

            <div class="collapse" id="sec-violations">
                <div class="bk-body">

                    <div class="bk-filter-row">
                        <div class="bk-filter">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Filter these rows…" data-filter="tbl-violations">
                        </div>
                        <span class="bk-count" data-count-for="tbl-violations"></span>
                    </div>

                    <div class="bk-scroll">
                        <table class="data-table" id="tbl-violations">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Violation</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($previewViolations)): ?>
                                    <tr class="empty-row"><td colspan="6">No violations yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($previewViolations as $v): ?>
                                        <tr>
                                            <td class="sid"><?= htmlspecialchars($v['student_id']) ?></td>
                                            <td class="name"><?= htmlspecialchars($v['full_name']) ?></td>
                                            <td><span class="mini-badge <?= htmlspecialchars($v['category']) ?>"><?= htmlspecialchars(ucfirst($v['category'])) ?></span></td>
                                            <td class="muted"><?= htmlspecialchars($v['violation']) ?></td>
                                            <td><span class="mini-badge <?= htmlspecialchars($v['status']) ?>"><?= htmlspecialchars(ucfirst($v['status'])) ?></span></td>
                                            <td class="muted"><?= date('M d, Y', strtotime($v['date_submitted'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($violationCount > BK_PREVIEW): ?>
                        <div class="bk-foot"><i class="fas fa-info-circle" style="margin-right:5px;"></i>Showing <?= BK_PREVIEW ?> of <?= number_format($violationCount) ?> records. Export to get all of them.</div>
                    <?php endif; ?>

                </div>
            </div>

        </section>


        <!-- =============================================
             DISCIPLINARY ACTIONS
        ============================================= -->
        <section class="bk-card" data-key="disciplinary" data-count="<?= $disciplinaryCount ?>">

            <div class="bk-card-head">

                <input type="checkbox" class="bk-cb bk-sel" value="disciplinary" aria-label="Select Disciplinary Actions">

                <button type="button" class="bk-toggle" data-bs-toggle="collapse" data-bs-target="#sec-disciplinary" aria-expanded="false" aria-controls="sec-disciplinary">
                    <div class="bk-icon c-amber"><i class="fas fa-gavel"></i></div>
                    <div class="bk-meta">
                        <div class="bk-title">Disciplinary Actions <span class="bk-pill"><?= number_format($disciplinaryCount) ?></span></div>
                        <div class="bk-desc">Violation, sanction, status, start and end dates</div>
                    </div>
                    <span class="bk-hint">Click to preview</span>
                    <i class="fas fa-chevron-down bk-chev"></i>
                </button>

                <div class="bk-actions">
                    <a href="?export=disciplinary" class="bk-btn primary sm" data-dl data-records="<?= $disciplinaryCount ?>" data-label="Disciplinary Actions"><i class="fas fa-file-excel" style="font-size:11px;"></i> Excel</a>
                    <a href="?export=disciplinary&amp;format=csv" class="bk-btn ghost sm" data-dl data-records="<?= $disciplinaryCount ?>" data-label="Disciplinary Actions"><i class="fas fa-file-csv" style="font-size:11px;"></i> CSV</a>
                </div>

            </div>

            <div class="collapse" id="sec-disciplinary">
                <div class="bk-body">

                    <div class="bk-filter-row">
                        <div class="bk-filter">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Filter these rows…" data-filter="tbl-disciplinary">
                        </div>
                        <span class="bk-count" data-count-for="tbl-disciplinary"></span>
                    </div>

                    <div class="bk-scroll">
                        <table class="data-table" id="tbl-disciplinary">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Violation</th>
                                    <th>Sanction</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($previewDisciplinary)): ?>
                                    <tr class="empty-row"><td colspan="5">No disciplinary records yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($previewDisciplinary as $da): ?>
                                        <?php $san = (string)($da['sanction'] ?? ''); ?>
                                        <tr>
                                            <td class="sid"><?= htmlspecialchars($da['student_id']) ?></td>
                                            <td class="name"><?= htmlspecialchars($da['full_name']) ?></td>
                                            <td class="muted"><?= htmlspecialchars($da['violation']) ?></td>
                                            <td class="muted" style="max-width:240px;">
                                                <small><?= htmlspecialchars(bkSubstr($san, 0, 60)) ?><?= bkStrlen($san) > 60 ? '…' : '' ?></small>
                                            </td>
                                            <td><span class="mini-badge <?= htmlspecialchars($da['status']) ?>"><?= htmlspecialchars(ucfirst($da['status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($disciplinaryCount > BK_PREVIEW): ?>
                        <div class="bk-foot"><i class="fas fa-info-circle" style="margin-right:5px;"></i>Showing <?= BK_PREVIEW ?> of <?= number_format($disciplinaryCount) ?> records. Export to get all of them.</div>
                    <?php endif; ?>

                </div>
            </div>

        </section>


        <!-- =============================================
             RISK LEVEL
        ============================================= -->
        <section class="bk-card" data-key="risk" data-count="<?= $riskCount ?>">

            <div class="bk-card-head">

                <input type="checkbox" class="bk-cb bk-sel" value="risk" aria-label="Select Risk Level Data">

                <button type="button" class="bk-toggle" data-bs-toggle="collapse" data-bs-target="#sec-risk" aria-expanded="false" aria-controls="sec-risk">
                    <div class="bk-icon c-teal"><i class="fas fa-shield-alt"></i></div>
                    <div class="bk-meta">
                        <div class="bk-title">Risk Level Data <span class="bk-pill"><?= number_format($riskCount) ?></span></div>
                        <div class="bk-desc">Minor and major counts, total, and risk level per student</div>
                    </div>
                    <span class="bk-hint">Click to preview</span>
                    <i class="fas fa-chevron-down bk-chev"></i>
                </button>

                <div class="bk-actions">
                    <a href="?export=risk" class="bk-btn primary sm" data-dl data-records="<?= $riskCount ?>" data-label="Risk Level Data"><i class="fas fa-file-excel" style="font-size:11px;"></i> Excel</a>
                    <a href="?export=risk&amp;format=csv" class="bk-btn ghost sm" data-dl data-records="<?= $riskCount ?>" data-label="Risk Level Data"><i class="fas fa-file-csv" style="font-size:11px;"></i> CSV</a>
                </div>

            </div>

            <div class="collapse" id="sec-risk">
                <div class="bk-body">

                    <div class="bk-filter-row">
                        <div class="bk-filter">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Filter these rows…" data-filter="tbl-risk">
                        </div>
                        <span class="bk-count" data-count-for="tbl-risk"></span>
                    </div>

                    <div class="bk-scroll">
                        <table class="data-table" id="tbl-risk">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Minor</th>
                                    <th>Major</th>
                                    <th>Total</th>
                                    <th>Risk Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($previewRisk)): ?>
                                    <tr class="empty-row"><td colspan="6">No data yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($previewRisk as $r): ?>
                                        <?php
                                        $t = (int)$r['total'];

                                        if ($t >= 5)      { $rl = 'Critical'; $rc = '#dc2626'; $rb = '#fff0f0'; }
                                        elseif ($t >= 4)  { $rl = 'High';     $rc = '#d97706'; $rb = '#fffbeb'; }
                                        elseif ($t >= 2)  { $rl = 'Moderate'; $rc = '#2563eb'; $rb = '#eff4ff'; }
                                        else              { $rl = 'Low';      $rc = '#16a34a'; $rb = '#f0fdf4'; }
                                        ?>
                                        <tr>
                                            <td class="sid"><?= htmlspecialchars($r['student_id']) ?></td>
                                            <td class="name"><?= htmlspecialchars($r['full_name']) ?></td>
                                            <td class="muted"><?= (int)$r['minor_c'] ?></td>
                                            <td class="muted"><?= (int)$r['major_c'] ?></td>
                                            <td class="muted"><?= $t ?></td>
                                            <td>
                                                <span class="risk-badge" style="background:<?= $rb ?>;color:<?= $rc ?>;">
                                                    <span class="dot" style="background:<?= $rc ?>;"></span>
                                                    <?= $rl ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($riskCount > BK_PREVIEW): ?>
                        <div class="bk-foot"><i class="fas fa-info-circle" style="margin-right:5px;"></i>Showing the top <?= BK_PREVIEW ?> of <?= number_format($riskCount) ?> students by violations. Export to get all of them.</div>
                    <?php endif; ?>

                </div>
            </div>

        </section>

    </div>

</div>


<!-- Selection bar -->
<div class="bk-bar-wrap">
    <div class="bk-bar" id="selBar">
        <div class="bk-bar-text">
            <strong id="selCount">0 tables</strong> selected
            <span class="bk-bar-sub" id="selRecords"></span>
        </div>
        <button type="button" class="bk-btn clear sm" onclick="clearSelection()">Clear</button>
        <a href="#" class="bk-btn go" id="selExport" data-dl>
            <i class="fas fa-file-excel" style="font-size:12px;"></i> Export selected
        </a>
    </div>
</div>

<!-- Backup loading -->
<div class="bk-overlay" id="bkOverlay" aria-hidden="true">
    <div class="bk-modal" id="bkModal" role="dialog" aria-live="polite" aria-labelledby="bkTitle">
        <div class="bk-modal-icon"><i class="fas fa-cloud-arrow-down" id="bkIcon"></i></div>
        <div class="bk-modal-title" id="bkTitle">Backing up…</div>
        <div class="bk-modal-sub" id="bkSub"></div>
        <div class="bk-progress"><div class="bk-progress-bar" id="bkBar"></div></div>
        <div class="bk-progress-meta"><span id="bkRecords"></span><span id="bkPct">0%</span></div>
        <div class="bk-modal-actions">
            <button type="button" class="bk-btn ghost sm" id="bkClose">Cancel</button>
        </div>
    </div>
</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =====================================================
   EXPAND / COLLAPSE
===================================================== */
const collapseEls  = document.querySelectorAll('.bk-card .collapse');
const toggleAllBtn = document.getElementById('toggleAllBtn');

function allOpen() { return [...collapseEls].every(el => el.classList.contains('show')); }

function updateToggleAll() {
    const open = allOpen();
    toggleAllBtn.querySelector('span').textContent = open ? 'Collapse all' : 'Expand all';
    toggleAllBtn.querySelector('i').className = open ? 'fas fa-angles-up' : 'fas fa-angles-down';
    toggleAllBtn.querySelector('i').style.fontSize = '12px';
}

collapseEls.forEach(el => {
    el.addEventListener('shown.bs.collapse', updateToggleAll);
    el.addEventListener('hidden.bs.collapse', updateToggleAll);
});

function toggleAll() {
    const open = allOpen();
    collapseEls.forEach(el => {
        const inst = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
        open ? inst.hide() : inst.show();
    });
}


/* =====================================================
   SELECT TABLES → EXPORT TOGETHER
===================================================== */
const sels      = [...document.querySelectorAll('.bk-sel')];
const selectAll = document.getElementById('selectAll');
const selBar    = document.getElementById('selBar');

function refreshSelection() {
    const chosen = sels.filter(c => c.checked);
    const records = chosen.reduce((sum, c) => sum + (parseInt(c.closest('.bk-card').dataset.count) || 0), 0);

    sels.forEach(c => c.closest('.bk-card').classList.toggle('selected', c.checked));

    selectAll.checked = chosen.length === sels.length;
    selectAll.indeterminate = chosen.length > 0 && chosen.length < sels.length;

    document.getElementById('selCount').textContent = chosen.length + (chosen.length === 1 ? ' table' : ' tables');
    document.getElementById('selRecords').textContent = '· ' + records.toLocaleString() + ' records';
    const selLink = document.getElementById('selExport');
    selLink.href = '?export=custom&tables=' + chosen.map(c => c.value).join(',');
    selLink.dataset.records = records;
    selLink.dataset.label = chosen.length + (chosen.length === 1 ? ' table' : ' tables');

    selBar.classList.toggle('show', chosen.length > 0);
}

sels.forEach(c => c.addEventListener('change', refreshSelection));
selectAll.addEventListener('change', () => { sels.forEach(c => c.checked = selectAll.checked); refreshSelection(); });

function clearSelection() { sels.forEach(c => c.checked = false); refreshSelection(); }


/* =====================================================
   FILTER PREVIEW ROWS
===================================================== */
document.querySelectorAll('[data-filter]').forEach(input => {

    const table = document.getElementById(input.dataset.filter);
    const label = document.querySelector('[data-count-for="' + input.dataset.filter + '"]');
    const rows  = [...table.querySelectorAll('tbody tr:not(.empty-row)')];

    const colspan = table.querySelectorAll('thead th').length;
    const none = document.createElement('tr');
    none.className = 'bk-nomatch';
    none.style.display = 'none';
    none.innerHTML = '<td colspan="' + colspan + '">No matching rows</td>';
    table.querySelector('tbody').appendChild(none);

    const update = () => {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(tr => {
            const match = !q || tr.textContent.toLowerCase().includes(q);
            tr.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        none.style.display = (rows.length && !shown) ? '' : 'none';
        label.textContent = q ? shown + ' of ' + rows.length + ' rows' : '';
    };

    input.addEventListener('input', update);
});


/* =====================================================
   BACKUP LOADING + PROGRESS
   - Progress while the server prepares the file is an estimate based on
     how many records are being backed up.
   - Once the file starts arriving, progress follows the real download.
===================================================== */
const ov      = document.getElementById('bkOverlay');
const modal   = document.getElementById('bkModal');
const bkTitle = document.getElementById('bkTitle');
const bkSub   = document.getElementById('bkSub');
const bkBar   = document.getElementById('bkBar');
const bkRecs  = document.getElementById('bkRecords');
const bkPct   = document.getElementById('bkPct');
const bkIcon  = document.getElementById('bkIcon');
const bkClose = document.getElementById('bkClose');

let bkCtrl = null;
let bkRaf  = null;
let bkAutoHide = null;

const fmtNum = n => Number(n).toLocaleString();
const sleep  = ms => new Promise(r => setTimeout(r, ms));

function setProgress(p, records) {
    p = Math.max(0, Math.min(100, p));
    bkBar.style.width = p + '%';
    bkPct.textContent = Math.round(p) + '%';
    bkRecs.textContent = 'Backed up ' + fmtNum(Math.round(records * p / 100)) + ' of ' + fmtNum(records) + ' records';
}

function hideLoader() {
    clearTimeout(bkAutoHide);
    ov.classList.remove('show');
    ov.setAttribute('aria-hidden', 'true');
}

function finishState(kind, title, sub) {
    cancelAnimationFrame(bkRaf);
    modal.classList.toggle('is-done', kind === 'done');
    modal.classList.toggle('is-error', kind === 'error');
    bkIcon.className = kind === 'done' ? 'fas fa-circle-check' : 'fas fa-triangle-exclamation';
    bkTitle.textContent = title;
    bkSub.textContent = sub;
    bkClose.textContent = 'Close';
}

bkClose.addEventListener('click', () => {
    if (bkCtrl) { bkCtrl.abort(); } else { hideLoader(); }
});

async function runBackup(url, records, label) {

    if (bkCtrl) return;                         // one backup at a time

    bkCtrl = new AbortController();
    clearTimeout(bkAutoHide);

    modal.classList.remove('is-done', 'is-error');
    bkIcon.className = 'fas fa-cloud-arrow-down';
    bkTitle.textContent = 'Backing up ' + label;
    bkSub.textContent = fmtNum(records) + (records === 1 ? ' record' : ' records') + ' · please keep this page open';
    bkClose.textContent = 'Cancel';
    setProgress(0, records);

    ov.classList.add('show');
    ov.setAttribute('aria-hidden', 'false');

    const started = performance.now();
    const estMs   = Math.min(25000, Math.max(1200, 800 + records * 6));   // more records → longer estimate
    let shown = 0;
    let phase = 'build';

    const tick = () => {
        if (phase === 'build') {
            const t = performance.now() - started;
            shown = Math.max(shown, 80 * (1 - Math.exp(-3 * t / estMs)));
        }
        setProgress(shown, records);
        bkRaf = requestAnimationFrame(tick);
    };
    tick();

    try {

        const res  = await fetch(url, { signal: bkCtrl.signal, credentials: 'same-origin' });
        const type = res.headers.get('Content-Type') || '';

        if (!res.ok || type.includes('text/html')) {
            throw new Error('The server did not return a backup file. Please refresh the page and try again.');
        }

        phase = 'download';
        shown = Math.max(shown, 80);

        const total  = parseInt(res.headers.get('Content-Length') || '0', 10);
        const reader = res.body.getReader();
        const chunks = [];
        let loaded = 0;

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            chunks.push(value);
            loaded += value.length;
            if (total > 0 && loaded <= total) {
                shown = Math.max(shown, 80 + 19 * (loaded / total));
            }
        }

        const blob = new Blob(chunks, { type: type || 'application/octet-stream' });
        if (blob.size === 0) throw new Error('The backup file was empty.');

        // keep the loader on screen long enough to be seen
        await sleep(Math.max(0, 900 - (performance.now() - started)));

        shown = 100;
        setProgress(100, records);

        const cd   = res.headers.get('Content-Disposition') || '';
        const m    = cd.match(/filename="?([^";]+)"?/i);
        const name = m ? m[1] : 'backup';

        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 10000);

        finishState('done', 'Backup complete', fmtNum(records) + ' records saved as ' + name);
        bkAutoHide = setTimeout(hideLoader, 2600);

    } catch (err) {

        if (err.name === 'AbortError') {
            cancelAnimationFrame(bkRaf);
            hideLoader();
        } else {
            finishState('error', 'Backup failed', err.message || 'Something went wrong.');
        }

    } finally {
        bkCtrl = null;
    }
}

document.querySelectorAll('[data-dl]').forEach(a => {
    a.addEventListener('click', ev => {
        if (!(window.fetch && window.ReadableStream && window.AbortController)) return;   // old browser: normal download
        ev.preventDefault();
        runBackup(a.getAttribute('href'), parseInt(a.dataset.records) || 0, a.dataset.label || 'data');
    });
});


/* =====================================================
   NOTIFICATIONS
===================================================== */
function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function fmtDate(s) {
    const d = new Date(s);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        + ' · '
        + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function renderNotifs(data) {

    const badge  = document.getElementById('notifBadge');
    const pill   = document.getElementById('notifPill');
    const listEl = document.getElementById('notifList');
    const btn    = document.getElementById('bellBtn');
    const isOpen = btn?.closest('.dropdown')?.classList.contains('show');

    if (data.unread > 0) {

        if (badge) {
            badge.textContent = data.unread;
            badge.style.display = '';
        } else if (btn) {
            const nb = document.createElement('span');
            nb.id = 'notifBadge';
            nb.className = 'notif-badge';
            nb.textContent = data.unread;
            btn.appendChild(nb);
        }

        if (pill) {
            pill.textContent = data.unread + ' new';
            pill.classList.add('visible');
        }

    } else {
        if (badge) badge.style.display = 'none';
        if (pill) pill.classList.remove('visible');
    }

    if (!listEl || isOpen) return;

    if (!data.notifications.length) {
        listEl.innerHTML = '<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You\'re all caught up</p></div>';
        return;
    }

    listEl.innerHTML = data.notifications.map(n => `
        <a class="notif-item${n.is_read == 0 ? ' unread' : ''}" href="${escHtml(n.link || '#')}" onclick="markRead(${n.id},this);return true;">
            <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>
            <div class="notif-body-text">
                <div class="notif-msg">${escHtml(n.message)}</div>
                <div class="notif-ts"><i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i>${fmtDate(n.created_at)}</div>
            </div>
            ${n.is_read == 0 ? '<div class="notif-dot-ind"></div>' : ''}
        </a>
    `).join('');
}

function markRead(id, el) {

    fetch('ajax/notifications.php?action=read&id=' + id);

    el.classList.remove('unread');
    el.querySelector('.notif-dot-ind')?.remove();

    const badge = document.getElementById('notifBadge');
    const pill  = document.getElementById('notifPill');

    if (badge) {
        const n = parseInt(badge.textContent) - 1;
        if (n <= 0) badge.style.display = 'none'; else badge.textContent = n;
    }

    if (pill) {
        const n = parseInt(pill.textContent) - 1;
        if (n <= 0) pill.classList.remove('visible'); else pill.textContent = n + ' new';
    }
}

function markAllRead() {

    fetch('ajax/notifications.php?action=read_all');

    document.querySelectorAll('#notifList .notif-item').forEach(el => {
        el.classList.remove('unread');
        el.querySelector('.notif-dot-ind')?.remove();
    });

    const badge = document.getElementById('notifBadge');
    if (badge) badge.style.display = 'none';

    const pill = document.getElementById('notifPill');
    if (pill) pill.classList.remove('visible');
}

document.addEventListener('DOMContentLoaded', () => {

    const poll = () => fetch('ajax/notifications.php?action=poll')
        .then(r => r.ok ? r.json() : null)
        .then(d => { if (d && !d.error) renderNotifs(d); });

    poll();
    setInterval(poll, 15000);
});

</script>

</body>
</html>
