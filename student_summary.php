<?php
/**
 * student_summary.php
 * Shows a per-student breakdown of Present / Late / Absent counts
 * with an overall attendance rate per student.
 * Filterable by section and date range.
 */
include 'db.php';
requireLogin();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_section = intval($_GET['section_id'] ?? 0);
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to']   ?? '');

// ── Build WHERE for attendance rows ──────────────────────────────────────────
$a_where  = [];
$a_params = [];
$a_types  = '';

if ($filter_section) {
    $a_where[]  = "s.section_id = ?";
    $a_params[] = $filter_section;
    $a_types   .= 'i';
}
if ($date_from) {
    $a_where[]  = "a.date >= ?";
    $a_params[] = $date_from;
    $a_types   .= 's';
}
if ($date_to) {
    $a_where[]  = "a.date <= ?";
    $a_params[] = $date_to;
    $a_types   .= 's';
}

$where_clause = $a_where ? 'AND ' . implode(' AND ', $a_where) : '';

// ── Main query: per-student aggregate ────────────────────────────────────────
$sql = "
    SELECT
        s.id,
        s.name,
        s.school_id,
        sec.name        AS section,
        COUNT(a.id)     AS total_records,
        SUM(a.status = 'Present') AS present_count,
        SUM(a.status = 'Late')    AS late_count,
        SUM(a.status = 'Absent')  AS absent_count
    FROM students s
    JOIN sections sec ON sec.id = s.section_id
    LEFT JOIN attendance a ON a.student_id = s.id $where_clause
    WHERE 1=1
    " . ($filter_section ? " AND s.section_id = ?" : "") . "
    GROUP BY s.id, s.name, s.school_id, sec.name
    ORDER BY sec.name, s.name
";

// We need section_id twice if filtered (once in the JOIN subfilter, once for WHERE)
$final_params = $a_params;
$final_types  = $a_types;
if ($filter_section) {
    $final_params[] = $filter_section;
    $final_types   .= 'i';
}

$stmt = $conn->prepare($sql);
if ($final_params) {
    $stmt->bind_param($final_types, ...$final_params);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Section list for filter dropdown ─────────────────────────────────────────
$sections_list = $conn->query("SELECT id, name FROM sections ORDER BY name");

// ── Totals ────────────────────────────────────────────────────────────────────
$grand_present = array_sum(array_column($students, 'present_count'));
$grand_late    = array_sum(array_column($students, 'late_count'));
$grand_absent  = array_sum(array_column($students, 'absent_count'));
$grand_total   = $grand_present + $grand_late + $grand_absent;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Student Summary</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Rate bar */
        .rate-bar-wrap {
            background: var(--surface2);
            border-radius: 99px;
            height: 8px;
            width: 100%;
            min-width: 80px;
            overflow: hidden;
        }
        .rate-bar-fill {
            height: 8px;
            border-radius: 99px;
            background: linear-gradient(90deg, var(--success), #16a34a);
            transition: width 0.4s ease;
        }
        .rate-label {
            font-family: var(--mono);
            font-size: 12px;
            font-weight: 600;
            min-width: 38px;
            text-align: right;
        }
        /* Responsive table */
        .summary-count {
            font-family: var(--mono);
            font-size: 13px;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">👤 Student Summary</div>
                <div class="page-subtitle">Per-student attendance totals and rates</div>
            </div>
            <a href="export.php" class="btn btn-success">💾 Export</a>
        </div>

        <!-- ── Filters ── -->
        <form method="GET" class="card" style="margin-bottom:20px;">
            <div class="card-body">
                <div class="filter-bar">
                    <select name="section_id">
                        <option value="">All Sections</option>
                        <?php while ($sec = $sections_list->fetch_assoc()): ?>
                            <option value="<?php echo $sec['id']; ?>"
                                <?php echo $filter_section == $sec['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From date">
                    <input type="date" name="date_to"   value="<?php echo htmlspecialchars($date_to); ?>"   placeholder="To date">

                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="student_summary.php" class="btn btn-ghost">Clear</a>
                </div>
            </div>
        </form>

        <!-- ── Grand Totals ── -->
        <?php if ($grand_total > 0): ?>
        <div class="stats-grid" style="margin-bottom:20px;">
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php echo $grand_present; ?></div>
                <div class="stat-label">Total Present</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🕐</div>
                <div class="stat-value"><?php echo $grand_late; ?></div>
                <div class="stat-label">Total Late</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">❌</div>
                <div class="stat-value"><?php echo $grand_absent; ?></div>
                <div class="stat-label">Total Absent</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-value">
                    <?php echo $grand_total > 0 ? round(($grand_present / $grand_total) * 100) : 0; ?>%
                </div>
                <div class="stat-label">Overall Rate (Present)</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Summary Table ── -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Results</span>
                <span class="text-muted"><?php echo count($students); ?> student(s)</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>School ID</th>
                            <th>Section</th>
                            <th style="text-align:center;">Present</th>
                            <th style="text-align:center;">Late</th>
                            <th style="text-align:center;">Absent</th>
                            <th>Attendance Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="7">
                            <div class="empty-state">
                                <div class="empty-icon">🔍</div>
                                <p>No student data found.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($students as $row):
                            $total   = (int)$row['total_records'];
                            $present = (int)$row['present_count'];
                            $late    = (int)$row['late_count'];
                            $absent  = (int)$row['absent_count'];
                            $rate    = $total > 0 ? round(($present / $total) * 100) : 0;
                            $rate_color = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                            </td>
                            <td class="text-mono text-muted"><?php echo htmlspecialchars($row['school_id']); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($row['section']); ?></span></td>
                            <td style="text-align:center;">
                                <span class="summary-count" style="color:var(--success);"><?php echo $present; ?></span>
                            </td>
                            <td style="text-align:center;">
                                <span class="summary-count" style="color:var(--warning);"><?php echo $late; ?></span>
                            </td>
                            <td style="text-align:center;">
                                <span class="summary-count" style="color:var(--danger);"><?php echo $absent; ?></span>
                            </td>
                            <td>
                                <?php if ($total > 0): ?>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div class="rate-bar-wrap">
                                        <div class="rate-bar-fill"
                                             style="width:<?php echo $rate; ?>%; background: <?php
                                                 echo $rate >= 80
                                                     ? 'linear-gradient(90deg,var(--success),#16a34a)'
                                                     : ($rate >= 60
                                                         ? 'linear-gradient(90deg,var(--warning),#b45309)'
                                                         : 'linear-gradient(90deg,var(--danger),#b91c1c)');
                                             ?>;">
                                        </div>
                                    </div>
                                    <span class="rate-label" style="color:<?php echo $rate_color; ?>;">
                                        <?php echo $rate; ?>%
                                    </span>
                                </div>
                                <?php else: ?>
                                    <span class="text-muted">No records</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>
</body>
</html>
