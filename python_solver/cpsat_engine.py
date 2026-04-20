from __future__ import annotations

from dataclasses import dataclass
from typing import Dict, Iterable, List, Optional, Tuple


DAY_TO_INDEX = {
    "monday": 0,
    "tuesday": 1,
    "wednesday": 2,
    "thursday": 3,
    "friday": 4,
    "saturday": 5,
}


@dataclass(frozen=True)
class FixedInterval:
    start: int  # global minutes
    end: int  # global minutes


def _to_global_minutes(day: str, minutes: int) -> Optional[int]:
    idx = DAY_TO_INDEX.get((day or "").strip().lower())
    if idx is None:
        return None
    return (idx * 24 * 60) + int(minutes)


def _blocked_intervals_to_fixed(ga, intervals: Iterable[dict]) -> List[FixedInterval]:
    fixed: List[FixedInterval] = []
    for itv in intervals or []:
        day = (itv.get("day") or "").strip().lower()
        start = _to_global_minutes(day, int(itv.get("start_minutes") or 0))
        end = _to_global_minutes(day, int(itv.get("end_minutes") or 0))
        if start is None or end is None:
            continue
        if end <= start:
            continue
        fixed.append(FixedInterval(start=start, end=end))
    return fixed


def _slot_global_start(ga, slot_id: int) -> Optional[int]:
    slot = ga.get_slot(slot_id) or {}
    day = (slot.get("day") or "").strip().lower()
    start_minutes = ga.time_to_minutes(slot.get("start_time"))
    return _to_global_minutes(day, start_minutes)


def _is_slot_eligible_for_gene(ga, gene: dict, slot_id: int) -> bool:
    if ga.is_disallowed_slot(slot_id):
        return False
    interval = ga.get_entry_interval(gene, slot_id)
    if not interval:
        return False
    if not ga.is_interval_within_windows(
        interval["day"],
        interval["start_minutes"],
        interval["end_minutes"],
        ga.open_windows_by_day,
    ):
        return False
    return True


def _allowed_pairs_for_gene(ga, gene: dict, candidate_instructor_ids: List[int], allowed_slot_ids: List[int]) -> List[Tuple[int, int]]:
    """(instructor_id, slot_id) pairs that satisfy instructor availability windows for this gene's duration."""
    pairs: List[Tuple[int, int]] = []
    for iid in candidate_instructor_ids:
        windows = ga.availability_windows.get(int(iid), {})
        for slot_id in allowed_slot_ids:
            interval = ga.get_entry_interval(gene, slot_id)
            if not interval:
                continue
            if getattr(ga, "is_wednesday_slot", None) and ga.is_wednesday_slot(slot_id):
                if getattr(ga, "is_instructor_allowed_on_wednesday", None) and not ga.is_instructor_allowed_on_wednesday(int(iid), gene):
                    continue
            # interval already uses the gene's duration; ensure it fits within availability windows.
            if ga.is_interval_within_windows(
                interval["day"],
                interval["start_minutes"],
                interval["end_minutes"],
                windows,
            ):
                pairs.append((int(iid), int(slot_id)))
    return pairs


def _eligible_room_ids_for_gene(ga, gene: dict) -> List[int]:
    meeting_kind = str(gene.get("meeting_kind") or "lecture").strip().lower()
    lecture_rooms: List[int] = []
    lab_rooms: List[int] = []
    for room in ga.rooms:
        rid = int(room["id"])
        if ga.normalize_room_type(room) == "lab":
            lab_rooms.append(rid)
        else:
            lecture_rooms.append(rid)
    if meeting_kind == "lab":
        return lab_rooms or lecture_rooms or [int(r["id"]) for r in ga.rooms]
    return lecture_rooms or lab_rooms or [int(r["id"]) for r in ga.rooms]


