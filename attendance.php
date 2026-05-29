<?php
include 'db.php';
requireLogin();

$section_id = intval($_GET['section_id'] ?? 0);
if (!$section_id) { header("Location: section.php"); exit; }

$s = $conn->prepare("SELECT * FROM sections WHERE id = ?");
$s->bind_param("i", $section_id);
$s->execute();
$section = $s->get_result()->fetch_assoc();
$s->close();
if (!$section) { header("Location: section.php"); exit; }

$token   = trim($_GET['token'] ?? '');
$session = null;
$qr_url  = null;

if ($token) {
    $qs = $conn->prepare("SELECT * FROM qr_sessions WHERE token = ? AND section_id = ?");
    $qs->bind_param("si", $token, $section_id);
    $qs->execute();
    $session = $qs->get_result()->fetch_assoc();
    $qs->close();
}
if (!$session) {
    $qs = $conn->prepare("SELECT * FROM qr_sessions WHERE section_id = ? AND session_end > NOW() ORDER BY created_at DESC LIMIT 1");
    $qs->bind_param("i", $section_id);
    $qs->execute();
    $session = $qs->get_result()->fetch_assoc();
    $qs->close();
}
if ($session) {
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $qr_url     = $protocol . '://' . $host . $script_dir . '/scan.php?token=' . $session['token'];
}

