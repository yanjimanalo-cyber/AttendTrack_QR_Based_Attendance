<?php
/**
 * auto_backup.php — AttendTrack Automatic Database Backup
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO USE:
 *   Include this file at the top of db.php (after the connection is established)
 *   with:  require_once 'auto_backup.php';
 *
 * WHEN IT RUNS:
 *   Automatically triggers a backup on every request, but only if:
 *   - No backup has been made in the last BACKUP_INTERVAL_MINUTES minutes, OR
 *   - A significant action just occurred (login, logout, attendance recorded)
 *
 * OUTPUT:
 *   xampp/htdocs/AttendTrack/backups/backup_YYYY-MM-DD_HH-MMAM.sql
 *
 * REQUIREMENTS:
 *   - The $conn (mysqli) object must be available before this file is included.
 *   - The backups/ folder must be writable (created automatically if absent).
 */

// ── Configuration ─────────────────────────────────────────────────────────────
define('BACKUP_DIR',              __DIR__ . '/backups/');
define('BACKUP_INTERVAL_MINUTES', 10);       // auto-backup every N minutes
define('BACKUP_KEEP_DAYS',        30);       // delete backups older than N days
define('BACKUP_LOCK_FILE',        BACKUP_DIR . '.last_backup');

// ── Guard: only run once per request ─────────────────────────────────────────
if (defined('AUTO_BACKUP_LOADED')) return;
define('AUTO_BACKUP_LOADED', true);

// ── Ensure backup directory exists ───────────────────────────────────────────
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

/**
 * Main entry point — decides if a backup is needed right now.
 * Called automatically at the bottom of this file.
 */
function autoBackupMaybeRun(mysqli $conn): void {
    // Detect significant events from the current request
    $uri          = $_SERVER['REQUEST_URI'] ?? '';
    $method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isTriggerReq = (
        $method === 'POST' ||
        str_contains($uri, 'save_attendance') ||
        str_contains($uri, 'login') ||
        str_contains($uri, 'logout') ||
        str_contains($uri, 'generate_qr') ||
        str_contains($uri, 'register') ||
        str_contains($uri, 'add_student') ||
        str_contains($uri, 'manage_sections')
    );

    // Check last backup time
    $lastBackup = 0;
    if (file_exists(BACKUP_LOCK_FILE)) {
        $lastBackup = (int) file_get_contents(BACKUP_LOCK_FILE);
    }
    $minutesSinceLast = (time() - $lastBackup) / 60;

    // Run if: significant event OR interval exceeded
    if ($isTriggerReq || $minutesSinceLast >= BACKUP_INTERVAL_MINUTES) {
        autoBackupRun($conn);
    }
}

/**
 * Performs the actual SQL dump and saves it to the backups/ directory.
 */
function autoBackupRun(mysqli $conn): void {
    // ── Build filename: backup_2026-05-14_10-30PM.sql ────────────────────────
    $timestamp = date('Y-m-d_h-iA');   // e.g. 2026-05-14_10-30PM
    $filename  = "backup_{$timestamp}.sql";
    $filepath  = BACKUP_DIR . $filename;

    // Avoid writing two backups in the same minute (race condition guard)
    if (file_exists($filepath)) {
        return;
    }

    // ── Dump all tables ───────────────────────────────────────────────────────
    $sql = autoBackupGenerateSQL($conn);
    if (empty($sql)) return;

    // ── Write file ────────────────────────────────────────────────────────────
    $written = file_put_contents($filepath, $sql, LOCK_EX);
    if ($written === false) return;

    // ── Update last-backup timestamp ──────────────────────────────────────────
    file_put_contents(BACKUP_LOCK_FILE, time(), LOCK_EX);

    // ── Log this backup to the DB ─────────────────────────────────────────────
    autoBackupLog($conn, $filename, $written);

    // ── Purge old backups ─────────────────────────────────────────────────────
    autoBackupPurgeOld();
}

