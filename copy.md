# Plan: True Paired-Day Duplication (Mon/Thu + Tue/Fri) + Wednesday Doubling

## Goal
Transform paired-day mode so that **both lecture and lab are duplicated** across the paired days (Mon/Thu or Tue/Fri). Wednesday subjects get a **single doubled session** to maintain parity.

---

## Desired Output Patterns

### Mon/Thu Subjects (Paired-Day Duplication)
- **Monday:**    Lecture 8:00–9:00 (Room 101) + Lab 1:00–2:30 (Lab 1)
- **Thursday:**  Lecture 8:00–9:00 (Room 101) + Lab 1:00–2:30 (Lab 1)
- Total: 4 weekly meetings per subject-section → Report aggregates to 2 rows

### Wednesday Subjects (Non-Mirror Doubling)
- **Wednesday:** ANY subject scheduled on Wednesday gets a single doubled session (e.g., 8:00–10:00 for a 1hr base subject)
- Total: 1 weekly meeting per subject-section → Report shows 1 row with doubled units/hours
- This applies to ALL subjects on Wednesday (PE, Math, Programming, etc.) — not just PE

### Report Format
```
BSCS 1A
M/TH 8:00–9:00   | Subject | Instructor | Units: 2 | Hrs: 2 | Room(Lec)
M/TH 1:00–2:30   | Subject | Instructor | Units: 3 | Hrs: 3 | Room(Lab)
W    8:00–10:00  | PE      | Instructor | Units: 2 | Hrs: 2 | Room(Gym)
```
- Mon/Thu: units/hours = base × 2 (summed across both days)
- Wednesday: units/hours = base × 2 (single doubled session)

---

## Phase 1 — Gene Creation (`genetic_algorithm.py`)

### Step 1.1: Add a new mode flag
Add `self.duplicate_paired_day_mode = True` (or derive from constraints) to distinguish this from the old 2-gene paired-day mode.

### Step 1.2: Modify `create_genes()` for paired-day subjects
When `four_day_pattern == True` and `duplicate_paired_day_mode == True`, create **4 genes** per subject-section:
- `lecture-anchor`  (Monday or Tuesday)
- `lecture-mirror`  (Thursday or Friday)
- `lab-anchor`      (Monday or Tuesday)
- `lab-mirror`      (Thursday or Friday)

Each gene keeps its **original duration** (do not multiply minutes):
- Lecture: 60 min → 60 min (anchor) + 60 min (mirror) = 120 min total
- Lab: 90 min → 90 min (anchor) + 90 min (mirror) = 180 min total

Metadata per gene:
- `_day_role`: `"anchor"` or `"mirror"`
- `_meeting_kind_original`: `"lecture"` or `"lab"` (preserved across pair)
- `_partner_gene_key`: the `_gene_key` of the matching anchor/mirror partner
- `_is_wednesday_double`: `False`

### Step 1.3: Modify `create_genes()` for Wednesday subjects
When a subject is assigned to a **Wednesday-only** slot (non-mirror day), create **1 gene** with **doubled duration**:
- `meeting_minutes` = original_minutes × 2
- `meeting_hours` = original_hours × 2
- `_is_wednesday_double = True`
- Example: PE base = 60 min → gene = 120 min (8:00–10:00)

This makes Wednesday subjects match the total weekly hours of Mon/Thu subjects.

### Step 1.4: Update `get_gene_identity()`
- Ensure 4 genes have **unique identities** per section.
- `meeting_index` can run 1–4 for paired-day subjects.
- Update `target_gene_counter` so the fitness validator expects 4 entries per paired-day subject.

---

## Phase 2 — Placement Logic (`genetic_algorithm.py`)

### Step 2.1: Rewrite `create_individual()` anchor-first block
Group genes by `(section_key, subject_id, _meeting_kind_original, _day_role)`.

For **anchor genes** (`_day_role == "anchor"`):
1. Place anchor via `_try_place_gene_at_time()` on Mon/Tue slots.
2. On success, immediately place the **matching mirror gene** on the paired day (Thu/Fri) using `_try_place_mirror_gene_from_anchor_entry()`.
3. If anchor fails after all slots, try next anchor slot.

For **Wednesday genes** (`_is_wednesday_double == True`):
1. Place normally via `_try_place_gene_at_time()` on Wednesday slots.
2. No mirror copy needed.

