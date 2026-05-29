<?php
include 'db.php';
// Already logged in? Go to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="logo">
            <div class="logo-icon">📋</div>
            <div>
                <div class="logo-text">AttendTrack</div>
            </div>
        </div>

        <h2>Welcome back</h2>
        <p class="subtitle">Sign in to your teacher account</p>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">✅ Account created! You can now log in.</div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="teacher@school.edu" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Sign In →
            </button>
        </form>

        <div class="auth-link">
            Don't have an account? <a href="signup.php">Create one</a>
        </div>
    </div>
</div>
</body>
</html>
