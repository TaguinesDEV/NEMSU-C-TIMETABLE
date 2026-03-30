#!/usr/bin/env python3
import json
import sys
import random
import mysql.connector
from datetime import datetime
import traceback
from collections import Counter

# ================= GA DEFAULT PARAMETERS =================
DEFAULT_POPULATION_SIZE = 80
DEFAULT_GENERATIONS = 200
DEFAULT_MUTATION_RATE = 0.1
DEFAULT_CROSSOVER_RATE = 0.8
DEFAULT_ELITE_SIZE = 5

WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
SATURDAY = 'saturday'

class ScheduleGA:
    def __init__(self, job_id):
        self.job_id = job_id
        self.db_config = {
            'host': 'localhost',
            'user': 'root', 
            'password': '',
            'database': 'academic_scheduling'
        }
        self.load_data()
        self.genes = self.create_genes()
        self.required_wed_sections = self._compute_required_wed_sections()
        self.configure_ga_parameters()
        self.last_progress_percent = -1

    def load_data(self):
        print(f"Loading data for job {self.job_id}...")
        
        conn = mysql.connector.connect(**self.db_config)
        cursor = conn.cursor(dictionary=True)

        # Schema upgrades for progress tracking (non-blocking)
        schema_checks = [
            ('schedule_jobs', 'error_message', "ALTER TABLE schedule_jobs ADD COLUMN error_message TEXT NULL AFTER completed_at"),
            ('schedule_jobs', 'progress_percent', "ALTER TABLE schedule_jobs ADD COLUMN progress_percent INT NOT NULL DEFAULT 0 AFTER status"),
            ('schedule_jobs', 'current_generation', "ALTER TABLE schedule_jobs ADD COLUMN current_generation INT NOT NULL DEFAULT 0 AFTER progress_percent"), 
            ('schedule_jobs', 'total_generations', "ALTER TABLE schedule_jobs ADD COLUMN total_generations INT NOT NULL DEFAULT 0 AFTER current_generation"),
            ('schedule_jobs', 'best_fitness', "ALTER TABLE schedule_jobs ADD COLUMN best_fitness INT NOT NULL DEFAULT 0 AFTER total_generations"),
            ('schedules', 'scheduled_hours', "ALTER TABLE schedules ADD COLUMN scheduled_hours DECIMAL(5,2) NULL AFTER section"),
            ('schedules', 'meeting_kind', "ALTER TABLE schedules ADD COLUMN meeting_kind ENUM('lecture','lab') NULL AFTER scheduled_hours"),
            ('schedules', 'scheduled_minutes', "ALTER TABLE schedules ADD COLUMN scheduled_minutes INT NULL AFTER scheduled_hours")
        ]
        
        for table, col, sql in schema_checks:
            try:
                cursor.execute("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s", 
                    (self.db_config['database'], table, col))
                if cursor.fetchone()['cnt'] == 0:
                    cursor.execute(sql)
                    conn.commit()
            except:
                pass

        cursor.execute("SELECT input_data FROM schedule_jobs WHERE id = %s", (self.job_id,))
        result = cursor.fetchone()
        if not result:
            raise Exception(f"Job ID {self.job_id} not found!")
        
        self.input_data = json.loads(result['input_data'])
        self.respect_availability = bool(self.input_data.get('constraints', {}).get('respect_availability', True))
        self.four_day_pattern = bool(self.input_data.get('constraints', {}).get('four_day_pattern', False))
        self.mirror_pairs = []
        self.day_pair_lookup = {}
        self.non_mirror_mode = int(self.input_data.get('constraints', {}).get('non_mirror_mode', 1) or 0)
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

        self.instructor_subject_codes = {}
        instructor_ids = [inst['id'] for inst in self.input_data.get('instructors', [])]
        if instructor_ids:
            placeholders = ','.join(['%s'] * len(instructor_ids))
            cursor.execute(f"""
                SELECT sia.instructor_id, sub.subject_code
                FROM subject_instructor_assignments sia
                JOIN subjects sub ON sia.subject_id = sub.id
                WHERE sia.instructor_id IN ({placeholders})
                ORDER BY sia.assignment_slot, sub.subject_code
            """, tuple(instructor_ids))
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
            """, tuple(instructor_ids))
            for row in cursor.fetchall():
                inst_id = int(row['instructor_id'])
                code = (row['specialization_name'] or '').strip().upper()
                if not code:
                    continue
                self.instructor_subject_codes.setdefault(inst_id, set()).add(code)

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

        # Time slots
        self.time_slots = self.input_data.get('time_slots', [])
        if not self.time_slots:
            cursor.execute("SELECT * FROM time_slots ORDER BY day, start_time") 
            self.time_slots = cursor.fetchall()
            
        self.time_slots_by_id = {int(ts['id']): ts for ts in self.time_slots}
        self.open_windows_by_day = {}
        for ts in self.time_slots:
            day = str(ts.get('day', '')).strip().lower()
            slot_type = str(ts.get('slot_type') or 'regular').strip().lower()
            if slot_type == 'lunch':
                continue
            start_minutes = self.time_to_minutes(ts.get('start_time'))
            end_minutes = self.time_to_minutes(ts.get('end_time'))
            self.open_windows_by_day.setdefault(day, []).append((start_minutes, end_minutes))
        for day in list(self.open_windows_by_day.keys()):
            self.open_windows_by_day[day] = self._merge_windows(self.open_windows_by_day[day])
        
        # ✅ CRITICAL FIX: Initialize BEFORE use
        self.time_key_to_id = {}
        self.slot_hour_unit = self.detect_slot_hour_unit()
        self._build_slot_indexes()

        # Availability, instructors, rooms, subjects, blocked times (unchanged logic)
        default_slots = [ts['id'] for ts in self.time_slots if str(ts.get('day', '')).lower() != SATURDAY]
        self.availability = {}
        self.availability_windows = {}
        for inst in self.input_data['instructors']:
            iid = inst['id']
            if self.respect_availability:
                cursor.execute("SELECT ts.id FROM instructor_availability ia JOIN time_slots ts ON ia.time_slot_id = ts.id WHERE ia.instructor_id = %s AND ia.is_available = 1", (iid,))
                slots = [r['id'] for r in cursor.fetchall()]
                self.availability[iid] = slots or default_slots
            else:
                self.availability[iid] = [ts['id'] for ts in self.time_slots]
            self.availability_windows[iid] = self._build_windows_from_slot_ids(self.availability[iid])

        self.instructor_max_hours = {inst['id']: float(inst.get('max_hours_per_week', 20)) for inst in self.input_data['instructors']}
        self.instructors = self.input_data['instructors']
        self.rooms = self.input_data['rooms']
        self.subjects = self.input_data['subjects']
        self.subject_by_id = {s['id']: s for s in self.subjects}
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
                
            population = self.next_generation(population, fitness)
            
        if best_fit < 100:
            raise Exception("No valid schedule found after GA search")
            
        self.save_schedule(best)
        print("✅ Schedule saved successfully")
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
        for subj in self.subjects:
            subj_year_level = subj.get('year_level')
            lecture_minutes, lab_minutes, meetings_per_week = self.get_subject_meeting_plan(subj)
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
                # Mixed lecture/lab subjects should use their real weekly breakdown once each.
                # Using `meetings_per_week` for both lecture and lab duplicates the weekly load.
                if configured_lecture_hours > 0 and configured_lab_hours > 0:
                    mixed_meetings = [
                        ('lecture', int(round(configured_lecture_hours * 60.0))),
                        ('lab', int(round(configured_lab_hours * 60.0))),
                    ]
                    for meeting_kind, configured_minutes in mixed_meetings:
                        genes.append({
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
                    continue

                for meeting_kind, configured_minutes in (('lecture', lecture_minutes), ('lab', lab_minutes)):
                    if configured_minutes <= 0:
                        continue
                    for _ in range(meetings_per_week):
                        genes.append({
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
                            'subject_total_hours': round(((lecture_minutes + lab_minutes) * meetings_per_week) / 60.0, 2)
                        })
                        meeting_index += 1
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
        if gene_count <= 30:
            pop = 80
            gens = 200
        elif gene_count <= 80:
            pop = 120
            gens = 300
        else:
            pop = 160
            gens = 400

        mutation = DEFAULT_MUTATION_RATE
        crossover = DEFAULT_CROSSOVER_RATE
        elite = DEFAULT_ELITE_SIZE

        # Optional per-job overrides (backward compatible).
        ga_cfg = self.input_data.get('ga') or {}
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

        # Safety bounds
        self.population_size = max(20, min(400, pop))
        self.generations = max(50, min(1000, gens))
        self.mutation_rate = max(0.01, min(0.5, mutation))
        self.crossover_rate = max(0.1, min(1.0, crossover))
        self.elite_size = max(1, min(20, elite, self.population_size))

        # Paired-day mode is stricter; increase search effort automatically.
        if self.four_day_pattern:
            self.population_size = min(400, max(self.population_size, 180))
            self.generations = min(1000, max(self.generations, 500))
            self.mutation_rate = min(0.5, max(self.mutation_rate, 0.15))

        print(
            "GA params => "
            f"genes={gene_count}, population={self.population_size}, generations={self.generations}, "
            f"mutation={self.mutation_rate:.3f}, crossover={self.crossover_rate:.3f}, elite={self.elite_size}"
        )

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
            raise Exception("Time/load issue: mandatory instructor load exceeds weekly limit. " + "; ".join(violations))

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
            meetings_per_week = max(1, int(subject.get('meetings_per_week') or 2))
        except Exception:
            meetings_per_week = 2
        try:
            lecture_minutes_per_meeting = int(subject.get('lecture_minutes_per_meeting') or 0)
        except Exception:
            lecture_minutes_per_meeting = 0
        try:
            lab_minutes_per_meeting = int(subject.get('lab_minutes_per_meeting') or 0)
        except Exception:
            lab_minutes_per_meeting = 0
        if lecture_minutes_per_meeting > 0 or lab_minutes_per_meeting > 0:
            return round((lecture_minutes_per_meeting * meetings_per_week) / 60.0, 2), round((lab_minutes_per_meeting * meetings_per_week) / 60.0, 2)

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

        subject_type = str(subject.get('subject_type') or '').strip().lower()
        if subject_type == 'minor':
            return 1.5, 0.0
        return 2.5, 0.0

    def get_subject_meeting_plan(self, subject):
        try:
            meetings_per_week = max(1, int(subject.get('meetings_per_week') or 2))
        except Exception:
            meetings_per_week = 2
        try:
            lecture_minutes = int(subject.get('lecture_minutes_per_meeting') or 0)
        except Exception:
            lecture_minutes = 0
        try:
            lab_minutes = int(subject.get('lab_minutes_per_meeting') or 0)
        except Exception:
            lab_minutes = 0

        if lecture_minutes > 0 or lab_minutes > 0:
            return lecture_minutes, lab_minutes, meetings_per_week

        lecture_hours, lab_hours = self.get_subject_hour_breakdown(subject)
        return int(round((lecture_hours * 60.0) / max(1, meetings_per_week))), int(round((lab_hours * 60.0) / max(1, meetings_per_week))), meetings_per_week

    def get_gene_minutes(self, gene):
        try:
            minutes = int(gene.get('meeting_minutes') or 0)
        except Exception:
            minutes = 0
        if minutes > 0:
            return minutes
        return int(round(float(gene.get('meeting_hours') or self.get_subject_hours(gene['subject_id'])) * 60.0))

    def time_to_minutes(self, value):
        try:
            parts = str(value or '00:00:00').split(':')
            hours = int(parts[0])
            minutes = int(parts[1])
            return (hours * 60) + minutes
        except Exception:
            return 0

    def minutes_to_time(self, total_minutes):
        total_minutes = max(0, int(total_minutes))
        return f"{(total_minutes // 60) % 24:02d}:{total_minutes % 60:02d}:00"

    def get_entry_interval(self, entry_or_gene, time_slot_id=None):
        slot_id = time_slot_id if time_slot_id is not None else entry_or_gene.get('time_slot_id')
        slot = self.get_slot(slot_id)
        if not slot:
            return None
        day = str(slot.get('day') or '').strip().lower()
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
        subject = self.subject_by_id.get(subject_id) or {}
        lecture_hours, lab_hours = self.get_subject_hour_breakdown(subject)
        return lecture_hours > 0 and lab_hours > 0

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
        slot_type = str(ts.get('slot_type') or 'regular').strip().lower()
        if slot_type == 'lunch':
            return True
        start = str(ts.get('start_time') or '')
        end = str(ts.get('end_time') or '')
        if start == '11:30:00' and end == '13:00:00':
            return True
        day = str(ts.get('day') or '').strip().lower()
        if self.four_day_pattern and self.non_mirror_mode == 0 and day in self.non_mirror_days:
            return True
        return False

    def is_wednesday_slot(self, time_slot_id):
        ts = self.get_slot(time_slot_id)
        if not ts:
            return False
        return str(ts.get('day') or '').strip().lower() in self.non_mirror_days

    def get_pair_bucket_key(self, entry):
        if not self.four_day_pattern:
            return None
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

    def get_section_key(self, entry_or_gene):
        return (
            int(entry_or_gene.get('year_level')) if entry_or_gene.get('year_level') is not None else None,
            str(entry_or_gene.get('section') or '')
        )

    def get_gene_identity(self, entry_or_gene):
        return (
            int(entry_or_gene.get('subject_id') or 0),
            self.get_section_key(entry_or_gene),
            int(entry_or_gene.get('meeting_index') or 0),
            str(entry_or_gene.get('meeting_kind') or 'lecture').strip().lower(),
            int(entry_or_gene.get('meeting_minutes') or self.get_gene_minutes(entry_or_gene)),
        )

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

        if not self.can_teach_subject(instructor, gene['subject_code']):
            return False
        if self.is_disallowed_slot(time):
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
            existing_room = state['paired_subject_room'].get(pair_subject_slot_key)
            if existing_room is not None and int(existing_room) != int(room):
                return False
        
        if self.four_day_pattern:
            wed_subject = state['wednesday_subject_per_section'].get(sec_key)
            non_wed_subjects = state['non_wednesday_subjects_per_section'].get(sec_key, set())
            if self.is_wednesday_slot(time):
                if wed_subject is not None and int(wed_subject) != current_subj_id:
                    return False
                if current_subj_id in non_wed_subjects:
                    return False
                used_kinds = state['non_mirror_subject_kinds_per_section'].get(sec_key, {}).get(current_subj_id, set())
                current_kind = str(gene.get('meeting_kind') or 'lecture').strip().lower()
                if self.subject_has_both_kinds(current_subj_id) and current_kind in used_kinds:
                    return False
            elif wed_subject is not None and int(wed_subject) == current_subj_id:
                return False

        entry_hours = float(gene.get('meeting_hours') or self.get_subject_hours(gene['subject_id']))
        max_hours = self.instructor_max_hours.get(instructor, 20.0)
        if (state['used_inst_hours'].get(instructor, 0.0) + entry_hours) > max_hours:
            return False

        return True

    def _commit_entry(self, gene, instructor, room, time, state):
        entry = {
            **gene,
            'instructor_id': instructor,
            'room_id': room,
            'time_slot_id': time,
            'scheduled_minutes': self.get_gene_minutes(gene)
        }
        entry.update(self.get_entry_interval(entry) or {})
        state['individual'].append(entry)
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
        pair_key = self.get_pair_bucket_key({
            'department': gene['department'],
            'year_level': gene['year_level'],
            'section': gene['section'],
            'time_slot_id': time
        })
        if pair_key is not None:
            state['used_pair_subject'][pair_key] = gene['subject_id']
        pair_subject_slot_key = self.get_pair_subject_slot_key(entry)
        if pair_subject_slot_key is not None:
            state['paired_subject_instructor'][pair_subject_slot_key] = instructor
            state['paired_subject_room'][pair_subject_slot_key] = room

        if self.four_day_pattern:
            sec_key = self.get_section_key(gene)
            if self.is_wednesday_slot(time):
                state['wednesday_subject_per_section'][sec_key] = gene['subject_id']
                state['used_wed_sections'].add(sec_key)
                state['non_mirror_subject_kinds_per_section'].setdefault(sec_key, {}).setdefault(int(gene['subject_id']), set()).add(
                    str(gene.get('meeting_kind') or 'lecture').strip().lower()
                )
            else:
                state['non_wednesday_subjects_per_section'].setdefault(sec_key, set()).add(int(gene['subject_id']))
        return entry

    def _rollback_entry(self, entry, state):
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

        pair_key = self.get_pair_bucket_key({
            'department': gene['department'],
            'year_level': gene['year_level'],
            'section': gene['section'],
            'time_slot_id': time
        })
        if pair_key is not None:
            # Rebuild pair map for correctness (small and safe for rollback path).
            state['used_pair_subject'].clear()
            state['paired_subject_instructor'].clear()
            state['paired_subject_room'].clear()
            for e in state['individual']:
                pk = self.get_pair_bucket_key({
                    'department': e['department'],
                    'year_level': e['year_level'],
                    'section': e['section'],
                    'time_slot_id': e['time_slot_id']
                })
                if pk is not None:
                    state['used_pair_subject'][pk] = e['subject_id']
                psk = self.get_pair_subject_slot_key(e)
                if psk is not None:
                    state['paired_subject_instructor'][psk] = e['instructor_id']
                    state['paired_subject_room'][psk] = e['room_id']

        if self.four_day_pattern:
            state['wednesday_subject_per_section'].clear()
            state['non_wednesday_subjects_per_section'].clear()
            state['non_mirror_subject_kinds_per_section'].clear()
            state['used_wed_sections'].clear()
            for e in state['individual']:
                sec_key = self.get_section_key(e)
                if self.is_wednesday_slot(e['time_slot_id']):
                    state['wednesday_subject_per_section'][sec_key] = e['subject_id']
                    state['used_wed_sections'].add(sec_key)
                    state['non_mirror_subject_kinds_per_section'].setdefault(sec_key, {}).setdefault(int(e['subject_id']), set()).add(
                        str(e.get('meeting_kind') or 'lecture').strip().lower()
                    )
                else:
                    state['non_wednesday_subjects_per_section'].setdefault(sec_key, set()).add(int(e['subject_id']))

    def _try_place_gene_at_time(self, gene, time, state, attempts=24):
        candidate_instructors = [inst['id'] for inst in self.instructors]
        random.shuffle(candidate_instructors)
        candidate_rooms = self.get_candidate_room_ids(gene, time, state)
        if not candidate_rooms:
            return None

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

    def build_state_from_individual(self, individual):
        state = {
            'individual': [],
            'room_bookings': {},
            'inst_bookings': {},
            'section_bookings': {},
            'used_inst_hours': {},
            'used_pair_subject': {},
            'paired_subject_instructor': {},
            'paired_subject_room': {},
            'wednesday_subject_per_section': {},
            'non_wednesday_subjects_per_section': {},
            'non_mirror_subject_kinds_per_section': {},
            'used_wed_sections': set()
        }

        ordered_entries = sorted(
            [dict(entry) for entry in individual],
            key=lambda entry: (
                -int(entry.get('meeting_minutes') or self.get_gene_minutes(entry)),
                str(entry.get('section') or ''),
                str(entry.get('subject_code') or ''),
                int(entry.get('meeting_index') or 0),
            )
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
            for gene in missing_genes:
                candidate_slots = [int(ts['id']) for ts in self.time_slots]
                random.shuffle(candidate_slots)
                placed_entry = None
                for time_slot_id in candidate_slots:
                    placed_entry = self._try_place_gene_at_time(gene, time_slot_id, state, attempts=32)
                    if placed_entry is not None:
                        placed_counts[self.get_gene_identity(placed_entry)] += 1
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
            'used_inst_hours': {},
            'used_pair_subject': {},
            'paired_subject_instructor': {},
            'paired_subject_room': {},
            'wednesday_subject_per_section': {},
            'non_wednesday_subjects_per_section': {},
            'non_mirror_subject_kinds_per_section': {},
            'used_wed_sections': set()
        }

        if not self.four_day_pattern:
            ordered_genes = sorted(
                self.genes,
                key=lambda gene: (
                    -int(gene.get('meeting_minutes') or self.get_gene_minutes(gene)),
                    str(gene.get('section') or ''),
                    str(gene.get('subject_code') or ''),
                    int(gene.get('meeting_index') or 0),
                )
            )
            for gene in ordered_genes:
                slot_ids = [int(ts['id']) for ts in self.time_slots]
                if random.random() < 0.35:
                    random.shuffle(slot_ids)
                placed = None
                for time in slot_ids:
                    placed = self._try_place_gene_at_time(gene, time, state, attempts=64)
                    if placed is not None:
                        break
            self.repair_missing_genes(state, max_passes=8)
            return state['individual']

        # Pair-aware initialization:
        # Build subject+section groups and place paired meetings (Mon<->Thu or Tue<->Fri) together.
        grouped = {}
        for gene in self.genes:
            gkey = (self.get_section_key(gene), int(gene['subject_id']))
            if gkey not in grouped:
                grouped[gkey] = []
            grouped[gkey].append(gene)

        group_keys = list(grouped.keys())
        random.shuffle(group_keys)

        anchor_slots = list(self.pair_anchor_slot_ids)
        non_mirror_slots = []
        for day in self.non_mirror_days:
            non_mirror_slots.extend(self.day_slot_ids.get(day, []))

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

                placed = False
                for gene in candidate_genes:
                    for t in random.sample(non_mirror_slots, len(non_mirror_slots)):
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
            pairable_count = (len(genes) // 2) * 2

            # Place paired genes first.
            idx = 0
            while idx < pairable_count:
                g1 = genes[idx]
                g2 = genes[idx + 1]
                placed_pair = False

                for _ in range(80):
                    if not anchor_slots:
                        break
                    t1 = random.choice(anchor_slots)
                    t2 = self.paired_slot_map.get(t1)
                    if not t2:
                        continue

                    e1 = self._try_place_gene_at_time(g1, t1, state, attempts=16)
                    if e1 is None:
                        continue
                    e2 = self._try_place_gene_at_time(g2, t2, state, attempts=16)
                    if e2 is None:
                        self._rollback_entry(e1, state)
                        continue

                    placed_pair = True
                    break

                if not placed_pair:
                    # Fallback: random placement (may be repaired later by GA/mutation).
                    fallback_slots = [int(ts['id']) for ts in self.time_slots]
                    random.shuffle(fallback_slots)
                    for g in (g1, g2):
                        placed = None
                        for t in fallback_slots:
                            placed = self._try_place_gene_at_time(g, t, state, attempts=8)
                            if placed is not None:
                                break
                        if placed is None:
                            break

                idx += 2

            # If odd count, place one extra (prefer non-mirror day to avoid pair imbalance).
            if len(genes) % 2 == 1:
                extra = genes[-1]
                placed_extra = None
                candidate_slots = list(non_mirror_slots) if non_mirror_slots else []
                if not candidate_slots:
                    candidate_slots = [int(ts['id']) for ts in self.time_slots]
                random.shuffle(candidate_slots)
                for t in candidate_slots:
                    placed_extra = self._try_place_gene_at_time(extra, t, state, attempts=12)
                    if placed_extra is not None:
                        break

        self.repair_missing_genes(state, max_passes=4)
        return state['individual']

    # ================= POPULATION =================
    def initialize_population(self):
        population = []
        for idx in range(self.population_size):
            population.append(self.create_individual())
            # Show visible progress during heavy initialization.
            init_percent = 1 + int(((idx + 1) / max(1, self.population_size)) * 19)  # 1..20
            self.update_progress(init_percent)
        return population

    # ================= FITNESS (HARD CONSTRAINT) =================
    def calculate_fitness(self, individual):
        if len(individual) != len(self.genes):
            return 0

        room_bookings = {}
        inst_bookings = {}
        section_bookings = {}
        used_inst_hours = {}
        used_pair_subject = {}
        paired_subject_instructor = {}
        paired_subject_room = {}
        wednesday_subject_per_section = {}
        non_wednesday_subjects_per_section = {}
        non_mirror_subject_kinds_per_section = {}
        used_wed_sections = set()
        pair_alignment_counter = {}
        pair_instructor_counter = {}
        pair_room_counter = {}
        required_sections = set(self.required_wed_sections)
        non_mirror_slots_exist = any(len(self.day_slot_ids.get(day, [])) > 0 for day in self.non_mirror_days)

        for e in individual:
            interval = self.get_entry_interval(e)
            if not interval:
                return 0
            pair_key = self.get_pair_bucket_key(e)
            align_key = self.get_pair_alignment_key(e)
            pair_subject_slot_key = self.get_pair_subject_slot_key(e)

            if self.is_disallowed_slot(e['time_slot_id']):
                return 0
            if not self.is_interval_within_windows(interval['day'], interval['start_minutes'], interval['end_minutes'], self.open_windows_by_day):
                return 0
            if self.has_interval_conflict(room_bookings.get(int(e['room_id']), []), interval):
                return 0
            if self.has_interval_conflict(inst_bookings.get(int(e['instructor_id']), []), interval):
                return 0
            if self.has_interval_conflict(section_bookings.get(self.get_section_key(e), []), interval):
                return 0
            if self.has_interval_conflict(self.blocked_room_intervals.get(int(e['room_id']), []), interval):
                return 0
            if self.has_interval_conflict(self.blocked_inst_intervals.get(int(e['instructor_id']), []), interval):
                return 0
            if not self.is_interval_within_windows(interval['day'], interval['start_minutes'], interval['end_minutes'], self.availability_windows.get(int(e['instructor_id']), {})):
                return 0
            if not self.can_teach_subject(e['instructor_id'], e['subject_code']):
                return 0

            entry_hours = float(e.get('meeting_hours') or self.get_subject_hours(e['subject_id']))
            next_hours = used_inst_hours.get(e['instructor_id'], 0.0) + entry_hours
            if next_hours > self.instructor_max_hours.get(e['instructor_id'], 20.0):
                return 0
            if pair_key is not None:
                existing_subject = used_pair_subject.get(pair_key)
                if existing_subject is not None and int(existing_subject) != int(e['subject_id']):
                    return 0
            if pair_subject_slot_key is not None:
                existing_instructor = paired_subject_instructor.get(pair_subject_slot_key)
                if existing_instructor is not None and int(existing_instructor) != int(e['instructor_id']):
                    return 0
                existing_room = paired_subject_room.get(pair_subject_slot_key)
                if existing_room is not None and int(existing_room) != int(e['room_id']):
                    return 0
            
            if self.four_day_pattern:
                sec_key = self.get_section_key(e)
                current_subj_id = int(e['subject_id'])
                if self.is_wednesday_slot(e['time_slot_id']):
                    if sec_key not in wednesday_subject_per_section:
                        wednesday_subject_per_section[sec_key] = current_subj_id
                    elif int(wednesday_subject_per_section[sec_key]) != current_subj_id:
                        return 0
                    if current_subj_id in non_wednesday_subjects_per_section.get(sec_key, set()):
                        return 0
                    current_kind = str(e.get('meeting_kind') or 'lecture').strip().lower()
                    used_kinds = non_mirror_subject_kinds_per_section.get(sec_key, {}).get(current_subj_id, set())
                    if self.subject_has_both_kinds(current_subj_id) and current_kind in used_kinds:
                        return 0
                elif int(wednesday_subject_per_section.get(sec_key, -1)) == current_subj_id:
                    return 0

            room_bookings.setdefault(int(e['room_id']), []).append(interval)
            inst_bookings.setdefault(int(e['instructor_id']), []).append(interval)
            section_bookings.setdefault(self.get_section_key(e), []).append(interval)
            used_inst_hours[e['instructor_id']] = next_hours
            if pair_key is not None:
                used_pair_subject[pair_key] = e['subject_id']
            if pair_subject_slot_key is not None:
                paired_subject_instructor[pair_subject_slot_key] = e['instructor_id']
                paired_subject_room[pair_subject_slot_key] = e['room_id']
            if self.four_day_pattern:
                sec_key = self.get_section_key(e)
                if self.is_wednesday_slot(e['time_slot_id']):
                    used_wed_sections.add(sec_key)
                    non_mirror_subject_kinds_per_section.setdefault(sec_key, {}).setdefault(int(e['subject_id']), set()).add(
                        str(e.get('meeting_kind') or 'lecture').strip().lower()
                    )
                else:
                    non_wednesday_subjects_per_section.setdefault(sec_key, set()).add(int(e['subject_id']))
            if align_key is not None:
                pair_alignment_counter[align_key] = pair_alignment_counter.get(align_key, 0) + 1
            if pair_subject_slot_key is not None:
                instr_key = pair_subject_slot_key + (str(self.get_slot(e['time_slot_id']).get('day') or '').strip().lower(), int(e['instructor_id']))
                pair_instructor_counter[instr_key] = pair_instructor_counter.get(instr_key, 0) + 1
                room_key = pair_subject_slot_key + (str(self.get_slot(e['time_slot_id']).get('day') or '').strip().lower(), int(e['room_id']))
                pair_room_counter[room_key] = pair_room_counter.get(room_key, 0) + 1

        # Strict paired-day alignment using the selected mirror pairs.
        if self.four_day_pattern:
            aggregate = {}
            for key, count in pair_alignment_counter.items():
                dep, year, sec, subj, pair_group, start, end, day = key
                base = (dep, year, sec, subj, pair_group, start, end)
                if base not in aggregate:
                    aggregate[base] = {}
                aggregate[base][day] = aggregate[base].get(day, 0) + count

            for base, day_counts in aggregate.items():
                pair_group = base[4]
                pair_days = [pair for pair in self.mirror_pairs if pair[2] == pair_group]
                if not pair_days:
                    return 0
                anchor_day, mirror_day, _ = pair_days[0]
                if day_counts.get(anchor_day, 0) != day_counts.get(mirror_day, 0):
                    return 0

            instructor_aggregate = {}
            for key, count in pair_instructor_counter.items():
                dep, year, sec, subj, pair_group, start, end, day, instructor_id = key
                base = (dep, year, sec, subj, pair_group, start, end, instructor_id)
                if base not in instructor_aggregate:
                    instructor_aggregate[base] = {}
                instructor_aggregate[base][day] = instructor_aggregate[base].get(day, 0) + count

            for base, day_counts in instructor_aggregate.items():
                pair_group = base[4]
                pair_days = [pair for pair in self.mirror_pairs if pair[2] == pair_group]
                if not pair_days:
                    return 0
                anchor_day, mirror_day, _ = pair_days[0]
                if day_counts.get(anchor_day, 0) != day_counts.get(mirror_day, 0):
                    return 0

            room_aggregate = {}
            for key, count in pair_room_counter.items():
                dep, year, sec, subj, pair_group, start, end, day, room_id = key
                base = (dep, year, sec, subj, pair_group, start, end, room_id)
                if base not in room_aggregate:
                    room_aggregate[base] = {}
                room_aggregate[base][day] = room_aggregate[base].get(day, 0) + count

            for base, day_counts in room_aggregate.items():
                pair_group = base[4]
                pair_days = [pair for pair in self.mirror_pairs if pair[2] == pair_group]
                if not pair_days:
                    return 0
                anchor_day, mirror_day, _ = pair_days[0]
                if day_counts.get(anchor_day, 0) != day_counts.get(mirror_day, 0):
                    return 0

            # Option 1: each section must have at least one non-mirror-day class.
            if non_mirror_slots_exist and required_sections:
                for sec in required_sections:
                    if sec not in used_wed_sections:
                        return 0

        return 100

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
            return p1, p2

        # Guard against tiny or incomplete individuals. In these cases there is
        # no valid interior crossover point, so keep the parents unchanged.
        if len(p1) <= 1 or len(p2) <= 1:
            return [dict(g) for g in p1], [dict(g) for g in p2]

        point = random.randint(1, len(p1) - 1)
        return p1[:point] + p2[point:], p2[:point] + p1[point:]

    # ================= MUTATION WITH REPAIR =================
    def mutation(self, individual):
        mutated = [dict(g) for g in individual]
        mutation_attempts = 60 if self.four_day_pattern else 30

        for i in range(len(mutated)):
            if random.random() < self.mutation_rate:
                for _ in range(mutation_attempts):
                    new_time = random.choice(self.time_slots)['id']
                    candidate_rooms = self.get_candidate_room_ids(mutated[i], new_time, {'used_room_time': set(), 'used_inst_time': set(), 'used_section_time': set(), 'used_inst_hours': {}, 'used_pair_subject': {}, 'paired_subject_instructor': {}, 'paired_subject_room': {}, 'wednesday_subject_per_section': {}, 'non_wednesday_subjects_per_section': {}, 'non_mirror_subject_kinds_per_section': {}, 'used_wed_sections': set()}, excluded_room_id=None)
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
                                if int(new_room or 0) != int(other.get('room_id') or 0):
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
        self.configure_ga_parameters()

    def evolve_population(self, population):
        best = None
        best_fit = 0
        stagnation = 0
        stagnation_limit = 200 if self.four_day_pattern else 80

        for gen in range(self.generations):
            fitness = [self.calculate_fitness(ind) for ind in population]
            gen_best = max(fitness)
            progress_percent = min(99, max(21, 20 + int(((gen + 1) / max(1, self.generations)) * 79)))
            self.update_progress(progress_percent, generation=(gen + 1), total_generations=self.generations, best_fit=gen_best)

            if gen_best > best_fit:
                best_fit = gen_best
                best = population[fitness.index(gen_best)]
                stagnation = 0
            else:
                stagnation += 1

            print(f"Generation {gen} | Best Fitness: {gen_best}")

            if best_fit == 100:
                break

            if stagnation >= stagnation_limit:
                print(f"Early stop: no fitness improvement in {stagnation_limit} generations")
                break

            selected = self.selection(population, fitness)
            next_pop = []

            for i in range(0, self.population_size - 1, 2):
                c1, c2 = self.crossover(selected[i], selected[i + 1])
                next_pop.append(self.mutation(c1))
                next_pop.append(self.mutation(c2))

            population = next_pop[:self.population_size]

        return best, best_fit

    # ================= RUN GA =================
    def run(self):
        self.update_job_status('processing')
        self.update_progress(1, generation=0, total_generations=self.generations, best_fit=0)
        self.precheck_feasibility()
        population = self.initialize_population()
        best, best_fit = self.evolve_population(population)

        if (not best or best_fit < 100) and self.four_day_pattern:
            print("Retrying schedule search with paired-day mode disabled.")
            self.disable_paired_day_mode()
            self.update_progress(5, generation=0, total_generations=self.generations, best_fit=best_fit)
            retry_population = self.initialize_population()
            retry_best, retry_best_fit = self.evolve_population(retry_population)
            if retry_best and retry_best_fit >= best_fit:
                best, best_fit = retry_best, retry_best_fit

        if not best or best_fit < 100:
            fail_msg = "No valid schedule found. Check instructor availability, selected subjects, rooms, and constraints."
            if self.four_day_pattern:
                fail_msg += " Paired-day mode is strict (Mon/Thu and Tue/Fri mirror). Try adding more instructors/slots or disabling paired-day mode."
            self.update_job_status('failed', fail_msg)
            raise Exception(fail_msg)

        self.save_schedule(best)
        self.update_job_status('completed')
        self.update_progress(100, generation=self.generations, total_generations=self.generations, best_fit=best_fit)
        return best

    # ================= SAVE SCHEDULE =================
    def save_schedule(self, schedule):
        if not schedule:
            print("No schedule to save!")
            return
            
        conn = mysql.connector.connect(**self.db_config)
        cursor = conn.cursor()

        cursor.execute("DELETE FROM schedules WHERE job_id = %s", (self.job_id,))

        for e in schedule:
            cursor.execute("""
                INSERT INTO schedules
                (job_id, subject_id, instructor_id, room_id, time_slot_id,
                 department, year_level, section, scheduled_hours, scheduled_minutes, meeting_kind, is_published)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, 0)
            """, (
                self.job_id,
                e['subject_id'],
                e['instructor_id'],
                e['room_id'],
                e['time_slot_id'],
                e['department'],
                e['year_level'],
                e['section'],
                float(e.get('meeting_hours') or self.get_subject_hours(e['subject_id'])),
                int(e.get('scheduled_minutes') or self.get_gene_minutes(e)),
                str(e.get('meeting_kind') or 'lecture')
            ))

        conn.commit()
        cursor.close()
        conn.close()
        print(f"Saved {len(schedule)} schedule entries to database (is_published = 0)")

    # ================= JOB STATUS =================
    def update_job_status(self, status, error_message=None):
        conn = mysql.connector.connect(**self.db_config)
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
        conn.close()

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
        self.last_progress_percent = percent
        if generation is not None:
            self.last_reported_generation = generation
        if best_fit is not None:
            self.last_reported_best_fit = best_fit

        conn = mysql.connector.connect(**self.db_config)
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
        conn.close()

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
                db_config = {
                    'host': 'localhost',
                    'user': 'root',
                    'password': '',
                    'database': 'academic_scheduling'
                }
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
