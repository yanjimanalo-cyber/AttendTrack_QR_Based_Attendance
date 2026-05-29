<?php
/**
 * logout.php — AttendTrack
 * Logs the logout event, then destroys the session.
 */
include 'db.php';

if (isset($_SESSION['user_id'])) {
    // Log before destroying session
    logSystemEvent($conn, 'logout',
        "Teacher '{$_SESSION['user_name']}' signed out",
        $_SESSION['user_id']
    );
}

session_unset();
session_destroy();

header("Location: index.php");
exit;
