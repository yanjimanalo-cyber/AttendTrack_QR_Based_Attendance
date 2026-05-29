<?php
include 'db.php';
requireLogin();

$sections = $conn->query("
    SELECT sections.id, sections.name,
           COUNT(students.id) AS student_count
    FROM sections
    LEFT JOIN students ON students.section_id = sections.id
    GROUP BY sections.id
    ORDER BY sections.name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Sections</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">Sections</div>
                <div class="page-subtitle">Select a section to manage attendance</div>
            </div>
            <a href="manage_sections.php" class="btn btn-primary">+ Add Section</a>
        </div>

        <?php if ($sections->num_rows === 0): ?>
            <div class="empty-state">
                <div class="empty-icon">📁</div>
                <p>No sections yet. <a href="manage_sections.php" style="color:var(--accent)">Create one →</a></p>
            </div>
        <?php else: ?>
            <div class="section-grid">
                <?php while ($sec = $sections->fetch_assoc()): ?>
                <a href="attendance.php?section_id=<?php echo $sec['id']; ?>" class="section-card">
                    <div class="sc-icon">📚</div>
                    <div class="sc-name"><?php echo htmlspecialchars($sec['name']); ?></div>
                    <div class="sc-count"><?php echo $sec['student_count']; ?> student<?php echo $sec['student_count'] != 1 ? 's' : ''; ?></div>
                </a>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
