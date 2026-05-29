<?php
session_start();

// Replace with your actual InfinityFree credentials
$servername = "sql307.infinityfree.com";
$username   = "if0_41872295";
$password   = "dtQmJ0NRM8UdvzT";
$dbname     = "if0_41872295_attendance_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ── Auto Backup System ────────────────────────────────────────────────────────
// Runs silently in the background — no action needed from admin.
require_once __DIR__ . '/auto_backup.php';

// ── Redirect to login if not authenticated ────────────────────────────────────
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
}