def solve_with_cpsat(ga, timeout: Optional[float] = None) -> List[dict]:
    """
    Feasibility-first CP-SAT scheduler.

    Guarantees:
    - Respects instructor teachability (instructor_subject_map / instructor assignments)
    - Respects instructor availability windows (when enabled in the job)
    - Avoids conflicts for: room, instructor, section (interval-based, supports multi-slot meetings)
    - Avoids conflicts with *published* schedules (room & instructor) via blocked intervals

    Notes:
    - When paired-day / mirror mode is enabled on the GA instance, this model also
      enforces mirrored section usage across the configured day pairs.
    """
    # Local import: keep GA fallback working even if ortools isn't installed.
    from ortools.sat.python import cp_model

    # Early fail-fast checks already implemented in the GA class.
    ga.precheck_feasibility()

    genes: List[dict] = list(ga.genes or [])
    if not genes:
        raise Exception("No genes/classes to schedule.")

    # Precompute allowed start slots per gene (duration-aware) and slot->globalStart mapping.
    slot_global_start: Dict[int, int] = {}
    all_regular_slot_ids: List[int] = []
    for ts in ga.time_slots:
        sid = int(ts["id"])
        if ga.is_disallowed_slot(sid):
            continue
        gs = _slot_global_start(ga, sid)
        if gs is None:
            continue
        slot_global_start[sid] = int(gs)
        all_regular_slot_ids.append(sid)

    if not all_regular_slot_ids:
        raise Exception("No usable time slots (all are disallowed).")

    # Build fixed blocked intervals per room/instructor for published schedules.
    room_blocked: Dict[int, List[FixedInterval]] = {}
    for room_id, intervals in (ga.blocked_room_intervals or {}).items():
        room_blocked[int(room_id)] = _blocked_intervals_to_fixed(ga, intervals)
    inst_blocked: Dict[int, List[FixedInterval]] = {}
    for inst_id, intervals in (ga.blocked_inst_intervals or {}).items():
        inst_blocked[int(inst_id)] = _blocked_intervals_to_fixed(ga, intervals)

    # Create CP-SAT model.
    model = cp_model.CpModel()

    # Solver tuning: keep it "not time consuming" by default.
    gene_count = len(genes)
    # Small problems solve fast; allow slightly more for larger ones but keep bounded.
    # Mirror/paired-day mode has stronger coupling constraints, so allow a bit more time.
    if timeout is not None:
        max_seconds = max(1.0, float(timeout))
    elif bool(getattr(ga, "four_day_pattern", False)):
        max_seconds = 15.0 if gene_count <= 80 else (25.0 if gene_count <= 180 else 40.0)
    else:
        max_seconds = 10.0 if gene_count <= 80 else (18.0 if gene_count <= 180 else 30.0)

    # Variables per gene.
    slot_vars: List[cp_model.IntVar] = []
    start_vars: List[cp_model.IntVar] = []
    end_vars: List[cp_model.IntVar] = []
    base_intervals: List[cp_model.IntervalVar] = []
    inst_vars: List[cp_model.IntVar] = []
    room_vars: List[cp_model.IntVar] = []
    is_nonmirror_vars: List[cp_model.BoolVar] = []

    instructor_choice_bools: List[Dict[int, cp_model.BoolVar]] = []
    room_choice_bools: List[Dict[int, cp_model.BoolVar]] = []

    # Section (department, year_level, section) -> list of base intervals
    section_intervals: Dict[Tuple[str, int, str], List[cp_model.IntervalVar]] = {}

    # Keep original IDs around for decoding.
    allowed_slots_per_gene: List[List[int]] = []
    allowed_slot_set_per_gene: List[set] = []
    gene_section_key: List[Tuple[str, int, str]] = []
    gene_section_simple_key: List[Tuple[int, str]] = []
    gene_subject_id: List[int] = []
    gene_subject_code: List[str] = []

    slot_day: Dict[int, str] = {}
    slot_timekey: Dict[int, Tuple[str, str]] = {}
    for ts in ga.time_slots:
        sid = int(ts["id"])
        slot_day[sid] = str(ts.get("day") or "").strip().lower()
        slot_timekey[sid] = (str(ts.get("start_time") or ""), str(ts.get("end_time") or ""))

    for idx, gene in enumerate(genes):
        duration = int(ga.get_gene_minutes(gene))
        if duration <= 0:
            raise Exception(f"Invalid duration for gene {idx + 1}.")

        allowed_slots = [sid for sid in all_regular_slot_ids if _is_slot_eligible_for_gene(ga, gene, sid)]
        if not allowed_slots:
            code = (gene.get("subject_code") or "").strip()
            raise Exception(f"No valid time slots for {code} (duration={duration} minutes).")

        allowed_slots_per_gene.append(list(allowed_slots))
        allowed_slot_set_per_gene.append(set(allowed_slots))

        dep = str(gene.get("department") or "")
        year = int(gene.get("year_level") or 0)
        sec = str(gene.get("section") or "")
        subj_id = int(gene.get("subject_id") or 0)
        subj_code = str(gene.get("subject_code") or "").strip().upper()
        gene_section_key.append((dep, year, sec))
        gene_section_simple_key.append((year, sec))
        gene_subject_id.append(subj_id)
        gene_subject_code.append(subj_code)

        slot_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(allowed_slots), f"slot_{idx}")
        start_domain = sorted({slot_global_start[sid] for sid in allowed_slots if sid in slot_global_start})
        start_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(start_domain), f"start_{idx}")
        end_var = model.NewIntVar(min(start_domain) + duration, max(start_domain) + duration, f"end_{idx}")

        # Link slot -> global start time.
        model.AddAllowedAssignments(
            [slot_var, start_var],
            [(int(sid), int(slot_global_start[sid])) for sid in allowed_slots if sid in slot_global_start],
        )
        model.Add(end_var == start_var + duration)

        base_interval = model.NewIntervalVar(start_var, duration, end_var, f"itv_{idx}")

        slot_vars.append(slot_var)
        start_vars.append(start_var)
        end_vars.append(end_var)
        base_intervals.append(base_interval)

        # Non-mirror-day flag (used for paired-day mode constraints).
        non_mirror_days = set(getattr(ga, "non_mirror_days", []) or [])
        nonmirror_allowed = [sid for sid in allowed_slots if slot_day.get(int(sid), "") in non_mirror_days]
        nonmirror_flag = model.NewBoolVar(f"is_nonmirror_{idx}")
        # Map every allowed slot to {0,1}.
        model.AddAllowedAssignments(
            [slot_var, nonmirror_flag],
            [(int(sid), 1 if int(sid) in set(nonmirror_allowed) else 0) for sid in allowed_slots],
        )
        is_nonmirror_vars.append(nonmirror_flag)

        # Fixed section key (always applies).
        sec_key = (
            str(gene.get("department") or ""),
            int(gene.get("year_level") or 0),
            str(gene.get("section") or ""),
        )
        section_intervals.setdefault(sec_key, []).append(base_interval)

        # Instructor choice (teachability + availability).
        subject_code = (gene.get("subject_code") or "").strip().upper()
        candidates = list(ga.subject_candidate_instructors.get(subject_code) or ga.instructor_ids or [])
        if not candidates:
            raise Exception(f"No instructors available for {subject_code}.")

        # Availability filtering is duration-aware and per gene.
        allowed_inst_slot_pairs = _allowed_pairs_for_gene(ga, gene, candidates, allowed_slots)
        if not allowed_inst_slot_pairs:
            raise Exception(f"No (instructor, time) pairs fit availability for {subject_code}.")

        # Instructor var + choice bools.
        inst_ids = sorted({iid for iid, _sid in allowed_inst_slot_pairs})
        inst_bools: Dict[int, cp_model.BoolVar] = {iid: model.NewBoolVar(f"is_inst_{idx}_{iid}") for iid in inst_ids}
        model.AddExactlyOne(inst_bools.values())
        inst_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(inst_ids), f"inst_{idx}")
        for iid, b in inst_bools.items():
            model.Add(inst_var == iid).OnlyEnforceIf(b)
            model.Add(inst_var != iid).OnlyEnforceIf(b.Not())
        # Link (inst, slot) feasibility.
        model.AddAllowedAssignments([inst_var, slot_var], [(int(i), int(s)) for i, s in allowed_inst_slot_pairs])
        instructor_choice_bools.append(inst_bools)
        inst_vars.append(inst_var)

        # Room choice (type-based; conflicts handled via NoOverlap).
        eligible_rooms = _eligible_room_ids_for_gene(ga, gene)
        if not eligible_rooms:
            raise Exception("No rooms available.")
        room_bools: Dict[int, cp_model.BoolVar] = {rid: model.NewBoolVar(f"is_room_{idx}_{rid}") for rid in eligible_rooms}
        model.AddExactlyOne(room_bools.values())
        room_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(eligible_rooms), f"room_{idx}")
        for rid, b in room_bools.items():
            model.Add(room_var == rid).OnlyEnforceIf(b)
            model.Add(room_var != rid).OnlyEnforceIf(b.Not())
        room_choice_bools.append(room_bools)
        room_vars.append(room_var)

    # Section: no overlap (fixed assignment).
    for _sec_key, intervals in section_intervals.items():
        if len(intervals) > 1:
            model.AddNoOverlap(intervals)

    # Room: no overlap + published blocks.
    for room in ga.rooms:
        rid = int(room["id"])
        intervals: List[cp_model.IntervalVar] = []

        # Fixed blocked intervals (published schedules).
        for bi in room_blocked.get(rid, []):
            s = model.NewConstant(int(bi.start))
            e = model.NewConstant(int(bi.end))
            # Optional isn't needed; these are always present.
            intervals.append(
                model.NewIntervalVar(
                    s,
                    int(bi.end - bi.start),
                    e,
                    f"blk_room_{rid}_{bi.start}_{bi.end}",
                )
            )

        # Optional intervals for each gene choosing this room.
        for gi, base in enumerate(base_intervals):
            b = room_choice_bools[gi].get(rid)
            if b is None:
                continue
            duration = int(ga.get_gene_minutes(genes[gi]))
            intervals.append(
                model.NewOptionalIntervalVar(
                    start_vars[gi],
                    duration,
                    end_vars[gi],
                    b,
                    f"room_{rid}_itv_{gi}",
                )
            )

        if len(intervals) > 1:
            model.AddNoOverlap(intervals)

    # Instructor: no overlap + published blocks + weekly hour caps.
    instructor_max_minutes: Dict[int, int] = {}
    for inst in ga.instructors:
        iid = int(inst["id"])
        try:
            max_hours = float(ga.instructor_max_hours.get(iid, float(inst.get("max_hours_per_week") or 20) or 20))
        except Exception:
            max_hours = 20.0
        instructor_max_minutes[iid] = int(round(max(0.0, max_hours) * 60.0))

    for inst in ga.instructors:
        iid = int(inst["id"])
        intervals: List[cp_model.IntervalVar] = []

        for bi in inst_blocked.get(iid, []):
            s = model.NewConstant(int(bi.start))
            e = model.NewConstant(int(bi.end))
            intervals.append(
                model.NewIntervalVar(
                    s,
                    int(bi.end - bi.start),
                    e,
                    f"blk_inst_{iid}_{bi.start}_{bi.end}",
                )
            )

        load_terms: List[cp_model.LinearExpr] = []
        for gi, _base in enumerate(base_intervals):
            b = instructor_choice_bools[gi].get(iid)
            if b is None:
                continue
            duration = int(ga.get_gene_minutes(genes[gi]))
            intervals.append(
                model.NewOptionalIntervalVar(
                    start_vars[gi],
                    duration,
                    end_vars[gi],
                    b,
                    f"inst_{iid}_itv_{gi}",
                )
            )
            load_terms.append(duration * b)

        if len(intervals) > 1:
            model.AddNoOverlap(intervals)

        # Weekly hour cap.
        if load_terms:
            model.Add(sum(load_terms) <= int(instructor_max_minutes.get(iid, 20 * 60)))

    # ================= PAIRED-DAY / MIRROR CONSTRAINTS =================
    # These mirror constraints are configured per job via constraints.mirror_pairs (day<->mirror mappings).
    if bool(getattr(ga, "four_day_pattern", False)) and getattr(ga, "mirror_pairs", None):
        # Cache slot==value bools only when needed to keep the model smaller.
        slot_is_cache: Dict[Tuple[int, int], cp_model.BoolVar] = {}

        def slot_is(gene_index: int, slot_id: int) -> Optional[cp_model.BoolVar]:
            if int(slot_id) not in allowed_slot_set_per_gene[gene_index]:
                return None
            key = (int(gene_index), int(slot_id))
            if key in slot_is_cache:
                return slot_is_cache[key]
            b = model.NewBoolVar(f"is_slot_{gene_index}_{slot_id}")
            model.Add(slot_vars[gene_index] == int(slot_id)).OnlyEnforceIf(b)
            model.Add(slot_vars[gene_index] != int(slot_id)).OnlyEnforceIf(b.Not())
            slot_is_cache[key] = b
            return b

        # Build the list of mirror blocks (pair_group + start/end time) that exist on both days.
        # Each block gives two concrete slot IDs: one on the anchor day, one on the mirror day.
        blocks: List[Tuple[str, str, str, str, str, int, int]] = []
        # (pair_group, start, end, anchor_day, mirror_day, slot_anchor, slot_mirror)
        time_key_to_id = getattr(ga, "time_key_to_id", {}) or {}
        for anchor_day, mirror_day, pair_group in list(getattr(ga, "mirror_pairs", []) or []):
            for anchor_slot_id in list((getattr(ga, "day_slot_ids", {}) or {}).get(anchor_day, []) or []):
                anchor_slot_id = int(anchor_slot_id)
                if ga.is_disallowed_slot(anchor_slot_id):
                    continue
                start, end = slot_timekey.get(anchor_slot_id, ("", ""))
                mirror_slot_id = time_key_to_id.get((mirror_day, (start, end)))
                if not mirror_slot_id:
                    continue
                mirror_slot_id = int(mirror_slot_id)
                if ga.is_disallowed_slot(mirror_slot_id):
                    continue
                blocks.append((str(pair_group), str(start), str(end), str(anchor_day), str(mirror_day), anchor_slot_id, mirror_slot_id))

        # If blocks can't be formed, the GA would likely fail too; be explicit.
        if not blocks:
            raise Exception("Paired-day mode is enabled but no mirrored time-slot pairs were found.")

        # Map genes by (department, year, section, subject_id) and by section-only.
        genes_by_section_subject: Dict[Tuple[str, int, str, int], List[int]] = {}
        subjects_by_section: Dict[Tuple[str, int, str], set] = {}
        for gi in range(len(genes)):
            dep, year, sec = gene_section_key[gi]
            subj_id = gene_subject_id[gi]
            key = (dep, year, sec, subj_id)
            genes_by_section_subject.setdefault(key, []).append(gi)
            subjects_by_section.setdefault((dep, year, sec), set()).add(subj_id)

        # "Non-mirror day" rules (what GA calls "wednesday" internally):
        # - At most one subject per section can be scheduled on non-mirror days.
        # - If a subject is chosen as the non-mirror subject, all its meetings for that section must be on non-mirror days.
        # - If the UI rule requires it, each section must have at least one non-mirror-day class.
        non_mirror_days = set(getattr(ga, "non_mirror_days", []) or [])
        non_mirror_slots_exist = any(slot_day.get(int(sid), "") in non_mirror_days for sid in all_regular_slot_ids)
        if non_mirror_slots_exist and int(getattr(ga, "non_mirror_mode", 1) or 0) == 1:
            subject_nonmirror_vars: Dict[Tuple[int, str, int], cp_model.BoolVar] = {}
            # key: (year, section, subject_id) -> bool
            subjects_by_yearsec: Dict[Tuple[int, str], set] = {}
            genes_by_yearsec_subject: Dict[Tuple[int, str, int], List[int]] = {}
            for gi in range(len(genes)):
                year, sec = gene_section_simple_key[gi]
                subj_id = gene_subject_id[gi]
                subjects_by_yearsec.setdefault((year, sec), set()).add(subj_id)
                genes_by_yearsec_subject.setdefault((year, sec, subj_id), []).append(gi)

            for (year, sec), subj_ids in subjects_by_yearsec.items():
                for subj_id in sorted(subj_ids):
                    v = model.NewBoolVar(f"sec_nonmirror_{year}_{sec}_{subj_id}")
                    subject_nonmirror_vars[(year, sec, subj_id)] = v
                    for gi in genes_by_yearsec_subject.get((year, sec, subj_id), []):
                        model.Add(is_nonmirror_vars[gi] == v)

                # At most one subject per section on non-mirror days.
                model.Add(
                    sum(subject_nonmirror_vars[(year, sec, sid)] for sid in sorted(subj_ids)) <= 1
                )

            # If required, each section must have at least one non-mirror subject.
            required_sections = set(getattr(ga, "required_wed_sections", set()) or set())
            for year, sec in required_sections:
                subj_ids = sorted(subjects_by_yearsec.get((int(year or 0), str(sec)), set()))
                if subj_ids:
                    model.Add(sum(subject_nonmirror_vars[(int(year or 0), str(sec), sid)] for sid in subj_ids) >= 1)

            # Subjects that have both lecture+lab are restricted to <=1 meeting per kind on non-mirror days
            # (matches GA's "no duplicate kind for mixed subjects on non-mirror day").
            for (year, sec), subj_ids in subjects_by_yearsec.items():
                for subj_id in sorted(subj_ids):
                    if not bool(ga.subject_has_both_kinds(int(subj_id))):
                        continue
                    flag = subject_nonmirror_vars[(year, sec, subj_id)]
                    idxs = genes_by_yearsec_subject.get((year, sec, subj_id), [])
                    lecture_bools = [is_nonmirror_vars[gi] for gi in idxs if str(genes[gi].get("meeting_kind") or "lecture").strip().lower() == "lecture"]
                    lab_bools = [is_nonmirror_vars[gi] for gi in idxs if str(genes[gi].get("meeting_kind") or "lecture").strip().lower() == "lab"]
                    if lecture_bools:
                        model.Add(sum(lecture_bools) <= 1).OnlyEnforceIf(flag)
                    if lab_bools:
                        model.Add(sum(lab_bools) <= 1).OnlyEnforceIf(flag)

        # Mirror alignment:
        # - For each section + subject + block(start/end, pair_group), the number of meetings on the anchor day
        #   equals the number on the mirror day.
        # - For each section + block, only one subject can occupy that block across the paired days.
        # - For each section + subject + block, instructor and room are consistent across the paired days.
        for pair_group, start, end, anchor_day, mirror_day, slot_anchor, slot_mirror in blocks:
            # Apply per concrete section (department+year+section).
            for dep, year, sec in list(subjects_by_section.keys()):
                section_subjects = sorted(subjects_by_section[(dep, year, sec)])
                used_subject_flags: List[cp_model.BoolVar] = []

                for subj_id in section_subjects:
                    gene_idxs = genes_by_section_subject.get((dep, year, sec, subj_id), [])
                    if not gene_idxs:
                        continue

                    a_terms: List[cp_model.BoolVar] = []
                    b_terms: List[cp_model.BoolVar] = []
                    link_terms: List[Tuple[int, cp_model.BoolVar]] = []
                    for gi in gene_idxs:
                        ba = slot_is(gi, slot_anchor)
                        bb = slot_is(gi, slot_mirror)
                        if ba is not None:
                            a_terms.append(ba)
                            link_terms.append((gi, ba))
                        if bb is not None:
                            b_terms.append(bb)
                            link_terms.append((gi, bb))

                    # Count equality (if a gene can't ever use a slot, it simply doesn't contribute).
                    model.Add(sum(a_terms) == sum(b_terms))

                    total_terms = list(a_terms) + list(b_terms)
                    used = model.NewBoolVar(f"used_{pair_group}_{start}_{end}_{dep}_{year}_{sec}_{subj_id}")
                    if total_terms:
                        model.Add(sum(total_terms) >= 1).OnlyEnforceIf(used)
                        model.Add(sum(total_terms) == 0).OnlyEnforceIf(used.Not())
                    else:
                        model.Add(used == 0)
                    used_subject_flags.append(used)

                    # Instructor/room consistency for this (section,subject,block) whenever an entry is in the block.
                    # Create these only if the subject can actually use this block.
                    if link_terms:
                        # Domains are kept broad; the existing (instructor,slot) and room eligibility constraints narrow them.
                        subject_code = (ga.subject_by_id.get(int(subj_id)) or {}).get("subject_code") or ""
                        subject_code = str(subject_code).strip().upper()
                        inst_domain = list(ga.subject_candidate_instructors.get(subject_code) or ga.instructor_ids or [])
                        if not inst_domain:
                            inst_domain = list(ga.instructor_ids or [])
                        room_domain = [int(r["id"]) for r in ga.rooms]
                        inst_block = model.NewIntVarFromDomain(
                            cp_model.Domain.FromValues(sorted({int(x) for x in inst_domain})),
                            f"blk_inst_{pair_group}_{start}_{end}_{dep}_{year}_{sec}_{subj_id}",
                        )
                        room_block = model.NewIntVarFromDomain(
                            cp_model.Domain.FromValues(sorted({int(x) for x in room_domain})),
                            f"blk_room_{pair_group}_{start}_{end}_{dep}_{year}_{sec}_{subj_id}",
                        )
                        for gi, bt in link_terms:
                            model.Add(inst_vars[gi] == inst_block).OnlyEnforceIf(bt)
                            model.Add(room_vars[gi] == room_block).OnlyEnforceIf(bt)

                # One subject per block per section.
                if used_subject_flags:
                    model.Add(sum(used_subject_flags) <= 1)

    # Solve.
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(max_seconds)
    solver.parameters.num_search_workers = 8

    status = solver.Solve(model)
    if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        raise Exception(f"No feasible schedule found within {max_seconds:.0f}s (CP-SAT).")

    # Decode solution into schedule rows compatible with ScheduleGA.save_schedule().
    out: List[dict] = []
    for gi, gene in enumerate(genes):
        slot_id = int(solver.Value(slot_vars[gi]))
        room_id = None
        for rid, b in room_choice_bools[gi].items():
            if solver.Value(b) == 1:
                room_id = int(rid)
                break
        inst_id = None
        for iid, b in instructor_choice_bools[gi].items():
            if solver.Value(b) == 1:
                inst_id = int(iid)
                break
        if room_id is None or inst_id is None:
            raise Exception("Internal decode error: missing room/instructor assignment.")

        entry = dict(gene)
        entry["time_slot_id"] = slot_id
        entry["room_id"] = room_id
        entry["instructor_id"] = inst_id
        entry["scheduled_minutes"] = int(gene.get("meeting_minutes") or ga.get_gene_minutes(gene))
        out.append(entry)

    return out