$stu = $conn->prepare("
    SELECT s.id, s.name, s.school_id, a.status, a.time_in
    FROM students s
    LEFT JOIN attendance a ON a.student_id = s.id AND a.date = CURDATE()
    WHERE s.section_id = ?
    ORDER BY s.name
");
$stu->bind_param("i", $section_id);
$stu->execute();
$students = $stu->get_result();

$pc = $conn->prepare("SELECT COUNT(*) c FROM attendance a JOIN students s ON s.id=a.student_id WHERE s.section_id=? AND a.date=CURDATE() AND a.status='Present'");
$pc->bind_param("i",$section_id); $pc->execute();
$present_count = $pc->get_result()->fetch_assoc()['c']; $pc->close();

$lc = $conn->prepare("SELECT COUNT(*) c FROM attendance a JOIN students s ON s.id=a.student_id WHERE s.section_id=? AND a.date=CURDATE() AND a.status='Late'");
$lc->bind_param("i",$section_id); $lc->execute();
$late_count = $lc->get_result()->fetch_assoc()['c']; $lc->close();

$tc = $conn->prepare("SELECT COUNT(*) c FROM students WHERE section_id=?");
$tc->bind_param("i",$section_id); $tc->execute();
$total_count = $tc->get_result()->fetch_assoc()['c']; $tc->close();

$qr_error = urldecode($_GET['qr_error'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — <?php echo htmlspecialchars($section['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.65);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #ddd);
            border-radius: 14px;
            padding: 28px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.15);
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .modal-sub   { font-size: 13px; color: var(--text-muted, #888); margin-bottom: 20px; }
        .preset-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 20px; }
        .preset-btn {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border, #ddd);
            background: var(--surface2, #f5f5f5);
            color: var(--text, #333);
            font-size: 12px; font-weight: 600;
            cursor: pointer; text-align: left;
            transition: all 0.15s; line-height: 1.4;
        }
        .preset-btn:hover   { border-color: var(--accent, #c49a6c); background: rgba(196,154,108,0.1); }
        .preset-btn.selected{ border-color: var(--accent, #c49a6c); background: rgba(196,154,108,0.15); color: var(--accent, #c49a6c); }
        .preset-btn .pb-sub { font-size: 10px; font-weight: 400; color: var(--text-muted,#888); display:block; margin-top:2px; }
        .time-row { display: grid; grid-template-columns: 1fr 28px 1fr; align-items: end; gap: 8px; margin-bottom: 16px; }
        .time-row .sep { color: var(--text-muted,#888); font-size:18px; text-align:center; padding-bottom:8px; }
        .window-preview { background: var(--surface2,#f5f5f5); border: 1px solid var(--border,#ddd); border-radius: 8px; padding: 12px 14px; margin-bottom: 20px; }
        .wp-head { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); margin-bottom:10px; }
        .wp-row  { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--border,#ddd); font-size:13px; }
        .wp-row:last-child { border-bottom:none; }
        .wp-label { font-weight:600; }
        .wp-time  { font-family:monospace; font-size:12px; color:var(--text-muted,#888); }
        .c-present { color:#16a34a; } .c-late { color:#b45309; } .c-absent { color:#dc2626; } .c-muted { color:var(--text-muted,#888); }
        .dur-note { font-size:11px; color:var(--text-muted); margin-top:8px; line-height:1.6; }
        .or-divider { display:flex; align-items:center; gap:10px; margin-bottom:14px; font-size:11px; color:var(--text-muted,#888); }
        .or-divider::before, .or-divider::after { content:''; flex:1; height:1px; background:var(--border,#ddd); }
        .modal-actions { display:flex; gap:10px; margin-top:4px; }
        .modal-actions .btn { flex:1; }
        .err-msg { font-size:12px; color:#dc2626; margin-top:6px; display:none; }
        .modal-section-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:8px; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>
    <main class="main">

        <div class="page-header">
            <div>
                <div class="page-title">📚 <?php echo htmlspecialchars($section['name']); ?></div>
                <div class="page-subtitle">
                    <?php echo $present_count; ?> Present &nbsp;·&nbsp;
                    <?php echo $late_count; ?> Late &nbsp;·&nbsp;
                    <?php echo $total_count; ?> Total
                </div>
            </div>
            <div class="flex gap-2">
                <a href="add_student.php?section_id=<?php echo $section_id; ?>" class="btn btn-ghost">+ Student</a>
                <button type="button" class="btn btn-primary" onclick="openModal()">🔲 Generate QR</button>
            </div>
        </div>

        <?php if ($qr_error): ?>
        <div class="alert alert-error" style="margin-bottom:16px;">⚠️ <?php echo htmlspecialchars($qr_error); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['marked'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px;">✅ Attendance marked!</div>
        <?php endif; ?>

        <?php if ($qr_url && time() < strtotime($session['session_end'])): ?>
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <span class="card-title">🟢 Active QR Session</span>
                <span class="badge badge-success">Ends <?php echo date('h:i A', strtotime($session['session_end'])); ?></span>
            </div>
            <div class="card-body" style="display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap;">
                <div class="qr-box">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($qr_url); ?>"
                         alt="QR Code" width="200" height="200">
                    <div class="qr-expiry" style="color:#555;font-size:12px;">Scan with phone camera</div>
                </div>
                <div style="flex:1;min-width:200px;">
                    <div style="display:flex;gap:8px;margin-bottom:14px;font-size:12px;">
                        <div style="flex:1;padding:8px;border-radius:6px;text-align:center;font-weight:600;background:rgba(34,197,94,0.15);color:#16a34a;">
                            ✅ Present<br><span style="font-weight:400;">until <?php echo date('h:i A', strtotime($session['present_until'])); ?></span>
                        </div>
                        <div style="flex:1;padding:8px;border-radius:6px;text-align:center;font-weight:600;background:rgba(245,158,11,0.15);color:#b45309;">
                            🕐 Late<br><span style="font-weight:400;">until <?php echo date('h:i A', strtotime($session['late_until'])); ?></span>
                        </div>
                        <div style="flex:1;padding:8px;border-radius:6px;text-align:center;font-weight:600;background:rgba(239,68,68,0.15);color:#dc2626;">
                            ❌ Absent<br><span style="font-weight:400;">after <?php echo date('h:i A', strtotime($session['late_until'])); ?></span>
                        </div>
                    </div>
                    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:11px;word-break:break-all;margin-bottom:10px;color:var(--accent);">
                        <?php echo htmlspecialchars($qr_url); ?>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="navigator.clipboard.writeText('<?php echo addslashes($qr_url); ?>').then(()=>alert('Copied!'))" class="btn btn-ghost btn-sm">📋 Copy Link</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="openModal()">🔄 New QR</button>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card" style="margin-bottom:20px;">
            <div class="card-body" style="display:flex;align-items:center;gap:14px;padding:16px 20px;">
                <span style="font-size:28px;">🔲</span>
                <div>
                    <div style="font-weight:600;margin-bottom:2px;">No Active QR Session</div>
                    <div style="font-size:13px;color:var(--text-muted);">Click <strong>Generate QR</strong> to set the class schedule and start a session.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Students</span>
                <span class="text-muted"><?php echo $total_count; ?> total</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Name</th><th>School ID</th><th>Status</th><th>Time In</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($students->num_rows === 0): ?>
                        <tr><td colspan="5">
                            <div class="empty-state">
                                <div class="empty-icon">👥</div>
                                <p>No students yet. <a href="add_student.php?section_id=<?php echo $section_id;?>" style="color:var(--accent)">Add one →</a></p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php while($row = $students->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td class="text-mono text-muted"><?php echo htmlspecialchars($row['school_id']); ?></td>
                            <td>
                                <?php if ($row['status'] === 'Present'): ?>
                                    <span class="badge badge-success">✅ Present</span>
                                <?php elseif ($row['status'] === 'Late'): ?>
                                    <span class="badge badge-warn">🕐 Late</span>
                                <?php elseif ($row['status'] === 'Absent'): ?>
                                    <span class="badge badge-danger">❌ Absent</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(0,0,0,0.05);color:var(--text-muted);">— Not yet</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-mono text-muted">
                                <?php echo $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '—'; ?>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <?php if (!$row['status'] && $session && time() < strtotime($session['session_end'])): ?>
                                    <form method="POST" action="save_attendance.php">
                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="session_id"  value="<?php echo $session['id']; ?>">
                                        <input type="hidden" name="section_id"  value="<?php echo $section_id; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">✓ Mark</button>
                                    </form>
                                    <?php endif; ?>
                                    <a href="add_student.php?edit=<?php echo $row['id']; ?>&section_id=<?php echo $section_id; ?>" class="btn btn-ghost btn-sm">✏️</a>
                                    <a href="add_student.php?delete=<?php echo $row['id']; ?>&section_id=<?php echo $section_id; ?>"
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Delete student?')">🗑️</a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- SCHEDULE PICKER MODAL -->
<div class="modal-overlay" id="scheduleModal" onclick="handleOverlay(event)">
    <div class="modal-box">
        <div class="modal-title">📅 Set Class Schedule</div>
        <div class="modal-sub">Pick a preset or set a custom time range. Present and Late windows are computed automatically.</div>

        <div class="modal-section-label">🎓 Lecture Classes (1 hr)</div>
        <div class="preset-grid" style="margin-bottom:14px;">
            <button type="button" class="preset-btn" onclick="applyPreset('07:00','08:00',this)">7:00 AM – 8:00 AM<span class="pb-sub">1 hour · Lecture</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('08:00','09:00',this)">8:00 AM – 9:00 AM<span class="pb-sub">1 hour · Lecture</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('09:00','10:00',this)">9:00 AM – 10:00 AM<span class="pb-sub">1 hour · Lecture</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('10:00','11:00',this)">10:00 AM – 11:00 AM<span class="pb-sub">1 hour · Lecture</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('13:00','14:00',this)">1:00 PM – 2:00 PM<span class="pb-sub">1 hour · Lecture</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('14:00','15:00',this)">2:00 PM – 3:00 PM<span class="pb-sub">1 hour · Lecture</span></button>
        </div>

        <div class="modal-section-label">🔬 Lab Classes (3 hrs)</div>
        <div class="preset-grid" style="margin-bottom:20px;">
            <button type="button" class="preset-btn" onclick="applyPreset('07:00','10:00',this)">7:00 AM – 10:00 AM<span class="pb-sub">3 hours · Lab</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('08:00','11:00',this)">8:00 AM – 11:00 AM<span class="pb-sub">3 hours · Lab</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('13:00','16:00',this)">1:00 PM – 4:00 PM<span class="pb-sub">3 hours · Lab</span></button>
            <button type="button" class="preset-btn" onclick="applyPreset('14:00','17:00',this)">2:00 PM – 5:00 PM<span class="pb-sub">3 hours · Lab</span></button>
        </div>

        <div class="or-divider">or set custom time</div>

        <div class="time-row">
            <div>
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Class Start</label>
                <input type="time" id="classStart" oninput="onTimeInput()">
            </div>
            <div class="sep">→</div>
            <div>
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Class End</label>
                <input type="time" id="classEnd" oninput="onTimeInput()">
            </div>
        </div>
        <div class="err-msg" id="timeErr">⚠️ End time must be after start time.</div>

        <div class="window-preview" id="windowPreview" style="display:none;">
            <div class="wp-head">Session Windows Preview</div>
            <div class="wp-row"><span class="wp-label c-present">✅ Present until</span><span class="wp-time" id="prevPresent">—</span></div>
            <div class="wp-row"><span class="wp-label c-late">🕐 Late until</span><span class="wp-time" id="prevLate">—</span></div>
            <div class="wp-row"><span class="wp-label c-absent">❌ Absent window</span><span class="wp-time" id="prevAbsent">—</span></div>
            <div class="wp-row"><span class="wp-label c-muted">🔒 QR Closes</span><span class="wp-time" id="prevEnd">—</span></div>
            <div class="dur-note" id="durNote"></div>
        </div>

        <form method="POST" action="generate_qr.php" id="qrForm">
            <input type="hidden" name="section_id"   value="<?php echo $section_id; ?>">
            <input type="hidden" name="present_mins" id="hPresent" value="">
            <input type="hidden" name="late_mins"    id="hLate"    value="">
            <input type="hidden" name="session_mins" id="hSession" value="">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="genBtn" disabled>🔲 Generate QR</button>
            </div>
        </form>
    </div>
</div>

<script>
function toMins(hhmm){const[h,m]=hhmm.split(':').map(Number);return h*60+m;}
function toHHMM(mins){const t=((mins%1440)+1440)%1440;return String(Math.floor(t/60)).padStart(2,'0')+':'+String(t%60).padStart(2,'0');}
function fmt12(hhmm){const[h,m]=hhmm.split(':').map(Number);return(h%12||12)+':'+String(m).padStart(2,'0')+(h>=12?' PM':' AM');}
function applyPreset(s,e,btn){document.getElementById('classStart').value=s;document.getElementById('classEnd').value=e;document.querySelectorAll('.preset-btn').forEach(b=>b.classList.remove('selected'));btn.classList.add('selected');updatePreview();}
function onTimeInput(){document.querySelectorAll('.preset-btn').forEach(b=>b.classList.remove('selected'));updatePreview();}
function updatePreview(){
    const sv=document.getElementById('classStart').value,ev=document.getElementById('classEnd').value;
    const pre=document.getElementById('windowPreview'),err=document.getElementById('timeErr'),btn=document.getElementById('genBtn');
    if(!sv||!ev){pre.style.display='none';btn.disabled=true;err.style.display='none';return;}
    const sm=toMins(sv),em=toMins(ev),total=em-sm;
    if(total<=0){pre.style.display='none';btn.disabled=true;err.style.display='block';return;}
    err.style.display='none';
    const pMins=total>=60?15:10,lMins=total>=60?15:10,sMins=total;
    const pEnd=toHHMM(sm+pMins),lEnd=toHHMM(sm+pMins+lMins);
    document.getElementById('prevPresent').textContent=fmt12(sv)+' – '+fmt12(pEnd);
    document.getElementById('prevLate').textContent=fmt12(pEnd)+' – '+fmt12(lEnd);
    document.getElementById('prevAbsent').textContent=fmt12(lEnd)+' – '+fmt12(ev);
    document.getElementById('prevEnd').textContent=fmt12(ev);
    const hrs=Math.floor(total/60),mins=total%60,dur=hrs>0?`${hrs}h${mins>0?' '+mins+'m':''}`:`${mins}m`;
    document.getElementById('durNote').innerHTML=`📌 Duration: <strong>${dur}</strong> &nbsp;·&nbsp; ✅ Present: first <strong>${pMins} min</strong> &nbsp;·&nbsp; 🕐 Late: next <strong>${lMins} min</strong>`;
    document.getElementById('hPresent').value=pMins;
    document.getElementById('hLate').value=pMins+lMins;
    document.getElementById('hSession').value=sMins;
    pre.style.display='block';btn.disabled=false;
}
function openModal(){
    document.getElementById('scheduleModal').classList.add('active');
    if(!document.getElementById('classStart').value){
        const now=new Date();now.setMinutes(now.getMinutes()<30?0:30,0,0);
        document.getElementById('classStart').value=String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
    }
}
function closeModal(){document.getElementById('scheduleModal').classList.remove('active');}
function handleOverlay(e){if(e.target===document.getElementById('scheduleModal'))closeModal();}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
</script>
</body>
</html>