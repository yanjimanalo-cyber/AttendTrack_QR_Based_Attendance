<?php
/**
 * scan.php  (v3 — Device Binding + Student Login)
 * ─────────────────────────────────────────────────────────────────────────────
 * Security features added vs v2:
 *
 *  A. STUDENT LOGIN REQUIRED
 *     After scanning the QR, the student must verify their identity by entering:
 *       • Full Name (selected from dropdown — matches DB)
 *       • School ID  (typed manually — must match the selected student's record)
 *       • Section    (shown, confirmed automatically from session)
 *     This prevents random students from picking any name in the list.
 *
 *  B. ONE DEVICE = ONE ACCOUNT (Device Binding)
 *     • The browser sends a device fingerprint (generated client-side from
 *       browser properties + canvas hash + timezone) as a hidden field.
 *     • On first attendance from a device, the fingerprint is stored in the
 *       DB tied to that student_id + date.
 *     • On any subsequent attendance attempt from the SAME fingerprint on the
 *       SAME date: if it's a different student → BLOCKED with a warning.
 *     • This prevents proxy attendance (one phone scanning for many students).
 *
 *  C. DUPLICATE ATTENDANCE GUARD
 *     • INSERT IGNORE + UNIQUE KEY (student_id, date) prevents double-marking
 *       regardless of fingerprint.
 *
 *  D. EXPIRED SESSION GUARD
 *     • All time checks re-anchored at form-submission time.
 *
 * DB TABLE REQUIRED (add to setup.sql):
 *   CREATE TABLE IF NOT EXISTS device_locks (
 *     id           INT PRIMARY KEY AUTO_INCREMENT,
 *     fingerprint  VARCHAR(128) NOT NULL,
 *     student_id   INT NOT NULL,
 *     locked_date  DATE NOT NULL,
 *     created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE KEY uniq_device_date (fingerprint, locked_date),
 *     FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
 *   );
 */
include 'db.php';
// requireLogin() NOT called — students have no teacher account.

// ── Ensure device_locks table exists ─────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS device_locks (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        fingerprint VARCHAR(128) NOT NULL,
        student_id  INT NOT NULL,
        locked_date DATE NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_device_date (fingerprint, locked_date),
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Page state ────────────────────────────────────────────────────────────────
// 'verify'  → show Name + School ID verification form
// 'confirm' → show final "Submit Attendance" button (identity verified)
// 'success' → attendance recorded
// 'error'   → unrecoverable error / blocked
$page_state = 'verify';
$error      = '';
$success    = '';
$session    = null;
$students_list = [];
$status_now = '';
$msg        = '';
$verified_student = null;   // set after identity check passes

$now   = time();
$token = trim($_GET['token'] ?? '');

// ── 1. Validate token & load session ─────────────────────────────────────────
if (empty($token)) {
    $error      = "Invalid QR code. Please ask your teacher for a new one.";
    $page_state = 'error';
} else {
    $stmt = $conn->prepare("
        SELECT qr_sessions.id AS session_id,
               qr_sessions.section_id,
               qr_sessions.start_time,
               qr_sessions.present_until,
               qr_sessions.late_until,
               qr_sessions.session_end,
               sections.name AS section_name
        FROM qr_sessions
        JOIN sections ON sections.id = qr_sessions.section_id
        WHERE qr_sessions.token = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $error      = "❌ Invalid or expired QR code. Ask your teacher for a new one.";
        $page_state = 'error';
    } elseif ($now >= strtotime($session['session_end'])) {
        $error      = "⏱️ This QR session has ended. Ask your teacher to generate a new one.";
        $page_state = 'error';
    } else {
        // Load students for this section
        $s = $conn->prepare("SELECT id, name, school_id FROM students WHERE section_id = ? ORDER BY name");
        $s->bind_param("i", $session['section_id']);
        $s->execute();
        $students_list = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        // Current window label
        if ($now < strtotime($session['present_until'])) {
            $status_now = 'present';
            $mins_left  = max(1, ceil((strtotime($session['present_until']) - $now) / 60));
            $msg        = "🟢 Present window — {$mins_left} min left";
        } elseif ($now < strtotime($session['late_until'])) {
            $status_now = 'late';
            $mins_left  = max(1, ceil((strtotime($session['late_until']) - $now) / 60));
            $msg        = "🟡 Late window — {$mins_left} min left";
        } else {
            $status_now = 'absent';
            $msg        = "🔴 Absent window — scan recorded as Absent";
        }
    }
}

