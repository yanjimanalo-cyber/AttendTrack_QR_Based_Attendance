<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: signup.php");
    exit;
}

$name      = trim($_POST['name']      ?? '');
$school_id = trim($_POST['school_id'] ?? '');
$email     = trim($_POST['email']     ?? '');
$password  = $_POST['password']       ?? '';

if (empty($name) || empty($school_id) || empty($email) || empty($password)) {
    header("Location: signup.php?error=All fields are required.");
    exit;
}

if (strlen($password) < 8) {
    header("Location: signup.php?error=Password must be at least 8 characters.");
    exit;
}

// Check duplicate email
$chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
$chk->bind_param("s", $email);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    header("Location: signup.php?error=Email is already registered.");
    $chk->close();
    exit;
}
$chk->close();

// Check duplicate school_id
$chk2 = $conn->prepare("SELECT id FROM users WHERE school_id = ?");
$chk2->bind_param("s", $school_id);
$chk2->execute();
$chk2->store_result();
if ($chk2->num_rows > 0) {
    header("Location: signup.php?error=School ID is already registered.");
    $chk2->close();
    exit;
}
$chk2->close();

$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (name, school_id, email, password) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $name, $school_id, $email, $hashed);

if ($stmt->execute()) {
    header("Location: index.php?registered=1");
} else {
    header("Location: signup.php?error=Registration failed. Try again.");
}

$stmt->close();
exit;
?>
