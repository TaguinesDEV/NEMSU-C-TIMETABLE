# Academic Scheduling System

Web-based academic scheduling system that uses a Genetic Algorithm (GA) to generate class schedules with room, instructor, and time-slot constraints.

## Overview

This project combines:

- `PHP` for the web application (admin, program chair, instructor portals)
- `MySQL` for data storage
- `Python` for schedule generation:
  - CP-SAT (OR-Tools) for fast feasibility (`python_solver/run_solver.py` + `python_solver/cpsat_engine.py`)
  - GA fallback (`python_ga/genetic_algorithm.py`)

Generated schedules are stored in the database and can be reviewed/published from the admin/program chair interfaces.

## Core Features

- Role-based login (`admin`, `program_chair`, `instructor`)
- Manage instructors, subjects, rooms, time slots, programs, and program chairs
- Instructor specialization mapping (multiple specializations with priority)
- Instructor availability constraints
- Program-specific schedule generation
- Optional Saturday scheduling
- Published schedule conflict avoidance across programs
- Reports and export pages in admin module

## Project Structure

```text
academic-scheduling/
├── index.php                      # Main login page
├── logout.php                     # Logout handler
├── config/
│   ├── database.php              # DB connection config
│   └── report_signatories.json   # Report configuration
├── includes/                      # Shared PHP included files
│   ├── auth.php
│   ├── header.php
│   └── footer.php
├── admin/                         # Admin portal
│   ├── dashboard.php
│   ├── manage_subjects.php
│   ├── manage_instructors.php
│   ├── manage_rooms.php
│   ├── manage_time_slots.php
│   └── ...
├── program_chair/                 # Program coordinator portal
│   ├── dashboard.php
│   ├── generate_schedule.php
│   └── view_schedule.php
├── instructor/                    # Instructor dashboard
│   └── dashboard.php
├── assets/                        # Static assets
│   ├── css/
│   ├── js/
│   └── images/
│
├── python_ga/                     # Genetic Algorithm Solver
│   ├── genetic_algorithm.py       # Main GA implementation (core)
│   └── requirements.txt
│
├── python_solver/                 # Adaptive Solver Pipeline (Optimized v2.0)
│   ├── run_solver.py             # Main orchestrator (entry point)
│   ├── preprocessing.py           # Problem complexity analysis
│   ├── simulated_annealing.py    # SA optimizer
│   ├── cpsat_engine.py           # CP-SAT solver (if OR-Tools available)
│   └── requirements.txt
│
├── sql/                          # Database schemas
│   ├── academic_scheduling.sql   # Main schema
│   └── backups/                  # Database backups
│
├── scripts/                      # Utility scripts
│
├── OPTIMIZATION_GUIDE.md         # Technical optimization documentation
├── DEPLOYMENT_GUIDE.md           # Deployment instructions
└── CLEANUP_PLAN.md              # (Internal cleanup tracking)
```

## Quick File Reference

| File | Purpose |
|---|---|
| **python_solver/run_solver.py** | Main entry point for schedule generation - intelligent router |
| **python_ga/genetic_algorithm.py** | Core genetic algorithm solver |
| **python_solver/preprocessing.py** | Problem analysis & complexity scoring |
| **python_solver/simulated_annealing.py** | Quality optimization post-solver |
| **OPTIMIZATION_GUIDE.md** | Technical documentation of optimization pipeline |
| **DEPLOYMENT_GUIDE.md** | Instructions for deployment & monitoring

## Requirements

- XAMPP (Apache + MySQL + PHP 8+)
- Python 3.9+ (recommended)
- MySQL database named `academic_scheduling`

## Setup

1. Place the project in your web root (already under `c:\xampp\htdocs\academic-scheduling` in your current setup).
2. Start `Apache` and `MySQL` from XAMPP.
3. Create the database:
   - `academic_scheduling`
4. Import the single setup file:
   - `sql/academic_scheduling.sql`
5. Configure DB/Python paths in `config/database.php` if needed:
   - `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
   - `PYTHON_PATH` (for Windows typically `python`)
6. Install Python dependencies (GA fallback):
   - `cd python_ga`
   - `pip install -r requirements.txt`
7. (Recommended) Install CP-SAT dependencies for faster generation:
   - `cd python_solver`
   - `pip install -r requirements.txt`
8. Open the app:
   - `http://localhost/academic-scheduling/`

## Default Credentials

- Admin account from seed SQL:
  - Username: `admin`
  - Password: `admin123`
- Program chair account from setup SQL:
  - Username: `chair`
  - Password: `chair123`

Change these immediately in non-local environments.

## How Schedule Generation Works

1. User creates a schedule job from:
   - `admin/generate_schedule.php` or
   - `program_chair/generate_schedule.php`
2. Job input (instructors, rooms, subjects, constraints) is saved to `schedule_jobs.input_data`.
3. PHP starts the Python scheduler in background with job ID.
4. The Python scheduler tries CP-SAT first (fast feasibility) and writes generated entries to `schedules`.
5. If CP-SAT is not installed or cannot solve the job quickly (or mirror/paired-day mode is enabled), it falls back to the GA engine.
6. Status is updated in `schedule_jobs`.

## Notes

- `config/database.php` and Python GA both expect the database name `academic_scheduling`.
- `sql/academic_scheduling.sql` is the canonical database setup file in this project.
- There is a filename typo currently present in the repo: `program_chair/view_schedulde.php`.

## Troubleshooting

- Python job not running:
  - Verify `PYTHON_PATH` in `config/database.php`
  - Run `python --version` in terminal
  - Ensure `pip install -r python_ga/requirements.txt` was completed
- Login fails:
  - Confirm users were inserted by SQL scripts
  - Check password hashes were not overwritten manually
- Empty generation results:
  - Ensure selected instructors, rooms, and subject mappings exist
  - Ensure instructor availability and time slots are populated
