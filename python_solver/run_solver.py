#!/usr/bin/env python3
"""
OPTIMIZED Schedule solver orchestrator with adaptive phase management.
Uses problem analysis to apply appropriate solver strategy.
"""
import os
import sys
import traceback
import time


def _configure_stdio():
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except Exception:
            pass


_configure_stdio()


# Ensure project root is importable when executed via an absolute script path.
PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if PROJECT_ROOT not in sys.path:
    sys.path.insert(0, PROJECT_ROOT)


def _mark_job_failed(job_id, error_message):
    if not job_id:
        return

    try:
        import mysql.connector

        conn = mysql.connector.connect(
            host=os.getenv("ACADEMIC_SCHEDULING_DB_HOST", "localhost"),
            user=os.getenv("ACADEMIC_SCHEDULING_DB_USER", "root"),
            password=os.getenv("ACADEMIC_SCHEDULING_DB_PASS", ""),
            database=os.getenv("ACADEMIC_SCHEDULING_DB_NAME", "academic_scheduling"),
        )
        cursor = conn.cursor()
        cursor.execute(
            """
            UPDATE schedule_jobs
            SET status=%s, error_message=%s, progress_percent=0
            WHERE id=%s
            """,
            ("failed", (error_message or "Schedule generation failed.")[:65535], int(job_id)),
        )
        conn.commit()
        cursor.close()
        conn.close()
    except Exception:
        pass


def main():
    if len(sys.argv) < 2:
        print("Usage: python run_solver.py <job_id>")
        return 1

    job_id = int(sys.argv[1])
    print(f"\n🚀 Starting optimized scheduler for job {job_id}...\n")

    # Load GA instance
    from python_ga.genetic_algorithm import ScheduleGA
    from python_solver.preprocessing import ProblemAnalyzer

    ga = ScheduleGA(job_id)
    ga.update_job_status("processing")
    ga.update_progress(1)

    # Analyze problem complexity
    print("📊 Analyzing problem complexity...")
    analyzer = ProblemAnalyzer(ga)
    analysis = analyzer.print_analysis()
    
    difficulty = analysis['difficulty']
    solver_category = analysis['solver_category']
    recommendations = analysis['recommendations']

    print(f"\n🎯 Solver strategy: {solver_category.upper()}\n")

    start_time = time.time()
    
    # ========== ROUTE BY SOLVER CATEGORY ==========
    
    if solver_category == "auto-solve":
        # TINY problems: Use CSP only, no GA
        return _solve_tiny(ga, recommendations, start_time)
    
    elif solver_category == "fast-csp":
        # SMALL problems: CSP + light SA
        return _solve_small(ga, recommendations, start_time)
    
    elif solver_category == "csp-sa":
        # MEDIUM problems: CSP → SA → light GA
        return _solve_medium(ga, recommendations, start_time)
    
    elif solver_category == "sa-ga":
        # LARGE problems: Quick CP-SAT → SA + GA
        return _solve_large(ga, recommendations, start_time)
    
    else:  # "ga-only"
        # HUGE problems: Aggressive GA
        return _solve_huge(ga, recommendations, start_time)


def _solve_tiny(ga, recs, start_time):
    """Solve TINY problems with CSP only (< 5 seconds)."""
    print("⚡ TINY problem detected - using fast CSP solver only\n")
    
    try:
        from python_solver.csp_optimized import ScheduleCSPSolverOptimized
        
        ga.update_progress(10)
        csp_solver = ScheduleCSPSolverOptimized(ga, max_depth=50, max_time=recs['csp_timeout'])
        schedule, feasible = csp_solver.solve()
        
        if not schedule:
            raise Exception("CSP failed to find solution")
        
        ga.update_progress(95)
        ga.save_schedule(schedule)
        ga.update_job_status("completed")
        ga.update_progress(100, generation=0, total_generations=0, best_fit=95)
        
        total_time = time.time() - start_time
        print(f"✅ Solved in {total_time:.1f}s | {len(schedule)} entries | CSP-only")
        return 0
    
    except Exception as e:
        _mark_job_failed(job_id, str(e))
        print(f"❌ TINY solver failed: {e}")
        ga.update_job_status("failed", str(e))
        return 1


