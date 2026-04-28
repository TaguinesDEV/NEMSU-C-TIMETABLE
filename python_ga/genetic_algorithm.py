#!/usr/bin/env python3
import json
import os
import sys
import random
import time
import mysql.connector
from datetime import datetime
import traceback
from collections import Counter, OrderedDict

# ================= GA DEFAULT PARAMETERS =================
DEFAULT_POPULATION_SIZE = 30  # Further reduced for very fast execution
DEFAULT_GENERATIONS = 50      # Further reduced for very fast execution
DEFAULT_MUTATION_RATE = 0.2   # Increased for more exploration
DEFAULT_CROSSOVER_RATE = 0.9  # Increased for more recombination
DEFAULT_ELITE_SIZE = 2        # Reduced elite size
PROGRESS_DB_WRITE_THROTTLE_SECONDS = 2.0
RUNTIME_SCHEMA_CACHE_TTL_SECONDS = 300

WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
SATURDAY = 'saturday'
PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
RUNTIME_SCHEMA_CACHE_PATH = os.path.join(PROJECT_ROOT, "logs", ".runtime_schema_cache.json")


def build_db_config():
    return {
        'host': os.getenv('ACADEMIC_SCHEDULING_DB_HOST', 'localhost'),
        'user': os.getenv('ACADEMIC_SCHEDULING_DB_USER', 'root'),
        'password': os.getenv('ACADEMIC_SCHEDULING_DB_PASS', ''),
        'database': os.getenv('ACADEMIC_SCHEDULING_DB_NAME', 'academic_scheduling'),
    }


def has_fresh_runtime_schema_cache(db_config):
    try:
        with open(RUNTIME_SCHEMA_CACHE_PATH, 'r', encoding='utf-8') as handle:
            payload = json.load(handle)
    except Exception:
        return False

    checked_at = float(payload.get('checked_at') or 0)
    if checked_at <= 0 or (time.time() - checked_at) > RUNTIME_SCHEMA_CACHE_TTL_SECONDS:
        return False

    return (
        payload.get('host') == db_config.get('host')
        and payload.get('database') == db_config.get('database')
        and payload.get('user') == db_config.get('user')
    )


def write_runtime_schema_cache(db_config):
    try:
        os.makedirs(os.path.dirname(RUNTIME_SCHEMA_CACHE_PATH), exist_ok=True)
        with open(RUNTIME_SCHEMA_CACHE_PATH, 'w', encoding='utf-8') as handle:
            json.dump({
                'checked_at': time.time(),
                'host': db_config.get('host'),
                'database': db_config.get('database'),
                'user': db_config.get('user'),
            }, handle)
    except Exception:
        pass

