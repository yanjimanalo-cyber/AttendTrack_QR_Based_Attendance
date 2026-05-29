<?php
/**
 * save_attendance.php  (hardened)
 * ──────────────────────────────────────────────────────────────────────────────
 * Handles manual "✓ Mark" attendance from the teacher's attendance.php view.
 * Hardening changes vs original:
 *  1. Single $now anchor — same pattern as scan.php for consistency.
 *  2. Session is validated before trusting its time windows.
 *  3. Uses INSERT IGNORE + affected_rows to handle race-condition duplicates
 *     instead of a separate SELECT + INSERT (which had a TOCTOU window).
 *  4. session_id = 0 is handled gracefully (manual mark without an active QR).
 *  5. Status computation mirrors scan.php exactly (shared logic comment).
 *  6. Clear redirect on every code path.
 */
include 'db.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: section.php");
    exit;
}

$student_id = intval($_POST['student_id'] ?? 0);
$session_id = intval($_POST['session_id']  ?? 0);  // 0 = no active QR (manual)
$section_id = intval($_POST['section_id']  ?? 0);

if (!$student_id || !$section_id) {
    header("Location: section.php");
    exit;
}

// ── Single time anchor ────────────────────────────────────────────────────────
$now     = time();
$date    = date('Y-m-d', $now);
$time_in = date('H:i:s', $now);

// ── Determine status ──────────────────────────────────────────────────────────
// Default: manual mark by teacher always counts as Present
$status = 'Present';

if ($session_id) {
    $sq = $conn->prepare(
        "SELECT present_until, late_until, session_end FROM qr_sessions WHERE id = ?"
    );
    $sq->bind_param("i", $session_id);
    $sq->execute();
    $sess = $sq->get_result()->fetch_assoc();
    $sq->close();

    if ($sess) {
        // Mirror the exact same logic as scan.php
        if ($now < strtotime($sess['present_until'])) {
            $status = 'Present';
        } elseif ($now < strtotime($sess['late_until'])) {
            $status = 'Late';
        } elseif ($now < strtotime($sess['session_end'])) {
            $status = 'Absent';
        } else {
            // Session fully expired — teacher is manually marking; keep Present
            $status     = 'Present';
            $session_id = 0;  // disassociate from expired session
        }
    } else {
        // session_id supplied but not found — treat as manual mark
        $session_id = 0;
    }
}

// ── INSERT IGNORE — relies on UNIQUE KEY (student_id, date) ──────────────────
// This atomically prevents duplicates without a separate SELECT + INSERT race.
$ins = $conn->prepare(
    "INSERT IGNORE INTO attendance (student_id, session_id, status, date, time_in)
     VALUES (?, ?, ?, ?, ?)"
);
$ins->bind_param("iisss", $student_id, $session_id, $status, $date, $time_in);
$ins->execute();
// $ins->affected_rows === 0 means already recorded today (silently ignored)
$ins->close();

header("Location: attendance.php?section_id=$section_id&marked=1");
exit;
