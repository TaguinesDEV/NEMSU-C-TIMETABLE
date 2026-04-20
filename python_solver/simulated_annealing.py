#!/usr/bin/env python3
"""
Simulated Annealing Optimizer for Academic Scheduling
Improves an existing schedule solution using simulated annealing.
"""
import time
import random
import math
import sys
from copy import deepcopy


class SimulatedAnnealingOptimizer:
    """Simulated Annealing optimizer for improving schedule quality."""
    
    def __init__(self, ga, initial_schedule=None):
        """Initialize SA optimizer."""
        self.ga = ga
        self.initial_schedule = initial_schedule or []
        self.best_schedule = None
        self.best_fitness = 0
    
    def optimize(self, max_seconds=30, initial_temp=100, cooling_rate=0.95):
        """
        Optimize schedule using simulated annealing.
        
        Args:
            max_seconds: Maximum time to spend optimizing
            initial_temp: Starting temperature
            cooling_rate: Temperature reduction factor per iteration
        
        Returns:
            Optimized schedule entries
        """
        print(f"🌡️  Simulated Annealing: Optimizing solution...")
        try:
            sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        except Exception:
            pass
        start_time = time.time()
        
        # Use schedule directly as individual (no conversion needed)
        current_individual = list(self.initial_schedule) if self.initial_schedule else []
        if not current_individual:
            return self.initial_schedule
        
        current_fitness = self.ga.calculate_fitness(current_individual)
        self.best_schedule = deepcopy(current_individual)
        self.best_fitness = current_fitness
        
        temperature = initial_temp
        iteration = 0
        no_improvement_count = 0
        
        while time.time() - start_time < max_seconds:
            iteration += 1
            
            # Generate neighbor solution
            neighbor_individual = self._generate_neighbor(current_individual)
            neighbor_fitness = self.ga.calculate_fitness(neighbor_individual)
            
            # Calculate acceptance probability
            delta_fitness = neighbor_fitness - current_fitness
            
            if delta_fitness > 0:
                # Better solution, always accept
                current_individual = neighbor_individual
                current_fitness = neighbor_fitness
                no_improvement_count = 0
            elif temperature > 0:
                # Worse solution, accept with probability
                probability = math.exp(delta_fitness / temperature)
                if random.random() < probability:
                    current_individual = neighbor_individual
                    current_fitness = neighbor_fitness
                else:
                    no_improvement_count += 1
            
            # Track best solution found
            if current_fitness > self.best_fitness:
                self.best_schedule = deepcopy(current_individual)
                self.best_fitness = current_fitness
                no_improvement_count = 0
            
            # Cool down
            temperature *= cooling_rate
            
            # Early termination if good solution and no improvement
            if self.best_fitness >= 95 and no_improvement_count > 50:
                break
            
            if iteration % 100 == 0:
                elapsed = time.time() - start_time
                print(f"  Iteration {iteration}: fitness={self.best_fitness:.0f}%, temp={temperature:.2f}, time={elapsed:.1f}s")
        
        elapsed = time.time() - start_time
        print(f"✅ SA optimization complete: Best fitness={self.best_fitness:.0f}% in {elapsed:.1f}s ({iteration} iterations)")
        
        return self.best_schedule
    
    def _generate_neighbor(self, individual):
        """Generate a neighboring solution by making small perturbations."""
        neighbor = deepcopy(individual)
        
        # Randomly choose type of modification
        modification = random.choice(['swap', 'reassign_instructor', 'reassign_room', 'reassign_time'])
        
        if len(neighbor) < 2:
            return neighbor
        
        if modification == 'swap':
            # Swap two entries
            i, j = random.sample(range(len(neighbor)), 2)
            neighbor[i], neighbor[j] = neighbor[j], neighbor[i]
        
        elif modification == 'reassign_instructor' and random.random() < 0.4:
            # Change instructor for a random entry
            idx = random.randint(0, len(neighbor) - 1)
            entry = neighbor[idx]
            
            # Get candidate instructors
            subject_code = str(entry.get('subject_code', '')).strip().upper()
            candidates = list(self.ga.subject_candidate_instructors.get(
                subject_code,
                self.ga.instructor_ids
            ))
            
            if candidates and len(candidates) > 1:
                candidates = [c for c in candidates if c != entry.get('instructor_id')]
                if candidates:
                    entry['instructor_id'] = random.choice(candidates)
        
        elif modification == 'reassign_room' and random.random() < 0.4:
            # Change room for a random entry
            idx = random.randint(0, len(neighbor) - 1)
            entry = neighbor[idx]
            
            # Get candidate rooms
            candidates = [r['id'] for r in self.ga.rooms]
            if candidates and len(candidates) > 1:
                candidates = [r for r in candidates if r != entry.get('room_id')]
                if candidates:
                    entry['room_id'] = random.choice(candidates)
        
        elif modification == 'reassign_time' and random.random() < 0.4:
            # Change time slot for a random entry
            idx = random.randint(0, len(neighbor) - 1)
            entry = neighbor[idx]
            
            # Get candidate time slots - entry serves as gene for this lookup
            candidates = list(self.ga.get_ordered_slot_ids_for_gene(entry))
            if candidates and len(candidates) > 1:
                candidates = [t for t in candidates if t != entry.get('time_slot_id')]
                if candidates:
                    entry['time_slot_id'] = random.choice(candidates)
        
        return neighbor