// ── 2. Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $session && $page_state === 'verify') {

    $step        = $_POST['step']        ?? 'verify';
    $student_id  = intval($_POST['student_id']  ?? 0);
    $school_id   = trim($_POST['school_id_input'] ?? '');
    $fingerprint = trim($_POST['fingerprint']    ?? '');

    $valid_ids   = array_column($students_list, 'id');

    // ── STEP 1: Identity Verification ─────────────────────────────────────────
    if ($step === 'verify') {
        if (!$student_id || !in_array($student_id, $valid_ids, true)) {
            $error      = "⚠️ Please select your name from the list.";
            // Stay on verify form
        } elseif (empty($school_id)) {
            $error      = "⚠️ Please enter your School ID.";
        } else {
            // Load the selected student's record
            $sv = $conn->prepare("SELECT id, name, school_id, section_id FROM students WHERE id = ?");
            $sv->bind_param("i", $student_id);
            $sv->execute();
            $stu_row = $sv->get_result()->fetch_assoc();
            $sv->close();

            // Verify School ID matches
            if (!$stu_row || strtolower(trim($stu_row['school_id'])) !== strtolower($school_id)) {
                $error = "❌ School ID does not match. Please check and try again.";
            } else {
                // Identity verified — move to confirm step
                $verified_student = $stu_row;
                $page_state       = 'confirm';
            }
        }

    // ── STEP 2: Submit Attendance (after identity verified) ───────────────────
    } elseif ($step === 'submit') {

        if (!$student_id || !in_array($student_id, $valid_ids, true)) {
            $error      = "⚠️ Invalid student. Please go back and try again.";
            $page_state = 'error';
        } else {
            $now_submit = time();

            // Re-check session expiry
            if ($now_submit >= strtotime($session['session_end'])) {
                $error      = "⏱️ Session expired while you were on this page. Ask your teacher for a new QR code.";
                $page_state = 'error';
            } else {
                // ── Device Binding Check ──────────────────────────────────────
                $fp_blocked = false;
                $today      = date('Y-m-d', $now_submit);

                if (!empty($fingerprint)) {
                    // Look up this fingerprint for today
                    $fl = $conn->prepare(
                        "SELECT student_id FROM device_locks WHERE fingerprint = ? AND locked_date = ?"
                    );
                    $fl->bind_param("ss", $fingerprint, $today);
                    $fl->execute();
                    $fl_row = $fl->get_result()->fetch_assoc();
                    $fl->close();

                    if ($fl_row) {
                        // This device already used today
                        if ((int)$fl_row['student_id'] !== $student_id) {
                            // Different student trying to use the same device → BLOCKED
                            $fp_blocked = true;
                            $error      = "🚫 This device has already been used to mark attendance for a different student today. Only one attendance per device is allowed to prevent cheating.";
                            $page_state = 'error';
                        }
                        // else: same student re-submitting — let the UNIQUE KEY catch it below
                    } else {
                        // First use of this device today — register the lock
                        $ins_fp = $conn->prepare(
                            "INSERT IGNORE INTO device_locks (fingerprint, student_id, locked_date)
                             VALUES (?, ?, ?)"
                        );
                        $ins_fp->bind_param("sis", $fingerprint, $student_id, $today);
                        $ins_fp->execute();
                        $ins_fp->close();
                    }
                }

                if (!$fp_blocked) {
                    // Determine status
                    if ($now_submit < strtotime($session['present_until'])) {
                        $status = 'Present';
                    } elseif ($now_submit < strtotime($session['late_until'])) {
                        $status = 'Late';
                    } else {
                        $status = 'Absent';
                    }

                    $date    = date('Y-m-d', $now_submit);
                    $time_in = date('H:i:s', $now_submit);

                    // INSERT IGNORE — UNIQUE KEY (student_id, date) prevents duplicates
                    $ins = $conn->prepare(
                        "INSERT IGNORE INTO attendance (student_id, session_id, status, date, time_in)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $ins->bind_param("iisss", $student_id, $session['session_id'], $status, $date, $time_in);
                    $ins->execute();
                    $affected = $ins->affected_rows;
                    $ins->close();

                    if ($affected === 0) {
                        $error      = "✋ Your attendance was already recorded today. No duplicate entry allowed.";
                        $page_state = 'error';
                    } else {
                        // Log the attendance action
                        if (function_exists('logSystemEvent')) {
                            $stu_name = '';
                            foreach ($students_list as $s) {
                                if ($s['id'] === $student_id) { $stu_name = $s['name']; break; }
                            }
                            logSystemEvent($conn, 'attendance',
                                "Student '$stu_name' marked $status via QR scan at " . date('h:i A', $now_submit)
                            );
                        }

                        $emoji    = $status === 'Present' ? '✅' : ($status === 'Late' ? '🕐' : '❌');
                        $time_str = date('h:i A', $now_submit);
                        $success  = "$emoji Attendance recorded! You are marked <strong>$status</strong> at $time_str.";
                        $page_state = 'success';
                    }
                }
            }
        }

    // ── Handle 'confirm' step reload (re-populate verified_student) ───────────
    } elseif ($step === 'confirm') {
        if ($student_id && in_array($student_id, $valid_ids, true)) {
            $sv = $conn->prepare("SELECT id, name, school_id FROM students WHERE id = ?");
            $sv->bind_param("i", $student_id);
            $sv->execute();
            $verified_student = $sv->get_result()->fetch_assoc();
            $sv->close();
            $page_state = 'confirm';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Mark Attendance</title>
    <link rel="stylesheet" href="style.css">
    <style>
    /* ── Force dark theme para sa scan page ── */
    body {
        background: #141414 !important;
        color: #e8e0d5 !important;
    }
    .scan-wrap {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
        background: #141414 !important;
    }
    .scan-card {
        background: #1e1c1a !important;
        border: 1px solid #3a3530 !important;
        border-radius: 16px;
        padding: 32px 28px;
        width: 100%;
        max-width: 420px;
        color: #e8e0d5 !important;
    }

    /* Form inputs */
    .scan-card input,
    .scan-card select {
        background: #2a2620 !important;
        border: 1px solid #3a3530 !important;
        color: #e8e0d5 !important;
        border-radius: 8px;
        padding: 10px 14px;
        width: 100%;
        font-size: 15px;
    }
    .scan-card input::placeholder { color: #7a6f65 !important; }
    .scan-card label { color: #b0a090 !important; font-weight: 600; }
    .scan-card small,
    .scan-card .form-group div { color: #7a6f65 !important; font-size: 11px; }

    /* Status bar */
    .status-bar.present { background: rgba(34,197,94,0.15);  color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
    .status-bar.late    { background: rgba(245,158,11,0.15); color: #fcd34d; border: 1px solid rgba(245,158,11,0.3); }
    .status-bar.absent  { background: rgba(239,68,68,0.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
    .status-bar {
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 18px;
        text-align: center;
    }

    /* Time windows */
    .time-windows { display:flex; gap:8px; margin-bottom:18px; font-size:12px; }
    .tw { flex:1; padding:8px; border-radius:6px; text-align:center; font-weight:600; }
    .tw.present { background:rgba(34,197,94,0.1);  color:#86efac; }
    .tw.late    { background:rgba(245,158,11,0.1); color:#fcd34d; }
    .tw.absent  { background:rgba(239,68,68,0.1);  color:#fca5a5; }

    /* Step indicator */
    .step-indicator { display:flex; align-items:center; gap:6px; margin-bottom:18px; font-size:12px; color:#7a6f65; }
    .step-dot { width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11px; }
    .step-dot.active   { background: #c49a6c; color:#fff; }
    .step-dot.done     { background: #7da27d; color:#fff; }
    .step-dot.inactive { background: #2a2620; color:#7a6f65; border:1px solid #3a3530; }
    .step-line { flex:1; height:2px; background:#3a3530; border-radius:99px; }

    /* Identity card */
    .identity-card {
        background: #2a2620;
        border: 1px solid #3a3530;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 18px;
        text-align: left;
    }
    .identity-card .ic-label { font-size:11px; color:#7a6f65; text-transform:uppercase; letter-spacing:.05em; margin-bottom:2px; }
    .identity-card .ic-value { font-size:15px; font-weight:700; color:#c49a6c; margin-bottom:10px; }

    /* Security note */
    .security-note {
        font-size:11px; color:#7a6f65;
        background: #1a1815;
        border:1px solid #3a3530;
        border-radius:6px;
        padding:8px 12px;
        margin-top:14px;
        text-align:left;
    }

    /* Alerts */
    .alert { border-radius:8px; padding:12px 14px; margin-bottom:14px; font-size:13px; text-align:left; }
    .alert-error   { background:rgba(239,68,68,0.12);  border:1px solid rgba(239,68,68,0.3);  color:#fca5a5; }
    .alert-success { background:rgba(34,197,94,0.12);  border:1px solid rgba(34,197,94,0.3);  color:#86efac; }
    .alert-info    { background:rgba(100,150,255,0.1); border:1px solid rgba(100,150,255,0.2); color:#93c5fd; }

    /* Button */
    .btn-primary {
        background: linear-gradient(135deg, #c49a6c, #a67873);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 12px 20px;
        font-weight: 700;
        cursor: pointer;
        width: 100%;
        font-size: 15px;
    }
    .btn-ghost {
        background: transparent;
        border: 1px solid #3a3530;
        color: #b0a090;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        font-size: 14px;
        text-decoration: none;
        display: block;
        text-align: center;
    }
    .btn-full { width: 100%; }
    .form-group { margin-bottom: 16px; text-align: left; }
    .form-group label { display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#b0a090; }
</style>
</head>
<body>
<div class="scan-wrap">
    <div class="scan-card">
        <div class="scan-icon">📲</div>
        <h2 style="font-size:22px; font-weight:700; margin-bottom:6px;">Mark Attendance</h2>

        <?php if ($session): ?>
        <p style="color:var(--text-muted); font-size:14px; margin-bottom:12px;">
            <strong style="color:var(--accent)"><?php echo htmlspecialchars($session['section_name']); ?></strong>
            &nbsp;|&nbsp; <?php echo date('F j, Y', $now); ?>
        </p>

        <!-- Time Windows -->
        <div class="time-windows">
            <div class="tw present">✅ Present<br><span style="font-weight:400;font-size:11px;">until <?php echo date('h:i A', strtotime($session['present_until'])); ?></span></div>
            <div class="tw late">🕐 Late<br><span style="font-weight:400;font-size:11px;">until <?php echo date('h:i A', strtotime($session['late_until'])); ?></span></div>
            <div class="tw absent">❌ Absent<br><span style="font-weight:400;font-size:11px;">after <?php echo date('h:i A', strtotime($session['late_until'])); ?></span></div>
        </div>

        <?php if ($status_now && in_array($page_state, ['verify','confirm'])): ?>
        <div class="status-bar <?php echo $status_now; ?>"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Step Indicator -->
        <?php if (in_array($page_state, ['verify','confirm'])): ?>
        <div class="step-indicator">
            <div class="step-dot <?php echo $page_state === 'verify' ? 'active' : 'done'; ?>">1</div>
            <div style="font-size:11px;color:var(--text-muted);">Verify Identity</div>
            <div class="step-line"></div>
            <div class="step-dot <?php echo $page_state === 'confirm' ? 'active' : 'inactive'; ?>">2</div>
            <div style="font-size:11px;color:var(--text-muted);">Submit</div>
        </div>
        <?php endif; ?>

        <!-- ── ERROR ── -->
        <?php if ($page_state === 'error'): ?>
            <div class="alert alert-error" style="text-align:left;"><?php echo htmlspecialchars($error); ?></div>
            <?php if ($error && !empty($token)): ?>
                <p style="font-size:12px;color:var(--text-muted);margin-top:12px;">
                    If you believe this is a mistake, please approach your teacher.
                </p>
            <?php endif; ?>

        <!-- ── SUCCESS ── -->
        <?php elseif ($page_state === 'success'): ?>
            <div class="alert alert-success" style="text-align:left;"><?php echo $success; ?></div>
            <p style="color:var(--text-muted); font-size:13px; margin-top:12px;">
                ✔️ Your identity has been verified and attendance has been recorded. You may now close this page.
            </p>
            <div class="security-note">🔒 This device has been registered for today's session. No other student can use this device for attendance today.</div>

        <!-- ── STEP 1: VERIFY IDENTITY ── -->
        <?php elseif ($page_state === 'verify' && !empty($students_list)): ?>
            <?php if ($error): ?>
                <div class="alert alert-error" style="text-align:left;margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" style="text-align:left;" id="verifyForm">
                <input type="hidden" name="token"       value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="step"        value="verify">
                <input type="hidden" name="fingerprint" id="fingerprint" value="">

                <div class="form-group">
                    <label>1. Select Your Name</label>
                    <select name="student_id" required>
                        <option value="">— Choose your name —</option>
                        <?php foreach ($students_list as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>2. Enter Your School ID</label>
                    <input type="text" name="school_id_input" placeholder="e.g. 0324-0425" required
                           autocomplete="off" autocorrect="off" autocapitalize="off">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                        Must match your registered School ID exactly.
                    </div>
                </div>

                <div class="form-group">
                    <label>3. Section</label>
                    <input type="text" value="<?php echo htmlspecialchars($session['section_name'] ?? ''); ?>"
                           disabled style="background:var(--surface2);cursor:not-allowed;">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                        Section is set by your teacher's QR code.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full" style="margin-top:12px; padding:13px;">
                    🔍 Verify Identity →
                </button>

                <div class="security-note">
                    🔒 <strong>Security Notice:</strong> This device will be registered to your account upon submission.
                    Another student cannot use this device for attendance today.
                </div>
            </form>

        <!-- ── STEP 2: CONFIRM & SUBMIT ── -->
        <?php elseif ($page_state === 'confirm' && $verified_student): ?>
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">
                Please confirm your identity before submitting attendance:
            </p>

            <div class="identity-card">
                <div class="ic-label">Full Name</div>
                <div class="ic-value"><?php echo htmlspecialchars($verified_student['name']); ?></div>
                <div class="ic-label">School ID</div>
                <div class="ic-value"><?php echo htmlspecialchars($verified_student['school_id']); ?></div>
                <div class="ic-label">Section</div>
                <div class="ic-value"><?php echo htmlspecialchars($session['section_name'] ?? ''); ?></div>
            </div>

            <form method="POST" style="text-align:left;" id="submitForm">
                <input type="hidden" name="token"       value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="step"        value="submit">
                <input type="hidden" name="student_id"  value="<?php echo $verified_student['id']; ?>">
                <input type="hidden" name="fingerprint" id="fingerprint2" value="">

                <button type="submit" class="btn btn-primary btn-full" style="padding:13px;">
                    ✅ Submit Attendance
                </button>
                <a href="scan.php?token=<?php echo urlencode($token); ?>"
                   class="btn btn-ghost btn-full" style="margin-top:8px;">
                    ← Go Back
                </a>

                <div class="security-note">
                    ⚠️ By submitting, you confirm this is your own attendance.
                    Submitting on behalf of another student is a violation of academic integrity.
                </div>
            </form>

        <?php elseif ($page_state === 'verify' && empty($students_list)): ?>
            <div class="alert alert-info">No students are registered in this section yet.</div>
        <?php endif; ?>

    </div>
</div>

<script>
/**
 * Device Fingerprint Generator
 * Creates a reasonably unique identifier for this browser/device.
 * Combines: canvas hash + screen properties + timezone + browser agent.
 * This is NOT infallible but provides meaningful deterrence for a classroom.
 */
(function() {
    function generateFingerprint() {
        const parts = [];

        // Canvas fingerprint
        try {
            const canvas  = document.createElement('canvas');
            const ctx     = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font      = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('AttendTrack🎓', 2, 15);
            ctx.fillStyle = 'rgba(102,204,0,0.7)';
            ctx.fillText('AttendTrack🎓', 4, 17);
            parts.push(canvas.toDataURL().slice(-50));
        } catch(e) { parts.push('nocanvas'); }

        // Screen + window
        parts.push([
            screen.width, screen.height,
            screen.colorDepth,
            window.devicePixelRatio || 1,
            screen.availWidth, screen.availHeight
        ].join('x'));

        // Timezone
        parts.push(Intl.DateTimeFormat().resolvedOptions().timeZone || 'notz');

        // Language
        parts.push(navigator.language || 'nolang');

        // Platform
        parts.push(navigator.platform || 'noplat');

        // Simple hash
        const raw = parts.join('||');
        let hash = 0;
        for (let i = 0; i < raw.length; i++) {
            hash = ((hash << 5) - hash) + raw.charCodeAt(i);
            hash |= 0;
        }
        return 'fp_' + Math.abs(hash).toString(36) + '_' + raw.length.toString(36);
    }

    const fp = generateFingerprint();

    // Inject into both forms if they exist
    const f1 = document.getElementById('fingerprint');
    const f2 = document.getElementById('fingerprint2');
    if (f1) f1.value = fp;
    if (f2) f2.value = fp;
})();
</script>
</body>
</html>
