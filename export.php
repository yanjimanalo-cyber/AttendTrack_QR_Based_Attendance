<?php
/**
 * export.php  —  InfinityFree-compatible version
 * ─────────────────────────────────────────────────────────────────────────────
 * CSV  → downloads immediately (unchanged logic)
 * PDF  → exec() and Python are DISABLED on InfinityFree.
 *         Instead we serve a print-ready HTML page the user can
 *         File → Print → Save as PDF from any browser.
 *         All logic / filters are identical to the original.
 */
include 'db.php';
requireLogin();

// ── Shared query builder (unchanged) ─────────────────────────────────────────
function buildQuery($section_id, $filter_date, $date_from = '', $date_to = '') {
    $where  = [];
    $params = [];
    $types  = '';

    if ($section_id)  { $where[] = "students.section_id = ?"; $params[] = $section_id;  $types .= 'i'; }
    if ($filter_date) { $where[] = "attendance.date = ?";     $params[] = $filter_date; $types .= 's'; }
    if ($date_from)   { $where[] = "attendance.date >= ?";    $params[] = $date_from;   $types .= 's'; }
    if ($date_to)     { $where[] = "attendance.date <= ?";    $params[] = $date_to;     $types .= 's'; }

    $sql = "
        SELECT students.name, students.school_id, sections.name AS section,
               attendance.status, attendance.date, attendance.time_in
        FROM attendance
        JOIN students ON students.id = attendance.student_id
        JOIN sections ON sections.id = students.section_id
    ";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY attendance.date DESC, attendance.time_in DESC";

    return [$sql, $params, $types];
}

// ── CSV download (unchanged) ──────────────────────────────────────────────────
if (isset($_GET['download']) && ($_GET['format'] ?? 'csv') === 'csv') {
    $section_id  = intval($_GET['section_id'] ?? 0);
    $filter_date = $_GET['date']      ?? '';
    $date_from   = $_GET['date_from'] ?? '';
    $date_to     = $_GET['date_to']   ?? '';

    [$sql, $params, $types] = buildQuery($section_id, $filter_date, $date_from, $date_to);
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $filename = "attendance_export_" . date('Y-m-d') . ".csv";
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $out = fopen("php://output", "w");
    fputcsv($out, ['Name', 'School ID', 'Section', 'Status', 'Date', 'Time In']);
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['name'], $row['school_id'], $row['section'],
            $row['status'], $row['date'],
            date('h:i A', strtotime($row['time_in']))
        ]);
    }
    fclose($out);
    $stmt->close();
    exit;
}

