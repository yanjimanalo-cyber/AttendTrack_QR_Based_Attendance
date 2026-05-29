<?php
include 'db.php';
requireLogin();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_section = intval($_GET['section_id'] ?? 0);
$filter_date    = $_GET['date']      ?? '';        // single-day (kept for compat)
$date_from      = trim($_GET['date_from'] ?? '');  // range start
$date_to        = trim($_GET['date_to']   ?? '');  // range end
$filter_status  = $_GET['status']    ?? '';
$search         = trim($_GET['search'] ?? '');

// Validate: single date takes priority over range when both supplied
// (user should pick one mode; single date beats range to avoid confusion)
if ($filter_date) {
    $date_from = '';
    $date_to   = '';
}

// ── Build query ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($filter_section) { $where[] = "students.section_id = ?"; $params[] = $filter_section; $types .= 'i'; }
if ($filter_date)    { $where[] = "attendance.date = ?";      $params[] = $filter_date;    $types .= 's'; }
if ($date_from)      { $where[] = "attendance.date >= ?";     $params[] = $date_from;      $types .= 's'; }
if ($date_to)        { $where[] = "attendance.date <= ?";     $params[] = $date_to;        $types .= 's'; }
if ($filter_status)  { $where[] = "attendance.status = ?";   $params[] = $filter_status;  $types .= 's'; }
if ($search)         { $where[] = "students.name LIKE ?";    $params[] = "%$search%";     $types .= 's'; }

$sql = "
    SELECT students.name, students.school_id, sections.name AS section,
           attendance.status, attendance.date, attendance.time_in
    FROM attendance
    JOIN students ON students.id = attendance.student_id
    JOIN sections ON sections.id = students.section_id
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY attendance.date DESC, attendance.time_in DESC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();

$sections_list = $conn->query("SELECT id, name FROM sections ORDER BY name");

// Active filter summary label
$filter_label_parts = [];
if ($filter_date)  $filter_label_parts[] = "Date: " . date('M d, Y', strtotime($filter_date));
if ($date_from)    $filter_label_parts[] = "From: " . date('M d, Y', strtotime($date_from));
if ($date_to)      $filter_label_parts[] = "To: "   . date('M d, Y', strtotime($date_to));
$filter_label = $filter_label_parts ? implode('  ·  ', $filter_label_parts) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Records</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Toggle between single-date and date-range modes */
        .date-mode-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }
        .date-mode-tab {
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border);
            background: var(--surface2);
            color: var(--text-muted);
            transition: all 0.15s;
        }
        .date-mode-tab.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        .date-panel { display: none; }
        .date-panel.visible { display: flex; gap: 12px; flex-wrap: wrap; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">📊 Attendance Records</div>
                <div class="page-subtitle">
                    Filter and view all attendance history
                    <?php if ($filter_label): ?>
                        &nbsp;·&nbsp; <span style="color:var(--accent); font-size:12px;"><?php echo htmlspecialchars($filter_label); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="export.php" class="btn btn-success">💾 Export CSV/PDF</a>
        </div>

        <!-- ── Filters ── -->
        <form method="GET" class="card" style="margin-bottom:20px;" id="filterForm">
            <div class="card-body">

                <!-- Row 1: search + section + status -->
                <div class="filter-bar" style="margin-bottom:12px;">
                    <input type="text" name="search"
                           placeholder="🔍 Search student name..."
                           value="<?php echo htmlspecialchars($search); ?>">

                    <select name="section_id">
                        <option value="">All Sections</option>
                        <?php while ($sec = $sections_list->fetch_assoc()): ?>
                            <option value="<?php echo $sec['id']; ?>"
                                <?php echo $filter_section == $sec['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                    <select name="status">
                        <option value="">All Status</option>
                        <option value="Present" <?php echo $filter_status === 'Present' ? 'selected' : ''; ?>>Present</option>
                        <option value="Late"    <?php echo $filter_status === 'Late'    ? 'selected' : ''; ?>>Late</option>
                        <option value="Absent"  <?php echo $filter_status === 'Absent'  ? 'selected' : ''; ?>>Absent</option>
                    </select>
                </div>

                <!-- Row 2: date mode toggle -->
                <div style="margin-bottom:6px;">
                    <div class="date-mode-tabs">
                        <button type="button" class="date-mode-tab <?php echo (!$date_from && !$date_to) ? 'active' : ''; ?>"
                                onclick="setDateMode('single')">📅 Single Date</button>
                        <button type="button" class="date-mode-tab <?php echo ($date_from || $date_to) ? 'active' : ''; ?>"
                                onclick="setDateMode('range')">📆 Date Range</button>
                    </div>

                    <!-- Single date -->
                    <div class="date-panel <?php echo (!$date_from && !$date_to) ? 'visible' : ''; ?>" id="panelSingle">
                        <input type="date" name="date"
                               value="<?php echo htmlspecialchars($filter_date); ?>"
                               style="width:200px;">
                    </div>

                    <!-- Date range -->
                    <div class="date-panel <?php echo ($date_from || $date_to) ? 'visible' : ''; ?>" id="panelRange">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <input type="date" name="date_from"
                                   value="<?php echo htmlspecialchars($date_from); ?>"
                                   placeholder="From" style="width:160px;">
                            <span style="color:var(--text-muted); font-size:13px;">to</span>
                            <input type="date" name="date_to"
                                   value="<?php echo htmlspecialchars($date_to); ?>"
                                   placeholder="To" style="width:160px;">
                        </div>
                    </div>
                </div>

                <!-- Row 3: buttons -->
                <div style="display:flex; gap:8px; margin-top:8px;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="records.php" class="btn btn-ghost">Clear</a>
                </div>
            </div>
        </form>

        <!-- ── Table ── -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Results</span>
                <span class="text-muted"><?php echo $records->num_rows; ?> record(s)</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>School ID</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Time In</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($records->num_rows === 0): ?>
                        <tr><td colspan="6">
                            <div class="empty-state">
                                <div class="empty-icon">🔍</div>
                                <p>No records found for the selected filters.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php while ($row = $records->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td class="text-mono text-muted"><?php echo htmlspecialchars($row['school_id']); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($row['section']); ?></span></td>
                            <td>
                                <?php if ($row['status'] === 'Present'): ?>
                                    <span class="badge badge-success">✅ Present</span>
                                <?php elseif ($row['status'] === 'Late'): ?>
                                    <span class="badge badge-warn">🕐 Late</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">❌ Absent</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                            <td class="text-mono text-muted"><?php echo date('h:i A', strtotime($row['time_in'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
function setDateMode(mode) {
    const single = document.getElementById('panelSingle');
    const range  = document.getElementById('panelRange');
    const tabs   = document.querySelectorAll('.date-mode-tab');

    if (mode === 'single') {
        single.classList.add('visible');
        range.classList.remove('visible');
        // Clear range inputs
        range.querySelectorAll('input').forEach(i => i.value = '');
        tabs[0].classList.add('active');
        tabs[1].classList.remove('active');
    } else {
        range.classList.add('visible');
        single.classList.remove('visible');
        // Clear single input
        single.querySelectorAll('input').forEach(i => i.value = '');
        tabs[1].classList.add('active');
        tabs[0].classList.remove('active');
    }
}
</script>
</body>
</html>
