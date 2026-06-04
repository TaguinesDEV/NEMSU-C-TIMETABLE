# UI Plan: Paired-Day Duplication

## Goal
Align the UI with the new paired-day duplication backend behavior already introduced in:
- `python_ga/genetic_algorithm.py`
- `admin/generate_schedule.php`
- `program_chair/generate_schedule.php`

The UI should clearly explain that:
- Lecture and lab both duplicate across paired days
- Wednesday-only subjects use one doubled session
- Reports and schedule views can show grouped paired-day rows

## Current Code Reality
- New paired-day jobs now send `duplicate_paired_day_mode = true`
- Admin generate page already has updated duplication help text
- Program chair generate page already has updated duplication help text
- GA now supports:
  - lecture-anchor + lecture-mirror
  - lab-anchor + lab-mirror
  - doubled Wednesday sessions
- Schedule/report display pages do not fully reflect that model yet

## UI Direction
Keep the current scheduling workflow familiar, but rename and clarify the paired-day controls so they match the actual behavior.

Avoid introducing a brand-new complex UI if the current PHP forms can be improved with:
- better labels
- clearer helper text
- one small mode panel
- grouped/raw view toggles in display pages

## Phase 1: Generate Schedule Pages

### Target files
- `admin/generate_schedule.php`
- `program_chair/generate_schedule.php`

### Recommended control model
Use the existing structure, but rename the paired-day option:

- Current label: `Fast Paired Day`
- New label: `Paired-Day Duplication`

Keep:
- `Mon-Fri only`
- Saturday option
- other existing constraints

### Duplication panel behavior
When `Paired-Day Duplication` is checked, show a help panel with this meaning:

`Lecture and lab each meet twice per week on paired days (Mon/Thu or Tue/Fri). Wednesday-only subjects meet once in a doubled session. Reports group duplicated meetings into M/TH or T/F rows.`

### Admin page dropdown
Keep the existing admin dropdown, but rename the options to match backend behavior:

- `Strict duplication only`
- `Duplication with legacy fallback`

Meaning:
- `Strict duplication only`:
  Only the new duplication pattern is allowed
- `Duplication with legacy fallback`:
  Try duplication first, then fall back to the old paired-day behavior if needed

### Program chair page parity
Add the same duplication-mode dropdown to the program chair generate page so both pages behave consistently.

### Suggested exact UI copy
- Checkbox label: `Paired-Day Duplication`
- Dropdown label: `Duplication Handling`
- Strict option: `Strict duplication only`
- Soft option: `Duplication with legacy fallback`
- Weekday checkbox label: `Mon-Fri Individual Days`

## Phase 2: Schedule View Pages

### Target files
- `admin/view_schedules.php`
- `program_chair/view_schedule.php`

### Problem to solve
The database still stores individual rows, but duplication mode now creates:
- 4 rows for paired-day subjects
- 1 doubled row for Wednesday-only subjects

The UI should let users see either:
- grouped academic presentation
- raw stored rows

### Add a display toggle
Add a small toggle near the top of each schedule page:

- `Grouped paired-day view`
- `Raw entry view`

### Grouped view rules
- Monday + Thursday lecture rows with matching time/subject collapse into one row labeled `M/TH`
- Tuesday + Friday lecture rows collapse into one row labeled `T/F`
- Monday + Thursday lab rows collapse separately from lecture rows
- Wednesday doubled subjects show as a single `W` row

### Raw view rules
Keep the current row-per-day output unchanged for debugging and validation.

### Grouped row display example
- `M/TH 8:00-9:00 | Subject | Instructor | Units: 2 | Hrs: 2 | Room`
- `M/TH 1:00-2:30 | Subject | Instructor | Units: 3 | Hrs: 3 | Room`
- `W 8:00-10:00 | Subject | Instructor | Units: 2 | Hrs: 2 | Room`

### Room display
If both paired days use the same room:
- show one room label

If rooms differ:
- show compact combined text such as `RM101 / RM103`
- optionally add a tooltip or expand control:
  - `Mon: RM101`
  - `Thu: RM103`

## Phase 3: Report Page

### Target file
- `admin/report.php`

### Goal
The report should present duplication mode as grouped academic output, while keeping DB rows granular underneath.

### Report grouping rules
For each section and subject:
- Lecture anchor + mirror collapse into one grouped lecture row
- Lab anchor + mirror collapse into one grouped lab row
- Wednesday doubled entries remain one row

### Labels
- Use `M/TH` for Monday/Thursday
- Use `T/F` for Tuesday/Friday
- Use `W` for Wednesday doubled rows

### Hours and units
- Paired-day grouped rows should show doubled hours/units totals
- Wednesday doubled rows should also show doubled hours/units totals

### Important display detail
Do not merge lecture and lab together into one line.
They should remain separate grouped rows because the backend now treats them as separate mirrored meeting kinds.

## Phase 4: Terminology Cleanup

### Replace outdated wording
Where possible, phase out wording that suggests the old anchor-mirror shortcut behavior:

- Replace `Fast Paired Day`
- Replace vague “mirror” descriptions in user-facing copy

### Preferred user-facing terms
- `Paired-Day Duplication`
- `Duplication Handling`
- `Grouped paired-day view`
- `Raw entry view`
- `Wednesday doubled session`

### Keep technical terms internal
Do not expose low-level GA terms like:
- `anchor gene`
- `mirror gene`
- `_day_role`
- `_partner_gene_key`

Those are useful in code, not in the UI.

## Phase 5: Implementation Order

### Step 1
Finish generate-page wording consistency:
- admin page label cleanup
- program chair page label cleanup
- add duplication dropdown to program chair page

### Step 2
Add grouped/raw toggle to:
- admin schedule view
- program chair schedule view

### Step 3
Implement grouped paired-day rendering logic in:
- schedule views
- report page

### Step 4
Add small room-detail helper for grouped rows when paired rooms differ

## Recommended Minimal First Pass
If we want the smallest safe UI change first:

1. Rename `Fast Paired Day` to `Paired-Day Duplication`
2. Make admin and program chair generate pages visually consistent
3. Add grouped/raw toggle to both schedule views
4. Leave advanced room tooltips for a later pass

## Definition of Done
- Generate pages clearly describe the new duplication behavior
- Admin and program chair pages use the same paired-day wording
- Users can switch between grouped and raw schedule views
- Report output shows `M/TH`, `T/F`, and `W` correctly
- Lecture and lab are grouped separately
- Wednesday doubled sessions display as one row with doubled totals

## Notes
- This UI plan intentionally fits the backend already in progress instead of introducing a new scheduling model.
- The storage model remains granular; the grouped display is a presentation-layer concern.