// ── PDF: print-ready HTML (InfinityFree-safe replacement) ────────────────────
if (isset($_GET['download']) && ($_GET['format'] ?? '') === 'pdf') {
    $section_id  = intval($_GET['section_id'] ?? 0);
    $filter_date = $_GET['date']      ?? '';
    $date_from   = $_GET['date_from'] ?? '';
    $date_to     = $_GET['date_to']   ?? '';

    [$sql, $params, $types] = buildQuery($section_id, $filter_date, $date_from, $date_to);
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total   = count($rows);
    $present = count(array_filter($rows, fn($r) => $r['status'] === 'Present'));
    $late    = count(array_filter($rows, fn($r) => $r['status'] === 'Late'));
    $absent  = count(array_filter($rows, fn($r) => $r['status'] === 'Absent'));

    $flabels = [];
    if ($filter_date) $flabels[] = "Date: " . date('F j, Y', strtotime($filter_date));
    if ($date_from)   $flabels[] = "From: " . date('F j, Y', strtotime($date_from));
    if ($date_to)     $flabels[] = "To: "   . date('F j, Y', strtotime($date_to));
    $filter_label = $flabels ? implode('  |  ', $flabels) : 'All Records';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Report — <?php echo date('Y-m-d'); ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Sora', sans-serif;
    background: #fff;
    color: #3d3a35;
    padding: 72px 40px 32px;
    font-size: 13px;
    line-height: 1.5;
  }
  .print-bar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #3d3a35; color: #fff;
    padding: 10px 24px;
    display: flex; align-items: center; justify-content: space-between;
    font-size: 13px; z-index: 999; gap: 12px;
  }
  .print-bar span { color: #c4b7a6; font-size: 12px; }
  .btn-print {
    background: linear-gradient(135deg, #c49a6c, #a67873);
    color: #fff; border: none; padding: 8px 20px;
    border-radius: 6px; font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .btn-back {
    background: rgba(255,255,255,0.1); color: #fff;
    border: 1px solid rgba(255,255,255,0.2);
    padding: 8px 16px; border-radius: 6px;
    font-family: 'Sora', sans-serif; font-size: 13px;
    cursor: pointer; text-decoration: none;
  }
  .report-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 2px solid #c4b7a6;
    padding-bottom: 16px; margin-bottom: 20px;
  }
  .report-logo { display: flex; align-items: center; gap: 10px; }
  .logo-box {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #c49a6c, #a67873);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
  }
  .logo-name { font-size: 18px; font-weight: 700; }
  .logo-ver  { font-size: 11px; color: #7a7268; font-family: 'DM Mono', monospace; }
  .report-meta { text-align: right; color: #7a7268; font-size: 12px; }
  .report-meta strong { display: block; font-size: 15px; font-weight: 700; color: #3d3a35; margin-bottom: 2px; }
  .summary-row { display: flex; gap: 12px; margin-bottom: 20px; }
  .sum-card {
    flex: 1; border: 1px solid #c4b7a6; border-radius: 8px;
    padding: 12px 16px; background: #f5f0eb; text-align: center;
  }
  .sum-card .num { font-size: 22px; font-weight: 700; font-family: 'DM Mono', monospace; }
  .sum-card .lbl { font-size: 11px; color: #7a7268; margin-top: 2px; }
  .sum-card.present .num { color: #4a7a4a; }
  .sum-card.late    .num { color: #9a7020; }
  .sum-card.absent  .num { color: #a04040; }
  .filter-note {
    font-size: 12px; color: #7a7268;
    margin-bottom: 16px; padding: 8px 12px;
    background: #ede8e1; border-radius: 6px;
    border-left: 3px solid #c49a6c;
  }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  thead th {
    background: #d9cfc2; padding: 9px 12px; text-align: left;
    font-size: 10px; font-weight: 700; letter-spacing: 0.8px;
    text-transform: uppercase; color: #7a7268;
    border: 1px solid #c4b7a6;
  }
  tbody td { padding: 9px 12px; border: 1px solid #c4b7a6; vertical-align: middle; }
  tbody tr:nth-child(even) td { background: #f5f0eb; }
  tbody tr.row-absent  td { background: #fdf0f0; }
  tbody tr.row-late    td { background: #fdf8ed; }
  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 99px;
    font-size: 11px; font-weight: 700; font-family: 'DM Mono', monospace;
  }
  .badge.present { background: #e0f0e0; color: #4a7a4a; }
  .badge.late    { background: #faefd5; color: #9a7020; }
  .badge.absent  { background: #f8e0e0; color: #a04040; }
  .report-footer {
    margin-top: 24px; padding-top: 12px;
    border-top: 1px solid #c4b7a6;
    display: flex; justify-content: space-between;
    font-size: 11px; color: #7a7268;
  }
  @media print {
    .print-bar { display: none !important; }
    body { padding-top: 32px; }
    .sum-card, thead th, .badge,
    tbody tr.row-absent td, tbody tr.row-late td {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
  }
</style>
</head>
<body>

<div class="print-bar">
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="export.php" class="btn-back">← Back</a>
    <span>To save as PDF: click Print → change destination to "Save as PDF"</span>
  </div>
  <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<div class="report-header">
  <div class="report-logo">
    <div class="logo-box">📋</div>
    <div>
      <div class="logo-name">AttendTrack</div>
      <div class="logo-ver">Attendance Report</div>
    </div>
  </div>
  <div class="report-meta">
    <strong>Attendance Report</strong>
    Generated: <?php echo date('F j, Y \a\t h:i A'); ?>
  </div>
</div>

<div class="summary-row">
  <div class="sum-card">
    <div class="num"><?php echo $total; ?></div>
    <div class="lbl">Total Records</div>
  </div>
  <div class="sum-card present">
    <div class="num"><?php echo $present; ?></div>
    <div class="lbl">Present</div>
  </div>
  <div class="sum-card late">
    <div class="num"><?php echo $late; ?></div>
    <div class="lbl">Late</div>
  </div>
  <div class="sum-card absent">
    <div class="num"><?php echo $absent; ?></div>
    <div class="lbl">Absent</div>
  </div>
</div>

<div class="filter-note">
  📎 Filters: <strong><?php echo htmlspecialchars($filter_label); ?></strong>
</div>

<table>
  <thead>
    <tr>
      <th>Student Name</th>
      <th>School ID</th>
      <th>Section</th>
      <th>Status</th>
      <th>Date</th>
      <th>Time In</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="6" style="text-align:center;padding:24px;color:#7a7268;">No records found for the selected filters.</td></tr>
  <?php else: ?>
    <?php foreach ($rows as $row):
      $rowClass   = ($row['status'] === 'Absent') ? 'row-absent' : (($row['status'] === 'Late') ? 'row-late' : '');
      $badgeClass = strtolower($row['status']);
      $timeStr = date('h:i A', strtotime($row['time_in']));
      $dateStr = date('M d, Y', strtotime($row['date']));
    ?>
    <tr class="<?php echo $rowClass; ?>">
      <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
      <td style="font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($row['school_id']); ?></td>
      <td><?php echo htmlspecialchars($row['section']); ?></td>
      <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
      <td><?php echo $dateStr; ?></td>
      <td style="font-family:'DM Mono',monospace;"><?php echo $timeStr; ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<div class="report-footer">
  <span>AttendTrack — <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Teacher'); ?></span>
  <span>Total: <?php echo $total; ?> record(s) &nbsp;·&nbsp; <?php echo date('Y-m-d H:i'); ?></span>
</div>

</body>
</html>
<?php
    exit;
}

// ── PAGE UI ───────────────────────────────────────────────────────────────────
$sections_list = $conn->query("SELECT id, name FROM sections ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Export & Backup</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">💾 Export & Backup</div>
                <div class="page-subtitle">Download attendance records as CSV or view as printable PDF</div>
            </div>
        </div>

        <div style="max-width:520px; display:flex; flex-direction:column; gap:16px;">

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Export Attendance Records</span>
                </div>
                <div class="card-body">
                    <p style="font-size:14px; color:var(--text-muted); margin-bottom:20px;">
                        Filter your records then choose a format. CSV downloads directly.
                        PDF opens a print-ready page — use <strong>File → Print → Save as PDF</strong>.
                    </p>

                    <form method="GET" action="export.php" id="exportForm">
                        <input type="hidden" name="download" value="1">
                        <input type="hidden" name="format"   value="csv" id="fmtInput">

                        <div class="form-group">
                            <label>Section (optional)</label>
                            <select name="section_id">
                                <option value="">All Sections</option>
                                <?php
                                $sections_list->data_seek(0);
                                while ($sec = $sections_list->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $sec['id']; ?>"><?php echo htmlspecialchars($sec['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                            <div class="form-group">
                                <label>Date From (optional)</label>
                                <input type="date" name="date_from">
                            </div>
                            <div class="form-group">
                                <label>Date To (optional)</label>
                                <input type="date" name="date_to">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Specific Date (optional)</label>
                            <input type="date" name="date">
                        </div>

                        <div style="display:flex; gap:10px; margin-top:8px;">
                            <button type="button" onclick="submitAs('csv')" class="btn btn-ghost btn-full">
                                📄 Download CSV
                            </button>
                            <button type="button" onclick="submitAs('pdf')" class="btn btn-primary btn-full">
                                🖨️ View / Print PDF
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">⚡ Quick Export</span>
                </div>
                <div class="card-body">
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px;">One-click exports for common needs:</p>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <div style="display:flex; gap:8px;">
                            <a href="export.php?download=1&format=csv&date=<?php echo date('Y-m-d'); ?>" class="btn btn-ghost" style="flex:1;">
                                📅 Today — CSV
                            </a>
                            <a href="export.php?download=1&format=pdf&date=<?php echo date('Y-m-d'); ?>" class="btn btn-ghost" style="flex:1;" target="_blank">
                                📅 Today — PDF
                            </a>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <a href="export.php?download=1&format=csv" class="btn btn-ghost" style="flex:1;">
                                📦 Full Backup — CSV
                            </a>
                            <a href="export.php?download=1&format=pdf" class="btn btn-ghost" style="flex:1;" target="_blank">
                                📦 Full Backup — PDF
                            </a>
                        </div>
                    </div>
                    <p style="font-size:11px; color:var(--text-muted); margin-top:12px;">
                        💡 PDF links open in a new tab. Use your browser's Print dialog (Ctrl+P) to save as PDF.
                    </p>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
function submitAs(fmt) {
    document.getElementById('fmtInput').value = fmt;
    var form = document.getElementById('exportForm');
    if (fmt === 'pdf') {
        var orig = form.target;
        form.target = '_blank';
        form.submit();
        form.target = orig;
    } else {
        form.submit();
    }
}
</script>
</body>
</html>