/**
 * Generates a complete SQL dump string from the current database.
 * Pure PHP — no exec() or shell required.
 */
function autoBackupGenerateSQL(mysqli $conn): string {
    $sql  = "-- ============================================================\n";
    $sql .= "-- AttendTrack Auto Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Server: " . ($conn->host_info ?? 'local') . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET time_zone='+00:00';\n\n";

    // Get all tables
    $tables_result = $conn->query("SHOW TABLES");
    if (!$tables_result) return '';

    $tables = [];
    while ($row = $tables_result->fetch_row()) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $safeTable = "`" . $conn->real_escape_string($table) . "`";

        // Table structure
        $create_result = $conn->query("SHOW CREATE TABLE $safeTable");
        if (!$create_result) continue;
        $create_row = $create_result->fetch_assoc();
        $create_sql = $create_row['Create Table'] ?? '';
        $create_result->free();

        $sql .= "-- ── Table: $table ──────────────────────────────────────────\n";
        $sql .= "DROP TABLE IF EXISTS $safeTable;\n";
        $sql .= $create_sql . ";\n\n";

        // Table data
        $data_result = $conn->query("SELECT * FROM $safeTable");
        if (!$data_result || $data_result->num_rows === 0) {
            if ($data_result) $data_result->free();
            continue;
        }

        $sql .= "INSERT INTO $safeTable VALUES\n";
        $rows  = [];
        while ($row = $data_result->fetch_row()) {
            $values = array_map(function($v) use ($conn) {
                if ($v === null) return 'NULL';
                return "'" . $conn->real_escape_string($v) . "'";
            }, $row);
            $rows[] = "(" . implode(", ", $values) . ")";
        }
        $sql .= implode(",\n", $rows) . ";\n\n";
        $data_result->free();
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

/**
 * Writes a record to the system_logs table (created if it doesn't exist).
 */
function autoBackupLog(mysqli $conn, string $filename, int $bytes): void {
    // Ensure system_logs table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS system_logs (
            id          INT PRIMARY KEY AUTO_INCREMENT,
            event_type  VARCHAR(50)  NOT NULL,
            description TEXT,
            user_id     INT          DEFAULT NULL,
            ip_address  VARCHAR(45)  DEFAULT NULL,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event (event_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $user_id  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $desc     = "Auto backup created: $filename (" . number_format($bytes) . " bytes)";

    $stmt = $conn->prepare(
        "INSERT INTO system_logs (event_type, description, user_id, ip_address)
         VALUES ('auto_backup', ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("sis", $desc, $user_id, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Logs general system events (login, logout, attendance, etc.)
 * Call this from login.php, logout.php, save_attendance.php, etc.
 *
 * Usage:  logSystemEvent($conn, 'login', 'Teacher logged in', $user_id);
 */
function logSystemEvent(mysqli $conn, string $type, string $desc, ?int $userId = null): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS system_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            description TEXT,
            user_id INT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event (event_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $uid  = $userId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $stmt = $conn->prepare(
        "INSERT INTO system_logs (event_type, description, user_id, ip_address)
         VALUES (?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("ssis", $type, $desc, $uid, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Deletes backup files older than BACKUP_KEEP_DAYS days.
 */
function autoBackupPurgeOld(): void {
    $cutoff = time() - (BACKUP_KEEP_DAYS * 86400);
    foreach (glob(BACKUP_DIR . 'backup_*.sql') as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

// ── Auto-run on include ───────────────────────────────────────────────────────
// Uses output buffering trick so backup runs AFTER response is sent to client
// (non-blocking UX — user never waits for backup to finish)
if (function_exists('fastcgi_finish_request')) {
    // PHP-FPM: finish response first, then backup
    register_shutdown_function(function() use ($conn) {
        fastcgi_finish_request();
        autoBackupMaybeRun($conn);
    });
} else {
    // Standard PHP: run normally (adds ~50ms on backup days)
    register_shutdown_function(function() use ($conn) {
        autoBackupMaybeRun($conn);
    });
}
