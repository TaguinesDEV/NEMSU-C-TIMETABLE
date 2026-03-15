diff --git a/c:\xampp\htdocs\academic-scheduling\README.md b/c:\xampp\htdocs\academic-scheduling\README.md
new file mode 100644
--- /dev/null
+++ b/c:\xampp\htdocs\academic-scheduling\README.md
@@ -0,0 +1,108 @@
+# Academic Scheduling System
+
+Web-based academic scheduling system that uses a Genetic Algorithm (GA) to generate class schedules with room, instructor, and time-slot constraints.
+
+## Overview
+
+This project combines:
+
+- `PHP` for the web application (admin, program chair, instructor portals)
+- `MySQL` for data storage
+- `Python` for GA-based schedule generation (`python_ga/genetic_algorithm.py`)
+
+Generated schedules are stored in the database and can be reviewed/published from the admin/program chair interfaces.
+
+## Core Features
+
+- Role-based login (`admin`, `program_chair`, `instructor`)
+- Manage instructors, subjects, rooms, time slots, programs, and program chairs
+- Instructor specialization mapping (multiple specializations with priority)
+- Instructor availability constraints
+- Program-specific schedule generation
+- Optional Saturday scheduling
+- Published schedule conflict avoidance across programs
+- Reports and export pages in admin module
+
+## Project Structure
+
+```text
+academic-scheduling/
+|- index.php
+|- config/
+|  `- database.php
+|- includes/
+|- admin/
+|- program_chair/
+|- instructor/
+|- python_ga/
+|  |- genetic_algorithm.py
+|  `- requirements.txt
+`- sql/
+```
+
+## Requirements
+
+- XAMPP (Apache + MySQL + PHP 8+)
+- Python 3.9+ (recommended)
+- MySQL database named `academic_scheduling`
+
+## Setup
+
+1. Place the project in your web root (already under `c:\xampp\htdocs\academic-scheduling` in your current setup).
+2. Start `Apache` and `MySQL` from XAMPP.
+3. Create the database:
+   - `academic_scheduling`
+4. Import SQL files in this order:
+   - `sql/database.sql`
+   - `sql/setup_program_chairs.sql`
+   - `sql/migrate_specializations.sql`
+   - `sql/migrate_subject_type_and_hours.sql`
+   - `sql/add_slot_type.sql`
+5. Configure DB/Python paths in `config/database.php` if needed:
+   - `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
+   - `PYTHON_PATH` (for Windows typically `python`)
+6. Install Python dependencies:
+   - `cd python_ga`
+   - `pip install -r requirements.txt`
+7. Open the app:
+   - `http://localhost/academic-scheduling/`
+
+## Default Credentials
+
+- Admin account from seed SQL:
+  - Username: `admin`
+  - Password: `admin123`
+- Program chair account from setup SQL:
+  - Username: `chair`
+  - Password: `chair123`
+
+Change these immediately in non-local environments.
+
+## How Schedule Generation Works
+
+1. User creates a schedule job from:
+   - `admin/generate_schedule.php` or
+   - `program_chair/generate_schedule.php`
+2. Job input (instructors, rooms, subjects, constraints) is saved to `schedule_jobs.input_data`.
+3. PHP starts Python GA in background with job ID.
+4. Python GA reads job input, enforces constraints, and writes generated entries to `schedules`.
+5. Status is updated in `schedule_jobs`.
+
+## Notes
+
+- `config/database.php` and Python GA both expect the database name `academic_scheduling`.
+- Some legacy SQL in `sql/database.sql` may contain older naming (`academics_cheduling` / `academicscheduling`). Use `academic_scheduling` consistently to match runtime config.
+- There is a filename typo currently present in the repo: `program_chair/view_schedulde.php`.
+
+## Troubleshooting
+
+- Python job not running:
+  - Verify `PYTHON_PATH` in `config/database.php`
+  - Run `python --version` in terminal
+  - Ensure `pip install -r python_ga/requirements.txt` was completed
+- Login fails:
+  - Confirm users were inserted by SQL scripts
+  - Check password hashes were not overwritten manually
+- Empty generation results:
+  - Ensure selected instructors, rooms, and subject mappings exist
+  - Ensure instructor availability and time slots are populated
