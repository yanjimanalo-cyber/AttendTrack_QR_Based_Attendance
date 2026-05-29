# AttendTrack v2.0

A QR-based student attendance tracking system for teachers.

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.4+
- Web server: Apache or Nginx (XAMPP / WAMP / Laragon recommended for local dev)

## Setup (Local with XAMPP)

1. **Copy the project folder** into your `htdocs` directory:
   ```
   C:\xampp\htdocs\AttendTrack\
   ```

2. **Start XAMPP** — make sure Apache and MySQL are running.

3. **Create the database** — open `http://localhost/phpmyadmin`, then:
   - Click **New** → name it `attendance_db` → click **Create**
   - Select the new database → click **Import** → choose `setup.sql` → click **Go**

4. **Configure DB credentials** — open `db.php` and update if needed:
   ```php
   $conn = new mysqli("localhost", "root", "", "attendance_db");
   ```
   Default XAMPP uses `root` with no password. Change to match your setup.

5. **Open the app** in your browser:
   ```
   http://localhost/AttendTrack/
   ```

6. **Register a teacher account** via the Sign Up page, then log in.

## File Overview

| File | Purpose |
|------|---------|
| `index.php` | Login page |
| `signup.php` / `register.php` | Teacher registration |
| `dashboard.php` | Overview stats & recent attendance |
| `section.php` | Section list / picker |
| `manage_sections.php` | Add / rename / delete sections |
| `attendance.php` | Per-section attendance view + QR panel |
| `add_student.php` | Add or edit a student |
| `generate_qr.php` | Creates a timed QR session |
| `scan.php` | Student-facing QR scan page (no login needed) |
| `save_attendance.php` | Handles manual "Mark" from teacher |
| `records.php` | Full attendance history with filters |
| `student_summary.php` | Per-student Present/Late/Absent totals |
| `export.php` | CSV download & printable PDF |
| `db.php` | DB connection + `requireLogin()` helper |
| `sidebar.php` | Shared navigation sidebar |
| `style.css` | All styles (nude/beige theme) |
| `setup.sql` | Database schema |
| `generate_pdf.py` | Optional Python PDF generator (not needed for InfinityFree) |

## QR Attendance Flow

1. Teacher opens a section → clicks **Generate QR**
2. A timed session is created with three windows:
   - ✅ **Present** — first 15 minutes
   - 🕐 **Late** — next 10 minutes (up to 25 min total)
   - ❌ **Absent** — recorded but marked absent after that
3. Students scan the QR code with their phone camera
4. Students select their name → submit → attendance is recorded
5. Teacher sees live updates on the attendance page

## Notes

- The `UNIQUE KEY (student_id, date)` in the `attendance` table prevents duplicate entries per day.
- QR tokens are 64-character hex strings (`bin2hex(random_bytes(32))`).
- Generating a new QR for a section clears the old session.
- The PDF export uses a browser print-ready HTML page — no Python/ReportLab needed on shared hosting.