def _solve_small(ga, recs, start_time):
    """Solve SMALL problems with CSP + light SA (< 15 seconds)."""
    print("⚡ SMALL problem detected - using CSP + Simulated Annealing\n")
    
    try:
        from python_solver.csp_optimized import ScheduleCSPSolverOptimized
        from python_solver.simulated_annealing import SimulatedAnnealingOptimizer
        
        # Phase 1: CSP
        ga.update_progress(15)
        print(f"  Phase 1: CSP+Backtracking ({recs['csp_timeout']}s timeout)...")
        csp_start = time.time()
        
        csp_solver = ScheduleCSPSolverOptimized(ga, max_depth=80, max_time=recs['csp_timeout'])
        schedule, feasible = csp_solver.solve()
        csp_time = time.time() - csp_start
        
        if not schedule:
            raise Exception(f"CSP failed in {csp_time:.1f}s")
        
        print(f"  ✓ CSP found solution in {csp_time:.1f}s")
        
        # Phase 2: SA
        ga.update_progress(50)
        print(f"  Phase 2: Simulated Annealing ({recs['sa_timeout']}s timeout)...")
        sa_start = time.time()
        
        sa_optimizer = SimulatedAnnealingOptimizer(ga, schedule)
        sa_optimizer.initial_temp = recs.get('sa_initial_temp', 80)
        sa_optimizer.cooling_rate = recs.get('sa_cooling_rate', 0.98)
        
        optimized_schedule = sa_optimizer.optimize(max_seconds=recs['sa_timeout'])
        sa_time = time.time() - sa_start
        fitness = int(sa_optimizer.best_fitness)
        
        print(f"  ✓ SA optimized in {sa_time:.1f}s | Fitness: {fitness}%")
        
        ga.update_progress(90)
        ga.save_schedule(optimized_schedule)
        ga.update_job_status("completed")
        ga.update_progress(100, generation=0, total_generations=0, best_fit=fitness)
        
        total_time = time.time() - start_time
        print(f"\n✅ Solved in {total_time:.1f}s | {len(optimized_schedule)} entries | Fitness: {fitness}%")
        return 0
    
    except Exception as e:
        print(f"❌ SMALL solver failed: {e}")
        traceback.print_exc()
        ga.update_job_status("failed", str(e))
        return 1


def _solve_medium(ga, recs, start_time):
    """Solve MEDIUM problems with GA (faster than trying CSP)."""
    print("⚡ MEDIUM problem detected - using Genetic Algorithm\n")
    
    try:
        ga.update_progress(20)
        print(f"  Genetic Algorithm (pop={recs['ga_population']}, gen={recs['ga_generations']})...\n")
        
        result = ga.run()
        return 0 if result else 1
    
    except Exception as e:
        print(f"❌ MEDIUM solver failed: {e}")
        traceback.print_exc()
        ga.update_job_status("failed", str(e))
        return 1


def _solve_large(ga, recs, start_time):
    """Solve LARGE problems with quick CP-SAT → SA + GA (45s - 5min)."""
    print("⚡ LARGE problem detected - using aggressive CP-SAT → SA + GA\n")
    
    try:
        # Phase 1: Try aggressive CP-SAT with very short timeout
        ga.update_progress(10)
        print(f"  Phase 1: CP-SAT ({recs['cpsat_timeout']}s timeout)...")
        
        try:
            from python_solver.cpsat_engine import solve_with_cpsat
            cpsat_start = time.time()
            schedule = solve_with_cpsat(ga, timeout=recs['cpsat_timeout'])
            cpsat_time = time.time() - cpsat_start
            
            print(f"  ✓ CP-SAT succeeded in {cpsat_time:.1f}s")
            
            # Optimize with SA
            ga.update_progress(50)
            print(f"  Phase 2: Simulated Annealing ({recs['sa_timeout']}s)...")
            
            from python_solver.simulated_annealing import SimulatedAnnealingOptimizer
            sa_start = time.time()
            
            sa_optimizer = SimulatedAnnealingOptimizer(ga, schedule)
            sa_optimizer.initial_temp = recs.get('sa_initial_temp', 120)
            sa_optimizer.cooling_rate = recs.get('sa_cooling_rate', 0.95)
            
            optimized_schedule = sa_optimizer.optimize(max_seconds=recs['sa_timeout'])
            sa_time = time.time() - sa_start
            fitness = int(sa_optimizer.best_fitness)
            
            print(f"  ✓ SA optimized in {sa_time:.1f}s | Fitness: {fitness}%")
            
            ga.update_progress(90)
            ga.save_schedule(optimized_schedule)
            ga.update_job_status("completed")
            ga.update_progress(100, generation=0, total_generations=0, best_fit=fitness)
            
            total_time = time.time() - start_time
            print(f"\n✅ Solved in {total_time:.1f}s | {len(optimized_schedule)} entries | Fitness: {fitness}%")
            return 0
        
        except Exception as cp_error:
            print(f"  ✗ CP-SAT failed: {cp_error}, using GA...")
    
    except Exception:
        pass
    
    # Fallback to GA
    print(f"  Phase 2: Genetic Algorithm (pop={recs['ga_population']}, gen={recs['ga_generations']})...\n")
    ga.update_progress(30)
    result = ga.run()
    return 0 if result else 1


def _solve_huge(ga, recs, start_time):
    """Solve HUGE problems with aggressive GA (1-10 minutes)."""
    print("⚡ HUGE problem detected - using genetic algorithm\n")
    
    ga.update_progress(10)
    result = ga.run()
    return 0 if result else 1


if __name__ == "__main__":
    job_id = None
    if len(sys.argv) >= 2:
        try:
            job_id = int(sys.argv[1])
        except Exception:
            job_id = None

    try:
        raise SystemExit(main())
    except Exception as e:
        print(f"\n❌ Fatal error: {e}\n")
        traceback.print_exc()
        raise SystemExit(1)