### Step 2.2: Modify `_try_place_mirror_gene_from_anchor_entry()`
- Signature: `(anchor_entry, mirror_gene, state)`
- Copy `instructor_id` from anchor.
- Map time via `paired_slot_map` (Mon 8:00 → Thu 8:00).
- **Room strategy:**
  1. Try the **same room** as anchor first.
  2. If unavailable/occupied, pick any compatible room (lecture room for lecture, lab room for lab).
- Enforce same `_meeting_kind_original` as anchor.
- If mirror placement fails, **rollback the anchor** and retry.

### Step 2.3: Disable old cross-meeting-kind mirroring
- Remove the logic that paired `lecture` (anchor) → `lab` (mirror).
- In new mode, anchor and mirror must have **identical** meeting kinds.

### Step 2.4: Disable sequence pairs for paired-day mode
- In `build_sequence_pair_indexes()`, skip pairing if `duplicate_paired_day_mode` is on.
- Lecture and lab should be placed **independently** on the same day (morning vs afternoon), not forced back-to-back.

### Step 2.5: Update `repair_missing_genes()`
- Repair logic must recognize the 4-gene structure.
- If an anchor is missing, its partner mirror is also invalid.
- Repair anchor+mirror as a bonded pair.
- Wednesday genes repair individually.

---

## Phase 3 — Fitness & Validation (`genetic_algorithm.py`)

### Step 3.1: Update `_evaluate_individual()`
- Expect **4 entries** per paired-day subject-section.
- Expect **1 entry** per Wednesday subject-section.
- New fitness component: **instructor consistency** — anchor and mirror of the same meeting kind must share the same instructor.
- New fitness component: **time consistency** — anchor and mirror must be at the same clock time (Mon 8:00 ↔ Thu 8:00).

### Step 3.2: Update pair alignment checks
- Old: anchor count == mirror count per `(section, subject, block)`.
- New checks:
  - lecture-anchor count == lecture-mirror count (1 == 1)
  - lab-anchor count == lab-mirror count (1 == 1)
  - Anchor and mirror share same instructor
  - Anchor and mirror share same start/end time
  - Anchor and mirror use compatible room types

### Step 3.3: Update `precheck_feasibility()`
- Total required minutes must account for 4 genes per paired-day subject.
- Instructor weekly hour caps must account for doubled load.
- Room demand doubles — warn if room capacity is tight.
- Wednesday genes count as 1 slot but consume double the duration in instructor/room booking checks.

---

## Phase 4 — CP-SAT Engine (`python_solver/cpsat_engine.py`)

### Step 4.1: Gene variables
Since `create_genes()` now returns 4 genes, CP-SAT automatically creates 4 variable sets. No changes to the variable loop itself.

### Step 4.2: Update mirror constraints
Old logic assumed one subject per block per section across the pair. New logic:
- **Lecture block:** lecture-anchor and lecture-mirror must share instructor + time.
- **Lab block:** lab-anchor and lab-mirror must share instructor + time.
- Both lecture and lab can occupy the same time block on the same section (different rooms).

### Step 4.3: Wednesday constraints
- Wednesday genes are single-slot with doubled duration.
- No mirror constraints apply.
- Instructor load terms must use the doubled duration value.

### Step 4.4: Disable or relax non-mirror (Wednesday policy) constraints
- If `duplicate_paired_day_mode` is on, the "at most 2 subjects on Wednesday" rule can be relaxed or removed entirely.
- Wednesday is no longer a "special" day; it simply hosts the doubled-duration subjects.

---

## Phase 5 — Reports & UI Views

### Step 5.1: Modify `admin/report.php` — Grouping Logic
For each `(program, year_level, section, subject_id, meeting_kind)`:

**If paired-day subject (has anchor + mirror entries):**
- Group anchor and mirror entries.
- Display day label: `"M/TH"` or `"T/F"` based on pair_group.
- `units` = base_units × 2
- `hours` = base_hours × 2 (sum of anchor + mirror durations)
- `room` = show lecture room or lab room (they may differ)

**If Wednesday subject (`_is_wednesday_double` inferred from slot day):**
- Display day label: `"W"`
- `units` = base_units × 2
- `hours` = base_hours × 2 (stored duration is already doubled)

