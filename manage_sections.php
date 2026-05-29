<?php
include 'db.php';
requireLogin();

$success = $error = '';

// DELETE
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $d = $conn->prepare("DELETE FROM sections WHERE id = ?");
    $d->bind_param("i", $del_id);
    if ($d->execute()) {
        $success = "Section deleted.";
    } else {
        $error = "Cannot delete — section may have students.";
    }
    $d->close();
}

// EDIT LOAD
$edit_sec = null;
if (isset($_GET['edit'])) {
    $e = $conn->prepare("SELECT * FROM sections WHERE id = ?");
    $e->bind_param("i", intval($_GET['edit']));
    $e->execute();
    $edit_sec = $e->get_result()->fetch_assoc();
    $e->close();
}

// ADD / UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $edit_id = intval($_POST['edit_id'] ?? 0);

    if (empty($name)) {
        $error = "Section name is required.";
    } elseif ($edit_id) {
        $u = $conn->prepare("UPDATE sections SET name=? WHERE id=?");
        $u->bind_param("si", $name, $edit_id);
        $u->execute();
        $u->close();
        $success = "Section updated!";
        $edit_sec = null;
    } else {
        $ins = $conn->prepare("INSERT INTO sections (name) VALUES (?)");
        $ins->bind_param("s", $name);
        if ($ins->execute()) {
            $success = "Section added!";
        } else {
            $error = "Section name already exists.";
        }
        $ins->close();
    }
}

$sections = $conn->query("SELECT sections.id, sections.name, COUNT(students.id) as cnt FROM sections LEFT JOIN students ON students.section_id = sections.id GROUP BY sections.id ORDER BY sections.name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AttendTrack — Manage Sections</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">🗂️ Manage Sections</div>
                <div class="page-subtitle">Add, rename, or remove class sections</div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:340px 1fr; gap:24px; align-items:start;">

            <!-- Form -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo $edit_sec ? 'Edit Section' : 'Add Section'; ?></span>
                </div>
                <div class="card-body">
                    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?php echo $error;   ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success">✅ <?php echo $success; ?></div><?php endif; ?>

                    <form method="POST">
                        <?php if ($edit_sec): ?>
                            <input type="hidden" name="edit_id" value="<?php echo $edit_sec['id']; ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Section Name</label>
                            <input type="text" name="name" placeholder="e.g. Info2B" required
                                   value="<?php echo htmlspecialchars($edit_sec['name'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-full">
                            <?php echo $edit_sec ? '💾 Update Section' : '+ Add Section'; ?>
                        </button>
                        <?php if ($edit_sec): ?>
                            <a href="manage_sections.php" class="btn btn-ghost btn-full" style="margin-top:8px;">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">All Sections</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Section</th><th>Students</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($sections->num_rows === 0): ?>
                            <tr><td colspan="3"><div class="empty-state"><div class="empty-icon">📁</div><p>No sections yet.</p></div></td></tr>
                        <?php else: ?>
                            <?php while ($sec = $sections->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($sec['name']); ?></strong></td>
                                <td class="text-mono text-muted"><?php echo $sec['cnt']; ?></td>
                                <td>
                                    <div class="flex gap-2">
                                        <a href="manage_sections.php?edit=<?php echo $sec['id']; ?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
                                        <a href="manage_sections.php?delete=<?php echo $sec['id']; ?>"
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Delete section \'<?php echo htmlspecialchars($sec['name']); ?>\'? This will also delete all students inside!')">
                                           🗑️
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
