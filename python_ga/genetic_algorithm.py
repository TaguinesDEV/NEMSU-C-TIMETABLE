#!/usr/bin/env python3
import json
import sys
import random
import mysql.connector
from datetime import datetime
import traceback

# ================= GA PARAMETERS =================
POPULATION_SIZE = 80
GENERATIONS = 200
MUTATION_RATE = 0.1
CROSSOVER_RATE = 0.8
ELITE_SIZE = 5

# ================= GA CLASS =================
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

    # ================= LOAD DATA =================
    def load_data(self):
        print(f"Loading data for job {self.job_id}...")
        
        conn = mysql.connector.connect(**self.db_config)
        cursor = conn.cursor(dictionary=True)

        # Get job data
        cursor.execute("SELECT input_data FROM schedule_jobs WHERE id = %s", (self.job_id,))
        result = cursor.fetchone()
        
        if not result:
            raise Exception(f"Job with ID {self.job_id} not found!")
        
        self.input_data = json.loads(result['input_data'])
        print(f"Input data loaded: {len(self.input_data.get('instructors', []))} instructors, {len(self.input_data.get('rooms', []))} rooms, {len(self.input_data.get('subjects', []))} subjects")

        # Check if we have required data
        if not self.input_data.get('instructors'):
            raise Exception("No instructors selected! Please select at least one instructor.")
        if not self.input_data.get('rooms'):
            raise Exception("No rooms selected! Please select at least one room.")
        if not self.input_data.get('subjects'):
            raise Exception("No subjects selected! Please select at least one subject.")

        # Use filtered time slots from input_data (respects Saturday checkbox)
        # Fallback to DB query only for older jobs that do not include time_slots in input_data.
        input_time_slots = self.input_data.get('time_slots') or []
        if input_time_slots:
            self.time_slots = input_time_slots
        else:
            cursor.execute("SELECT * FROM time_slots ORDER BY day, start_time")
            self.time_slots = cursor.fetchall()

        if not self.time_slots:
            raise Exception("No time slots found! Please add/select time slots in the scheduler.")

        print(f"Using {len(self.time_slots)} time slots from job input")

        # Load instructor availability
        self.availability = {}
        for inst in self.input_data['instructors']:
            cursor.execute("""
                SELECT ts.id FROM instructor_availability ia
                JOIN time_slots ts ON ia.time_slot_id = ts.id
                WHERE ia.instructor_id = %s AND ia.is_available = 1
            """, (inst['id'],))
            self.availability[inst['id']] = [r['id'] for r in cursor.fetchall()]
            print(f"Instructor {inst['id']} has {len(self.availability[inst['id']])} available slots")

        # Load instructor-to-subject-code preferences (stored in specializations table)
        self.instructor_subject_codes = {}
        instructor_ids = [inst['id'] for inst in self.input_data['instructors']]
        if instructor_ids:
            placeholders = ",".join(["%s"] * len(instructor_ids))
            cursor.execute(f"""
                SELECT ism.instructor_id, s.specialization_name
                FROM instructor_specializations ism
                JOIN specializations s ON ism.specialization_id = s.id
                WHERE ism.instructor_id IN ({placeholders})
                ORDER BY ism.priority
            """, tuple(instructor_ids))
            for row in cursor.fetchall():
                inst_id = row['instructor_id']
                code = (row['specialization_name'] or "").strip().upper()
                if not code:
                    continue
                if inst_id not in self.instructor_subject_codes:
                    self.instructor_subject_codes[inst_id] = set()
                self.instructor_subject_codes[inst_id].add(code)

        print(f"Loaded subject preferences for {len(self.instructor_subject_codes)} instructors")

        # Per-job override from generate page checkboxes:
        # instructor_subject_map = { "instructor_id": ["CS101", "CS102"] }
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
                    for c in codes_raw:
                        code = (str(c) if c is not None else "").strip().upper()
                        if code:
                            cleaned.add(code)
                    self.job_instructor_subject_codes[inst_id] = cleaned
        print(f"Loaded per-job subject selections for {len(self.job_instructor_subject_codes)} instructors")

        # Instructor max-hours map (weekly)
        self.instructor_max_hours = {}
        for inst in self.input_data['instructors']:
            try:
                self.instructor_max_hours[inst['id']] = float(inst.get('max_hours_per_week', 20) or 20)
            except Exception:
                self.instructor_max_hours[inst['id']] = 20.0

        # Load globally published allocations to avoid cross-program conflicts
        cursor.execute("""
            SELECT room_id, instructor_id, time_slot_id
            FROM schedules
            WHERE is_published = 1
        """)
        published_rows = cursor.fetchall()
        self.blocked_room_time = set()
        self.blocked_inst_time = set()
        for row in published_rows:
            self.blocked_room_time.add((row['room_id'], row['time_slot_id']))
            self.blocked_inst_time.add((row['instructor_id'], row['time_slot_id']))
        print(f"Loaded {len(published_rows)} published schedule entries as global constraints")

        cursor.close()
        conn.close()

        self.instructors = self.input_data['instructors']
        self.rooms = self.input_data['rooms']
        self.subjects = self.input_data['subjects']
        self.subject_by_id = {s['id']: s for s in self.subjects}

        self.year_level = int(self.input_data.get('year_level', 1))
        self.num_sections = max(1, min(10, int(self.input_data.get('num_sections', 1))))
        self.sections = [chr(65 + i) for i in range(self.num_sections)]
        
        print(f"Year Level: {self.year_level}, Sections: {self.num_sections}")

    # ================= GENE CREATION =================
    def create_genes(self):
        genes = []
        for subj in self.subjects:
            for sec in self.sections:
                genes.append({
                    'subject_id': subj['id'],
                    'subject_code': subj['subject_code'],
                    'department': subj['department'],
                    'section': sec,
                    'subject_type': (subj.get('subject_type') or 'major')
                })
        return genes

    def get_subject_hours(self, subject_id):
        subject = self.subject_by_id.get(subject_id) or {}
        subject_type = str(subject.get('subject_type') or '').strip().lower()
        if subject_type == 'minor':
            return 1.5
        if subject_type == 'major':
            return 2.5

        # Fallback for older data.
        try:
            return float(subject.get('hours_per_week') or 2.5)
        except Exception:
            return 2.5

    # ================= CREATE INDIVIDUAL =================
    def create_individual(self):
        individual = []
        used_room_time = set()
        used_inst_time = set()
        used_inst_hours = {}

        for gene in self.genes:
            for _ in range(50):
                instructor = random.choice(self.instructors)['id']
                room = random.choice(self.rooms)['id']
                time = random.choice(self.time_slots)['id']

                if not self.can_teach_subject(instructor, gene['subject_code']):
                    continue

                if (room, time) in used_room_time:
                    continue
                if (instructor, time) in used_inst_time:
                    continue
                if (room, time) in self.blocked_room_time:
                    continue
                if (instructor, time) in self.blocked_inst_time:
                    continue
                if time not in self.availability.get(instructor, []):
                    continue

                entry_hours = self.get_subject_hours(gene['subject_id'])
                max_hours = self.instructor_max_hours.get(instructor, 20.0)
                if (used_inst_hours.get(instructor, 0.0) + entry_hours) > max_hours:
                    continue

                individual.append({
                    **gene,
                    'instructor_id': instructor,
                    'room_id': room,
                    'time_slot_id': time
                })
                used_room_time.add((room, time))
                used_inst_time.add((instructor, time))
                used_inst_hours[instructor] = used_inst_hours.get(instructor, 0.0) + entry_hours
                break

        return individual

    # ================= POPULATION =================
    def initialize_population(self):
        return [self.create_individual() for _ in range(POPULATION_SIZE)]

    # ================= FITNESS (HARD CONSTRAINT) =================
    def calculate_fitness(self, individual):
        if len(individual) != len(self.genes):
            return 0

        used_room_time = set()
        used_inst_time = set()
        used_inst_hours = {}

        for e in individual:
            rt = (e['room_id'], e['time_slot_id'])
            it = (e['instructor_id'], e['time_slot_id'])

            if rt in used_room_time or it in used_inst_time:
                return 0
            if rt in self.blocked_room_time or it in self.blocked_inst_time:
                return 0
            if e['time_slot_id'] not in self.availability.get(e['instructor_id'], []):
                return 0
            if not self.can_teach_subject(e['instructor_id'], e['subject_code']):
                return 0

            entry_hours = self.get_subject_hours(e['subject_id'])
            next_hours = used_inst_hours.get(e['instructor_id'], 0.0) + entry_hours
            if next_hours > self.instructor_max_hours.get(e['instructor_id'], 20.0):
                return 0

            used_room_time.add(rt)
            used_inst_time.add(it)
            used_inst_hours[e['instructor_id']] = next_hours

        return 100

    # ================= SELECTION =================
    def selection(self, population, fitness):
        selected = []
        
        # Sort indices by fitness (descending)
        sorted_indices = sorted(range(len(fitness)), key=lambda i: fitness[i], reverse=True)
        elite_idx = sorted_indices[:ELITE_SIZE]
        
        for idx in elite_idx:
            selected.append(population[idx])

        while len(selected) < POPULATION_SIZE:
            a, b = random.sample(range(len(population)), 2)
            selected.append(population[a] if fitness[a] > fitness[b] else population[b])

        return selected

    # ================= CROSSOVER =================
    def crossover(self, p1, p2):
        if random.random() > CROSSOVER_RATE:
            return p1, p2

        point = random.randint(1, len(p1) - 1)
        return p1[:point] + p2[point:], p2[:point] + p1[point:]

    # ================= MUTATION WITH REPAIR =================
    def mutation(self, individual):
        mutated = [dict(g) for g in individual]

        for i in range(len(mutated)):
            if random.random() < MUTATION_RATE:
                for _ in range(30):
                    new_time = random.choice(self.time_slots)['id']
                    new_room = random.choice(self.rooms)['id']

                    conflict = False
                    for j, other in enumerate(mutated):
                        if i == j:
                            continue
                        if other['time_slot_id'] == new_time:
                            if other['room_id'] == new_room:
                                conflict = True
                            if other['instructor_id'] == mutated[i]['instructor_id']:
                                conflict = True

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
        population = self.initialize_population()

        best = None
        best_fit = 0

        for gen in range(GENERATIONS):
            fitness = [self.calculate_fitness(ind) for ind in population]
            gen_best = max(fitness)

            if gen_best > best_fit:
                best_fit = gen_best
                best = population[fitness.index(gen_best)]

            print(f"Generation {gen} | Best Fitness: {gen_best}")

            if best_fit == 100:
                break

            selected = self.selection(population, fitness)
            next_pop = []

            for i in range(0, POPULATION_SIZE - 1, 2):
                c1, c2 = self.crossover(selected[i], selected[i + 1])
                next_pop.append(self.mutation(c1))
                next_pop.append(self.mutation(c2))

            population = next_pop[:POPULATION_SIZE]

        self.save_schedule(best)
        self.update_job_status('completed')
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
                 department, year_level, section, is_published)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s, 0)
            """, (
                self.job_id,
                e['subject_id'],
                e['instructor_id'],
                e['room_id'],
                e['time_slot_id'],
                e['department'],
                self.year_level,
                e['section']
            ))

        conn.commit()
        cursor.close()
        conn.close()
        print(f"Saved {len(schedule)} schedule entries to database (is_published = 0)")

    # ================= JOB STATUS =================
    def update_job_status(self, status):
        conn = mysql.connector.connect(**self.db_config)
        cursor = conn.cursor()

        if status == 'completed':
            cursor.execute("""
                UPDATE schedule_jobs SET status=%s, completed_at=%s WHERE id=%s
            """, (status, datetime.now(), self.job_id))
        else:
            cursor.execute("""
                UPDATE schedule_jobs SET status=%s WHERE id=%s
            """, (status, self.job_id))

        conn.commit()
        cursor.close()
        conn.close()


# ================= MAIN =================
if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            print("Usage: python genetic_algorithm.py <job_id>")
            sys.exit(1)

        job_id = int(sys.argv[1])
        print(f"Starting GA for job {job_id}...")
        
        ga = ScheduleGA(job_id)
        result = ga.run()
        
        if result:
            print(f"✅ Schedule generated successfully! {len(result)} classes scheduled.")
        else:
            print("⚠️ No valid schedule could be generated. Try adjusting constraints.")
            
    except Exception as e:
        print(f"❌ Error: {str(e)}")
        traceback.print_exc()
        sys.exit(1)
