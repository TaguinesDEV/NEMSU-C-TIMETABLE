# PROJECT CLEANUP ANALYSIS

## ROOT-LEVEL PYTHON FILES (32 files!)

### MONITORING SCRIPTS (7 DUPLICATES) - CONSOLIDATE INTO ONE
1. check_job23_details.py - OLD monitoring
2. check_job23_status.py - OLD monitoring
3. check_job_20.py - OLD test
4. check_jobs.py - Generic checker
5. check_pending_jobs.py - Generic checker (NEW, good)
6. continuous_monitor.py - OLD continuous monitoring
7. monitor_job23.py - OLD Job 23 specific
8. realtime_monitor.py - OLD real-time monitoring
9. job25_status.py - NEW, specific

**RECOMMENDATION:** Keep only `check_pending_jobs.py` for generic job checking

### DEBUG/TEST SCRIPTS (6 files) - MOSTLY UNNECESSARY NOW
1. debug_csp.py - OLD debug tool
2. debug_fitness.py - OLD debug tool
3. test_csp.py - OLD CSP testing
4. test_sa.py - OLD SA testing
5. test_job23_optimization.py - NEW optimization demo (KEEP)
6. verify_optimization.py - NEW optimization verification (KEEP)

**RECOMMENDATION:** Delete old debug files, keep the new test/verify scripts

### SETUP/ONE-OFF SCRIPTS (3 files) - ONE-TIME USE
1. create_test_job.py - One-time setup (can DELETE after use)
2. generate_it_program.py - One-time setup (can DELETE after use)
3. reset_job25.py - One-time emergency fix (can DELETE)

**RECOMMENDATION:** Delete after confirming not needed

---

## PYTHON_SOLVER DIRECTORY (8 files)

### ACTIVE CORE FILES (Keep)
1. preprocessing.py - NEW, needed for adaptive pipeline ✅
2. run_solver.py - MAIN orchestrator ✅
3. simulated_annealing.py - Optimization algorithm ✅
4. cpsat_engine.py - CP-SAT solver (if OR-Tools available) ✅

### DUPLICATE/BACKUP FILES (REMOVE)
1. run_solver_optimized.py - OBSOLETE (run_solver.py is already optimized)
2. csp_backtracking.py - OLD version (replaced by csp_optimized.py)
3. csp_optimized.py - Has bugs, GA is primary solver now
4. csp_optimized.py vs csp_backtracking.py - BOTH problematic

**RECOMMENDATION:** Remove run_solver_optimized.py, remove both CSP variants (problematic)

---

## DOCUMENTATION FILES (6 files)

### MAIN DOCS (Keep)
1. README.md - Original project readme ✅
2. OPTIMIZATION_GUIDE.md - Technical reference
3. DEPLOYMENT_GUIDE.md - Deployment instructions ✅

### REDUNDANT DOCS (CONSOLIDATE)
1. README_OPTIMIZATION.md - Duplicate of optimization info
2. OPTIMIZATION_COMPLETE.md - Summary (duplicate of GUIDE)
3. EFFICIENCY_SUMMARY.txt - Summary (duplicate of GUIDE)

**RECOMMENDATION:** Consolidate into single README and OPTIMIZATION_GUIDE.md

---

## OTHER FILES (Clean up needed)

1. TODO.md - Should be updated or removed
2. read.text - Unclear purpose
3. EFFICIENCY_SUMMARY.txt - Can be part of README
4. Dashboard, logout.php - PHP scaffolding
5. academic_scheduling (bup).sql - Old backup file
6. subject and sem.sql - Old data file

---

## SUMMARY OF UNNECESSARY CODE

### DELETE (Safe to remove - one-time use or debug):
- check_job23_details.py
- check_job23_status.py
- check_job_20.py
- continuous_monitor.py
- monitor_job23.py
- realtime_monitor.py
- debug_csp.py
- debug_fitness.py
- test_csp.py
- test_sa.py
- create_test_job.py
- generate_it_program.py
- reset_job25.py
- python_solver/run_solver_optimized.py
- python_solver/csp_backtracking.py
- python_solver/csp_optimized.py (or fix it)
- EFFICIENCY_SUMMARY.txt
- README_OPTIMIZATION.md
- OPTIMIZATION_COMPLETE.md

### CONSOLIDATE:
- Multiple monitoring scripts → Single check_pending_jobs.py
- Multiple docs → Single README + OPTIMIZATION_GUIDE.md
- Multiple test scripts → Keep only verify_optimization.py

### KEEP (Core functionality):
- python_ga/genetic_algorithm.py
- python_solver/run_solver.py
- python_solver/preprocessing.py
- python_solver/simulated_annealing.py
- python_solver/cpsat_engine.py (if available)
- test_job23_optimization.py
- verify_optimization.py

---

## ESTIMATED CLEANUP
- Files to delete: 18+
- Files to consolidate: Documentation
- Result: Clean, maintainable codebase with ~40% fewer files
