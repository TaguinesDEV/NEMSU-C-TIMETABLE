#!/usr/bin/env python3
"""
Preprocessing module for schedule generation
Analyzes problem complexity and applies optimizations
"""
import json
import sys


class ProblemAnalyzer:
    """Analyze problem to determine difficulty and optimal solver parameters."""
    
    def __init__(self, ga):
        """Initialize with GA instance."""
        self.ga = ga
        self.instructors = ga.instructors
        self.rooms = ga.rooms
        self.time_slots = ga.time_slots
        self.genes = ga.genes
        self.num_sections = ga.num_sections
    
    def analyze(self):
        """Return problem difficulty analysis and recommended parameters."""
        num_genes = len(self.genes)
        num_instructors = len(self.instructors)
        num_rooms = len(self.rooms)
        num_slots = len(self.time_slots)
        num_constraints = self._count_constraints()
        
        # Calculate problem complexity score
        complexity = self._calculate_complexity(
            num_genes, num_instructors, num_rooms, num_slots, num_constraints
        )
        
        # Determine difficulty level
        if complexity < 50:
            difficulty = "TINY"
            category = "auto-solve"
        elif complexity < 150:
            difficulty = "SMALL"
            category = "fast-csp"
        elif complexity < 400:
            difficulty = "MEDIUM"
            category = "csp-sa"
        elif complexity < 800:
            difficulty = "LARGE"
            category = "sa-ga"
        else:
            difficulty = "HUGE"
            category = "ga-only"
        
        # Get solver recommendations
        recommendations = self._get_recommendations(difficulty, num_genes)
        
        result = {
            'difficulty': difficulty,
            'complexity_score': complexity,
            'solver_category': category,
            'num_genes': num_genes,
            'num_instructors': num_instructors,
            'num_rooms': num_rooms,
            'num_slots': num_slots,
            'num_constraints': num_constraints,
            'recommendations': recommendations
        }
        
        return result
    
    def _count_constraints(self):
        """Estimate number of active constraints."""
        count = 0
        
        # Room conflicts
        count += len(self.rooms) * len(self.time_slots)
        
        # Instructor time constraints
        count += len(self.instructors) * len(self.time_slots)
        
        # Section constraints
        count += self.num_sections * len(self.time_slots)
        
        # Mirror pair constraints
        if self.ga.four_day_pattern and self.ga.mirror_pairs:
            count += len(self.ga.mirror_pairs) * len(self.time_slots)
        
        return count
    
    def _calculate_complexity(self, genes, instructors, rooms, slots, constraints):
        """Calculate problem complexity score (0-1000+)."""
        # Weighted scoring
        gene_factor = genes * 5  # 5 points per gene
        instructor_factor = instructors * 2  # 2 points per instructor
        room_factor = rooms * 1.5  # 1.5 points per room
        slot_factor = slots * 0.5  # 0.5 points per slot
        constraint_factor = constraints * 0.1  # 0.1 points per constraint
        
        complexity = (
            gene_factor +
            instructor_factor +
            room_factor +
            slot_factor +
            constraint_factor
        )
        
        return int(complexity)
    
    def _get_recommendations(self, difficulty, num_genes):
        """Return solver parameter recommendations based on difficulty."""
        
        if difficulty == "TINY":
            # Tiny problems: use CSP+Backtracking only (fastest)
            return {
                'use_cpsat': False,
                'use_csp': True,
                'use_sa': False,
                'use_ga': False,
                'csp_timeout': 5,
                'sa_timeout': 0,
                'ga_timeout': 0,
                'ga_population': 20,
                'ga_generations': 20,
                'estimated_time': '< 5 seconds'
            }
        
        elif difficulty == "SMALL":
            # Small problems: CSP+Backtracking + light SA
            return {
                'use_cpsat': False,
                'use_csp': True,
                'use_sa': True,
                'use_ga': False,
                'csp_timeout': 8,
                'sa_timeout': 5,
                'sa_initial_temp': 80,
                'sa_cooling_rate': 0.98,
                'ga_timeout': 0,
                'estimated_time': '< 15 seconds'
            }
        
        elif difficulty == "MEDIUM":
            # Medium problems: CSP+Backtracking → SA → light GA
            return {
                'use_cpsat': False,
                'use_csp': True,
                'use_sa': True,
                'use_ga': True,
                'csp_timeout': 10,
                'csp_max_depth': 100,
                'sa_timeout': 15,
                'sa_initial_temp': 100,
                'sa_cooling_rate': 0.97,
                'ga_population': 30,
                'ga_generations': 50,
                'ga_timeout': 120,
                'estimated_time': '15-45 seconds'
            }
        
        elif difficulty == "LARGE":
            # Large problems: Quick CP-SAT → SA + GA
            return {
                'use_cpsat': True,
                'cpsat_timeout': 3,  # Very aggressive timeout
                'use_csp': False,
                'use_sa': True,
                'use_ga': True,
                'sa_timeout': 20,
                'sa_initial_temp': 120,
                'sa_cooling_rate': 0.95,
                'ga_population': 40,
                'ga_generations': 80,
                'ga_timeout': 300,
                'estimated_time': '45 seconds - 5 minutes'
            }
        
        else:  # HUGE
            # Huge problems: Aggressive CP-SAT timeout, then GA
            return {
                'use_cpsat': True,
                'cpsat_timeout': 2,  # Very aggressive
                'use_csp': False,
                'use_sa': True,
                'use_ga': True,
                'sa_timeout': 30,
                'ga_population': 50,
                'ga_generations': 100,
                'ga_timeout': 600,
                'estimated_time': '1-10 minutes'
            }
    
    def print_analysis(self):
        """Print analysis results."""
        try:
            sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        except Exception:
            pass
        analysis = self.analyze()
        
        print("\n" + "=" * 80)
        print("📊 PROBLEM COMPLEXITY ANALYSIS")
        print("=" * 80)
        
        print(f"\nDifficulty Level: {analysis['difficulty']}")
        print(f"Complexity Score: {analysis['complexity_score']}")
        print(f"Solver Category: {analysis['solver_category']}")
        
        print(f"\nProblem Dimensions:")
        print(f"  • Genes (scheduling units): {analysis['num_genes']}")
        print(f"  • Instructors: {analysis['num_instructors']}")
        print(f"  • Rooms: {analysis['num_rooms']}")
        print(f"  • Time Slots: {analysis['num_slots']}")
        print(f"  • Estimated Constraints: {analysis['num_constraints']}")
        
        print(f"\n🎯 Recommended Solver Configuration:")
        recs = analysis['recommendations']
        print(f"  • Use CP-SAT: {recs.get('use_cpsat', False)}")
        print(f"  • Use CSP+Backtracking: {recs.get('use_csp', False)}")
        print(f"  • Use Simulated Annealing: {recs.get('use_sa', False)}")
        print(f"  • Use Genetic Algorithm: {recs.get('use_ga', False)}")
        print(f"  • Estimated Time: {recs.get('estimated_time', 'Unknown')}")
        
        print("\n" + "=" * 80)
        
        return analysis
