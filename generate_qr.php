<?php
/**
 * generate_qr.php  (v4 — Custom Schedule Support)
 * ─────────────────────────────────────────────────────────────────────────────
 * Now accepts custom time windows sent from the Schedule Picker modal
 * in attendance.php:
 *
 *   POST present_mins  — minutes from start until Present window closes
 *   POST late_mins     — minutes from start until Late window closes (cumulative)
 *   POST session_mins  — total session length = full class duration
 *
 * Falls back to safe defaults (15/25/60) if values are missing or invalid.
 */
include 'db.php';
requireLogin();

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: section.php");
    exit;
}

$section_id = intval($_POST['section_id'] ?? 0);
if (!$section_id) {
    header("Location: section.php?error=" . urlencode("No section selected."));
    exit;
}

// ── Verify section exists ─────────────────────────────────────────────────────
$sv = $conn->prepare("SELECT id FROM sections WHERE id = ?");
$sv->bind_param("i", $section_id);
$sv->execute();
$sv->store_result();
if ($sv->num_rows === 0) {
    $sv->close();
    header("Location: section.php?error=" . urlencode("Invalid section."));
    exit;
}
$sv->close();

// ── Read custom time windows from modal ───────────────────────────────────────
// Sanitize: must be positive integers within sane bounds (1 min – 480 min / 8 hrs)
function sanitizeMins(mixed $raw, int $default, int $min = 1, int $max = 480): int {
    $val = intval($raw ?? 0);
    return ($val >= $min && $val <= $max) ? $val : $default;
}

$present_mins = sanitizeMins($_POST['present_mins'] ?? null, 15, 1, 60);
$late_mins    = sanitizeMins($_POST['late_mins']    ?? null, 25, 2, 120);
$session_mins = sanitizeMins($_POST['session_mins'] ?? null, 60, 5, 480);

// Guard: late must be after present; session must be at least as long as late
if ($late_mins <= $present_mins) {
    $late_mins = $present_mins + 10;
}
if ($session_mins < $late_mins) {
    $session_mins = $late_mins;
}

// ── Single time anchor ────────────────────────────────────────────────────────
$now           = time();
$token         = bin2hex(random_bytes(32));
$start_time    = date('Y-m-d H:i:s', $now);
$present_until = date('Y-m-d H:i:s', $now + $present_mins * 60);
$late_until    = date('Y-m-d H:i:s', $now + $late_mins    * 60);
$session_end   = date('Y-m-d H:i:s', $now + $session_mins * 60);

// ── Remove old sessions for this section ──────────────────────────────────────
$del = $conn->prepare("DELETE FROM qr_sessions WHERE section_id = ?");
$del->bind_param("i", $section_id);
if (!$del->execute()) {
    $del->close();
    $err = urlencode("Could not clear old sessions: " . $conn->error);
    header("Location: attendance.php?section_id=$section_id&qr_error=$err");
    exit;
}
$del->close();

// ── Insert new session ────────────────────────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO qr_sessions
        (section_id, token, start_time, present_until, late_until, session_end)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$ins->bind_param("isssss", $section_id, $token, $start_time, $present_until, $late_until, $session_end);

if ($ins->execute()) {
    $ins->close();
    header("Location: attendance.php?section_id=$section_id&token=" . urlencode($token));
    exit;
} else {
    $err = urlencode("DB Error creating QR session: " . $conn->error);
    $ins->close();
    header("Location: attendance.php?section_id=$section_id&qr_error=$err");
    exit;
}
