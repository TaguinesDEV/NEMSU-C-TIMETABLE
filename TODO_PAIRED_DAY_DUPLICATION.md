# TODO: Paired-Day Duplication

## Current status
- In progress
- Source plan: `PLAN_PAIRED_DAY_DUPLICATION.md`

## Accomplished
- [x] Added the new `duplicate_paired_day_mode` job constraint for newly generated paired-day jobs.
  - Done in `admin/generate_schedule.php`
  - Done in `program_chair/generate_schedule.php`
- [x] Updated paired-day help text so the UI now describes true duplication behavior.
  - Done in `admin/generate_schedule.php`
  - Done in `program_chair/generate_schedule.php`
- [x] Added GA support for duplication-mode gene creation.
  - Done in `python_ga/genetic_algorithm.py`
  - Paired-day subjects now create `anchor + mirror` genes per meeting kind.
  - Wednesday-only subjects now create doubled-duration genes.
- [x] Added duplication-mode metadata on genes.
  - Done in `python_ga/genetic_algorithm.py`
  - Added `_day_role`, `_meeting_kind_original`, `_partner_gene_key`, `_is_wednesday_double`
- [x] Disabled old lecture-lab sequence pairing when duplication mode is active.
  - Done in `python_ga/genetic_algorithm.py`
- [x] Added anchor-first placement for duplication mode.
  - Done in `python_ga/genetic_algorithm.py`
  - Anchor genes place first, then their mirror partner is placed immediately.
- [x] Added bonded repair for missing duplicate pairs.
  - Done in `python_ga/genetic_algorithm.py`
- [x] Updated paired-day validation keys so lecture and lab mirrors are checked independently.
  - Done in `python_ga/genetic_algorithm.py`
- [x] Added fallback from duplication mode back to the legacy paired-day mode if duplication search fails.
  - Done in `python_ga/genetic_algorithm.py`

## Verified
- [x] `python -m py_compile python_ga/genetic_algorithm.py`
- [x] `php -l admin/generate_schedule.php`
- [x] `php -l program_chair/generate_schedule.php`

## Remaining
- [ ] Review `precheck_feasibility()` against the full duplication plan for room-pressure and Wednesday-specific warnings.
- [ ] Update `python_solver/cpsat_engine.py` if we want CP-SAT parity with duplication mode.
- [ ] Update `python_solver/run_solver.py` only if fallback should move out of the GA and into the top-level orchestrator.
- [ ] Update `admin/report.php` aggregation for grouped `M/TH`, `T/F`, and doubled `W` output.
- [ ] Update `admin/view_schedules.php` for grouped paired-day display toggle.
- [ ] Update `program_chair/view_schedule.php` for grouped paired-day display.
- [ ] Run an end-to-end generation test with a real paired-day job and inspect saved `schedules` rows.

## Notes
- Assumption for this pass: Wednesday-doubled subjects are identified from existing Wednesday/non-mirror preferences plus the existing PE/PATHFIT logic.
- Report aggregation and schedule-view grouping are still pending, so the database can already store the new structure before all PHP views fully collapse it into the final display format.
