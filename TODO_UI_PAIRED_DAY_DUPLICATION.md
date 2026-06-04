# TODO: UI Paired-Day Duplication

## Current status
- In progress
- Source plan: `UI_PLAN_PAIRED_DAY_DUPLICATION.md`

## Accomplished
- [x] Renamed the paired-day checkbox in the admin generate page to match the new behavior.
  - Done in `admin/generate_schedule.php`
  - `Fast Paired Day` -> `Paired-Day Duplication`
- [x] Renamed the weekday checkbox in the admin generate page for clarity.
  - Done in `admin/generate_schedule.php`
  - `Mon-Fri only` -> `Mon-Fri Individual Days`
- [x] Renamed the paired-day handling label in the admin generate page.
  - Done in `admin/generate_schedule.php`
  - `Fast Paired Day Handling` -> `Duplication Handling`
- [x] Updated the admin duplication dropdown copy to match the backend fallback behavior.
  - Done in `admin/generate_schedule.php`
  - `Duplication with legacy fallback`
  - `Strict duplication only`
- [x] Expanded the admin duplication help text so it explains paired duplication, Wednesday doubling, and grouped report output.
  - Done in `admin/generate_schedule.php`

- [x] Renamed the paired-day checkbox in the program chair generate page to match the new behavior.
  - Done in `program_chair/generate_schedule.php`
  - `Fast paired day` -> `Paired-Day Duplication`
- [x] Renamed the weekday checkbox in the program chair generate page for clarity.
  - Done in `program_chair/generate_schedule.php`
  - `Mon-Fri only` -> `Mon-Fri Individual Days`
- [x] Added the missing duplication dropdown to the program chair generate page.
  - Done in `program_chair/generate_schedule.php`
  - Added `Duplication Handling`
  - Added `Duplication with legacy fallback`
  - Added `Strict duplication only`
- [x] Updated the program chair duplication help text to match the admin page.
  - Done in `program_chair/generate_schedule.php`
- [x] Wired the program chair backend to actually save the selected duplication mode behavior.
  - Done in `program_chair/generate_schedule.php`
  - `preferred_day_mode` is now parsed from POST
  - `allow_non_mirror_fallback` now reflects `soft` vs `strict`

## Verified
- [x] `php -l admin/generate_schedule.php`
- [x] `php -l program_chair/generate_schedule.php`
- [x] `php -l admin/view_schedules.php`
- [x] `php -l program_chair/view_schedule.php`
- [x] `php -l admin/report.php`

## Accomplished In Phase 2
- [x] Added grouped/raw toggle to the admin schedule view page.
  - Done in `admin/view_schedules.php`
  - Added `Grouped paired-day view`
  - Added `Raw entry view`
- [x] Added grouped/raw toggle to the program chair schedule view page.
  - Done in `program_chair/view_schedule.php`
  - Added `Grouped paired-day view`
  - Added `Raw entry view`
- [x] Added first-pass grouped paired-day rendering in the admin schedule view.
  - Done in `admin/view_schedules.php`
  - Mon/Thu collapses to `M/TH`
  - Tue/Fri collapses to `T/F`
  - Wednesday grouped display uses `W`
- [x] Added first-pass grouped paired-day rendering in the program chair schedule view.
  - Done in `program_chair/view_schedule.php`
  - Mon/Thu collapses to `M/TH`
  - Tue/Fri collapses to `T/F`
  - Wednesday grouped display uses `W`
- [x] Preserved raw-row editing and deletion safety.
  - Grouped multi-entry rows now tell the user to switch to raw view for individual edits
- [x] Added compact grouped room display.
  - If grouped rows use different rooms across paired days, the view shows combined room labels and day-by-day room details
- [x] Added displayed-row summary count in both schedule pages.

## Accomplished In Phase 3
- [x] Updated the report multiplier logic to better support true paired-day duplication.
  - Done in `admin/report.php`
  - Paired duplicated `M/TH` and `T/F` rows now use duplication-aware hour multipliers
  - Wednesday grouped rows now use duplication-aware unit multipliers
- [x] Added separate report unit and hour multiplier handling.
  - Done in `admin/report.php`
  - This keeps Wednesday doubled sessions from over-doubling hours while still doubling units correctly in grouped report output
- [x] Updated grouped section/report rendering to use row-specific multipliers.
  - Done in `admin/report.php`
- [x] Updated visible grouped report labels to align better with the new UI wording.
  - Done in `admin/report.php`
  - `M/TH`
  - `T/F`
  - `W`

## Remaining
- [ ] Visually verify grouped report output in the browser with a real duplication-mode job
- [ ] Review grouped row editing affordances if you want a future “expand row” action instead of raw-view switching only
- [ ] Review whether the weekday help text should explicitly mention “individual raw view” wording

## Notes
- Phase 1 of the UI plan is now much closer to the backend behavior already implemented in the GA.
- Admin and program chair generation pages now use matching duplication terminology and matching mode controls.
- Phase 2 now gives both schedule pages a safe grouped presentation layer without changing the underlying stored rows.
- Phase 3 updates the report logic, but it still needs browser-level verification against an actual generated duplication schedule.
