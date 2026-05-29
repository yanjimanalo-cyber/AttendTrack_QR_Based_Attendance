<?php
include 'db.php';
requireLogin();

$section_id = intval($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
$success = $error = '';

// ── DELETE ──
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $d = $conn->prepare("DELETE FROM students WHERE id = ?");
    $d->bind_param("i", $del_id);
    $d->execute();
    $d->close();
    header("Location: attendance.php?section_id=$section_id");
    exit;
}

// ── EDIT LOAD ──
$edit_student = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $e = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $e->bind_param("i", $edit_id);
    $e->execute();
    $edit_student = $e->get_result()->fetch_assoc();
    $e->close();
}

// ── ADD / UPDATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']      ?? '');
    $school_id = trim($_POST['school_id'] ?? '');
    $sec_id    = intval($_POST['section_id'] ?? 0);
    $edit_id   = intval($_POST['edit_id']   ?? 0);

    if (empty($name) || empty($school_id) || !$sec_id) {
        $error = "All fields are required.";
    } else {
        if ($edit_id) {
            // UPDATE
            $u = $conn->prepare("UPDATE students SET name=?, school_id=?, section_id=? WHERE id=?");
            $u->bind_param("ssii", $name, $school_id, $sec_id, $edit_id);
            $u->execute();
            $u->close();
            $success = "Student updated successfully!";
        } else {
            // CHECK duplicate school_id
            $chk = $conn->prepare("SELECT id FROM students WHERE school_id = ?");
            $chk->bind_param("s", $school_id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $error = "School ID already exists.";
            } else {
                $ins = $conn->prepare("INSERT INTO students (name, school_id, section_id) VALUES (?, ?, ?)");
                $ins->bind_param("ssi", $name, $school_id, $sec_id);
                $ins->execute();
                $ins->close();
                $success = "Student added successfully!";
            }
            $chk->close();
        }
        if (!$error && $sec_id) {
            header("Location: attendance.php?section_id=$sec_id");
            exit;
        }
    }
}

// Sections dropdown
$sections = $conn->query("SELECT id, name FROM sections ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — <?php echo $edit_student ? 'Edit' : 'Add'; ?> Student</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title"><?php echo $edit_student ? ' Edit Student' : ' Add Student'; ?></div>
                <div class="page-subtitle"><?php echo $edit_student ? 'Update student information' : 'Register a new student'; ?></div>
            </div>
            <?php if ($section_id): ?>
                <a href="attendance.php?section_id=<?php echo $section_id; ?>" class="btn btn-ghost">← Back</a>
            <?php endif; ?>
        </div>

        <div class="card" style="max-width:500px;">
            <div class="card-header">
                <span class="card-title"><?php echo $edit_student ? 'Student Details' : 'New Student'; ?></span>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?php if ($edit_student): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_student['id']; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="section_id" value="<?php echo $section_id ?: ($edit_student['section_id'] ?? 0); ?>">

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" placeholder="Juan Dela Cruz" required
                               value="<?php echo htmlspecialchars($edit_student['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>School / Student ID</label>
                        <input type="text" name="school_id" placeholder="S-2024-001" required
                               value="<?php echo htmlspecialchars($edit_student['school_id'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <select name="section_id" required>
                            <option value="">— Select section —</option>
                            <?php
                            $sections->data_seek(0);
                            while ($sec = $sections->fetch_assoc()):
                                $sel = ($sec['id'] == ($section_id ?: ($edit_student['section_id'] ?? 0))) ? 'selected' : '';
                            ?>
                            <option value="<?php echo $sec['id']; ?>" <?php echo $sel; ?>>
                                <?php echo htmlspecialchars($sec['name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">
                        <?php echo $edit_student ? '💾 Save Changes' : '+ Add Student'; ?>
                    </button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
