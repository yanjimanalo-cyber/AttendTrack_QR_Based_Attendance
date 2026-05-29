<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    header("Location: index.php?error=Please fill in all fields.");
    exit;
}

$stmt = $conn->prepare("SELECT id, name, password FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];

    // ── Log login event ───────────────────────────────────────────────────────
    logSystemEvent($conn, 'login',
        "Teacher '{$user['name']}' logged in from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        $user['id']
    );

    // ── Force a backup on every login ─────────────────────────────────────────
    // Reset the last-backup timer so a fresh backup runs right now
    $lockFile = __DIR__ . '/backups/.last_backup';
    if (file_exists($lockFile)) {
        file_put_contents($lockFile, '0', LOCK_EX);
    }

    header("Location: dashboard.php");
} else {
    // Log failed login attempt
    logSystemEvent($conn, 'login_failed',
        "Failed login attempt for email: $email from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    );
    header("Location: index.php?error=Invalid email or password.");
}
exit;
