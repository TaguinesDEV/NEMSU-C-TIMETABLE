#!/usr/bin/env python3
import json
import sys
import random
import mysql.connector
from datetime import datetime
import traceback

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
            ('schedules', 'meeting_kind', "ALTER TABLE schedules ADD COLUMN meeting_kind ENUM('lecture','lab') NULL AFTER scheduled_hours")
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

        self.last_reported_generation = -1
        self.last_reported_best_fit = -1

        self.instructor_subject_codes = {}
        instructor_ids = [inst['id'] for inst in self.input_data.get('instructors', [])]
        if instructor_ids:
            placeholders = ','.join(['%s'] * len(instructor_ids))
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
        
        # ✅ CRITICAL FIX: Initialize BEFORE use
        self.time_key_to_id = {}
        self.slot_hour_unit = self.detect_slot_hour_unit()
        self._build_slot_indexes()

        # Availability, instructors, rooms, subjects, blocked times (unchanged logic)
        default_slots = [ts['id'] for ts in self.time_slots if str(ts.get('day', '')).lower() != SATURDAY]
        self.availability = {}
        for inst in self.input_data['instructors']:
            iid = inst['id']
            if self.respect_availability:
                cursor.execute("SELECT ts.id FROM instructor_availability ia JOIN time_slots ts ON ia.time_slot_id = ts.id WHERE ia.instructor_id = %s AND ia.is_available = 1", (iid,))
                slots = [r['id'] for r in cursor.fetchall()]
                self.availability[iid] = slots or default_slots
            else:
                self.availability[iid] = [ts['id'] for ts in self.time_slots]

        self.instructor_max_hours = {inst['id']: float(inst.get('max_hours_per_week', 20)) for inst in self.input_data['instructors']}
        self.instructors = self.input_data['instructors']
        self.rooms = self.input_data['rooms']
        self.subjects = self.input_data['subjects']
        self.subject_by_id = {s['id']: s for s in self.subjects}
        self.blocked_room_time = set()
        self.blocked_inst_time = set()

        cursor.execute("SELECT room_id, instructor_id, time_slot_id FROM schedules WHERE is_published = 1")
        for row in cursor.fetchall():
            self.blocked_room_time.add((row['room_id'], row['time_slot_id']))
            self.blocked_inst_time.add((row['instructor_id'], row['time_slot_id']))

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

        pair_days = {'monday': 'thursday', 'tuesday': 'friday'}
        for anchor_day, mirror_day in pair_days.items():
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
        if len(self.day_slot_ids.get('wednesday', [])) == 0:
            return set()
        # User rule: at least one Wednesday class per section (if Wednesday slots exist).
        return set((g['department'], g['year_level'], g['section']) for g in self.genes)

    # ================= GENE CREATION =================
    def create_genes(self):
        genes = []
        for subj in self.subjects:
            subj_year_level = subj.get('year_level')
            lecture_hours, lab_hours = self.get_subject_hour_breakdown(subj)
            meeting_index = 1

            for sec in self.sections:
                for meeting_kind, configured_hours in (('lecture', lecture_hours), ('lab', lab_hours)):
                    remaining = round(configured_hours, 2)
                    while remaining > 1e-6:
                        chunk_hours = round(min(self.slot_hour_unit, remaining), 2)
                        genes.append({
                            'subject_id': subj['id'],
                            'subject_code': subj['subject_code'],
                            'department': subj['department'],
                            'year_level': subj_year_level,
                            'section': sec,
                            'subject_type': (subj.get('subject_type') or 'major'),
                            'meeting_index': meeting_index,
                            'meeting_kind': meeting_kind,
                            'meeting_hours': chunk_hours,
                            'subject_total_hours': round(lecture_hours + lab_hours, 2)
                        })
                        remaining = round(remaining - self.slot_hour_unit, 6)
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
        # Basic hour-capacity precheck
        total_required_hours = 0.0
        total_capacity = 0.0
        for gene in self.genes:
            total_required_hours += float(gene.get('meeting_hours') or 0)
        for inst in self.instructors:
            total_capacity += self.instructor_max_hours.get(inst['id'], 20.0)

        if total_required_hours > total_capacity + 1e-6:
            raise Exception(
                f"Infeasible load: required {total_required_hours:.2f}h exceeds selected instructor capacity {total_capacity:.2f}h."
            )

        # Per-subject teachability and mandatory-load check:
        # If a subject is teachable by only one instructor, its full load is mandatory for that instructor.
        mandatory_hours = {inst['id']: 0.0 for inst in self.instructors}
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
                for g in self.genes:
                    if int(g.get('subject_id')) == int(subj['id']):
                        subj_required += float(g.get('meeting_hours') or 0)
                mandatory_hours[only_inst] += subj_required

        violations = []
        for inst in self.instructors:
            iid = inst['id']
            req = mandatory_hours.get(iid, 0.0)
            cap = self.instructor_max_hours.get(iid, 20.0)
            if req > cap + 1e-6:
                violations.append(f"instructor {iid} needs {req:.2f}h but max is {cap:.2f}h")

        if violations:
            raise Exception("Infeasible mandatory load: " + "; ".join(violations))

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

        subject_type = str(subject.get('subject_type') or '').strip().lower()
        if subject_type == 'minor':
            return 1.5, 0.0
        return 2.5, 0.0

    def normalize_room_type(self, room):
        room_type = str(room.get('room_type') or '').strip().lower()
        if room_type in ('lecture', 'lab'):
            return room_type
        return 'lab' if int(room.get('has_computers') or 0) == 1 else 'lecture'

    def get_candidate_room_ids(self, gene, time, state, excluded_room_id=None):
        meeting_kind = str(gene.get('meeting_kind') or 'lecture').strip().lower()
        lecture_rooms = []
        lab_rooms = []

        for room in self.rooms:
            room_id = int(room['id'])
            if excluded_room_id is not None and room_id == int(excluded_room_id):
                continue
            if (room_id, time) in state['used_room_time'] or (room_id, time) in self.blocked_room_time:
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
        return False

    def is_wednesday_slot(self, time_slot_id):
        ts = self.get_slot(time_slot_id)
        if not ts:
            return False
        return str(ts.get('day') or '').strip().lower() == 'wednesday'

    def get_pair_bucket_key(self, entry):
        if not self.four_day_pattern:
            return None
        ts = self.get_slot(entry.get('time_slot_id'))
        if not ts:
            return None

        day = str(ts.get('day') or '').strip().lower()
        if day in ('monday', 'thursday'):
            pair_group = 'MTh'
        elif day in ('tuesday', 'friday'):
            pair_group = 'TF'
        else:
            return None

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
        if day not in ('monday', 'thursday', 'tuesday', 'friday'):
            return None

        pair_group = 'MTh' if day in ('monday', 'thursday') else 'TF'
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

    def _can_place_entry(self, gene, instructor, room, time, state):
        section_time_key = (gene['department'], gene['year_level'], gene['section'], time)
        pair_key = self.get_pair_bucket_key({
            'department': gene['department'],
            'year_level': gene['year_level'],
            'section': gene['section'],
            'time_slot_id': time
        })

        if not self.can_teach_subject(instructor, gene['subject_code']):
            return False
        if self.is_disallowed_slot(time):
            return False
        candidate_rooms = self.get_candidate_room_ids(gene, time, state)
        if int(room) not in candidate_rooms:
            return False
        if (room, time) in state['used_room_time']:
            return False
        if (instructor, time) in state['used_inst_time']:
            return False
        if section_time_key in state['used_section_time']:
            return False
        if (room, time) in self.blocked_room_time:
            return False
        if (instructor, time) in self.blocked_inst_time:
            return False
        if time not in self.availability.get(instructor, []):
            return False
        if pair_key is not None:
            existing_subject = state['used_pair_subject'].get(pair_key)
            if existing_subject is not None and int(existing_subject) != int(gene['subject_id']):
                return False
        
        if self.four_day_pattern and self.is_wednesday_slot(time):
            sec_key = (gene['department'], gene['year_level'], gene['section'])
            current_subj_id = gene['subject_id']
            if sec_key in state['wednesday_subject_per_section'] and state['wednesday_subject_per_section'][sec_key] != current_subj_id:
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
            'time_slot_id': time
        }
        state['individual'].append(entry)
        state['used_room_time'].add((room, time))
        state['used_inst_time'].add((instructor, time))
        state['used_section_time'].add((gene['department'], gene['year_level'], gene['section'], time))
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

        if self.four_day_pattern and self.is_wednesday_slot(time):
            sec_key = (gene['department'], gene['year_level'], gene['section'])
            state['wednesday_subject_per_section'][sec_key] = gene['subject_id']
            state['used_wed_sections'].add(sec_key)
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
        state['used_room_time'].discard((room, time))
        state['used_inst_time'].discard((instructor, time))
        state['used_section_time'].discard((gene['department'], gene['year_level'], gene['section'], time))
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
            for e in state['individual']:
                pk = self.get_pair_bucket_key({
                    'department': e['department'],
                    'year_level': e['year_level'],
                    'section': e['section'],
                    'time_slot_id': e['time_slot_id']
                })
                if pk is not None:
                    state['used_pair_subject'][pk] = e['subject_id']

        if self.four_day_pattern and self.is_wednesday_slot(time):
            state['wednesday_subject_per_section'].clear()
            state['used_wed_sections'].clear()
            for e in state['individual']:
                if self.is_wednesday_slot(e['time_slot_id']):
                    sec_key = (e['department'], e['year_level'], e['section'])
                    state['wednesday_subject_per_section'][sec_key] = e['subject_id']
                    state['used_wed_sections'].add(sec_key)

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

    # ================= CREATE INDIVIDUAL =================
    def create_individual(self):
        state = {
            'individual': [],
            'used_room_time': set(),
            'used_inst_time': set(),
            'used_section_time': set(),
            'used_inst_hours': {},
            'used_pair_subject': {},
            'wednesday_subject_per_section': {},
            'used_wed_sections': set()
        }

        if not self.four_day_pattern:
            per_gene_attempts = 50
            for gene in self.genes:
                for _ in range(per_gene_attempts):
                    time = random.choice(self.time_slots)['id']
                    placed = self._try_place_gene_at_time(gene, time, state, attempts=1)
                    if placed is not None:
                        break
            return state['individual']

        # Pair-aware initialization:
        # Build subject+section groups and place paired meetings (Mon<->Thu or Tue<->Fri) together.
        grouped = {}
        for gene in self.genes:
            gkey = (gene['department'], gene['section'], int(gene['subject_id']))
            if gkey not in grouped:
                grouped[gkey] = []
            grouped[gkey].append(gene)

        group_keys = list(grouped.keys())
        random.shuffle(group_keys)

        anchor_slots = list(self.pair_anchor_slot_ids)
        wed_slots = list(self.day_slot_ids.get('wednesday', []))

        # Ensure at least one Wednesday class per section (when Wednesday slots exist).
        required_sections = set(self.required_wed_sections)
        if wed_slots:
            section_keys = list(required_sections)
            random.shuffle(section_keys)
            for sec_key in section_keys:
                dep, _, sec = sec_key
                candidate_genes = []
                for gk, glist in grouped.items():
                    if not glist:
                        continue
                    if gk[0] == dep and gk[1] == sec:
                        candidate_genes.extend(glist)
                random.shuffle(candidate_genes)

                placed = False
                for gene in candidate_genes:
                    for t in random.sample(wed_slots, len(wed_slots)):
                        entry = self._try_place_gene_at_time(gene, t, state, attempts=16)
                        if entry is None:
                            continue
                        gk = (gene['department'], gene['section'], int(gene['subject_id']))
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

            # If odd count, place one extra (prefer Wednesday to avoid pair imbalance).
            if len(genes) % 2 == 1:
                extra = genes[-1]
                placed_extra = None
                candidate_slots = list(wed_slots) if wed_slots else []
                if not candidate_slots:
                    candidate_slots = [int(ts['id']) for ts in self.time_slots]
                random.shuffle(candidate_slots)
                for t in candidate_slots:
                    placed_extra = self._try_place_gene_at_time(extra, t, state, attempts=12)
                    if placed_extra is not None:
                        break

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

        used_room_time = set()
        used_inst_time = set()
        used_section_time = set()
        used_inst_hours = {}
        used_pair_subject = {}
        wednesday_subject_per_section = {}
        used_wed_sections = set()
        pair_alignment_counter = {}
        required_sections = set(self.required_wed_sections)
        wed_slots_exist = len(self.day_slot_ids.get('wednesday', [])) > 0

        for e in individual:
            rt = (e['room_id'], e['time_slot_id'])
            it = (e['instructor_id'], e['time_slot_id'])
            st = (e['department'], e['year_level'], e['section'], e['time_slot_id'])
            pair_key = self.get_pair_bucket_key(e)
            align_key = self.get_pair_alignment_key(e)

            if self.is_disallowed_slot(e['time_slot_id']):
                return 0

            if rt in used_room_time or it in used_inst_time or st in used_section_time:
                return 0
            if rt in self.blocked_room_time or it in self.blocked_inst_time:
                return 0
            if e['time_slot_id'] not in self.availability.get(e['instructor_id'], []):
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
            
            if self.four_day_pattern and self.is_wednesday_slot(e['time_slot_id']):
                sec_key = (e['department'], e['year_level'], e['section'])
                current_subj_id = e['subject_id']
                if sec_key not in wednesday_subject_per_section:
                    wednesday_subject_per_section[sec_key] = current_subj_id
                elif wednesday_subject_per_section[sec_key] != current_subj_id:
                    return 0

            used_room_time.add(rt)
            used_inst_time.add(it)
            used_section_time.add(st)
            used_inst_hours[e['instructor_id']] = next_hours
            if pair_key is not None:
                used_pair_subject[pair_key] = e['subject_id']
            if self.four_day_pattern and self.is_wednesday_slot(e['time_slot_id']):
                sec_key = (e['department'], e['year_level'], e['section'])
                used_wed_sections.add(sec_key)
            if align_key is not None:
                pair_alignment_counter[align_key] = pair_alignment_counter.get(align_key, 0) + 1

        # Strict paired-day alignment:
        # Monday must mirror Thursday (same section/subject/time), Tuesday must mirror Friday.
        if self.four_day_pattern:
            aggregate = {}
            for key, count in pair_alignment_counter.items():
                dep, year, sec, subj, pair_group, start, end, day = key
                base = (dep, year, sec, subj, pair_group, start, end)
                if base not in aggregate:
                    aggregate[base] = {'monday': 0, 'thursday': 0, 'tuesday': 0, 'friday': 0}
                aggregate[base][day] += count

            section_mismatch_units = {}
            for base, day_counts in aggregate.items():
                pair_group = base[4]
                sec_key = (base[0], base[1], base[2])
                if pair_group == 'MTh':
                    diff = abs(day_counts['monday'] - day_counts['thursday'])
                else:
                    diff = abs(day_counts['tuesday'] - day_counts['friday'])

                # For now, we are very strict: pairs must match perfectly.
                if diff > 0:
                    return 0

            # Option 1: each section must have at least one Wednesday class.
            if wed_slots_exist and required_sections:
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
                    candidate_rooms = self.get_candidate_room_ids(mutated[i], new_time, {'used_room_time': set(), 'used_inst_time': set(), 'used_section_time': set(), 'used_inst_hours': {}, 'used_pair_subject': {}, 'wednesday_subject_per_section': {}, 'used_wed_sections': set()}, excluded_room_id=None)
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

                        if not conflict and self.four_day_pattern and self.is_wednesday_slot(new_time):
                            if self.is_wednesday_slot(other.get('time_slot_id')):
                                sec_key_i = (mutated[i].get('department'), mutated[i].get('year_level'), mutated[i].get('section'))
                                sec_key_other = (other.get('department'), other.get('year_level'), other.get('section'))
                                if sec_key_i == sec_key_other:
                                    if mutated[i].get('subject_id') != other.get('subject_id'):
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

        return mutated

    def can_teach_subject(self, instructor_id, subject_code):
        if instructor_id in self.job_instructor_subject_codes:
            selected_codes = self.job_instructor_subject_codes[instructor_id]
            return (subject_code or "").strip().upper() in selected_codes

        allowed_codes = self.instructor_subject_codes.get(instructor_id)
        # Backward compatibility: if no configured subject preferences, allow all subjects.
        if not allowed_codes:
            return True
        return (subject_code or "").strip().upper() in allowed_codes

    # ================= RUN GA =================
    def run(self):
        self.update_job_status('processing')
        self.update_progress(1, generation=0, total_generations=self.generations, best_fit=0)
        self.precheck_feasibility()
        population = self.initialize_population()

        best = None
        best_fit = 0

        stagnation = 0
        stagnation_limit = 200 if self.four_day_pattern else 80
        for gen in range(self.generations):
            fitness = [self.calculate_fitness(ind) for ind in population]
            gen_best = max(fitness)
            # Reserve 1..20% for initialization, use 21..99% for GA generations.
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

            # Early stop if stuck for a long time.
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
                 department, year_level, section, scheduled_hours, meeting_kind, is_published)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, 0)
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
