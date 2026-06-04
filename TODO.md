# Instructor Load Units Integration
**Status**: Completed

**What Was Added:**
- [x] Added `instructor_lecture_units` and `instructor_lab_units` to subjects
- [x] Added clear `Class ... Units` and `Instructor ... Load Units` labels in `admin/manage_subjects.php`
- [x] Added `Total Instructor Load Units` summaries to add/edit subject forms
- [x] Defaulted instructor load units to class units, while still allowing manual override
- [x] Switched faculty workload calculations in `admin/report.php` to use instructor load units
- [x] Updated workload report labels to say `Instructor Load Units`

**Files Touched:**
- `admin/manage_subjects.php`
- `admin/report.php`
- `TODO.md`

**Notes:**
- Class units remain separate from instructor load units.
- Faculty workload totals now use instructor load units when available, with fallback to class units for older subjects.

---

# Manage Subjects UI Enhancement
**Status**: In Progress

**Breakdown of Approved Plan:**
- [x] Create TODO.md with steps
- [x] Create TODO.md with steps
- [x] Step 1: Update HTML labels in Add Subject modal (#add_major_breakdown) to include "| units" display
- [x] Step 2: Update HTML labels in Edit Subject modal (#edit_major_breakdown) to include "| units" display  
- [x] Step 3: Add CSS for .units-display styling
- [x] Step 4: Add JS function to dynamically update units display based on credits input (both modals)
- [x] Step 5: Change "Weekly Total:" labels to "Total Hours and Units:" in both modals
- [x] Step 6: Test modals and verify functionality
- [x] Step 7: Mark complete and attempt_completion

**Task Complete!** 🎉

**Changes Summary:**
- Moved Credits input to Time Breakdown section (removed from Basic Information)
- Added "| X units" next to Lecture/Laboratory labels in Add/Edit modals (dynamic via JS)
- Changed "Weekly Total" to "Total Hours and Units"
- Units update live when credits input changes (total credits shown for both lec/lab)
- CSS styling for units display

View changes: `admin/manage_subjects.php`

**Original Request**: Add credits(units) next to Lecture/Lab in time breakdown. Change weekly total to "total hours and units".

**Plan Details**: Use total credits (single DB field) displayed dynamically via JS next to both labels.

---

# Config And Generation Optimization Notes
**Status**: Completed

**What Was Edited:**
- [x] Phase 1 config cleanup in `config/database.php`
- [x] Switched shared PHP DB config to env-first values with local fallbacks
- [x] Added env-based DB config to `python_ga/genetic_algorithm.py`
- [x] Updated `scripts/import_subjects_from_subject_and_sem.php` to use shared `getDB()`
- [x] Updated helper scripts `check_pending_jobs.py` and `inspect_job25.py` to use env-based DB settings
- [x] Reduced per-request array scanning in `admin/generate_schedule.php`
- [x] Reduced per-request array scanning in `program_chair/generate_schedule.php`
- [x] Reduced Python GA startup overhead by caching schema validation in `python_ga/genetic_algorithm.py`
- [x] Reused instructor-subject selections from job payload before falling back to extra DB queries
- [x] Increased throttling for progress writes to reduce repeated DB updates during generation

**Files Touched:**
- `config/database.php`
- `python_ga/genetic_algorithm.py`
- `scripts/import_subjects_from_subject_and_sem.php`
- `check_pending_jobs.py`
- `inspect_job25.py`
- `admin/generate_schedule.php`
- `program_chair/generate_schedule.php`

**Why These Changes Were Made:**
- Keep DB/runtime settings consistent across PHP and Python
- Remove duplicated hard-coded credentials
- Lower generation startup overhead
- Cut unnecessary repeated lookups and array scans during job creation
- Make schedule progress tracking cheaper while generation is running

**Next Check To Do:**
- [ ] Compare old vs new schedule generation time
- [ ] Check if jobs leave `pending/processing` less often or for shorter time
- [ ] Inspect latest job log in `logs/` if generation still stalls
- [x] Filter cross-program instructor lists so only instructors with matching current semester/program subject codes are shown
- [x] Use subject-level preferred start/end time as a soft slot-ordering hint inside the GA
- [x] Add fast paired-day anchor generation that mirrors Monday/Tuesday placements into Thursday/Friday and repairs room mismatches during generation
