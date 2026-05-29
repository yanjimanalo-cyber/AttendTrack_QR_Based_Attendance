<?php
include 'db.php';
requireLogin();

// Stats
$total_students  = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$total_sections  = $conn->query("SELECT COUNT(*) as c FROM sections")->fetch_assoc()['c'];
$present_today   = $conn->query("SELECT COUNT(*) as c FROM attendance WHERE date = CURDATE() AND status='Present'")->fetch_assoc()['c'];

// Attendance rate today
$rate = $total_students > 0 ? round(($present_today / $total_students) * 100) : 0;

// Recent attendance (last 10)
$recent = $conn->query("
    SELECT students.name, sections.name AS section, attendance.status, attendance.time_in, attendance.date
    FROM attendance
    JOIN students ON students.id = attendance.student_id
    JOIN sections ON sections.id = students.section_id
    ORDER BY attendance.date DESC, attendance.time_in DESC
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">Dashboard</div>
                <div class="page-subtitle"><?php echo date('l, F j, Y'); ?></div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📁</div>
                <div class="stat-value"><?php echo $total_sections; ?></div>
                <div class="stat-label">Sections</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php echo $present_today; ?></div>
                <div class="stat-label">Present Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-value"><?php echo $rate; ?>%</div>
                <div class="stat-label">Attendance Rate</div>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Attendance</span>
                <a href="records.php" class="btn btn-ghost btn-sm">View All →</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Time In</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($recent->num_rows === 0): ?>
                        <tr><td colspan="5">
                            <div class="empty-state">
                                <div class="empty-icon">📭</div>
                                <p>No attendance records yet today.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php while ($row = $recent->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($row['section']); ?></span></td>
                            <td>
                                <span class="badge <?php echo $row['status'] === 'Present' ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $row['status']; ?>
                                </span>
                            </td>
                            <td class="text-mono"><?php echo date('h:i A', strtotime($row['time_in'])); ?></td>
                            <td class="text-muted"><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