### Step 5.2: Modify `admin/view_schedules.php`
- Add toggle: **"Group by paired days"** vs **"Show individual entries"**.
- When grouped, collapse Mon+Thu rows into one visual row.
- Show a tooltip or expand button to reveal individual room assignments per day.

### Step 5.3: Modify `program_chair/view_schedule.php`
- Same grouping logic.
- Program chairs see the aggregated M/TH and W format.

---

## Phase 6 — PHP Generation Forms

### Step 6.1: Update help text in `admin/generate_schedule.php`
```
Fast Paired Day (Duplication Mode):
- Lecture and Lab each meet TWICE per week (Mon/Thu or Tue/Fri).
- Wednesday subjects meet ONCE per week in a doubled session.
- Report shows combined rows (e.g., "M/TH 8:00-9:00") with doubled units/hours.
```

### Step 6.2: Update `program_chair/generate_schedule.php`
- Same help text update.
- Preserve `allow_non_mirror_fallback` — falls back to standard 2-meeting mode if duplication mode fails.

---

## Phase 7 — Database & Save Logic

### Step 7.1: `save_schedule()`
- Inserts 4 rows per paired-day subject (anchor + mirror × 2 kinds).
- Inserts 1 row per Wednesday subject (already doubled in duration).
- No schema changes needed.

### Step 7.2: Optional enhancement — `gene_metadata` JSON column
If future reporting needs to distinguish anchor vs mirror at the DB level, store `_day_role` and `_is_wednesday_double` in a JSON metadata field. For now, infer from the slot day.

---

## Phase 8 — Backward Compatibility & Fallbacks

### Step 8.1: Preserve old paired-day mode
- When `duplicate_paired_day_mode == False`, keep existing 2-gene logic untouched.

### Step 8.2: Preserve non-paired-day mode
- When `four_day_pattern == False`, default to standard scheduling (2 genes, no mirroring).

### Step 8.3: Add fallback in `run_solver.py`
If duplication mode fails after restarts:
1. Fall back to old 2-gene paired-day mode, then
2. Fall back to standard non-paired-day mode.

---

## Phase 9 — Testing Checklist

| # | Test Case | Expected Result |
|---|-----------|-----------------|
| 1 | Lec=1.0h, Lab=1.5h, Duplication=ON, 1 section | 4 entries: Lec-Mon, Lec-Thu, Lab-Mon, Lab-Thu |
| 2 | Same instructor all 4 entries | Fitness = 100%, report shows Inst A on both M/TH rows |
| 3 | Room 101 Mon available, Room 101 Thu NOT available | Mirror uses alternate lecture room |
| 4 | 2 sections, 2 instructors | Section A = Inst A, Section B = Inst B, independently mirrored |
| 5 | Wednesday PE, base=1hr | 1 entry: Wed 8:00-10:00, report shows Units:2, Hrs:2 |
| 6 | Instructor cap = 10h, 2 paired-day subjects | Cap check sees 10h (5h × 2 subjects) correctly |
| 7 | Duplication=OFF | Old behavior: 2 entries (Lec+Lab, possibly sequence pair) |
| 8 | CP-SAT path | Produces same 4-entry (or 1-entry Wed) structure as GA |

---

## Risk Notes

| Risk | Mitigation |
|------|------------|
| Instructor hours appear doubled in DB | `save_schedule()` writes 4 rows; cap checks must sum all 4 |
| Room demand doubles | Warn if lecture/lab room counts are low |
| Report complexity | Aggregate in PHP; keep DB rows granular |
| Student schedule density | 4 meetings/subject/week; verify against academic policy |
| Wednesday doubling may break existing non-mirror rules | Gate the old "2 subjects max on Wed" rule behind `not duplicate_paired_day_mode` |

---

## Summary of Files to Change

| File | What to Change |
|------|----------------|
| `python_ga/genetic_algorithm.py` | `create_genes()`, `create_individual()`, mirror placement, fitness, precheck |
| `python_solver/cpsat_engine.py` | Mirror constraints, Wednesday handling |
| `python_solver/run_solver.py` | Fallback routing |
| `admin/report.php` | Aggregation for M/TH and W display |
| `admin/view_schedules.php` | Paired-day grouping toggle |
| `program_chair/view_schedule.php` | Same grouping logic |
| `admin/generate_schedule.php` | Help text |
| `program_chair/generate_schedule.php` | Help text |