class ScheduleGA:
    def __init__(self, job_id):
        self.job_id = job_id
        self.db_config = build_db_config()
        self._job_conn = None
        self.fitness_cache = OrderedDict()
        self.fitness_cache_limit = 4096
        self.partial_fitness_viable_threshold = 86
        self.load_data()
        self.genes = self.create_genes()
        self.target_gene_counter = Counter(self.get_gene_identity(gene) for gene in self.genes)
        self.build_search_indexes()
        self.required_wed_sections = self._compute_required_wed_sections()
        self.configure_ga_parameters()
        self.last_progress_percent = -1
        self.run_started_at = None
        self.stop_reason = None
        self.last_progress_write_at = 0.0

    def load_data(self):
        print(f"Loading data for job {self.job_id}...")
        
        conn = mysql.connector.connect(**self.db_config)
        cursor = conn.cursor(dictionary=True)

        # Schema upgrades for progress tracking (non-blocking).
        # Avoid repeated information_schema lookups by fetching existing columns once.
        if not has_fresh_runtime_schema_cache(self.db_config):
            schema_checks = {
                'schedule_jobs': {
                    'error_message': "ALTER TABLE schedule_jobs ADD COLUMN error_message TEXT NULL AFTER completed_at",
                    'progress_percent': "ALTER TABLE schedule_jobs ADD COLUMN progress_percent INT NOT NULL DEFAULT 0 AFTER status",
                    'current_generation': "ALTER TABLE schedule_jobs ADD COLUMN current_generation INT NOT NULL DEFAULT 0 AFTER progress_percent",
                    'total_generations': "ALTER TABLE schedule_jobs ADD COLUMN total_generations INT NOT NULL DEFAULT 0 AFTER current_generation",
                    'best_fitness': "ALTER TABLE schedule_jobs ADD COLUMN best_fitness INT NOT NULL DEFAULT 0 AFTER total_generations",
                },
                'schedules': {
                    'scheduled_hours': "ALTER TABLE schedules ADD COLUMN scheduled_hours DECIMAL(5,2) NULL AFTER section",
                    'meeting_kind': "ALTER TABLE schedules ADD COLUMN meeting_kind ENUM('lecture','lab') NULL AFTER scheduled_hours",
                    'scheduled_minutes': "ALTER TABLE schedules ADD COLUMN scheduled_minutes INT NULL AFTER scheduled_hours",
                    'is_overload': "ALTER TABLE schedules ADD COLUMN is_overload TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published",
                },
            }

            existing_cols = {'schedule_jobs': set(), 'schedules': set()}
            schema_validation_ok = True
            schema_changed = False
            try:
                cursor.execute(
                    """
                    SELECT TABLE_NAME, COLUMN_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = %s
                      AND TABLE_NAME IN ('schedule_jobs', 'schedules')
                    """,
                    (self.db_config['database'],),
                )
                for row in cursor.fetchall():
                    table_name = str(row.get('TABLE_NAME') or '')
                    col_name = str(row.get('COLUMN_NAME') or '')
                    if table_name in existing_cols and col_name:
                        existing_cols[table_name].add(col_name)
            except Exception:
                existing_cols = {}
                schema_validation_ok = False

            for table_name, checks in schema_checks.items():
                for col_name, sql in checks.items():
                    try:
                        if col_name in existing_cols.get(table_name, set()):
                            continue
                        cursor.execute(sql)
                        schema_changed = True
                    except Exception:
                        schema_validation_ok = False

            if schema_changed:
                conn.commit()
            if schema_validation_ok:
                write_runtime_schema_cache(self.db_config)

        cursor.execute("SELECT input_data FROM schedule_jobs WHERE id = %s", (self.job_id,))
        result = cursor.fetchone()
        if not result:
            raise Exception(f"Job ID {self.job_id} not found!")
        
        self.input_data = json.loads(result['input_data'])
        self.respect_availability = False
        self.four_day_pattern = bool(self.input_data.get('constraints', {}).get('four_day_pattern', False))
        self.mirror_pairs = []
        self.day_pair_lookup = {}
        self.non_mirror_mode = int(self.input_data.get('constraints', {}).get('non_mirror_mode', 1) or 0)
        self.allow_non_mirror_fallback = bool(
            self.input_data.get('constraints', {}).get('allow_non_mirror_fallback', self.four_day_pattern)
        )
        self.relaxed_mirror_mode = False
        self.preferred_day_enabled = bool(
            self.input_data.get('constraints', {}).get('preferred_day_enabled', False)
        )
        self.preferred_day_mode = str(
            self.input_data.get('constraints', {}).get('preferred_day_mode', 'none')
        ).strip().lower()
        if not self.preferred_day_enabled or self.preferred_day_mode not in ('soft', 'strict'):
            self.preferred_day_mode = 'none'
        # Guard rail: strict mirror mode must remain strict even if older jobs
        # still carry allow_non_mirror_fallback=true in stored constraints.
        if self.preferred_day_mode == 'strict':
            self.allow_non_mirror_fallback = False
        raw_pairs = self.input_data.get('constraints', {}).get('mirror_pairs') or []
        if isinstance(raw_pairs, list):
            for idx, pair in enumerate(raw_pairs):
                if not isinstance(pair, dict):
                    continue
                day = str(pair.get('day') or '').strip().lower()
                mirror = str(pair.get('mirror') or '').strip().lower()
                if not day or not mirror or day == mirror:
                    continue
                if day not in WEEKDAYS or mirror not in WEEKDAYS:
                    continue
                pair_group = f"pair_{idx + 1}"
                self.mirror_pairs.append((day, mirror, pair_group))
                self.day_pair_lookup[day] = (mirror, pair_group)
                self.day_pair_lookup[mirror] = (day, pair_group)
        if self.four_day_pattern and not self.mirror_pairs:
            self.four_day_pattern = False
        paired_days = {day for day, _, _ in self.mirror_pairs} | {mirror for _, mirror, _ in self.mirror_pairs}
        self.non_mirror_days = [day for day in WEEKDAYS if day not in paired_days]

        self.last_reported_generation = -1
        self.last_reported_best_fit = -1

        self.job_instructor_subject_codes = {}
        raw_map = self.input_data.get('instructor_subject_map') or {}
        if isinstance(raw_map, dict):
            for inst_id_raw, codes_raw in raw_map.items():
                try:
                    inst_id = int(inst_id_raw)
                except Exception:
                    continue
                if isinstance(codes_raw, list):
                    cleaned = set()
                    for code_raw in codes_raw:
                        code = (str(code_raw) if code_raw is not None else '').strip().upper()
                        if code:
                            cleaned.add(code)
                    self.job_instructor_subject_codes[inst_id] = cleaned

        self.instructor_subject_codes = {
            inst_id: set(codes)
            for inst_id, codes in self.job_instructor_subject_codes.items()
        }
        self.job_has_explicit_instructor_subjects = bool(self.job_instructor_subject_codes)
        instructor_ids = [int(inst['id']) for inst in self.input_data.get('instructors', [])]
        missing_instructor_ids = [inst_id for inst_id in instructor_ids if inst_id not in self.job_instructor_subject_codes]
        if missing_instructor_ids:
            placeholders = ','.join(['%s'] * len(missing_instructor_ids))
            cursor.execute(f"""
                SELECT sia.instructor_id, sub.subject_code
                FROM subject_instructor_assignments sia
                JOIN subjects sub ON sia.subject_id = sub.id
                WHERE sia.instructor_id IN ({placeholders})
                ORDER BY sia.assignment_slot, sub.subject_code
            """, tuple(missing_instructor_ids))
            for row in cursor.fetchall():
                inst_id = int(row['instructor_id'])
                code = (row['subject_code'] or '').strip().upper()
                if not code:
                    continue
                self.instructor_subject_codes.setdefault(inst_id, set()).add(code)

            cursor.execute(f"""
                SELECT ism.instructor_id, s.specialization_name
                FROM instructor_specializations ism
                JOIN specializations s ON ism.specialization_id = s.id
                WHERE ism.instructor_id IN ({placeholders})
                ORDER BY ism.priority
            """, tuple(missing_instructor_ids))
            for row in cursor.fetchall():
                inst_id = int(row['instructor_id'])
                code = (row['specialization_name'] or '').strip().upper()
                if not code:
                    continue
                self.instructor_subject_codes.setdefault(inst_id, set()).add(code)

        # Time slots
        self.time_slots = self.input_data.get('time_slots', [])
        if not self.time_slots:
            cursor.execute("SELECT * FROM time_slots ORDER BY day, start_time") 
            self.time_slots = cursor.fetchall()
            
        self.time_slots_by_id = {int(ts['id']): ts for ts in self.time_slots}
        self.open_windows_by_day = {}
        self.hard_breaks_by_day = {}
        for ts in self.time_slots:
            day = str(ts.get('day', '')).strip().lower()
            slot_type = str(ts.get('slot_type') or 'regular').strip().lower()
            start_minutes = self.time_to_minutes(ts.get('start_time'))
            end_minutes = self.time_to_minutes(ts.get('end_time'))
            if slot_type == 'lunch':
                self.hard_breaks_by_day.setdefault(day, []).append((start_minutes, end_minutes))
                continue
            self.open_windows_by_day.setdefault(day, []).append((start_minutes, end_minutes))

        # Precompute normalized slot fields used heavily during fitness checks.
        for ts in self.time_slots:
            ts['_day'] = str(ts.get('day', '')).strip().lower()
            ts['_slot_type'] = str(ts.get('slot_type') or 'regular').strip().lower()
            ts['_start_minutes'] = self.time_to_minutes(ts.get('start_time'))
            ts['_end_minutes'] = self.time_to_minutes(ts.get('end_time'))
        for day in list(self.open_windows_by_day.keys()):
            self.open_windows_by_day[day] = self._merge_windows(self.open_windows_by_day[day])
        for day in list(self.hard_breaks_by_day.keys()):
            self.hard_breaks_by_day[day] = self._merge_windows(self.hard_breaks_by_day[day])
        
        # ✅ CRITICAL FIX: Initialize BEFORE use
        self.time_key_to_id = {}
        self.slot_hour_unit = self.detect_slot_hour_unit()
        self._build_slot_indexes()

        # Availability, instructors, rooms, subjects, blocked times (unchanged logic)
        all_slot_ids = [int(ts['id']) for ts in self.time_slots]
        default_slots = [int(ts['id']) for ts in self.time_slots if str(ts.get('day', '')).lower() != SATURDAY]
        self.availability = {}
        self.availability_windows = {}
        for inst in self.input_data['instructors']:
            iid = inst['id']
            if self.respect_availability:
                cursor.execute("SELECT ts.id FROM instructor_availability ia JOIN time_slots ts ON ia.time_slot_id = ts.id WHERE ia.instructor_id = %s AND ia.is_available = 1", (iid,))
                slots = [r['id'] for r in cursor.fetchall()]
                self.availability[iid] = slots or default_slots
            else:
                self.availability[iid] = list(all_slot_ids)
            self.availability_windows[iid] = self._build_windows_from_slot_ids(self.availability[iid])

        self.instructor_max_hours = {inst['id']: float(inst.get('max_hours_per_week', 20)) for inst in self.input_data['instructors']}
        self.max_classes_per_day = int(self.input_data.get('constraints', {}).get('max_classes_per_day', 4) or 4)
        self.instructors = self.input_data['instructors']
        self.instructor_by_id = {int(inst['id']): inst for inst in self.instructors}
        self.rooms = self.input_data['rooms']
        self.subjects = self.input_data['subjects']
        self.subject_by_id = {s['id']: s for s in self.subjects}
        self.subject_has_both_kinds_by_id = {}
        self.subject_preferred_days_by_id = {}
        self.subject_preferred_start_minutes_by_id = {}
        self.subject_preferred_end_minutes_by_id = {}
        for subject in self.subjects:
            lecture_hours, lab_hours = self.get_subject_hour_breakdown(subject)
            self.subject_has_both_kinds_by_id[int(subject['id'])] = lecture_hours > 0 and lab_hours > 0
            preferred_days = self.parse_preferred_day_pair(subject.get('preferred_day_pair'))
            if preferred_days:
                self.subject_preferred_days_by_id[int(subject['id'])] = preferred_days
            preferred_start_minutes = self.parse_optional_time_to_minutes(subject.get('preferred_start_time'))
            if preferred_start_minutes is not None:
                self.subject_preferred_start_minutes_by_id[int(subject['id'])] = preferred_start_minutes
            preferred_end_minutes = self.parse_optional_time_to_minutes(subject.get('preferred_end_time'))
            if preferred_end_minutes is not None:
                self.subject_preferred_end_minutes_by_id[int(subject['id'])] = preferred_end_minutes
        self.blocked_room_time = set()
        self.blocked_inst_time = set()
        self.blocked_room_intervals = {}
        self.blocked_inst_intervals = {}

        cursor.execute("SELECT room_id, instructor_id, time_slot_id, scheduled_minutes, scheduled_hours FROM schedules WHERE is_published = 1")
        for row in cursor.fetchall():
            self.blocked_room_time.add((row['room_id'], row['time_slot_id']))
            self.blocked_inst_time.add((row['instructor_id'], row['time_slot_id']))
            interval = self.get_entry_interval({
                'time_slot_id': row['time_slot_id'],
                'meeting_minutes': int(row.get('scheduled_minutes') or round(float(row.get('scheduled_hours') or 0) * 60.0))
            })
            if interval:
                self.blocked_room_intervals.setdefault(int(row['room_id']), []).append(interval)
                self.blocked_inst_intervals.setdefault(int(row['instructor_id']), []).append(interval)

        self.num_sections = max(1, min(10, int(self.input_data.get('num_sections', 1))))
        self.sections = [chr(65 + i) for i in range(self.num_sections)]

        cursor.close()
        conn.close()

    def _build_slot_indexes(self):
        """Safe index building - FIXED init order"""
        self.day_slot_ids = {}
        self.weekday_slot_ids = {}
        for ts in self.time_slots:
            day = str(ts.get('day', '')).strip().lower()
            tid = int(ts['id'])
            self.day_slot_ids.setdefault(day, []).append(tid)
            if day in WEEKDAYS:
                self.weekday_slot_ids.setdefault(day, []).append(tid)

        # Populate time_key_to_id AFTER safe initialization
        for ts in self.time_slots:
            day = str(ts.get('day', '')).strip().lower()
            start, end = str(ts.get('start_time', '')), str(ts.get('end_time', ''))
            self.time_key_to_id[(day, (start, end))] = int(ts['id'])

    def create_genes(self):
        genes = []
        for subj in self.subjects:
            year = subj.get('year_level')
            for sec in self.sections:
                total_h = self.get_subject_hours(subj['id'])
                remaining = total_h
                m_idx = 1
                while remaining > 1e-6:
                    chunk = round(min(self.slot_hour_unit, remaining), 2)
                    genes.append({
                        'subject_id': subj['id'],
                        'subject_code': subj['subject_code'], 
                        'department': subj['department'],
                        'year_level': year,
                        'section': sec,
                        'meeting_hours': chunk,
                        'subject_total_hours': total_h
                    })
                    remaining -= chunk
                    m_idx += 1
        return genes

    def get_subject_hours(self, sid):
        subj = self.subject_by_id.get(sid, {})
        try:
            return float(subj.get('hours_per_week') or 0) or 2.5
        except:
            return 1.5 if subj.get('subject_type') == 'minor' else 2.5

    def detect_slot_hour_unit(self):
        durations = []
        for ts in self.time_slots:
            try:
                h = (datetime.strptime(str(ts['end_time']), '%H:%M:%S') - datetime.strptime(str(ts['start_time']), '%H:%M:%S')).total_seconds() / 3600
                durations.append(round(h, 2))
            except:
                pass
        return sorted(durations)[len(durations)//2] if durations else 1.5

    # ... GA methods (selection, crossover, mutation, fitness - identical to working version)
    
    def run(self):
        import time
        ga_start = time.time()
        print("🚀 Starting merged GA (fixed + flexible)")
        self.update_job_status('processing')
        population = self.initialize_population()
        
        best, best_fit = None, 0
        for gen in range(self.generations):
            fitness = [self.calculate_fitness(ind) for ind in population]
            top_fit = max(fitness)
            self.update_progress(gen + 1, self.generations, top_fit)
            
            if top_fit > best_fit:
                best_fit = top_fit
                best = population[fitness.index(top_fit)]
            
            if best_fit == 100:
                break
                
            # Early termination for "good enough" solutions (90%+ fitness)
            # This significantly speeds up generation while maintaining quality
            if best_fit >= 90:
                print(f"Found high-quality solution with {best_fit}% fitness, terminating early")
                break
                
            population = self.next_generation(population, fitness)
            
        if best_fit < 85:  # Allow solutions with 85%+ fitness
            raise Exception(f"No sufficiently good schedule found after GA search (best fitness: {best_fit}%)")
            
        self.save_schedule(best)
        ga_time = time.time() - ga_start
        print(f"✅ Schedule saved successfully (fitness: {best_fit}%) in {ga_time:.1f}s")
        return best


    def _build_slot_indexes(self):
        self.day_slot_ids = {}
        keyed = {}
        for ts in self.time_slots:
            tid = int(ts['id'])
            day = str(ts.get('day') or '').strip().lower()
            start = str(ts.get('start_time') or '')
            end = str(ts.get('end_time') or '')
            self.day_slot_ids.setdefault(day, []).append(tid)
            keyed[(day, start, end)] = tid

        self.weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
        self.paired_slot_map = {}
        self.weekday_slot_ids = {}
        self.pair_anchor_slot_ids = []
        self.pair_group_days = {}

        for ts in self.time_slots:
            tid = int(ts['id'])
            day = str(ts.get('day') or '').strip().lower()
            start = str(ts.get('start_time') or '')
            end = str(ts.get('end_time') or '')
            key = (start, end)
            self.time_key_to_id[(day, key)] = tid
            if day in self.weekdays:
                self.weekday_slot_ids.setdefault(day, []).append(tid)

        for anchor_day, mirror_day, _pair_group in self.mirror_pairs:
            self.pair_group_days[_pair_group] = (anchor_day, mirror_day)
            for tid in self.day_slot_ids.get(anchor_day, []):
                ts = self.time_slots_by_id.get(int(tid))
                if not ts:
                    continue
                start = str(ts.get('start_time') or '')
                end = str(ts.get('end_time') or '')
                mirror_tid = keyed.get((mirror_day, start, end))
                if mirror_tid:
                    self.paired_slot_map[int(tid)] = int(mirror_tid)
                    self.paired_slot_map[int(mirror_tid)] = int(tid)
                    self.pair_anchor_slot_ids.append(int(tid))


    def _compute_required_wed_sections(self):
        if not self.four_day_pattern:
            return set()
        if self.non_mirror_mode != 1:
            return set()
        if not any(len(self.day_slot_ids.get(day, [])) > 0 for day in self.non_mirror_days):
            return set()
        # User rule: at least one non-mirror-day class per section (if non-mirror slots exist).
        return set(self.get_section_key(g) for g in self.genes)

    # ================= GENE CREATION =================
    def create_genes(self):
        genes = []
        gene_key = 0
        for subj in self.subjects:
            subj_year_level = subj.get('year_level')
            lecture_minutes, lab_minutes, _meetings_per_week = self.get_subject_meeting_plan(subj)
            try:
                configured_lecture_hours = round(max(0.0, float(subj.get('lecture_hours') or 0.0)), 2)
            except Exception:
                configured_lecture_hours = 0.0
            try:
                configured_lab_hours = round(max(0.0, float(subj.get('lab_hours') or 0.0)), 2)
            except Exception:
                configured_lab_hours = 0.0
            meeting_index = 1

            for sec in self.sections:
                # When explicit lecture/lab weekly hours exist, create those weekly
                # blocks exactly once. Repeating them by meetings_per_week would
                # duplicate the subject load in the generated schedule.
                if configured_lecture_hours > 0 or configured_lab_hours > 0:
                    configured_meetings = []
                    if configured_lecture_hours > 0:
                        configured_meetings.append(('lecture', int(round(configured_lecture_hours * 60.0))))
                    if configured_lab_hours > 0:
                        configured_meetings.append(('lab', int(round(configured_lab_hours * 60.0))))
                    for meeting_kind, configured_minutes in configured_meetings:
                        genes.append({
                            '_gene_key': gene_key,
                            'subject_id': subj['id'],
                            'subject_code': subj['subject_code'],
                            'department': subj['department'],
                            'year_level': subj_year_level,
                            'section': sec,
                            'subject_type': (subj.get('subject_type') or 'major'),
                            'meeting_index': meeting_index,
                            'meeting_kind': meeting_kind,
                            'meeting_minutes': configured_minutes,
                            'meeting_hours': round(configured_minutes / 60.0, 2),
                            'subject_total_hours': round(configured_lecture_hours + configured_lab_hours, 2)
                        })
                        meeting_index += 1
                        gene_key += 1
                    continue

                for meeting_kind, configured_minutes in (('lecture', lecture_minutes), ('lab', lab_minutes)):
                    if configured_minutes <= 0:
                        continue
                    genes.append({
                        '_gene_key': gene_key,
                        'subject_id': subj['id'],
                        'subject_code': subj['subject_code'],
                        'department': subj['department'],
                        'year_level': subj_year_level,
                        'section': sec,
                        'subject_type': (subj.get('subject_type') or 'major'),
                        'meeting_index': meeting_index,
                        'meeting_kind': meeting_kind,
                        'meeting_minutes': configured_minutes,
                        'meeting_hours': round(configured_minutes / 60.0, 2),
                        'subject_total_hours': round((lecture_minutes + lab_minutes) / 60.0, 2)
                    })
                    meeting_index += 1
                    gene_key += 1
        return genes

    def detect_slot_hour_unit(self):
        durations = []
        for ts in self.time_slots:
            try:
                start = datetime.strptime(str(ts['start_time']), "%H:%M:%S")
                end = datetime.strptime(str(ts['end_time']), "%H:%M:%S")
                hours = (end - start).total_seconds() / 3600.0
                if hours > 0:
                    durations.append(round(hours, 2))
            except Exception:
                continue
        if not durations:
            return 1.5
        durations.sort()
        return durations[len(durations) // 2]

    def configure_ga_parameters(self):
        gene_count = len(self.genes)

        # Auto-scale GA effort based on problem size.
        # Tuned from local run history:
        # - small/medium jobs benefit from deeper exploration than before
        # - larger jobs need a higher runtime cap but controlled population growth
        if gene_count <= 12:
            pop = 42
            gens = 90
            max_runtime_seconds = 600
        elif gene_count <= 30:
            pop = 64
            gens = 150
            max_runtime_seconds = 900
        elif gene_count <= 60:
            pop = 84
            gens = 200
            max_runtime_seconds = 1050
        elif gene_count <= 100:
            pop = 96
            gens = 230
            max_runtime_seconds = 1200
        else:
            pop = 108
            gens = 260
            max_runtime_seconds = 1350

        if gene_count <= 30:
            mutation = 0.20
        elif gene_count <= 60:
            mutation = 0.22
        else:
            mutation = 0.24
        crossover = 0.88
        elite = 2 if pop < 90 else 3

        # Optional per-job overrides (backward compatible).
        ga_cfg = self.input_data.get('ga') or {}
        runtime_override_provided = False
        if isinstance(ga_cfg, dict):
            try:
                pop = int(ga_cfg.get('population_size', pop))
            except Exception:
                pass
            try:
                gens = int(ga_cfg.get('generations', gens))
            except Exception:
                pass
            try:
                mutation = float(ga_cfg.get('mutation_rate', mutation))
            except Exception:
                pass
            try:
                crossover = float(ga_cfg.get('crossover_rate', crossover))
            except Exception:
                pass
            try:
                elite = int(ga_cfg.get('elite_size', elite))
            except Exception:
                pass
            try:
                max_runtime_seconds = int(ga_cfg.get('max_runtime_seconds', max_runtime_seconds))
                runtime_override_provided = 'max_runtime_seconds' in ga_cfg
            except Exception:
                pass

        # Safety bounds
        self.population_size = max(20, min(400, pop))
        self.generations = max(50, min(1000, gens))
        self.mutation_rate = max(0.01, min(0.5, mutation))
        self.crossover_rate = max(0.1, min(1.0, crossover))
        self.elite_size = max(1, min(20, elite, self.population_size))
        self.max_runtime_seconds = max(0, min(1800, max_runtime_seconds))

        # Paired-day mode is stricter; increase search effort and maintain diversity.
        if self.four_day_pattern:
            if gene_count <= 30:
                self.population_size = min(400, max(self.population_size, 72))
                self.generations = min(1000, max(self.generations, 180))
            elif gene_count <= 80:
                self.population_size = min(400, max(self.population_size, 90))
                self.generations = min(1000, max(self.generations, 240))
            else:
                self.population_size = min(400, max(self.population_size, 102))
                self.generations = min(1000, max(self.generations, 280))

            strict_pair_mode = (self.preferred_day_mode == 'strict')
            self.mutation_rate = min(0.5, max(self.mutation_rate, 0.22 if strict_pair_mode else 0.18))
            self.elite_size = max(2, min(self.elite_size, self.population_size))

            # Respect explicit runtime overrides, otherwise give strict mode more time.
            if strict_pair_mode and not runtime_override_provided:
                strict_runtime_floor = 1200 if gene_count <= 60 else 1500
                self.max_runtime_seconds = max(self.max_runtime_seconds, strict_runtime_floor)

        print(
            "GA params => "
            f"genes={gene_count}, population={self.population_size}, generations={self.generations}, "
            f"mutation={self.mutation_rate:.3f}, crossover={self.crossover_rate:.3f}, "
            f"elite={self.elite_size}, max_runtime={self.max_runtime_seconds}s"
        )
        self.fitness_cache_limit = max(2048, min(20000, self.population_size * 24))

    def invalidate_fitness_cache(self):
        self.fitness_cache = OrderedDict()

    def get_individual_signature(self, individual):
        signature_items = []
        for entry in individual:
            signature_items.append((
                repr(self.get_gene_identity(entry)),
                int(entry.get('instructor_id') or 0),
                int(entry.get('room_id') or 0),
                int(entry.get('time_slot_id') or 0),
            ))
        signature_items.sort()
        return tuple(signature_items)

    def get_partial_fitness_score(self, passed_entries, passed_sequence=0, passed_pair_checks=0, total_pair_checks=0):
        total_genes = max(1, len(self.genes))
        score = int((min(max(0, passed_entries), total_genes) * 86) / total_genes)

        sequence_total = len(self.sequence_anchor_identities)
        if sequence_total > 0:
            score += int((min(max(0, passed_sequence), sequence_total) * 6) / sequence_total)

        if total_pair_checks > 0:
            score += int((min(max(0, passed_pair_checks), total_pair_checks) * 7) / total_pair_checks)

        return max(0, min(99, int(score)))

    def evaluate_individual(self, individual):
        signature = self.get_individual_signature(individual)
        cached = self.fitness_cache.get(signature)
        if cached is not None:
            self.fitness_cache.move_to_end(signature)
            return cached

        evaluation = self._evaluate_individual(individual)
        self.fitness_cache[signature] = evaluation
        if len(self.fitness_cache) > self.fitness_cache_limit:
            self.fitness_cache.popitem(last=False)
        return evaluation

    def precheck_feasibility(self):
        # Per-subject teachability and mandatory-load check:
        # If a subject is teachable by only one instructor, its full load is mandatory for that instructor.
        mandatory_hours = {inst['id']: 0.0 for inst in self.instructors}
        mandatory_subjects = {inst['id']: [] for inst in self.instructors}
        for subj in self.subjects:
            code = (subj.get('subject_code') or '').strip().upper()
            teachable = []
            for inst in self.instructors:
                if self.can_teach_subject(inst['id'], code):
                    teachable.append(inst['id'])

            if not teachable:
                raise Exception(f"Infeasible subject mapping: no selected instructor can teach {code}.")

            if len(teachable) == 1:
                only_inst = teachable[0]
                # Sum required hours from all meeting genes of this subject across all sections.
                subj_required = 0.0
                section_keys = set()
                for g in self.genes:
                    if int(g.get('subject_id')) == int(subj['id']):
                        subj_required += float(g.get('meeting_hours') or 0)
                        section_keys.add(self.get_section_key(g))
                mandatory_hours[only_inst] += subj_required
                mandatory_subjects.setdefault(only_inst, []).append({
                    'subject_code': code or f"subject#{subj.get('id')}",
                    'hours': round(subj_required, 2),
                    'sections': len(section_keys),
                })

        violations = []
        for inst in self.instructors:
            iid = inst['id']
            req = mandatory_hours.get(iid, 0.0)
            cap = self.instructor_max_hours.get(iid, 20.0)
            if req > cap + 1e-6:
                instructor_name = str(inst.get('full_name') or inst.get('name') or f"instructor {iid}").strip()
                subject_bits = []
                for item in sorted(mandatory_subjects.get(iid, []), key=lambda row: (-float(row.get('hours') or 0.0), str(row.get('subject_code') or ''))):
                    subject_bits.append(
                        f"{item['subject_code']} ({float(item.get('hours') or 0.0):.2f}h/{int(item.get('sections') or 0)} section(s))"
                    )
                detail_suffix = f" Mandatory subjects: {', '.join(subject_bits)}." if subject_bits else ""
                violations.append(
                    f"{instructor_name} [id {iid}] needs {req:.2f}h but max is {cap:.2f}h.{detail_suffix}"
                )

        if violations:
            # Do not fail generation on overload-only situations.
            # Overloaded results are allowed and are handled in reporting/approval flows.
            print("Overload warning: mandatory instructor load exceeds weekly limit. " + "; ".join(violations))

        total_open_minutes = 0
        for day_windows in self.open_windows_by_day.values():
            for start_minutes, end_minutes in day_windows:
                total_open_minutes += max(0, int(end_minutes) - int(start_minutes))

        if total_open_minutes <= 0:
            raise Exception("Time/slot issue: no usable scheduling windows are available.")

        section_minutes = {}
        for gene in self.genes:
            sec_key = self.get_section_key(gene)
            section_minutes[sec_key] = section_minutes.get(sec_key, 0) + self.get_gene_minutes(gene)
        overloaded_sections = []
        for sec_key, required_minutes in sorted(section_minutes.items()):
            if required_minutes > total_open_minutes:
                overloaded_sections.append(
                    f"year {sec_key[0]} section {sec_key[1]} needs {required_minutes // 60:.2f}h but only {total_open_minutes // 60:.2f}h of regular slots exist"
                )
        if overloaded_sections:
            raise Exception("Section load issue: " + "; ".join(overloaded_sections))

        room_issues = []
        has_lab_room = any(self.normalize_room_type(room) == 'lab' for room in self.rooms)
        has_lecture_room = any(self.normalize_room_type(room) != 'lab' for room in self.rooms)
        for subj in self.subjects:
            lecture_minutes, lab_minutes, _meetings_per_week = self.get_subject_meeting_plan(subj)
            code = (subj.get('subject_code') or f"subject#{subj.get('id')}").strip()
            if lab_minutes > 0 and not has_lab_room:
                room_issues.append(code)
                continue
            if lecture_minutes > 0 and not has_lecture_room and not has_lab_room:
                room_issues.append(code)
        if room_issues:
            raise Exception("Room issue: no compatible room is available for " + ", ".join(sorted(room_issues)) + ".")

        total_required_minutes = sum(self.get_gene_minutes(gene) for gene in self.genes)
        total_instructor_capacity_minutes = 0
        for inst in self.instructors:
            iid = int(inst['id'])
            availability_minutes = 0
            for day_windows in self.availability_windows.get(iid, {}).values():
                for start_minutes, end_minutes in day_windows:
                    availability_minutes += max(0, int(end_minutes) - int(start_minutes))
            load_cap_minutes = int(round(float(self.instructor_max_hours.get(iid, 20.0)) * 60.0))
            total_instructor_capacity_minutes += min(availability_minutes, load_cap_minutes)
        if total_required_minutes > total_instructor_capacity_minutes:
            raise Exception(
                "Instructor capacity issue: selected instructors can cover only "
                f"{total_instructor_capacity_minutes / 60.0:.2f}h but the job needs {total_required_minutes / 60.0:.2f}h."
            )
        if self.required_wed_sections:
            wednesday_issues = []
            for sec_key in sorted(self.required_wed_sections):
                eligible = False
                for gene in self.genes:
                    if self.get_section_key(gene) != sec_key:
                        continue
                    for inst in self.instructors:
                        iid = int(inst['id'])
                        if self.can_teach_subject(iid, gene['subject_code']) and self.is_instructor_allowed_on_wednesday(iid, gene):
                            eligible = True
                            break
                    if eligible:
                        break
                if not eligible:
                    wednesday_issues.append(
                        f"year {sec_key[0]} section {sec_key[1]} has no Wednesday-eligible instructor/class under the current policy"
                    )
            if wednesday_issues:
                raise Exception("Wednesday policy issue: " + "; ".join(wednesday_issues))

        self._raise_sequence_feasibility_issues()

    def get_subject_hours(self, subject_id):
        subject = self.subject_by_id.get(subject_id) or {}
        lecture_hours, lab_hours = self.get_subject_hour_breakdown(subject)
        total_hours = round(lecture_hours + lab_hours, 2)
        if total_hours > 0:
            return total_hours

        subject_type = str(subject.get('subject_type') or '').strip().lower()
        if subject_type == 'minor':
            return 1.5
        return 2.5

    def get_subject_hour_breakdown(self, subject):
        try:
            lecture_hours = float(subject.get('lecture_hours') or 0)
        except Exception:
            lecture_hours = 0.0
        try:
            lab_hours = float(subject.get('lab_hours') or 0)
        except Exception:
            lab_hours = 0.0
        if lecture_hours > 0 or lab_hours > 0:
            return round(max(0.0, lecture_hours), 2), round(max(0.0, lab_hours), 2)

        try:
            configured = float(subject.get('hours_per_week') or 0)
        except Exception:
            configured = 0.0
        if configured > 0:
            return round(configured, 2), 0.0

        try:
            lecture_minutes_per_meeting = int(subject.get('lecture_minutes_per_meeting') or 0)
        except Exception:
            lecture_minutes_per_meeting = 0
        try:
            lab_minutes_per_meeting = int(subject.get('lab_minutes_per_meeting') or 0)
        except Exception:
            lab_minutes_per_meeting = 0
        if lecture_minutes_per_meeting > 0 or lab_minutes_per_meeting > 0:
            return round(lecture_minutes_per_meeting / 60.0, 2), round(lab_minutes_per_meeting / 60.0, 2)

        subject_type = str(subject.get('subject_type') or '').strip().lower()
        if subject_type == 'minor':
            return 1.5, 0.0
        return 2.5, 0.0

    def get_subject_meeting_plan(self, subject):
        lecture_hours, lab_hours = self.get_subject_hour_breakdown(subject)
        return int(round(lecture_hours * 60.0)), int(round(lab_hours * 60.0)), 1

    def get_gene_minutes(self, gene):
        try:
            minutes = int(gene.get('meeting_minutes') or 0)
        except Exception:
            minutes = 0
        if minutes > 0:
            return minutes
        return int(round(float(gene.get('meeting_hours') or self.get_subject_hours(gene['subject_id'])) * 60.0))

    def prepare_entry_metadata(self, entry_or_gene, instructor_id=None):
        if not isinstance(entry_or_gene, dict):
            return entry_or_gene

        sec_key = (
            int(entry_or_gene.get('year_level')) if entry_or_gene.get('year_level') is not None else None,
            str(entry_or_gene.get('section') or '')
        )
        entry_or_gene['_section_key'] = sec_key
        entry_or_gene['_meeting_kind_norm'] = str(entry_or_gene.get('meeting_kind') or 'lecture').strip().lower()

        try:
            time_slot_id = int(entry_or_gene.get('time_slot_id') or 0)
        except Exception:
            time_slot_id = 0
        if time_slot_id <= 0:
            return entry_or_gene

        slot = self.get_slot(time_slot_id)
        if not slot:
            return entry_or_gene

        day = str(slot.get('_day') or slot.get('day') or '').strip().lower()
        start_minutes = slot.get('_start_minutes')
        if start_minutes is None:
            start_minutes = self.time_to_minutes(slot.get('start_time'))
        start_minutes = int(start_minutes)
        duration_minutes = self.get_gene_minutes(entry_or_gene)
        end_minutes = start_minutes + duration_minutes

        entry_or_gene['_cached_time_slot_id'] = time_slot_id
        entry_or_gene['day'] = day
        entry_or_gene['start_minutes'] = start_minutes
        entry_or_gene['end_minutes'] = end_minutes
        entry_or_gene['start_time'] = self.minutes_to_time(start_minutes)
        entry_or_gene['end_time'] = self.minutes_to_time(end_minutes)

        pair_key = None
        pair_alignment_key = None
        pair_subject_slot_key = None
        instructor_subject_pair_key = None
        pair_info = self.day_pair_lookup.get(day) if self.four_day_pattern else None
        if pair_info:
            _mirror_day, pair_group = pair_info
            slot_start = str(slot.get('start_time') or '')
            slot_end = str(slot.get('end_time') or '')
            pair_key = (
                str(entry_or_gene.get('department') or ''),
                int(entry_or_gene.get('year_level')) if entry_or_gene.get('year_level') is not None else None,
                str(entry_or_gene.get('section') or ''),
                pair_group,
                slot_start,
                slot_end
            )
            pair_alignment_key = (
                str(entry_or_gene.get('department') or ''),
                int(entry_or_gene.get('year_level')) if entry_or_gene.get('year_level') is not None else None,
                str(entry_or_gene.get('section') or ''),
                int(entry_or_gene.get('subject_id') or 0),
                pair_group,
                slot_start,
                slot_end,
                day
            )
            pair_subject_slot_key = (
                str(entry_or_gene.get('department') or ''),
                int(entry_or_gene.get('year_level')) if entry_or_gene.get('year_level') is not None else None,
                str(entry_or_gene.get('section') or ''),
                int(entry_or_gene.get('subject_id') or 0),
                pair_group,
                slot_start,
                slot_end
            )
            active_instructor_id = instructor_id if instructor_id is not None else entry_or_gene.get('instructor_id')
            try:
                active_instructor_id = int(active_instructor_id)
            except Exception:
                active_instructor_id = 0
            if active_instructor_id > 0:
                instructor_subject_pair_key = (
                    active_instructor_id,
                    str(entry_or_gene.get('section') or ''),
                    int(entry_or_gene.get('subject_id') or 0),
                    pair_group
                )
                entry_or_gene['_cached_instructor_id'] = active_instructor_id

        entry_or_gene['_pair_bucket_key'] = pair_key
        entry_or_gene['_pair_alignment_key'] = pair_alignment_key
        entry_or_gene['_pair_subject_slot_key'] = pair_subject_slot_key
        entry_or_gene['_instructor_subject_pair_key'] = instructor_subject_pair_key
        return entry_or_gene

    def build_search_indexes(self):
        self.instructor_ids = [int(inst['id']) for inst in self.instructors]
        if self.job_has_explicit_instructor_subjects:
            self.active_instructor_ids = [iid for iid in self.instructor_ids if iid in self.job_instructor_subject_codes]
        else:
            self.active_instructor_ids = list(self.instructor_ids)
        self.subject_candidate_instructors = {}
        for subj in self.subjects:
            code = (subj.get('subject_code') or '').strip().upper()
            candidates = [iid for iid in self.active_instructor_ids if self.can_teach_subject(iid, code)]
            self.subject_candidate_instructors[code] = candidates or list(self.active_instructor_ids)
        self.slot_order_cache = {}
        self.gene_sort_key_cache = {}
        self.invalidate_fitness_cache()

        preferred_start_raw = str(self.input_data.get('constraints', {}).get('preferred_start_time') or '07:00').strip()
        if len(preferred_start_raw) == 5:
            preferred_start_raw += ':00'
        self.preferred_start_minutes = self.time_to_minutes(preferred_start_raw)

        self.day_priority = {day: idx for idx, day in enumerate(WEEKDAYS + [SATURDAY])}
        self.all_regular_slot_ids = [
            int(ts['id']) for ts in self.time_slots
            if not self.is_disallowed_slot(ts['id'])
        ]
        self.all_regular_slot_ids_sorted = sorted(self.all_regular_slot_ids, key=self.get_slot_priority_key)
        self.non_mirror_slot_ids = [
            int(slot_id) for slot_id in self.all_regular_slot_ids
            if str((self.get_slot(slot_id) or {}).get('day') or '').strip().lower() in self.non_mirror_days
        ]
        self.non_mirror_slot_id_set = set(self.non_mirror_slot_ids)
        self.non_mirror_slot_ids_sorted = sorted(self.non_mirror_slot_ids, key=self.get_slot_priority_key)
        self.anchor_slot_ids_sorted = sorted(
            [int(slot_id) for slot_id in self.pair_anchor_slot_ids if not self.is_disallowed_slot(slot_id)],
            key=self.get_slot_priority_key
        )
        self.anchor_slot_id_set = set(self.anchor_slot_ids_sorted)
        self.primary_anchor_slot_ids_sorted = sorted(
            [
                int(slot_id) for slot_id in self.all_regular_slot_ids
                if (
                    str((self.get_slot(slot_id) or {}).get('day') or '').strip().lower() in self.non_mirror_days
                    or int(slot_id) in self.anchor_slot_id_set
                )
            ],
            key=self.get_slot_priority_key
        )
        self.primary_anchor_slot_id_set = set(self.primary_anchor_slot_ids_sorted)
        self.build_sequence_pair_indexes()

    def build_sequence_pair_indexes(self):
        self.sequence_partner_by_identity = {}
        self.sequence_gene_by_identity = {}
        self.sequence_role_by_identity = {}
        self.sequence_anchor_identities = set()
        self.sequence_pairs = []
        self.sequence_relaxed_pairs = []
        self.sequence_relaxed_pair_keys = set()

        grouped = {}
        for gene in self.genes:
            identity = self.get_gene_identity(gene)
            self.sequence_gene_by_identity[identity] = gene
            grouped.setdefault((self.get_section_key(gene), int(gene.get('subject_id') or 0)), []).append(gene)

        for grouped_genes in grouped.values():
            lecture_genes = [g for g in grouped_genes if str(g.get('meeting_kind') or 'lecture').strip().lower() == 'lecture']
            lab_genes = [g for g in grouped_genes if str(g.get('meeting_kind') or 'lecture').strip().lower() == 'lab']
            if len(lecture_genes) != 1 or len(lab_genes) != 1:
                continue

            lecture_gene = lecture_genes[0]
            lab_gene = lab_genes[0]
            self.sequence_pairs.append((lecture_gene, lab_gene))
            if not self.has_feasible_sequence_slots(lecture_gene, lab_gene):
                self.relax_sequence_pair(lecture_gene, lab_gene)
                continue

            lecture_identity = self.get_gene_identity(lecture_gene)
            lab_identity = self.get_gene_identity(lab_gene)
            self.sequence_partner_by_identity[lecture_identity] = lab_gene
            self.sequence_partner_by_identity[lab_identity] = lecture_gene
            self.sequence_role_by_identity[lecture_identity] = 'lecture'
            self.sequence_role_by_identity[lab_identity] = 'lab'
            self.sequence_anchor_identities.add(lecture_identity)

    def relax_sequence_pair(self, lecture_gene, lab_gene):
        lecture_identity = self.get_gene_identity(lecture_gene)
        lab_identity = self.get_gene_identity(lab_gene)
        pair_key = (lecture_identity, lab_identity)
        if pair_key not in self.sequence_relaxed_pair_keys:
            self.sequence_relaxed_pair_keys.add(pair_key)
            self.sequence_relaxed_pairs.append((lecture_gene, lab_gene))

        self.sequence_partner_by_identity.pop(lecture_identity, None)
        self.sequence_partner_by_identity.pop(lab_identity, None)
        self.sequence_role_by_identity.pop(lecture_identity, None)
        self.sequence_role_by_identity.pop(lab_identity, None)
        self.sequence_anchor_identities.discard(lecture_identity)

    def get_sequence_pair_genes(self, gene_a, gene_b):
        if not gene_a or not gene_b:
            return None, None
        if int(gene_a.get('subject_id') or 0) != int(gene_b.get('subject_id') or 0):
            return None, None
        if self.get_section_key(gene_a) != self.get_section_key(gene_b):
            return None, None

        kind_a = str(gene_a.get('meeting_kind') or 'lecture').strip().lower()
        kind_b = str(gene_b.get('meeting_kind') or 'lecture').strip().lower()
        if kind_a == 'lecture' and kind_b == 'lab':
            return gene_a, gene_b
        if kind_a == 'lab' and kind_b == 'lecture':
            return gene_b, gene_a
        return None, None

    def is_relaxed_sequence_pair(self, gene_a, gene_b):
        lecture_gene, lab_gene = self.get_sequence_pair_genes(gene_a, gene_b)
        if lecture_gene is None or lab_gene is None:
            return False
        pair_key = (
            self.get_gene_identity(lecture_gene),
            self.get_gene_identity(lab_gene),
        )
        return pair_key in self.sequence_relaxed_pair_keys

    def has_feasible_sequence_slots(self, lecture_gene, lab_gene):
        for lecture_time in self.get_ordered_slot_ids_for_gene(lecture_gene):
            lecture_interval = self.get_entry_interval(lecture_gene, lecture_time)
            if not lecture_interval:
                continue
            lab_time = self.get_slot_id_by_day_and_start(lecture_interval['day'], lecture_interval['end_minutes'])
            if lab_time is None:
                continue
            if self.get_entry_interval(lab_gene, lab_time) is None:
                continue
            return True
        return False

    def format_section_label(self, section_key):
        try:
            year_level, section_name = section_key
        except Exception:
            return str(section_key)
        return f"year {year_level} section {section_name}"

    def format_day_label(self, day):
        text = str(day or '').strip().lower()
        if not text:
            return 'Unknown day'
        return text.capitalize()

    def format_interval_label(self, day, start_minutes, end_minutes):
        return (
            f"{self.format_day_label(day)} "
            f"{self.minutes_to_time(int(start_minutes))[:5]}-{self.minutes_to_time(int(end_minutes))[:5]}"
        )

    def get_sequence_pair_slot_candidates(self, lecture_gene, lab_gene):
        candidates = []
        required_days = self.get_gene_required_days(lecture_gene)
        empty_state = {'room_bookings': {}}

        for lecture_time in self.get_ordered_slot_ids_for_gene(lecture_gene):
            lecture_probe = dict(lecture_gene)
            lecture_interval = self.get_entry_interval(lecture_probe, lecture_time)
            if not lecture_interval:
                continue
            if required_days and lecture_interval['day'] not in required_days:
                continue
            if self.is_disallowed_slot(lecture_time):
                continue
            if not self.is_interval_within_windows(
                lecture_interval['day'],
                lecture_interval['start_minutes'],
                lecture_interval['end_minutes'],
                self.open_windows_by_day
            ):
                continue

            lab_time = self.get_slot_id_by_day_and_start(lecture_interval['day'], lecture_interval['end_minutes'])
            if lab_time is None or self.is_disallowed_slot(lab_time):
                continue

            lab_probe = dict(lab_gene)
            lab_interval = self.get_entry_interval(lab_probe, lab_time)
            if not lab_interval:
                continue
            if not self.is_interval_within_windows(
                lab_interval['day'],
                lab_interval['start_minutes'],
                lab_interval['end_minutes'],
                self.open_windows_by_day
            ):
                continue

            lecture_room_ids = self.get_candidate_room_ids(dict(lecture_gene), lecture_time, empty_state)
            lab_room_ids = self.get_candidate_room_ids(dict(lab_gene), lab_time, empty_state)
            if not lecture_room_ids or not lab_room_ids:
                continue

            candidates.append({
                'lecture_slot_id': int(lecture_time),
                'lab_slot_id': int(lab_time),
                'day': lecture_interval['day'],
                'start_minutes': int(lecture_interval['start_minutes']),
                'end_minutes': int(lab_interval['end_minutes']),
            })

        return candidates

    def get_feasible_sequence_pair_placements(self, lecture_gene, lab_gene):
        feasible = []
        seen = set()
        subject_code = str(lecture_gene.get('subject_code') or '').strip().upper()
        lecture_minutes = self.get_gene_minutes(lecture_gene)
        lab_minutes = self.get_gene_minutes(lab_gene)

        for candidate in self.get_sequence_pair_slot_candidates(lecture_gene, lab_gene):
            eligible_instructors = []
            for instructor_id in self.subject_candidate_instructors.get(subject_code, []):
                iid = int(instructor_id)
                if not self.can_teach_subject(iid, subject_code):
                    continue
                if not self.is_interval_within_windows(
                    candidate['day'],
                    candidate['start_minutes'],
                    candidate['start_minutes'] + lecture_minutes,
                    self.availability_windows.get(iid, {})
                ):
                    continue
                if not self.is_interval_within_windows(
                    candidate['day'],
                    candidate['end_minutes'] - lab_minutes,
                    candidate['end_minutes'],
                    self.availability_windows.get(iid, {})
                ):
                    continue
                if (
                    self.is_non_mirror_slot(candidate['lecture_slot_id'])
                    or self.is_non_mirror_slot(candidate['lab_slot_id'])
                ) and not (
                    self.is_instructor_allowed_on_wednesday(iid, lecture_gene)
                    and self.is_instructor_allowed_on_wednesday(iid, lab_gene)
                ):
                    continue
                eligible_instructors.append(iid)

            placement_key = (
                candidate['day'],
                candidate['start_minutes'],
                candidate['end_minutes'],
            )
            if eligible_instructors and placement_key not in seen:
                feasible.append({
                    **candidate,
                    'eligible_instructor_ids': eligible_instructors,
                })
                seen.add(placement_key)

        return feasible

    def can_assign_non_overlapping_sequence_pairs(self, section_pairs):
        ordered_pairs = sorted(section_pairs, key=lambda item: len(item.get('placements') or []))
        chosen = []

        def backtrack(index):
            if index >= len(ordered_pairs):
                return True

            for placement in ordered_pairs[index].get('placements') or []:
                overlaps = any(
                    self.intervals_overlap(
                        placement['day'],
                        placement['start_minutes'],
                        placement['end_minutes'],
                        existing['day'],
                        existing['start_minutes'],
                        existing['end_minutes'],
                    )
                    for existing in chosen
                )
                if overlaps:
                    continue
                chosen.append(placement)
                if backtrack(index + 1):
                    return True
                chosen.pop()
            return False

        return backtrack(0)

    def get_max_non_overlapping_sequence_pair_keys(self, section_pairs):
        ordered_pairs = sorted(
            section_pairs,
            key=lambda item: (
                len(item.get('placements') or []),
                str(item.get('subject_code') or ''),
            ),
        )
        chosen = []
        best_pair_keys = set()

        def backtrack(index):
            nonlocal best_pair_keys
            if len(chosen) + (len(ordered_pairs) - index) <= len(best_pair_keys):
                return

            if index >= len(ordered_pairs):
                candidate_keys = {item['pair_key'] for item in chosen}
                if len(candidate_keys) > len(best_pair_keys):
                    best_pair_keys = candidate_keys
                return

            current = ordered_pairs[index]
            for placement in current.get('placements') or []:
                overlaps = any(
                    self.intervals_overlap(
                        placement['day'],
                        placement['start_minutes'],
                        placement['end_minutes'],
                        existing['placement']['day'],
                        existing['placement']['start_minutes'],
                        existing['placement']['end_minutes'],
                    )
                    for existing in chosen
                )
                if overlaps:
                    continue
                chosen.append({
                    'pair_key': current['pair_key'],
                    'placement': placement,
                })
                backtrack(index + 1)
                chosen.pop()

            backtrack(index + 1)

        backtrack(0)
        return best_pair_keys

    def _raise_sequence_feasibility_issues(self):
        if not self.sequence_pairs:
            return

        pair_relax_warnings = []
        section_pairs = {}

        for lecture_gene, lab_gene in self.sequence_pairs:
            section_key = self.get_section_key(lecture_gene)
            subject_code = str(lecture_gene.get('subject_code') or f"subject#{lecture_gene.get('subject_id')}").strip()
            raw_candidates = self.get_sequence_pair_slot_candidates(lecture_gene, lab_gene)
            placements = self.get_feasible_sequence_pair_placements(lecture_gene, lab_gene)

            if not placements:
                subject_label = f"{subject_code} ({self.format_section_label(section_key)})"
                if not raw_candidates:
                    # No exact back-to-back window exists for this pair. In this
                    # case we allow lecture and lab to be scheduled separately
                    # instead of blocking the whole job up front.
                    self.relax_sequence_pair(lecture_gene, lab_gene)
                    continue
                if raw_candidates:
                    windows = ", ".join(
                        self.format_interval_label(item['day'], item['start_minutes'], item['end_minutes'])
                        for item in raw_candidates[:3]
                    )
                    has_non_mirror_candidate = any(
                        self.is_non_mirror_slot(item['lecture_slot_id']) or self.is_non_mirror_slot(item['lab_slot_id'])
                        for item in raw_candidates
                    )
                    if has_non_mirror_candidate:
                        pair_relax_warnings.append(
                            f"{subject_label} only fits consecutive lecture/lab windows at {windows}, but no selected instructor is allowed there under the Wednesday policy. Relaxing to allow non-consecutive placement."
                        )
                    else:
                        pair_relax_warnings.append(
                            f"{subject_label} has consecutive lecture/lab windows ({windows}), but none match the selected instructors' availability. Relaxing to allow non-consecutive placement."
                        )
                self.relax_sequence_pair(lecture_gene, lab_gene)
                continue

            section_pairs.setdefault(section_key, []).append({
                'pair_key': (
                    self.get_gene_identity(lecture_gene),
                    self.get_gene_identity(lab_gene),
                ),
                'lecture_gene': lecture_gene,
                'lab_gene': lab_gene,
                'subject_code': subject_code,
                'sole_instructor_id': (
                    placements[0]['eligible_instructor_ids'][0]
                    if placements
                    and all(
                        len(placement.get('eligible_instructor_ids') or []) == 1
                        and placement['eligible_instructor_ids'][0] == (placements[0].get('eligible_instructor_ids') or [None])[0]
                        for placement in placements
                    )
                    else None
                ),
                'placements': placements,
            })

        if pair_relax_warnings:
            print("Sequence placement warning: " + "; ".join(pair_relax_warnings))

        subject_relax_warnings = []
        subject_instructor_groups = {}
        for items in section_pairs.values():
            for item in items:
                sole_instructor_id = item.get('sole_instructor_id')
                if sole_instructor_id is None:
                    continue
                subject_instructor_groups.setdefault(
                    (str(item.get('subject_code') or ''), int(sole_instructor_id)),
                    [],
                ).append(item)

        for (subject_code, instructor_id), items in sorted(subject_instructor_groups.items()):
            if len(items) <= 1:
                continue

            unique_windows = {}
            for item in items:
                for placement in item.get('placements') or []:
                    window_key = (
                        placement['day'],
                        placement['start_minutes'],
                        placement['end_minutes'],
                    )
                    unique_windows[window_key] = self.format_interval_label(*window_key)

            if len(unique_windows) >= len(items):
                continue

            for item in items:
                self.relax_sequence_pair(item['lecture_gene'], item['lab_gene'])

            instructor = self.instructor_by_id.get(int(instructor_id)) or {}
            instructor_name = str(
                instructor.get('full_name')
                or instructor.get('name')
                or f"instructor {instructor_id}"
            ).strip()
            windows_text = ", ".join(unique_windows[key] for key in sorted(unique_windows))
            subject_relax_warnings.append(
                f"{subject_code} has {len(items)} section pair(s) sharing only {len(unique_windows)} distinct consecutive window(s) for {instructor_name}: {windows_text}. Relaxing to allow non-consecutive placement."
            )

        if subject_relax_warnings:
            print("Sequence instructor-capacity warning: " + "; ".join(subject_relax_warnings))

        relaxed_section_issues = []
        for section_key, items in sorted(section_pairs.items()):
            items = [
                item for item in items
                if item['pair_key'] not in self.sequence_relaxed_pair_keys
            ]
            if not items:
                continue
            if self.can_assign_non_overlapping_sequence_pairs(items):
                continue

            unique_windows = {}
            for item in items:
                for placement in item.get('placements') or []:
                    window_key = (
                        placement['day'],
                        placement['start_minutes'],
                        placement['end_minutes'],
                    )
                    unique_windows[window_key] = self.format_interval_label(*window_key)

            windows_text = ", ".join(unique_windows[key] for key in sorted(unique_windows))
            strict_pair_keys = self.get_max_non_overlapping_sequence_pair_keys(items)
            if len(strict_pair_keys) >= len(items):
                continue

            relaxed_items = list(items)
            for item in relaxed_items:
                self.relax_sequence_pair(item['lecture_gene'], item['lab_gene'])

            relaxed_codes = ", ".join(sorted(item['subject_code'] for item in relaxed_items))
            relaxed_section_issues.append(
                f"{self.format_section_label(section_key)} needs {len(items)} lecture/lab pair placements, "
                f"but only {len(unique_windows)} distinct non-overlapping consecutive window(s) are available: {windows_text}. "
                f"Relaxing consecutive placement for: {relaxed_codes}"
            )

        if relaxed_section_issues:
            print("Sequence capacity warning: " + "; ".join(relaxed_section_issues))

    def get_slot_priority_key(self, time_slot_id):
        slot = self.get_slot(time_slot_id) or {}
        day = str(slot.get('day') or '').strip().lower()
        start_minutes = self.time_to_minutes(slot.get('start_time'))
        return (
            abs(start_minutes - self.preferred_start_minutes),
            self.day_priority.get(day, 99),
            start_minutes,
            int(time_slot_id),
        )

    def normalize_day_name(self, value):
        token = str(value or '').strip().lower()
        if token.startswith('mon'):
            return 'monday'
        if token.startswith('tue'):
            return 'tuesday'
        if token.startswith('wed'):
            return 'wednesday'
        if token.startswith('thu'):
            return 'thursday'
        if token.startswith('fri'):
            return 'friday'
        if token.startswith('sat'):
            return 'saturday'
        return ''

    def parse_preferred_day_pair(self, raw_value):
        text = str(raw_value or '').strip().lower()
        if not text:
            return ()
        normalized_text = text.replace('-', '/').replace('_', '/')
        parts = [self.normalize_day_name(part) for part in normalized_text.split('/') if str(part).strip() != '']
        parts = [part for part in parts if part in WEEKDAYS]
        deduped = []
        for part in parts:
            if part not in deduped:
                deduped.append(part)
        if not deduped:
            return ()
        return tuple(deduped)

    def is_pathfit_or_pe_subject(self, entry_or_gene):
        subject_id = int(entry_or_gene.get('subject_id') or entry_or_gene.get('id') or 0)
        subject = self.subject_by_id.get(subject_id) or entry_or_gene
        code = str(subject.get('subject_code') or '').strip().upper()
        name = str(subject.get('subject_name') or '').strip().upper()
        normalized_code = code.replace(' ', '').replace('-', '')
        normalized_name = name.replace(' ', '').replace('-', '')
        if 'PATHFIT' in normalized_code or 'PATHFIT' in normalized_name:
            return True
        if normalized_code.startswith('PE') and not normalized_code.startswith('CPE'):
            return True
        if 'PHYSICALEDUCATION' in normalized_name:
            return True
        return False

    def is_instructor_allowed_on_wednesday(self, instructor_id, entry_or_gene):
        """
        Institutional Policy: Permanent instructors cannot teach on Wednesdays
        unless the subject is PATHFIT or PE. Contractual/Temporary are always allowed.
        """
        # Exception 1: Pathfit/PE subjects are always allowed on Wednesday for everyone.
        if self.is_pathfit_or_pe_subject(entry_or_gene):
            return True
            
        instructor = self.instructor_by_id.get(int(instructor_id)) or {}
        status = str(instructor.get('status') or '').strip().lower()
        
        # Exception 2: Only bar 'permanent'. Allow 'contractual', 'temporary', and others.
        return status not in ('permanent',)

    def get_gene_required_days(self, gene):
        if self.preferred_day_mode == 'strict':
            return self.get_gene_preferred_days(gene)
        return ()

    def get_gene_preferred_days(self, gene):
        if self.is_pathfit_or_pe_subject(gene):
            return ('wednesday',)
        if self.preferred_day_mode == 'none':
            return ()
        try:
            subject_id = int(gene.get('subject_id') or 0)
        except Exception:
            return ()
        return self.subject_preferred_days_by_id.get(subject_id, ())

    def slot_matches_gene_preferred_days(self, gene, time_slot_id):
        preferred_days = self.get_gene_preferred_days(gene)
        if not preferred_days:
            return False
        slot = self.get_slot(time_slot_id) or {}
        day = str(slot.get('_day') or slot.get('day') or '').strip().lower()
        return day in preferred_days

    def get_gene_preferred_start_minutes(self, gene):
        try:
            subject_id = int(gene.get('subject_id') or 0)
        except Exception:
            return None
        return self.subject_preferred_start_minutes_by_id.get(subject_id)

    def get_gene_preferred_end_minutes(self, gene):
        try:
            subject_id = int(gene.get('subject_id') or 0)
        except Exception:
            return None
        return self.subject_preferred_end_minutes_by_id.get(subject_id)

    def get_gene_slot_priority_key(self, gene, time_slot_id, prefer_non_mirror=False):
        slot = self.get_slot(time_slot_id) or {}
        day = str(slot.get('_day') or slot.get('day') or '').strip().lower()
        preferred_days = self.get_gene_preferred_days(gene)
        start_minutes = slot.get('_start_minutes')
        if start_minutes is None:
            start_minutes = self.time_to_minutes(slot.get('start_time'))
        end_minutes = slot.get('_end_minutes')
        if end_minutes is None:
            end_minutes = self.time_to_minutes(slot.get('end_time'))

        subject_preferred_start = self.get_gene_preferred_start_minutes(gene)
        subject_preferred_end = self.get_gene_preferred_end_minutes(gene)
        target_start = self.preferred_start_minutes if subject_preferred_start is None else subject_preferred_start

        window_penalty = 0
        if subject_preferred_start is not None and subject_preferred_end is not None:
            window_penalty = 0 if (start_minutes >= subject_preferred_start and end_minutes <= subject_preferred_end) else 1

        return (
            0 if (not prefer_non_mirror or int(time_slot_id) in self.non_mirror_slot_id_set) else 1,
            0 if (not preferred_days or day in preferred_days) else 1,
            window_penalty,
            abs(int(start_minutes) - int(target_start)),
            0 if subject_preferred_end is None else abs(int(end_minutes) - int(subject_preferred_end)),
            self.day_priority.get(day, 99),
            int(start_minutes),
            int(time_slot_id),
        )

    def get_ordered_slot_ids_for_gene(self, gene, prefer_non_mirror=False, anchor_only=False):
        cache_key = (
            self.get_gene_identity(gene),
            bool(prefer_non_mirror),
            bool(anchor_only),
        )
        if cache_key in self.slot_order_cache:
            return list(self.slot_order_cache[cache_key])

        if anchor_only:
            base_slots = list(self.anchor_slot_ids_sorted)
        elif prefer_non_mirror and self.non_mirror_slot_ids_sorted:
            others = [slot_id for slot_id in self.all_regular_slot_ids_sorted if slot_id not in self.non_mirror_slot_id_set]
            base_slots = list(self.non_mirror_slot_ids_sorted) + others
        else:
            base_slots = list(self.all_regular_slot_ids_sorted)

        ordered = [slot_id for slot_id in base_slots if self.get_entry_interval(gene, slot_id) is not None]
        preferred_days = self.get_gene_preferred_days(gene)
        if preferred_days:
            matching_slots = []
            fallback_slots = []
            for slot_id in ordered:
                if self.slot_matches_gene_preferred_days(gene, slot_id):
                    matching_slots.append(slot_id)
                else:
                    fallback_slots.append(slot_id)
            if self.preferred_day_mode == 'strict':
                ordered = matching_slots
            else:
                ordered = matching_slots + fallback_slots
        ordered.sort(
            key=lambda slot_id: self.get_gene_slot_priority_key(
                gene,
                slot_id,
                prefer_non_mirror=(prefer_non_mirror and not anchor_only),
            )
        )
        self.slot_order_cache[cache_key] = list(ordered)
        return ordered

    def get_primary_anchor_slot_ids_for_gene(self, gene, paired_anchor_only=False):
        base_slots = self.anchor_slot_ids_sorted if paired_anchor_only else self.primary_anchor_slot_ids_sorted
        ordered = [int(slot_id) for slot_id in base_slots if self.get_entry_interval(gene, slot_id) is not None]
        preferred_days = self.get_gene_preferred_days(gene)
        if preferred_days:
            matching_slots = []
            fallback_slots = []
            for slot_id in ordered:
                if self.slot_matches_gene_preferred_days(gene, slot_id):
                    matching_slots.append(slot_id)
                else:
                    fallback_slots.append(slot_id)
            if self.preferred_day_mode == 'strict':
                ordered = matching_slots
            else:
                ordered = matching_slots + fallback_slots
        ordered.sort(key=lambda slot_id: self.get_gene_slot_priority_key(gene, slot_id))
        return ordered

    def get_gene_sort_key(self, gene):
        identity = self.get_gene_identity(gene)
        if identity in self.gene_sort_key_cache:
            return self.gene_sort_key_cache[identity]

        candidate_instructor_count = len(self.subject_candidate_instructors.get(
            str(gene.get('subject_code') or '').strip().upper(),
            self.instructor_ids
        ))
        meeting_kind = str(gene.get('meeting_kind') or 'lecture').strip().lower()
        slot_candidates = len(self.get_ordered_slot_ids_for_gene(
            gene,
            prefer_non_mirror=self.four_day_pattern and self.is_non_mirror_slot(gene.get('time_slot_id')),
        ))
        sequence_role = self.sequence_role_by_identity.get(identity)
        if sequence_role == 'lecture':
            meeting_kind_priority = -1
        elif sequence_role == 'lab':
            meeting_kind_priority = 2
        else:
            meeting_kind_priority = 0 if meeting_kind == 'lab' else 1
        key = (
            candidate_instructor_count,
            meeting_kind_priority,
            slot_candidates,
            -int(gene.get('meeting_minutes') or self.get_gene_minutes(gene)),
            str(gene.get('section') or ''),
            str(gene.get('subject_code') or ''),
            int(gene.get('meeting_index') or 0),
        )
        self.gene_sort_key_cache[identity] = key
        return key

    def time_to_minutes(self, value):
        try:
            parts = str(value or '00:00:00').split(':')
            hours = int(parts[0])
            minutes = int(parts[1])
            return (hours * 60) + minutes
        except Exception:
            return 0

    def parse_optional_time_to_minutes(self, value):
        text = str(value or '').strip()
        if text == '':
            return None
        return self.time_to_minutes(text)

    def minutes_to_time(self, total_minutes):
        total_minutes = max(0, int(total_minutes))
        return f"{(total_minutes // 60) % 24:02d}:{total_minutes % 60:02d}:00"

    def get_entry_interval(self, entry_or_gene, time_slot_id=None):
        if (
            isinstance(entry_or_gene, dict)
            and time_slot_id is None
            and int(entry_or_gene.get('_cached_time_slot_id') or 0) == int(entry_or_gene.get('time_slot_id') or 0)
            and entry_or_gene.get('day') is not None
            and entry_or_gene.get('start_minutes') is not None
            and entry_or_gene.get('end_minutes') is not None
        ):
            return {
                'day': entry_or_gene['day'],
                'start_minutes': int(entry_or_gene['start_minutes']),
                'end_minutes': int(entry_or_gene['end_minutes']),
                'start_time': entry_or_gene.get('start_time') or self.minutes_to_time(int(entry_or_gene['start_minutes'])),
                'end_time': entry_or_gene.get('end_time') or self.minutes_to_time(int(entry_or_gene['end_minutes'])),
            }
        slot_id = time_slot_id if time_slot_id is not None else entry_or_gene.get('time_slot_id')
        slot = self.get_slot(slot_id)
        if not slot:
            return None
        day = str(slot.get('_day') or slot.get('day') or '').strip().lower()
        start_minutes = slot.get('_start_minutes')
        if start_minutes is None:
            start_minutes = self.time_to_minutes(slot.get('start_time'))
        duration_minutes = self.get_gene_minutes(entry_or_gene)
        end_minutes = start_minutes + duration_minutes
        return {
            'day': day,
            'start_minutes': start_minutes,
            'end_minutes': end_minutes,
            'start_time': self.minutes_to_time(start_minutes),
            'end_time': self.minutes_to_time(end_minutes),
        }

    def intervals_overlap(self, day_a, start_a, end_a, day_b, start_b, end_b):
        if day_a != day_b:
            return False
        return start_a < end_b and start_b < end_a

    def is_interval_within_windows(self, day, start_minutes, end_minutes, windows):
        for window_start, window_end in windows.get(day, []):
            if start_minutes >= window_start and end_minutes <= window_end:
                return True
        return False

    def overlaps_hard_break(self, day, start_minutes, end_minutes):
        for break_start, break_end in self.hard_breaks_by_day.get(day, []):
            if start_minutes < break_end and break_start < end_minutes:
                return True
        return False

    def _merge_windows(self, windows):
        if not windows:
            return []
        ordered = sorted((int(start), int(end)) for start, end in windows)
        merged = [ordered[0]]
        for start, end in ordered[1:]:
            prev_start, prev_end = merged[-1]
            if start <= prev_end:
                merged[-1] = (prev_start, max(prev_end, end))
            else:
                merged.append((start, end))
        return merged

    def _build_windows_from_slot_ids(self, slot_ids):
        windows = {}
        allowed_ids = {int(slot_id) for slot_id in slot_ids}
        for ts in self.time_slots:
            tid = int(ts['id'])
            if tid not in allowed_ids:
                continue
            day = str(ts.get('day', '')).strip().lower()
            slot_type = str(ts.get('slot_type') or 'regular').strip().lower()
            if slot_type == 'lunch':
                continue
            windows.setdefault(day, []).append((self.time_to_minutes(ts.get('start_time')), self.time_to_minutes(ts.get('end_time'))))
        for day in list(windows.keys()):
            windows[day] = self._merge_windows(windows[day])
        return windows

    def subject_has_both_kinds(self, subject_id):
        return bool(self.subject_has_both_kinds_by_id.get(int(subject_id or 0), False))

    def normalize_room_type(self, room):
        room_type = str(room.get('room_type') or '').strip().lower()
        if room_type in ('lecture', 'lab'):
            return room_type
        return 'lab' if int(room.get('has_computers') or 0) == 1 else 'lecture'

    def get_candidate_room_ids(self, gene, time, state, excluded_room_id=None):
        meeting_kind = str(gene.get('meeting_kind') or 'lecture').strip().lower()
        interval = self.get_entry_interval(gene, time)
        if not interval:
            return []
        lecture_rooms = []
        lab_rooms = []

        for room in self.rooms:
            room_id = int(room['id'])
            if excluded_room_id is not None and room_id == int(excluded_room_id):
                continue
            if self.has_interval_conflict(state.get('room_bookings', {}).get(room_id, []), interval):
                continue
            if self.has_interval_conflict(self.blocked_room_intervals.get(room_id, []), interval):
                continue
            room_type = self.normalize_room_type(room)
            if room_type == 'lab':
                lab_rooms.append(room_id)
            else:
                lecture_rooms.append(room_id)

        if meeting_kind == 'lab':
            return lab_rooms
        if lecture_rooms:
            return lecture_rooms
        return lab_rooms

    def get_slot(self, time_slot_id):
        try:
            return self.time_slots_by_id.get(int(time_slot_id))
        except Exception:
            return None

    def is_disallowed_slot(self, time_slot_id):
        ts = self.get_slot(time_slot_id)
        if not ts:
            return True
        slot_type = str(ts.get('_slot_type') or ts.get('slot_type') or 'regular').strip().lower()
        if slot_type == 'lunch':
            return True
        start = str(ts.get('start_time') or '')
        end = str(ts.get('end_time') or '')
        if start == '11:30:00' and end == '13:00:00':
            return True
        day = str(ts.get('_day') or ts.get('day') or '').strip().lower()
        if self.four_day_pattern and self.non_mirror_mode == 0 and day in self.non_mirror_days:
            return True
        return False

    def is_non_mirror_slot(self, time_slot_id):
        return int(time_slot_id or 0) in self.non_mirror_slot_id_set

    def is_wednesday_slot(self, time_slot_id):
        return self.is_non_mirror_slot(time_slot_id)

    def get_pair_bucket_key(self, entry):
        if not self.four_day_pattern:
            return None
        if (
            isinstance(entry, dict)
            and int(entry.get('_cached_time_slot_id') or 0) == int(entry.get('time_slot_id') or 0)
            and '_pair_bucket_key' in entry
        ):
            return entry.get('_pair_bucket_key')
        ts = self.get_slot(entry.get('time_slot_id'))
        if not ts:
            return None

        day = str(ts.get('day') or '').strip().lower()
        pair_info = self.day_pair_lookup.get(day)
        if not pair_info:
            return None
        _mirror_day, pair_group = pair_info

        return (
            str(entry.get('department') or ''),
            int(entry.get('year_level')) if entry.get('year_level') is not None else None,
            str(entry.get('section') or ''),
            pair_group,
            str(ts.get('start_time') or ''),
            str(ts.get('end_time') or '')
        )

    def get_pair_alignment_key(self, entry):
        if not self.four_day_pattern:
            return None
        if (
            isinstance(entry, dict)
            and int(entry.get('_cached_time_slot_id') or 0) == int(entry.get('time_slot_id') or 0)
            and '_pair_alignment_key' in entry
        ):
            return entry.get('_pair_alignment_key')
        ts = self.get_slot(entry.get('time_slot_id'))
        if not ts:
            return None

        day = str(ts.get('day') or '').strip().lower()
        pair_info = self.day_pair_lookup.get(day)
        if not pair_info:
            return None

        _mirror_day, pair_group = pair_info
        return (
            str(entry.get('department') or ''),
            int(entry.get('year_level')) if entry.get('year_level') is not None else None,
            str(entry.get('section') or ''),
            int(entry.get('subject_id') or 0),
            pair_group,
            str(ts.get('start_time') or ''),
            str(ts.get('end_time') or ''),
            day
        )

    def get_pair_subject_slot_key(self, entry):
        if not self.four_day_pattern:
            return None
        if (
            isinstance(entry, dict)
            and int(entry.get('_cached_time_slot_id') or 0) == int(entry.get('time_slot_id') or 0)
            and '_pair_subject_slot_key' in entry
        ):
            return entry.get('_pair_subject_slot_key')
        ts = self.get_slot(entry.get('time_slot_id'))
        if not ts:
            return None

        day = str(ts.get('day') or '').strip().lower()
        pair_info = self.day_pair_lookup.get(day)
        if not pair_info:
            return None
        _mirror_day, pair_group = pair_info

        return (
            str(entry.get('department') or ''),
            int(entry.get('year_level')) if entry.get('year_level') is not None else None,
            str(entry.get('section') or ''),
            int(entry.get('subject_id') or 0),
            pair_group,
            str(ts.get('start_time') or ''),
            str(ts.get('end_time') or '')
        )

    def get_instructor_subject_pair_key(self, instructor_id, section, subject_id, time_slot_id):
        if not self.four_day_pattern:
            return None
        ts = self.get_slot(time_slot_id)
        if not ts:
            return None
        day = str(ts.get('day') or '').strip().lower()
        pair_info = self.day_pair_lookup.get(day)
        if not pair_info:
            return None
        _mirror_day, pair_group = pair_info
        return (int(instructor_id), str(section or ''), int(subject_id or 0), pair_group)

    def get_section_key(self, entry_or_gene):
        if isinstance(entry_or_gene, dict) and entry_or_gene.get('_section_key') is not None:
            return entry_or_gene.get('_section_key')
        return (
            int(entry_or_gene.get('year_level')) if entry_or_gene.get('year_level') is not None else None,
            str(entry_or_gene.get('section') or '')
        )

    def get_gene_identity(self, entry_or_gene):
        gene_key = entry_or_gene.get('_gene_key')
        if gene_key is not None:
            try:
                return int(gene_key)
            except Exception:
                pass
        return (
            int(entry_or_gene.get('subject_id') or 0),
            self.get_section_key(entry_or_gene),
            int(entry_or_gene.get('meeting_index') or 0),
            str(entry_or_gene.get('meeting_kind') or 'lecture').strip().lower(),
            int(entry_or_gene.get('meeting_minutes') or self.get_gene_minutes(entry_or_gene)),
        )

    def get_sequence_partner_gene(self, entry_or_gene):
        return self.sequence_partner_by_identity.get(self.get_gene_identity(entry_or_gene))

    def get_sequence_role(self, entry_or_gene):
        return self.sequence_role_by_identity.get(self.get_gene_identity(entry_or_gene))

    def get_entry_by_identity(self, state, entry_or_gene):
        return state.get('entry_by_identity', {}).get(self.get_gene_identity(entry_or_gene))

    def get_slot_id_by_day_and_start(self, day, start_minutes):
        for slot_id in self.day_slot_ids.get(str(day or '').strip().lower(), []):
            slot = self.get_slot(slot_id)
            if not slot:
                continue
            slot_start = slot.get('_start_minutes')
            if slot_start is None:
                slot_start = self.time_to_minutes(slot.get('start_time'))
            if int(slot_start) == int(start_minutes):
                return int(slot_id)
        return None

    def validate_sequence_pair(self, lecture_entry, lab_entry):
        if not lecture_entry or not lab_entry:
            return False
        if str(lecture_entry.get('meeting_kind') or 'lecture').strip().lower() != 'lecture':
            return False
        if str(lab_entry.get('meeting_kind') or 'lecture').strip().lower() != 'lab':
            return False
        if int(lecture_entry.get('subject_id') or 0) != int(lab_entry.get('subject_id') or 0):
            return False
        if self.get_section_key(lecture_entry) != self.get_section_key(lab_entry):
            return False
        lecture_interval = self.get_entry_interval(lecture_entry)
        lab_interval = self.get_entry_interval(lab_entry)
        if not lecture_interval or not lab_interval:
            return False
        return (
            lecture_interval['day'] == lab_interval['day']
            and int(lecture_interval['end_minutes']) == int(lab_interval['start_minutes'])
        )

    def _try_place_explicit_sequence_pair_at_time(self, lecture_gene, lab_gene, lecture_time, state, attempts=24):
        if not lab_gene:
            return None

        lecture_interval = self.get_entry_interval(lecture_gene, lecture_time)
        if not lecture_interval:
            return None
        lab_time = self.get_slot_id_by_day_and_start(lecture_interval['day'], lecture_interval['end_minutes'])
        if lab_time is None:
            return None
        if self.get_entry_interval(lab_gene, lab_time) is None:
            return None

        lecture_entry = self._try_place_gene_at_time(lecture_gene, lecture_time, state, attempts=attempts, allow_sequence_pair=False)
        if lecture_entry is None:
            return None

        lab_entry = self._try_place_gene_at_time(lab_gene, lab_time, state, attempts=attempts, allow_sequence_pair=False)
        if lab_entry is None:
            self._rollback_entry(lecture_entry, state)
            return None
        if not self.validate_sequence_pair(lecture_entry, lab_entry):
            self._rollback_entry(lab_entry, state)
            self._rollback_entry(lecture_entry, state)
            return None
        return lecture_entry, lab_entry

    def _try_place_sequence_pair_at_time(self, lecture_gene, lecture_time, state, attempts=24):
        lab_gene = self.get_sequence_partner_gene(lecture_gene)
        return self._try_place_explicit_sequence_pair_at_time(
            lecture_gene,
            lab_gene,
            lecture_time,
            state,
            attempts=attempts,
        )

    def try_place_relaxed_sequence_pair(self, gene_a, gene_b, state, pair_attempts=16, single_attempts=16):
        lecture_gene, lab_gene = self.get_sequence_pair_genes(gene_a, gene_b)
        if lecture_gene is None or lab_gene is None:
            return False

        for lecture_time in self.get_ordered_slot_ids_for_gene(lecture_gene):
            pair_entries = self._try_place_explicit_sequence_pair_at_time(
                lecture_gene,
                lab_gene,
                lecture_time,
                state,
                attempts=pair_attempts,
            )
            if pair_entries is not None:
                return True

        independent_entries = []
        independent_genes = sorted((lecture_gene, lab_gene), key=self.get_gene_sort_key)
        for gene in independent_genes:
            placed_entry = None
            for time_slot_id in self.get_ordered_slot_ids_for_gene(gene):
                placed_entry = self._try_place_gene_at_time(gene, time_slot_id, state, attempts=single_attempts)
                if placed_entry is not None:
                    independent_entries.append(placed_entry)
                    break
            if placed_entry is None:
                for entry in reversed(independent_entries):
                    self._rollback_entry(entry, state)
                return False

        return True

    def group_entries_by_identity(self, individual):
        grouped = {}
        for entry in individual:
            identity = self.get_gene_identity(entry)
            grouped.setdefault(identity, []).append(dict(entry))
        return grouped

    def rebuild_child_from_parents(self, preferred_parent, fallback_parent, point):
        preferred_entries = self.group_entries_by_identity(preferred_parent)
        fallback_entries = self.group_entries_by_identity(fallback_parent)
        child = []

        # Rebuild the chromosome using the canonical gene order so every
        # required meeting identity appears exactly once before repair.
        for idx, gene in enumerate(self.genes):
            identity = self.get_gene_identity(gene)
            primary_pool = preferred_entries if idx < point else fallback_entries
            secondary_pool = fallback_entries if idx < point else preferred_entries

            if primary_pool.get(identity):
                child.append(primary_pool[identity].pop())
            elif secondary_pool.get(identity):
                child.append(secondary_pool[identity].pop())
            else:
                child.append(dict(gene))

        rebuilt_state = self.build_state_from_individual(child)
        self.repair_missing_genes(rebuilt_state, max_passes=6 if not self.four_day_pattern else 4)
        return rebuilt_state['individual']

    def has_interval_conflict(self, intervals, candidate):
        if not candidate:
            return True
        candidate_day = candidate['day']
        candidate_start = candidate['start_minutes']
        candidate_end = candidate['end_minutes']
        for interval in intervals:
            if self.intervals_overlap(candidate_day, candidate_start, candidate_end, interval['day'], interval['start_minutes'], interval['end_minutes']):
                return True
        return False

    def _can_place_entry(self, gene, instructor, room, time, state):
        sec_key = self.get_section_key(gene)
        current_subj_id = int(gene['subject_id'])
        candidate_interval = self.get_entry_interval(gene, time)
        if not candidate_interval:
            return False
        pair_key = self.get_pair_bucket_key({
            'department': gene['department'],
            'year_level': gene['year_level'],
            'section': gene['section'],
            'time_slot_id': time
        })
        pair_subject_slot_key = self.get_pair_subject_slot_key({
            'department': gene['department'],
            'year_level': gene['year_level'],
            'section': gene['section'],
            'subject_id': gene['subject_id'],
            'time_slot_id': time
        })
        instructor_subject_pair_key = self.get_instructor_subject_pair_key(
            instructor,
            gene.get('section'),
            gene['subject_id'],
            time,
        )

        if not self.can_teach_subject(instructor, gene['subject_code']):
            return False
        if self.is_disallowed_slot(time):
            return False
        if self.is_non_mirror_slot(time) and not self.is_instructor_allowed_on_wednesday(instructor, gene):
            return False
        required_days = self.get_gene_required_days(gene)
        if required_days and candidate_interval['day'] not in required_days:
            return False
        if self.overlaps_hard_break(candidate_interval['day'], candidate_interval['start_minutes'], candidate_interval['end_minutes']):
            return False
        if not self.is_interval_within_windows(candidate_interval['day'], candidate_interval['start_minutes'], candidate_interval['end_minutes'], self.open_windows_by_day):
            return False
        if not self.is_interval_within_windows(candidate_interval['day'], candidate_interval['start_minutes'], candidate_interval['end_minutes'], self.availability_windows.get(instructor, {})):
            return False
        candidate_rooms = self.get_candidate_room_ids(gene, time, state)
        if int(room) not in candidate_rooms:
            return False
        if self.has_interval_conflict(state.get('room_bookings', {}).get(int(room), []), candidate_interval):
            return False
        if self.has_interval_conflict(state.get('inst_bookings', {}).get(int(instructor), []), candidate_interval):
            return False
        if self.has_interval_conflict(state.get('section_bookings', {}).get(sec_key, []), candidate_interval):
            return False
        if self.has_interval_conflict(self.blocked_room_intervals.get(int(room), []), candidate_interval):
            return False
        if self.has_interval_conflict(self.blocked_inst_intervals.get(int(instructor), []), candidate_interval):
            return False
        if pair_key is not None:
            existing_subject = state['used_pair_subject'].get(pair_key)
            if existing_subject is not None and int(existing_subject) != int(gene['subject_id']):
                return False
        if pair_subject_slot_key is not None:
            existing_instructor = state['paired_subject_instructor'].get(pair_subject_slot_key)
            if existing_instructor is not None and int(existing_instructor) != int(instructor):
                return False
            # Mirror policy: instructor/time alignment is strict, but room identity
            # may differ across mirrored days as long as room compatibility passes.
        if instructor_subject_pair_key is not None:
            existing_time = state.get('instructor_subject_pair_time', {}).get(instructor_subject_pair_key)
            candidate_time = (int(candidate_interval['start_minutes']), int(candidate_interval['end_minutes']))
            if existing_time is not None and tuple(existing_time) != candidate_time:
                return False

        sequence_partner = self.get_sequence_partner_gene(gene)
        if sequence_partner is not None:
            partner_entry = self.get_entry_by_identity(state, sequence_partner)
            current_role = self.get_sequence_role(gene)
            if partner_entry is not None:
                if current_role == 'lecture':
                    if not self.validate_sequence_pair({**gene, 'time_slot_id': time}, partner_entry):
                        return False
                else:
                    if not self.validate_sequence_pair(partner_entry, {**gene, 'time_slot_id': time}):
                        return False
            elif current_role == 'lab':
                return False
        
        if self.four_day_pattern:
            wed_subjects = state['wednesday_subject_per_section'].get(sec_key, [])
            non_wed_subjects = state['non_wednesday_subjects_per_section'].get(sec_key, set())
            if self.is_non_mirror_slot(time):
                if current_subj_id not in wed_subjects and len(wed_subjects) >= 2:
                    return False
                if current_subj_id in non_wed_subjects:
                    return False
                used_kinds = state['non_mirror_subject_kinds_per_section'].get(sec_key, {}).get(current_subj_id, set())
                current_kind = str(gene.get('meeting_kind') or 'lecture').strip().lower()
                if self.subject_has_both_kinds(current_subj_id) and current_kind in used_kinds:
                    return False
            elif current_subj_id in wed_subjects:
                return False

        entry_hours = float(gene.get('meeting_hours') or self.get_subject_hours(gene['subject_id']))
        # Overload is allowed; keep tracking used hours for reporting.

        return True

    def _commit_entry(self, gene, instructor, room, time, state):
        entry = {
            **gene,
            'instructor_id': instructor,
            'room_id': room,
            'time_slot_id': time,
            'scheduled_minutes': self.get_gene_minutes(gene)
        }
        self.prepare_entry_metadata(entry, instructor_id=instructor)
        state['individual'].append(entry)
        state.setdefault('entry_by_identity', {})[self.get_gene_identity(entry)] = entry
        state.setdefault('room_bookings', {}).setdefault(int(room), []).append({
            'day': entry['day'],
            'start_minutes': entry['start_minutes'],
            'end_minutes': entry['end_minutes'],
        })
        state.setdefault('inst_bookings', {}).setdefault(int(instructor), []).append({
            'day': entry['day'],
            'start_minutes': entry['start_minutes'],
            'end_minutes': entry['end_minutes'],
        })
        state.setdefault('section_bookings', {}).setdefault(self.get_section_key(gene), []).append({
            'day': entry['day'],
            'start_minutes': entry['start_minutes'],
            'end_minutes': entry['end_minutes'],
        })
        entry_hours = float(gene.get('meeting_hours') or self.get_subject_hours(gene['subject_id']))
        state['used_inst_hours'][instructor] = state['used_inst_hours'].get(instructor, 0.0) + entry_hours
        pair_key = entry.get('_pair_bucket_key')
        if pair_key is not None:
            state['used_pair_subject'][pair_key] = gene['subject_id']
        pair_subject_slot_key = entry.get('_pair_subject_slot_key')
        if pair_subject_slot_key is not None:
            state['paired_subject_instructor'][pair_subject_slot_key] = instructor
            state['paired_subject_room'][pair_subject_slot_key] = room
        instructor_subject_pair_key = entry.get('_instructor_subject_pair_key')
        if instructor_subject_pair_key is not None:
            state.setdefault('instructor_subject_pair_time', {})[instructor_subject_pair_key] = (
                int(entry['start_minutes']),
                int(entry['end_minutes'])
            )

        if self.four_day_pattern:
            sec_key = self.get_section_key(gene)
            if self.is_non_mirror_slot(time):
                wed_list = state['wednesday_subject_per_section'].setdefault(sec_key, [])
                if int(gene['subject_id']) not in wed_list:
                    wed_list.append(int(gene['subject_id']))
                state['used_wed_sections'].add(sec_key)
                state['non_mirror_subject_kinds_per_section'].setdefault(sec_key, {}).setdefault(int(gene['subject_id']), set()).add(
                    str(gene.get('meeting_kind') or 'lecture').strip().lower()
                )
            else:
                state['non_wednesday_subjects_per_section'].setdefault(sec_key, set()).add(int(gene['subject_id']))
        return entry

    def _rollback_entry(self, entry, state):
        self.prepare_entry_metadata(entry, instructor_id=entry.get('instructor_id'))
        gene = entry
        instructor = entry['instructor_id']
        room = entry['room_id']
        time = entry['time_slot_id']
        if state['individual'] and state['individual'][-1] is entry:
            state['individual'].pop()
        else:
            try:
                state['individual'].remove(entry)
            except ValueError:
                pass
        state.setdefault('entry_by_identity', {}).pop(self.get_gene_identity(entry), None)
        room_intervals = state.get('room_bookings', {}).get(int(room), [])
        state['room_bookings'][int(room)] = [interval for interval in room_intervals if not (
            interval['day'] == entry.get('day') and interval['start_minutes'] == entry.get('start_minutes') and interval['end_minutes'] == entry.get('end_minutes')
        )]
        inst_intervals = state.get('inst_bookings', {}).get(int(instructor), [])
        state['inst_bookings'][int(instructor)] = [interval for interval in inst_intervals if not (
            interval['day'] == entry.get('day') and interval['start_minutes'] == entry.get('start_minutes') and interval['end_minutes'] == entry.get('end_minutes')
        )]
        sec_key_entry = self.get_section_key(gene)
        sec_intervals = state.get('section_bookings', {}).get(sec_key_entry, [])
        state['section_bookings'][sec_key_entry] = [interval for interval in sec_intervals if not (
            interval['day'] == entry.get('day') and interval['start_minutes'] == entry.get('start_minutes') and interval['end_minutes'] == entry.get('end_minutes')
        )]
        entry_hours = float(gene.get('meeting_hours') or self.get_subject_hours(gene['subject_id']))
        next_hours = state['used_inst_hours'].get(instructor, 0.0) - entry_hours
        if next_hours <= 1e-6:
            state['used_inst_hours'].pop(instructor, None)
        else:
            state['used_inst_hours'][instructor] = next_hours

        pair_key = entry.get('_pair_bucket_key')
        if pair_key is not None:
            # Rebuild pair map for correctness (small and safe for rollback path).
            state['used_pair_subject'].clear()
            state['paired_subject_instructor'].clear()
            state['paired_subject_room'].clear()
            state.setdefault('instructor_subject_pair_time', {}).clear()
            for e in state['individual']:
                self.prepare_entry_metadata(e, instructor_id=e.get('instructor_id'))
                pk = e.get('_pair_bucket_key')
                if pk is not None:
                    state['used_pair_subject'][pk] = e['subject_id']
                psk = e.get('_pair_subject_slot_key')
                if psk is not None:
                    state['paired_subject_instructor'][psk] = e['instructor_id']
                    state['paired_subject_room'][psk] = e['room_id']
                isp_key = e.get('_instructor_subject_pair_key')
                if isp_key is not None:
                    state['instructor_subject_pair_time'][isp_key] = (
                        int(e['start_minutes']),
                        int(e['end_minutes'])
                    )

        if self.four_day_pattern:
            state['wednesday_subject_per_section'].clear()
            state['non_wednesday_subjects_per_section'].clear()
            state['non_mirror_subject_kinds_per_section'].clear()
            state['used_wed_sections'].clear()
            for e in state['individual']:
                sec_key = self.get_section_key(e)
                if self.is_non_mirror_slot(e['time_slot_id']):
                    wed_list = state['wednesday_subject_per_section'].setdefault(sec_key, [])
                    if int(e['subject_id']) not in wed_list:
                        wed_list.append(int(e['subject_id']))
                    state['used_wed_sections'].add(sec_key)
                    state['non_mirror_subject_kinds_per_section'].setdefault(sec_key, {}).setdefault(int(e['subject_id']), set()).add(
                        str(e.get('meeting_kind') or 'lecture').strip().lower()
                    )
                else:
                    state['non_wednesday_subjects_per_section'].setdefault(sec_key, set()).add(int(e['subject_id']))

    def _try_place_gene_at_time(self, gene, time, state, attempts=24, allow_sequence_pair=True):
        sequence_partner = self.get_sequence_partner_gene(gene)
        if allow_sequence_pair and sequence_partner is not None:
            sequence_role = self.get_sequence_role(gene)
            partner_entry = self.get_entry_by_identity(state, sequence_partner)
            if sequence_role == 'lecture' and partner_entry is None:
                pair_entries = self._try_place_sequence_pair_at_time(gene, time, state, attempts=attempts)
                if pair_entries is not None:
                    return pair_entries[0]
                return None
            if sequence_role == 'lab' and partner_entry is None:
                return None

        subject_code = str(gene.get('subject_code') or '').strip().upper()
        candidate_instructors = list(self.subject_candidate_instructors.get(subject_code, self.instructor_ids))
        random.shuffle(candidate_instructors)
        candidate_instructors.sort(
            key=lambda iid: (
                float(state.get('used_inst_hours', {}).get(int(iid), 0.0)),
                -float(self.instructor_max_hours.get(int(iid), 20.0)),
                int(iid),
            )
        )
        candidate_rooms = self.get_candidate_room_ids(gene, time, state)
        if not candidate_rooms:
            return None
        candidate_rooms = sorted(candidate_rooms)

        attempt_count = 0
        for instructor in candidate_instructors:
            shuffled_rooms = list(candidate_rooms)
            random.shuffle(shuffled_rooms)
            for room in shuffled_rooms:
                attempt_count += 1
                if attempt_count > attempts * max(1, len(candidate_instructors)):
                    return None
                if not self._can_place_entry(gene, instructor, room, time, state):
                    continue
                return self._commit_entry(gene, instructor, room, time, state)
        return None

    def _try_place_mirror_gene_from_anchor_entry(self, anchor_entry, mirror_gene, state):
        anchor_time = int(anchor_entry.get('time_slot_id') or 0)
        mirror_time = self.paired_slot_map.get(anchor_time)
        if mirror_time is None:
            return None
        if self.get_entry_interval(mirror_gene, mirror_time) is None:
            return None

        instructor = int(anchor_entry.get('instructor_id') or 0)
        if instructor <= 0:
            return None

        anchor_room = int(anchor_entry.get('room_id') or 0)
        mirror_rooms = [int(room_id) for room_id in self.get_candidate_room_ids(mirror_gene, mirror_time, state)]
        if not mirror_rooms:
            return None

        preferred_mirror_rooms = []
        if anchor_room in mirror_rooms:
            preferred_mirror_rooms.append(anchor_room)
        alternate_mirror_rooms = [room_id for room_id in mirror_rooms if room_id != anchor_room]
        random.shuffle(alternate_mirror_rooms)
        mirror_room_order = preferred_mirror_rooms + alternate_mirror_rooms

        for mirror_room in mirror_room_order:
            if not self._can_place_entry(mirror_gene, instructor, mirror_room, mirror_time, state):
                continue
            return self._commit_entry(mirror_gene, instructor, mirror_room, mirror_time, state)
        return None

    def _try_place_anchor_then_mirror_pair(self, anchor_gene, mirror_gene, state, attempts=24):
        anchor_slots = self.get_primary_anchor_slot_ids_for_gene(anchor_gene, paired_anchor_only=True)
        if not anchor_slots:
            return None

        for anchor_time in anchor_slots[:max(1, attempts * 2)]:
            anchor_entry = self._try_place_gene_at_time(anchor_gene, anchor_time, state, attempts=attempts)
            if anchor_entry is None:
                continue
            mirror_entry = self._try_place_mirror_gene_from_anchor_entry(anchor_entry, mirror_gene, state)
            if mirror_entry is not None:
                return anchor_entry, mirror_entry
            self._rollback_entry(anchor_entry, state)
        return None

    def _try_place_mirrored_gene_pair_at_anchor_time(self, anchor_gene, mirror_gene, anchor_time, state, attempts=24):
        mirror_time = self.paired_slot_map.get(int(anchor_time))
        if mirror_time is None:
            return None
        if self.get_entry_interval(anchor_gene, anchor_time) is None:
            return None
        if self.get_entry_interval(mirror_gene, mirror_time) is None:
            return None

        subject_code = str(anchor_gene.get('subject_code') or '').strip().upper()
        candidate_instructors = list(self.subject_candidate_instructors.get(subject_code, self.instructor_ids))
        random.shuffle(candidate_instructors)
        candidate_instructors.sort(
            key=lambda iid: (
                float(state.get('used_inst_hours', {}).get(int(iid), 0.0)),
                -float(self.instructor_max_hours.get(int(iid), 20.0)),
                int(iid),
            )
        )

        anchor_rooms = self.get_candidate_room_ids(anchor_gene, anchor_time, state)
        if not anchor_rooms:
            return None

        attempt_limit = max(1, attempts * max(1, len(candidate_instructors)))
        attempt_count = 0

        for instructor in candidate_instructors:
            shuffled_anchor_rooms = list(anchor_rooms)
            random.shuffle(shuffled_anchor_rooms)

            for anchor_room in shuffled_anchor_rooms:
                attempt_count += 1
                if attempt_count > attempt_limit:
                    return None
                if not self._can_place_entry(anchor_gene, instructor, anchor_room, anchor_time, state):
                    continue

                anchor_entry = self._commit_entry(anchor_gene, instructor, anchor_room, anchor_time, state)
                mirror_entry = self._try_place_mirror_gene_from_anchor_entry(anchor_entry, mirror_gene, state)
                if mirror_entry is not None:
                    return anchor_entry, mirror_entry

                self._rollback_entry(anchor_entry, state)

        return None

    def build_state_from_individual(self, individual):
        state = {
            'individual': [],
            'room_bookings': {},
            'inst_bookings': {},
            'section_bookings': {},
            'entry_by_identity': {},
            'used_inst_hours': {},
            'used_pair_subject': {},
            'paired_subject_instructor': {},
            'paired_subject_room': {},
            'instructor_subject_pair_time': {},
            'wednesday_subject_per_section': {},
            'non_wednesday_subjects_per_section': {},
            'non_mirror_subject_kinds_per_section': {},
            'used_wed_sections': set()
        }

        ordered_entries = sorted(
            [self.prepare_entry_metadata(dict(entry), instructor_id=entry.get('instructor_id')) for entry in individual],
            key=self.get_gene_sort_key
        )

        for entry in ordered_entries:
            time_slot_id = int(entry.get('time_slot_id') or 0)
            instructor_id = int(entry.get('instructor_id') or 0)
            room_id = int(entry.get('room_id') or 0)
            if time_slot_id <= 0 or instructor_id <= 0 or room_id <= 0:
                continue
            if not self._can_place_entry(entry, instructor_id, room_id, time_slot_id, state):
                continue
            self._commit_entry(entry, instructor_id, room_id, time_slot_id, state)

        return state

    def repair_missing_genes(self, state, max_passes=3):
        target_counts = Counter(self.get_gene_identity(gene) for gene in self.genes)
        placed_counts = Counter(self.get_gene_identity(entry) for entry in state.get('individual', []))

        for _ in range(max_passes):
            progress_made = False
            missing_genes = []
            remaining_counts = target_counts - placed_counts
            if not remaining_counts:
                break

            for gene in self.genes:
                gene_identity = self.get_gene_identity(gene)
                if remaining_counts.get(gene_identity, 0) > 0:
                    missing_genes.append(gene)
                    remaining_counts[gene_identity] -= 1

            random.shuffle(missing_genes)
            missing_genes.sort(key=self.get_gene_sort_key)
            consumed_gene_keys = set()

            if self.four_day_pattern:
                grouped_missing = {}
                for gene in missing_genes:
                    grouped_missing.setdefault((self.get_section_key(gene), int(gene['subject_id'])), []).append(gene)

                for genes in grouped_missing.values():
                    random.shuffle(genes)
                    genes.sort(key=self.get_gene_sort_key)
                    idx = 0
                    while idx + 1 < len(genes):
                        g1 = genes[idx]
                        g2 = genes[idx + 1]
                        if self.is_relaxed_sequence_pair(g1, g2):
                            if self.try_place_relaxed_sequence_pair(g1, g2, state, pair_attempts=24, single_attempts=24):
                                consumed_gene_keys.add(int(g1.get('_gene_key')))
                                consumed_gene_keys.add(int(g2.get('_gene_key')))
                                placed_counts = Counter(self.get_gene_identity(entry) for entry in state.get('individual', []))
                                progress_made = True
                            idx += 2
                            continue
                        if (
                            self.get_sequence_role(g1) == 'lecture'
                            and self.get_gene_identity(self.get_sequence_partner_gene(g1) or {}) == self.get_gene_identity(g2)
                        ):
                            lecture_pair_placed = False
                            for lecture_time in self.get_primary_anchor_slot_ids_for_gene(g1):
                                pair_entries = self._try_place_sequence_pair_at_time(g1, lecture_time, state, attempts=24)
                                if pair_entries is not None:
                                    consumed_gene_keys.add(int(g1.get('_gene_key')))
                                    consumed_gene_keys.add(int(g2.get('_gene_key')))
                                    placed_counts = Counter(self.get_gene_identity(entry) for entry in state.get('individual', []))
                                    progress_made = True
                                    lecture_pair_placed = True
                                    break
                            if lecture_pair_placed:
                                idx += 2
                                continue
                            idx += 2
                            continue

                        paired_entries = self._try_place_anchor_then_mirror_pair(g1, g2, state, attempts=24)
                        if paired_entries is not None:
                            consumed_gene_keys.add(int(g1.get('_gene_key')))
                            consumed_gene_keys.add(int(g2.get('_gene_key')))
                            placed_counts = Counter(self.get_gene_identity(entry) for entry in state.get('individual', []))
                            progress_made = True
                        idx += 2

            for gene in missing_genes:
                if int(gene.get('_gene_key') or -1) in consumed_gene_keys:
                    continue
                if self.four_day_pattern:
                    candidate_slots = self.get_primary_anchor_slot_ids_for_gene(gene)
                    fallback_slots = []
                    if self.allow_non_mirror_fallback:
                        fallback_slots = [
                            slot_id
                            for slot_id in self.get_ordered_slot_ids_for_gene(gene)
                            if slot_id not in set(candidate_slots)
                        ]
                else:
                    candidate_slots = self.get_ordered_slot_ids_for_gene(gene)
                    fallback_slots = []
                placed_entry = None
                for time_slot_id in candidate_slots:
                    placed_entry = self._try_place_gene_at_time(gene, time_slot_id, state, attempts=32)
                    if placed_entry is not None:
                        placed_counts = Counter(self.get_gene_identity(entry) for entry in state.get('individual', []))
                        progress_made = True
                        break
                if placed_entry is None and fallback_slots:
                    for time_slot_id in fallback_slots:
                        placed_entry = self._try_place_gene_at_time(gene, time_slot_id, state, attempts=32)
                        if placed_entry is not None:
                            placed_counts = Counter(self.get_gene_identity(entry) for entry in state.get('individual', []))
                            progress_made = True
                            break

            if not progress_made:
                break

    # ================= CREATE INDIVIDUAL =================
    def create_individual(self):
        state = {
            'individual': [],
            'room_bookings': {},
            'inst_bookings': {},
            'section_bookings': {},
            'entry_by_identity': {},
            'used_inst_hours': {},
            'used_pair_subject': {},
            'paired_subject_instructor': {},
            'paired_subject_room': {},
            'instructor_subject_pair_time': {},
            'wednesday_subject_per_section': {},
            'non_wednesday_subjects_per_section': {},
            'non_mirror_subject_kinds_per_section': {},
            'used_wed_sections': set()
        }

        if not self.four_day_pattern:
            ordered_genes = sorted(
                self.genes,
                key=self.get_gene_sort_key
            )
            for gene in ordered_genes:
                if self.get_sequence_role(gene) == 'lab' and self.get_entry_by_identity(state, self.get_sequence_partner_gene(gene)) is None:
                    continue
                slot_ids = self.get_ordered_slot_ids_for_gene(gene, prefer_non_mirror=False)
                if random.random() < 0.35:
                    random.shuffle(slot_ids)
                placed = None
                for time in slot_ids:
                    placed = self._try_place_gene_at_time(gene, time, state, attempts=64)
                    if placed is not None:
                        break
            self.repair_missing_genes(state, max_passes=8)
            return state['individual']

        # Anchor-first paired-day initialization:
        # build Monday/Tuesday/Wednesday anchors first, then mirror Monday->Thursday
        # and Tuesday->Friday while keeping the same room when possible.
        grouped = {}
        for gene in self.genes:
            gkey = (self.get_section_key(gene), int(gene['subject_id']))
            if gkey not in grouped:
                grouped[gkey] = []
            grouped[gkey].append(gene)

        group_keys = list(grouped.keys())
        random.shuffle(group_keys)
        group_keys.sort(
            key=lambda gkey: min(self.get_gene_sort_key(gene) for gene in grouped.get(gkey, [])) if grouped.get(gkey) else (999, 999, 999, 0, '', '', 0)
        )

        non_mirror_slots = list(self.non_mirror_slot_ids_sorted)

        # Ensure at least one non-mirror-day class per section (when enabled and slots exist).
        required_sections = set(self.required_wed_sections)
        if non_mirror_slots:
            section_keys = list(required_sections)
            random.shuffle(section_keys)
            for sec_key in section_keys:
                candidate_genes = []
                for gk, glist in grouped.items():
                    if not glist:
                        continue
                    if gk[0] == sec_key:
                        candidate_genes.extend(glist)
                random.shuffle(candidate_genes)
                candidate_genes.sort(key=self.get_gene_sort_key)

                placed = False
                for gene in candidate_genes:
                    for t in self.get_ordered_slot_ids_for_gene(gene, prefer_non_mirror=True): # Prioritize Wed for Wed-eligible
                        entry = self._try_place_gene_at_time(gene, t, state, attempts=16)
                        if entry is None:
                            continue
                        gk = (self.get_section_key(gene), int(gene['subject_id']))
                        try:
                            grouped[gk].remove(gene)
                        except ValueError:
                            pass
                        placed = True
                        break
                    if placed:
                        break

        for gkey in group_keys:
            genes = list(grouped[gkey])
            random.shuffle(genes)
            genes.sort(key=self.get_gene_sort_key)
            pairable_count = (len(genes) // 2) * 2

            # Place paired genes from Monday/Tuesday anchors, then mirror them.
            idx = 0
            while idx < pairable_count:
                g1 = genes[idx]
                g2 = genes[idx + 1]
                if self.is_relaxed_sequence_pair(g1, g2):
                    self.try_place_relaxed_sequence_pair(g1, g2, state, pair_attempts=16, single_attempts=16)
                    idx += 2
                    continue
                if (
                    self.get_sequence_role(g1) == 'lecture'
                    and self.get_gene_identity(self.get_sequence_partner_gene(g1) or {}) == self.get_gene_identity(g2)
                ):
                    placed_pair = False
                    for t1 in self.get_primary_anchor_slot_ids_for_gene(g1):
                        pair_entries = self._try_place_sequence_pair_at_time(g1, t1, state, attempts=16)
                        if pair_entries is not None:
                            placed_pair = True
                            break
                    idx += 2
                    if placed_pair:
                        continue

                placed_pair = self._try_place_anchor_then_mirror_pair(g1, g2, state, attempts=16) is not None

                if not placed_pair:
                    # Soft fallback: when anchor copying is infeasible, allow a
                    # best-effort non-anchor placement so later repair/restarts
                    # still have something to work with.
                    if self.allow_non_mirror_fallback:
                        for g in (g1, g2):
                            placed = None
                            fallback_slots = self.get_ordered_slot_ids_for_gene(g)
                            for t in fallback_slots:
                                placed = self._try_place_gene_at_time(g, t, state, attempts=8)
                                if placed is not None:
                                    break
                            if placed is None:
                                break

                idx += 2

            # If odd count, place one extra on the anchor days first.
            if len(genes) % 2 == 1:
                extra = genes[-1]
                placed_extra = None
                candidate_slots = self.get_primary_anchor_slot_ids_for_gene(extra)
                for t in candidate_slots:
                    placed_extra = self._try_place_gene_at_time(extra, t, state, attempts=12)
                    if placed_extra is not None:
                        break
                if placed_extra is None and self.allow_non_mirror_fallback:
                    fallback_slots = [
                        slot_id
                        for slot_id in self.get_ordered_slot_ids_for_gene(extra, prefer_non_mirror=bool(non_mirror_slots))
                        if slot_id not in set(candidate_slots)
                    ]
                    for t in fallback_slots:
                        placed_extra = self._try_place_gene_at_time(extra, t, state, attempts=12)
                        if placed_extra is not None:
                            break

        self.repair_missing_genes(state, max_passes=4)
        return state['individual']

    # ================= POPULATION =================
    def initialize_population(self):
        population = []
        last_reported_percent = -1
        for idx in range(self.population_size):
            population.append(self.create_individual())
            # Show visible progress during heavy initialization,
            # but avoid DB writes for every single individual.
            init_percent = 1 + int(((idx + 1) / max(1, self.population_size)) * 19)  # 1..20
            if init_percent > last_reported_percent:
                self.update_progress(init_percent)
                last_reported_percent = init_percent
        return population

    # ================= FITNESS =================
    def _evaluate_individual(self, individual):
        if len(individual) != len(self.genes):
            return 0, False
        if Counter(self.get_gene_identity(entry) for entry in individual) != self.target_gene_counter:
            return 0, False

        room_bookings = {}
        inst_bookings = {}
        section_bookings = {}
        used_inst_hours = {}
        used_pair_subject = {}
        paired_subject_instructor = {}
        instructor_subject_pair_time = {}
        wednesday_subject_per_section = {}
        non_wednesday_subjects_per_section = {}
        non_mirror_subject_kinds_per_section = {}
        used_wed_sections = set()
        pair_alignment_counter = {}
        pair_instructor_counter = {}
        entry_by_identity = {}
        required_sections = set(self.required_wed_sections)
        non_mirror_slots_exist = any(len(self.day_slot_ids.get(day, [])) > 0 for day in self.non_mirror_days)
        passed_entries = 0
        passed_sequence = 0
        passed_pair_checks = 0
        total_pair_checks = 0

        for e in individual:
            self.prepare_entry_metadata(e, instructor_id=e.get('instructor_id'))
            interval = self.get_entry_interval(e)
            if not interval:
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.overlaps_hard_break(interval['day'], interval['start_minutes'], interval['end_minutes']):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            pair_key = e.get('_pair_bucket_key')
            align_key = e.get('_pair_alignment_key')
            pair_subject_slot_key = e.get('_pair_subject_slot_key')
            instructor_subject_pair_key = e.get('_instructor_subject_pair_key')
            sec_key = self.get_section_key(e)

            if self.is_disallowed_slot(e['time_slot_id']):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if not self.is_interval_within_windows(interval['day'], interval['start_minutes'], interval['end_minutes'], self.open_windows_by_day):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.has_interval_conflict(room_bookings.get(int(e['room_id']), []), interval):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.has_interval_conflict(inst_bookings.get(int(e['instructor_id']), []), interval):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.has_interval_conflict(section_bookings.get(sec_key, []), interval):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.has_interval_conflict(self.blocked_room_intervals.get(int(e['room_id']), []), interval):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.has_interval_conflict(self.blocked_inst_intervals.get(int(e['instructor_id']), []), interval):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if not self.is_interval_within_windows(interval['day'], interval['start_minutes'], interval['end_minutes'], self.availability_windows.get(int(e['instructor_id']), {})):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if not self.can_teach_subject(e['instructor_id'], e['subject_code']):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if self.is_non_mirror_slot(e['time_slot_id']) and not self.is_instructor_allowed_on_wednesday(e['instructor_id'], e):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            required_days = self.get_gene_required_days(e)
            if required_days and interval['day'] not in required_days:
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False

            entry_hours = float(e.get('meeting_hours') or self.get_subject_hours(e['subject_id']))
            next_hours = used_inst_hours.get(e['instructor_id'], 0.0) + entry_hours
            if pair_key is not None:
                existing_subject = used_pair_subject.get(pair_key)
                if existing_subject is not None and int(existing_subject) != int(e['subject_id']):
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if pair_subject_slot_key is not None:
                existing_instructor = paired_subject_instructor.get(pair_subject_slot_key)
                if existing_instructor is not None and int(existing_instructor) != int(e['instructor_id']):
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            if instructor_subject_pair_key is not None:
                existing_time = instructor_subject_pair_time.get(instructor_subject_pair_key)
                candidate_time = (int(interval['start_minutes']), int(interval['end_minutes']))
                if existing_time is not None and tuple(existing_time) != candidate_time:
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False

            if self.four_day_pattern:
                current_subj_id = int(e['subject_id'])
                if self.is_non_mirror_slot(e['time_slot_id']):
                    wed_subject_list = wednesday_subject_per_section.setdefault(sec_key, [])
                    if current_subj_id not in wed_subject_list:
                        wed_subject_list.append(current_subj_id)
                    if len(wed_subject_list) > 2:
                        return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                    if current_subj_id in non_wednesday_subjects_per_section.get(sec_key, set()):
                        return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                    current_kind = str(e.get('meeting_kind') or 'lecture').strip().lower()
                    used_kinds = non_mirror_subject_kinds_per_section.get(sec_key, {}).get(current_subj_id, set())
                    if self.subject_has_both_kinds(current_subj_id) and current_kind in used_kinds:
                        return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                elif current_subj_id in wednesday_subject_per_section.get(sec_key, []):
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False

            room_bookings.setdefault(int(e['room_id']), []).append(interval)
            inst_bookings.setdefault(int(e['instructor_id']), []).append(interval)
            section_bookings.setdefault(sec_key, []).append(interval)
            used_inst_hours[e['instructor_id']] = next_hours
            if pair_key is not None:
                used_pair_subject[pair_key] = e['subject_id']
            if pair_subject_slot_key is not None:
                paired_subject_instructor[pair_subject_slot_key] = e['instructor_id']
            if instructor_subject_pair_key is not None:
                instructor_subject_pair_time[instructor_subject_pair_key] = (
                    int(interval['start_minutes']),
                    int(interval['end_minutes'])
                )
            if self.four_day_pattern:
                if self.is_non_mirror_slot(e['time_slot_id']):
                    used_wed_sections.add(sec_key)
                    non_mirror_subject_kinds_per_section.setdefault(sec_key, {}).setdefault(int(e['subject_id']), set()).add(
                        e.get('_meeting_kind_norm') or str(e.get('meeting_kind') or 'lecture').strip().lower()
                    )
                else:
                    non_wednesday_subjects_per_section.setdefault(sec_key, set()).add(int(e['subject_id']))
            if align_key is not None:
                pair_alignment_counter[align_key] = pair_alignment_counter.get(align_key, 0) + 1
            if pair_subject_slot_key is not None:
                instr_key = pair_subject_slot_key + (interval['day'], int(e['instructor_id']))
                pair_instructor_counter[instr_key] = pair_instructor_counter.get(instr_key, 0) + 1
            entry_by_identity[self.get_gene_identity(e)] = e
            passed_entries += 1

        for lecture_identity in self.sequence_anchor_identities:
            lecture_entry = entry_by_identity.get(lecture_identity)
            lab_gene = self.sequence_partner_by_identity.get(lecture_identity)
            lab_entry = entry_by_identity.get(self.get_gene_identity(lab_gene)) if lab_gene is not None else None
            if not self.validate_sequence_pair(lecture_entry, lab_entry):
                return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
            passed_sequence += 1

        # Strict paired-day alignment using the selected mirror pairs.
        # Room identity is intentionally not mirrored; day/time/instructor still are.
        if self.four_day_pattern:
            aggregate = {}
            for key, count in pair_alignment_counter.items():
                dep, year, sec, subj, pair_group, start, end, day = key
                base = (dep, year, sec, subj, pair_group, start, end)
                if base not in aggregate:
                    aggregate[base] = {}
                aggregate[base][day] = aggregate[base].get(day, 0) + count

            total_pair_checks += len(aggregate)
            for base, day_counts in aggregate.items():
                pair_group = base[4]
                pair_days = self.pair_group_days.get(pair_group)
                if not pair_days:
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                anchor_day, mirror_day = pair_days
                if day_counts.get(anchor_day, 0) != day_counts.get(mirror_day, 0):
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                passed_pair_checks += 1

            instructor_aggregate = {}
            for key, count in pair_instructor_counter.items():
                dep, year, sec, subj, pair_group, start, end, day, instructor_id = key
                base = (dep, year, sec, subj, pair_group, start, end, instructor_id)
                if base not in instructor_aggregate:
                    instructor_aggregate[base] = {}
                instructor_aggregate[base][day] = instructor_aggregate[base].get(day, 0) + count

            total_pair_checks += len(instructor_aggregate)
            for base, day_counts in instructor_aggregate.items():
                pair_group = base[4]
                pair_days = self.pair_group_days.get(pair_group)
                if not pair_days:
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                anchor_day, mirror_day = pair_days
                if day_counts.get(anchor_day, 0) != day_counts.get(mirror_day, 0):
                    return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                passed_pair_checks += 1

            # Option 1: each section must have at least one non-mirror-day class.
            if non_mirror_slots_exist and required_sections:
                total_pair_checks += len(required_sections)
                for sec in required_sections:
                    if len(wednesday_subject_per_section.get(sec, [])) == 0:
                        return self.get_partial_fitness_score(passed_entries, passed_sequence, passed_pair_checks, total_pair_checks), False
                    passed_pair_checks += 1

        return 100, True

    def calculate_fitness(self, individual):
        score, _is_valid = self.evaluate_individual(individual)
        return score

    # ================= SELECTION =================
    def selection(self, population, fitness):
        selected = []
        
        # Sort indices by fitness (descending)
        sorted_indices = sorted(range(len(fitness)), key=lambda i: fitness[i], reverse=True)
        elite_idx = sorted_indices[:self.elite_size]
        
        for idx in elite_idx:
            selected.append(population[idx])

        while len(selected) < self.population_size:
            a, b = random.sample(range(len(population)), 2)
            selected.append(population[a] if fitness[a] > fitness[b] else population[b])

        return selected

    # ================= CROSSOVER =================
    def crossover(self, p1, p2):
        if random.random() > self.crossover_rate:
            return [dict(g) for g in p1], [dict(g) for g in p2]

        # Guard against tiny or incomplete individuals. In these cases there is
        # no valid interior crossover point, so keep the parents unchanged.
        if len(p1) <= 1 or len(p2) <= 1:
            return [dict(g) for g in p1], [dict(g) for g in p2]

        point = random.randint(1, len(p1) - 1)
        return (
            self.rebuild_child_from_parents(p1, p2, point),
            self.rebuild_child_from_parents(p2, p1, point)
        )

    # ================= MUTATION WITH REPAIR =================
    def mutation(self, individual):
        mutated = [dict(g) for g in individual]
        mutation_attempts = 60 if self.four_day_pattern else 30

        for i in range(len(mutated)):
            if random.random() < self.mutation_rate:
                candidate_slot_ids = self.get_ordered_slot_ids_for_gene(mutated[i])
                if not candidate_slot_ids:
                    continue
                for new_time in candidate_slot_ids[:mutation_attempts]:
                    candidate_rooms = self.get_candidate_room_ids(mutated[i], new_time, {'used_room_time': set(), 'used_inst_time': set(), 'used_section_time': set(), 'used_inst_hours': {}, 'used_pair_subject': {}, 'paired_subject_instructor': {}, 'paired_subject_room': {}, 'instructor_subject_pair_time': {}, 'wednesday_subject_per_section': {}, 'non_wednesday_subjects_per_section': {}, 'non_mirror_subject_kinds_per_section': {}, 'used_wed_sections': set()}, excluded_room_id=None)
                    if not candidate_rooms:
                        continue
                    new_room = random.choice(candidate_rooms)

                    conflict = False
                    if self.is_disallowed_slot(new_time):
                        conflict = True

                    candidate = dict(mutated[i])
                    candidate['time_slot_id'] = new_time
                    candidate_pair_key = self.get_pair_bucket_key(candidate)
                    
                    for j, other in enumerate(mutated):
                        if i == j:
                            continue
                        if other['time_slot_id'] == new_time:
                            if other['room_id'] == new_room:
                                conflict = True
                            if other['instructor_id'] == mutated[i]['instructor_id']:
                                conflict = True
                            if (
                                other.get('department') == mutated[i].get('department')
                                and other.get('year_level') == mutated[i].get('year_level')
                                and other.get('section') == mutated[i].get('section')
                            ):
                                conflict = True
                        
                        if not conflict and candidate_pair_key is not None and self.get_pair_bucket_key(other) == candidate_pair_key and int(other.get('subject_id') or 0) != int(mutated[i].get('subject_id') or 0):
                            conflict = True

                        if not conflict:
                            candidate_pair_subject_slot_key = self.get_pair_subject_slot_key({
                                'department': mutated[i].get('department'),
                                'year_level': mutated[i].get('year_level'),
                                'section': mutated[i].get('section'),
                                'subject_id': mutated[i].get('subject_id'),
                                'time_slot_id': new_time
                            })
                            if candidate_pair_subject_slot_key is not None and self.get_pair_subject_slot_key(other) == candidate_pair_subject_slot_key:
                                if int(mutated[i].get('instructor_id') or 0) != int(other.get('instructor_id') or 0):
                                    conflict = True

                        if not conflict and self.four_day_pattern:
                            sec_key_i = self.get_section_key(mutated[i])
                            sec_key_other = self.get_section_key(other)
                            if sec_key_i == sec_key_other:
                                new_is_wed = self.is_wednesday_slot(new_time)
                                other_is_wed = self.is_wednesday_slot(other.get('time_slot_id'))
                                if new_is_wed and other_is_wed and mutated[i].get('subject_id') != other.get('subject_id'):
                                    conflict = True
                                if (
                                    new_is_wed and other_is_wed
                                    and int(mutated[i].get('subject_id') or 0) == int(other.get('subject_id') or 0)
                                    and self.subject_has_both_kinds(int(mutated[i].get('subject_id') or 0))
                                    and str(mutated[i].get('meeting_kind') or 'lecture').strip().lower() == str(other.get('meeting_kind') or 'lecture').strip().lower()
                                ):
                                    conflict = True
                                if new_is_wed != other_is_wed and int(mutated[i].get('subject_id') or 0) == int(other.get('subject_id') or 0):
                                    conflict = True
                        if conflict:
                            break

                    if (new_room, new_time) in self.blocked_room_time:
                        conflict = True
                    if (mutated[i]['instructor_id'], new_time) in self.blocked_inst_time:
                        conflict = True

                    if not conflict:
                        mutated[i]['time_slot_id'] = new_time
                        mutated[i]['room_id'] = new_room
                        break

        rebuilt_state = self.build_state_from_individual(mutated)
        self.repair_missing_genes(rebuilt_state, max_passes=6 if not self.four_day_pattern else 4)
        return rebuilt_state['individual']

    def can_teach_subject(self, instructor_id, subject_code):
        if self.job_has_explicit_instructor_subjects and instructor_id not in self.job_instructor_subject_codes:
            return False
        if instructor_id in self.job_instructor_subject_codes:
            selected_codes = self.job_instructor_subject_codes[instructor_id]
            return (subject_code or "").strip().upper() in selected_codes

        allowed_codes = self.instructor_subject_codes.get(instructor_id)
        # Backward compatibility: if no configured subject preferences, allow all subjects.
        if not allowed_codes:
            return True
        return (subject_code or "").strip().upper() in allowed_codes

    def disable_paired_day_mode(self):
        self.four_day_pattern = False
        self.mirror_pairs = []
        self.day_pair_lookup = {}
        self.non_mirror_days = []
        self.required_wed_sections = set()
        self.paired_slot_map = {}
        self.pair_anchor_slot_ids = []
        self.invalidate_fitness_cache()
        self.configure_ga_parameters()

    def relax_instructor_availability(self):
        """Fallback mode: allow selected instructors to use all selected time slots."""
        self.respect_availability = False
        all_slot_ids = [int(ts['id']) for ts in self.time_slots]
        for inst in self.instructors:
            iid = int(inst['id'])
            self.availability[iid] = list(all_slot_ids)
            self.availability_windows[iid] = self._build_windows_from_slot_ids(self.availability[iid])
        self.invalidate_fitness_cache()

    def relax_mirror_constraints(self):
        """
        Fallback mode: allow a valid schedule even if strict mirror/paired-day
        constraints are infeasible. This can significantly improve convergence.
        """
        if not self.four_day_pattern:
            return
        self.relaxed_mirror_mode = True
        self.four_day_pattern = False
        self.mirror_pairs = []
        self.day_pair_lookup = {}
        self.paired_slot_map = {}
        self.pair_anchor_slot_ids = []
        self.anchor_slot_ids_sorted = []
        self.required_wed_sections = set()
        # Rebuild caches/indexes so slot filtering and ordering match the relaxed mode.
        self.build_search_indexes()

    def evolve_population(self, population):
        best = None
        best_fit = 0
        stagnation = 0
        stagnation_limit = 120 if self.four_day_pattern else 80
        # Fail fast on dead search spaces only when we can safely fall back to
        # a relaxed mode. For strict paired-day runs, allow much deeper search
        # before declaring the population dead.
        if self.four_day_pattern and self.allow_non_mirror_fallback and not self.relaxed_mirror_mode:
            # We can relax mirror constraints later, so keep this conservative.
            zero_fitness_limit = max(25, int(self.generations * 0.2))
        elif self.four_day_pattern and self.preferred_day_mode == 'strict':
            # Strict mirror mode has a smaller feasible space; avoid stopping at
            # a fixed low threshold (for example 50) when generations are higher.
            zero_fitness_limit = max(120, int(self.generations * 0.75))
        else:
            zero_fitness_limit = max(45, int(self.generations * 0.4))
        zero_fitness_limit = min(self.generations, max(1, zero_fitness_limit))

        for gen in range(self.generations):
            if self.max_runtime_seconds > 0 and self.run_started_at is not None and (time.monotonic() - self.run_started_at) >= self.max_runtime_seconds:
                self.stop_reason = f"timeout after {self.max_runtime_seconds} seconds"
                print(f"Early stop: {self.stop_reason}")
                break

            evaluations = [self.evaluate_individual(ind) for ind in population]
            fitness = [score for score, _is_valid in evaluations]
            valid_count = sum(1 for _score, is_valid in evaluations if is_valid)
            gen_best = max(fitness)
            progress_percent = min(99, max(21, 20 + int(((gen + 1) / max(1, self.generations)) * 79)))
            # Batch progress updates to reduce DB overhead while keeping UI responsive.
            should_report_progress = (
                gen == 0
                or (gen + 1) == self.generations
                or ((gen + 1) % 5 == 0)
            )
            if should_report_progress:
                self.update_progress(progress_percent, generation=(gen + 1), total_generations=self.generations, best_fit=gen_best)

            if gen_best > best_fit:
                best_fit = gen_best
                best = population[fitness.index(gen_best)]
                stagnation = 0
            else:
                stagnation += 1

            print(f"Generation {gen} | Best Fitness: {gen_best} | Valid: {valid_count}")

            if best_fit == 100:
                break

            # Partial fitness now distinguishes promising candidates from truly
            # dead search spaces. Only stop early when we still have no valid
            # schedules and the best candidate never clears the viability floor.
            if valid_count == 0 and best_fit < self.partial_fitness_viable_threshold and (gen + 1) >= zero_fitness_limit:
                self.stop_reason = (
                    f"no valid candidate found after {zero_fitness_limit} generations"
                )
                print(f"Early stop: {self.stop_reason}")
                break

            if stagnation >= stagnation_limit:
                print(f"Early stop: no fitness improvement in {stagnation_limit} generations")
                break

            selected = self.selection(population, fitness)
            # Keep elite schedules intact so the search does not discard the
            # best valid structures it already discovered.
            next_pop = [[dict(entry) for entry in individual] for individual in selected[:self.elite_size]]

            for i in range(self.elite_size, self.population_size - 1, 2):
                c1, c2 = self.crossover(selected[i], selected[i + 1])
                next_pop.append(self.mutation(c1))
                next_pop.append(self.mutation(c2))

            if len(next_pop) < self.population_size:
                tail_idx = min(len(selected) - 1, self.population_size - 1)
                next_pop.append(self.mutation(selected[tail_idx]))

            population = next_pop[:self.population_size]

        return best, best_fit

    def _search_best(self):
        self.precheck_feasibility()
        population = self.initialize_population()
        best, best_fit = self.evolve_population(population)

        # If mirror mode produces a dead search space (zero feasible candidates),
        # don't burn multiple restarts before we switch to relaxed mirror mode.
        if (
            self.four_day_pattern
            and self.allow_non_mirror_fallback
            and not self.relaxed_mirror_mode
            and self.stop_reason is not None
            and "no valid candidate found" in str(self.stop_reason).lower()
        ):
            return best, best_fit, False

        # Additional randomized restarts improve success without keeping a dead run alive too long.
        if not best or best_fit < 100:
            restart_attempts = 4 if (self.four_day_pattern and self.preferred_day_mode == 'strict') else 2
            base_mutation = self.mutation_rate
            for restart_idx in range(restart_attempts):
                print(f"Retrying schedule search with randomized restart #{restart_idx + 1}.")
                # Nudge mutation upward per restart in strict mode to escape
                # dead neighborhoods while keeping the first restart unchanged.
                if self.four_day_pattern and self.preferred_day_mode == 'strict':
                    self.mutation_rate = min(0.5, base_mutation + (0.04 * restart_idx))
                self.run_started_at = time.monotonic()
                self.stop_reason = None
                self.update_progress(5, generation=0, total_generations=self.generations, best_fit=best_fit)
                retry_population = self.initialize_population()
                retry_best, retry_best_fit = self.evolve_population(retry_population)
                if retry_best and retry_best_fit >= best_fit:
                    best, best_fit = retry_best, retry_best_fit
                if best_fit >= 100:
                    break
            self.mutation_rate = base_mutation

        used_relaxed_availability = False
        if (not best or best_fit < 100) and self.respect_availability:
            print("Retrying schedule search with instructor availability relaxed.")
            self.relax_instructor_availability()
            used_relaxed_availability = True
            for restart_idx in range(2):
                self.run_started_at = time.monotonic()
                self.stop_reason = None
                self.update_progress(5, generation=0, total_generations=self.generations, best_fit=best_fit)
                retry_population = self.initialize_population()
                retry_best, retry_best_fit = self.evolve_population(retry_population)
                if retry_best and retry_best_fit >= best_fit:
                    best, best_fit = retry_best, retry_best_fit
                if best_fit >= 100:
                    break

        if best and best_fit < 100:
            repaired_state = self.build_state_from_individual(best)
            self.repair_missing_genes(repaired_state, max_passes=12 if not self.four_day_pattern else 8)
            repaired_best = repaired_state.get('individual', [])
            repaired_best_fit = self.calculate_fitness(repaired_best)
            if repaired_best_fit > best_fit:
                best, best_fit = repaired_best, repaired_best_fit

        return best, best_fit, used_relaxed_availability

    # ================= RUN GA =================
    def run(self):
        try:
            self.run_started_at = time.monotonic()
            self.stop_reason = None
            self.update_job_status('processing')
            self.update_progress(1, generation=0, total_generations=self.generations, best_fit=0)

            best, best_fit, used_relaxed_availability = self._search_best()

            # If strict mirror constraints are enabled and the search space is too
            # restrictive, allow a controlled fallback that generates a valid
            # non-mirrored schedule.
            if (
                (not best or best_fit < 100)
                and self.allow_non_mirror_fallback
                and not self.relaxed_mirror_mode
                and self.four_day_pattern
            ):
                print("Falling back to relaxed mirror mode (non-mirror schedule allowed).")
                self.relax_mirror_constraints()
                self.run_started_at = time.monotonic()
                self.stop_reason = None
                self.update_progress(5, generation=0, total_generations=self.generations, best_fit=best_fit)
                best, best_fit, used_relaxed_availability = self._search_best()

            if not best or best_fit < 100:
                fail_msg = "No valid schedule found. Check instructor availability, selected subjects, rooms, and constraints."
                if self.stop_reason:
                    fail_msg = f"Schedule search stopped early due to {self.stop_reason}. " + fail_msg
                if self.four_day_pattern:
                    fail_msg += " Paired-day mode is strict (Mon/Thu and Tue/Fri mirror). Try adding more instructors/slots or disabling paired-day mode."
                if used_relaxed_availability:
                    fail_msg += " Automatic fallback already retried with relaxed instructor availability."
                if self.relaxed_mirror_mode:
                    fail_msg += " Relaxed mirror fallback was attempted (non-mirror schedule allowed) but still no valid schedule was found."
                self.update_job_status('failed', fail_msg)
                raise Exception(fail_msg)

            self.save_schedule(best)
            self.update_job_status('completed')
            self.update_progress(100, generation=self.generations, total_generations=self.generations, best_fit=best_fit)
            return best
        except Exception as exc:
            try:
                self.update_job_status('failed', str(exc) or 'Schedule generation failed.')
            except Exception:
                pass
            raise
        finally:
            self._close_job_conn()

    # ================= SAVE SCHEDULE =================
    def save_schedule(self, schedule):
        if not schedule:
            print("No schedule to save!")
            return
            
        conn = mysql.connector.connect(**self.db_config)
        cursor = conn.cursor()

        cursor.execute("DELETE FROM schedules WHERE job_id = %s", (self.job_id,))

        if self.max_classes_per_day > 0:
            instructor_day_groups = {}
            for idx, e in enumerate(schedule):
                slot = self.get_slot(e['time_slot_id'])
                if not slot:
                    continue
                day = str(slot.get('day') or '').strip().lower()
                if day == '':
                    continue
                iid = int(e['instructor_id'])
                instructor_day_groups.setdefault((iid, day), []).append((idx, self.time_to_minutes(slot.get('start_time'))))

            for (_, _), entries in instructor_day_groups.items():
                entries.sort(key=lambda item: (item[1], item[0]))
                for position, (idx, _) in enumerate(entries):
                    schedule[idx]['is_overload'] = 1 if position >= self.max_classes_per_day else 0

        for e in schedule:
            if 'is_overload' not in e:
                e['is_overload'] = 0

        insert_rows = []
        for e in schedule:
            meeting_hours = e.get('meeting_hours')
            if meeting_hours is None:
                meeting_hours = self.get_subject_hours(e['subject_id'])
            meeting_minutes = e.get('scheduled_minutes')
            if meeting_minutes is None:
                meeting_minutes = e.get('meeting_minutes')
            if meeting_minutes is None:
                meeting_minutes = self.get_gene_minutes(e)

            insert_rows.append((
                self.job_id,
                int(e['subject_id']),
                int(e['instructor_id']),
                int(e['room_id']),
                int(e['time_slot_id']),
                e.get('department'),
                e.get('year_level'),
                e.get('section'),
                float(meeting_hours),
                int(meeting_minutes),
                str(e.get('meeting_kind') or 'lecture'),
                int(e.get('is_overload') or 0),
            ))

        cursor.executemany(
            """
            INSERT INTO schedules
            (job_id, subject_id, instructor_id, room_id, time_slot_id,
             department, year_level, section, scheduled_hours, scheduled_minutes, meeting_kind, is_published, is_overload)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, 0, %s)
            """,
            insert_rows,
        )

        conn.commit()
        cursor.close()
        conn.close()
        print(f"Saved {len(schedule)} schedule entries to database (is_published = 0)")

    # ================= JOB STATUS =================
    def _get_job_conn(self):
        if self._job_conn is not None:
            try:
                if self._job_conn.is_connected():
                    return self._job_conn
            except Exception:
                pass
        self._job_conn = mysql.connector.connect(**self.db_config)
        return self._job_conn

    def _close_job_conn(self):
        if self._job_conn is None:
            return
        try:
            self._job_conn.close()
        except Exception:
            pass
        self._job_conn = None

    def update_job_status(self, status, error_message=None):
        conn = self._get_job_conn()
        cursor = conn.cursor()

        if status == 'completed':
            cursor.execute("""
                UPDATE schedule_jobs
                SET status=%s, completed_at=%s, error_message=NULL, progress_percent=100,
                    current_generation = GREATEST(current_generation, total_generations)
                WHERE id=%s
            """, (status, datetime.now(), self.job_id))
        elif status == 'failed':
            cursor.execute("""
                UPDATE schedule_jobs
                SET status=%s, error_message=%s, progress_percent=0
                WHERE id=%s
            """, (status, (error_message or "Schedule generation failed."), self.job_id))
        else:
            cursor.execute("""
                UPDATE schedule_jobs
                SET status=%s, error_message=NULL, progress_percent=1,
                    current_generation=0, total_generations=0, best_fitness=0
                WHERE id=%s
            """, (status, self.job_id))

        conn.commit()
        cursor.close()

    def update_progress(self, percent, generation=None, total_generations=None, best_fit=None):
        percent = max(0, min(100, int(percent)))
        generation = None if generation is None else max(0, int(generation))
        total_generations = None if total_generations is None else max(0, int(total_generations))
        best_fit = None if best_fit is None else max(0, min(100, int(best_fit)))

        if (
            percent == self.last_progress_percent
            and (generation is None or generation == self.last_reported_generation)
            and (best_fit is None or best_fit == self.last_reported_best_fit)
        ):
            return

        now_ts = time.monotonic()
        # Throttle progress writes to the DB (except final completion-like updates).
        if percent < 100 and (now_ts - self.last_progress_write_at) < PROGRESS_DB_WRITE_THROTTLE_SECONDS:
            return

        self.last_progress_percent = percent
        if generation is not None:
            self.last_reported_generation = generation
        if best_fit is not None:
            self.last_reported_best_fit = best_fit
        self.last_progress_write_at = now_ts

        conn = self._get_job_conn()
        cursor = conn.cursor()
        if generation is None and total_generations is None and best_fit is None:
            cursor.execute(
                "UPDATE schedule_jobs SET progress_percent=%s WHERE id=%s",
                (percent, self.job_id)
            )
        else:
            cursor.execute("""
                UPDATE schedule_jobs
                SET progress_percent=%s,
                    current_generation=COALESCE(%s, current_generation),
                    total_generations=COALESCE(%s, total_generations),
                    best_fitness=COALESCE(%s, best_fitness)
                WHERE id=%s
            """, (percent, generation, total_generations, best_fit, self.job_id))
        conn.commit()
        cursor.close()

# ================= MAIN =================
if __name__ == "__main__":
    job_id = None
    try:
        if len(sys.argv) < 2:
            print("Usage: python genetic_algorithm.py <job_id>")
            sys.exit(1)

        job_id = int(sys.argv[1])
        print(f"Starting GA for job {job_id}...")

        ga = ScheduleGA(job_id)
        result = ga.run()

        if result:
            print(f"Schedule generated successfully! {len(result)} classes scheduled.")
        else:
            print("No valid schedule could be generated. Try adjusting constraints.")

    except Exception as e:
        print(f"Error: {str(e)}")
        traceback.print_exc()

        # Best-effort: persist failure message to schedule_jobs for UI visibility.
        if job_id is not None:
            try:
                db_config = build_db_config()
                conn = mysql.connector.connect(**db_config)
                cursor = conn.cursor()
                cursor.execute("""
                    UPDATE schedule_jobs
                    SET status = 'failed', error_message = %s, progress_percent = 0
                    WHERE id = %s
                """, (str(e), job_id))
                conn.commit()
                cursor.close()
                conn.close()
            except Exception:
                pass

        sys.exit(1)
