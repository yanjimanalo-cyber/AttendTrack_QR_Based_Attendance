<?php
// Determine active page
$current = basename($_SERVER['PHP_SELF']);
function nav($page, $current) {
    return strpos($current, $page) !== false ? 'active' : '';
}
$initials = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 2));
?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">📋</div>
        <div>
            <div class="logo-text">AttendTrack</div>
            <div class="logo-sub">v2.0</div>
        </div>
    </div>

    <div class="nav-section">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="nav-item <?php echo nav('dashboard', $current); ?>">
            <span class="icon">🏠</span> Dashboard
        </a>
        <a href="section.php" class="nav-item <?php echo nav('section', $current); ?>">
            <span class="icon">📁</span> Sections
        </a>
        <a href="records.php" class="nav-item <?php echo nav('records', $current); ?>">
            <span class="icon">📊</span> Records
        </a>
        <a href="student_summary.php" class="nav-item <?php echo nav('student_summary', $current); ?>">
            <span class="icon">👤</span> Student Summary
        </a>
    </div>

    <div class="nav-section">
        <div class="nav-label">Manage</div>
        <a href="add_student.php" class="nav-item <?php echo nav('add_student', $current); ?>">
            <span class="icon">➕</span> Students
        </a>
        <a href="manage_sections.php" class="nav-item <?php echo nav('manage_sections', $current); ?>">
            <span class="icon">🗂️</span> Manage Sections
        </a>
        <a href="export.php" class="nav-item <?php echo nav('export', $current); ?>">
            <span class="icon">💾</span> Export / Backup
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div>
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
                <div class="user-role">Teacher</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">🚪 Sign Out</a>
    </div>
</aside>
