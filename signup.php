<?php
include 'db.php';
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Sign Up</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="logo">
            <div class="logo-icon">📋</div>
            <div><div class="logo-text">AttendTrack</div></div>
        </div>

        <h2>Create account</h2>
        <p class="subtitle">Register as a teacher / admin</p>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="Juan Dela Cruz" required>
            </div>
            <div class="form-group">
                <label>School / Employee ID</label>
                <input type="text" name="school_id" placeholder="T-2024-001" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="teacher@school.edu" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Min. 8 characters" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Create Account
            </button>
        </form>

        <div class="auth-link">
            Already have an account? <a href="index.php">Sign in</a>
        </div>
    </div>
</div>
</body>
</html>
