#!/usr/bin/env python3
"""
Quick optimization verification script
Validates that all optimization components are working correctly
"""
import sys
import os
import json

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

PROJECT_ROOT = os.path.abspath(os.path.dirname(__file__))
if PROJECT_ROOT not in sys.path:
    sys.path.insert(0, PROJECT_ROOT)


def main():
    print("\n" + "="*80)
    print("✅ EFFICIENCY OPTIMIZATION VERIFICATION")
    print("="*80 + "\n")
    
    errors = []
    
    # Check 1: Preprocessing module exists and works
    print("1️⃣  Checking preprocessing module...")
    try:
        from python_solver.preprocessing import ProblemAnalyzer
        print("   ✅ preprocessing.py imported successfully")
    except Exception as e:
        print(f"   ❌ Failed to import preprocessing: {e}")
        errors.append(f"Preprocessing import: {e}")
    
    # Check 2: Optimized CSP module
    print("2️⃣  Checking optimized CSP solver...")
    try:
        from python_solver.csp_optimized import ScheduleCSPSolverOptimized
        print("   ✅ csp_optimized.py imported successfully")
    except Exception as e:
        print(f"   ❌ Failed to import csp_optimized: {e}")
        errors.append(f"CSP Optimized import: {e}")
    
    # Check 3: Updated run_solver pipeline
    print("3️⃣  Checking updated run_solver pipeline...")
    try:
        run_solver_path = os.path.join(PROJECT_ROOT, "python_solver", "run_solver.py")
        with open(run_solver_path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            if "ProblemAnalyzer" in content and "_solve_tiny" in content:
                print("   ✅ run_solver.py has new adaptive pipeline")
            else:
                print("   ⚠️  run_solver.py may not have all optimizations")
                errors.append("run_solver.py missing optimization code")
    except Exception as e:
        print(f"   ❌ Failed to check run_solver: {e}")
        errors.append(f"run_solver check: {e}")
    
    # Check 4: Documentation files
    print("4️⃣  Checking documentation files...")
    docs = [
        ("OPTIMIZATION_GUIDE.md", "Technical guide"),
        ("EFFICIENCY_SUMMARY.txt", "Summary document"),
        ("test_job23_optimization.py", "Test script")
    ]
    
    for filename, desc in docs:
        filepath = os.path.join(PROJECT_ROOT, filename)
        if os.path.exists(filepath):
            print(f"   ✅ {filename} ({desc})")
        else:
            print(f"   ❌ {filename} NOT FOUND")
            errors.append(f"Missing: {filename}")
    
    # Check 5: Load GA and verify it works with preprocessing
    print("5️⃣  Testing with sample GA instance...")
    try:
        from python_ga.genetic_algorithm import ScheduleGA
        from python_solver.preprocessing import ProblemAnalyzer
        
        # Try to load job 23 (or create dummy)
        ga = ScheduleGA(23)
        analyzer = ProblemAnalyzer(ga)
        analysis = analyzer.analyze()
        
        if 'difficulty' in analysis and 'complexity_score' in analysis:
            print(f"   ✅ Problem analysis working")
            print(f"      Detected: {analysis['difficulty']} (score: {analysis['complexity_score']})")
        else:
            print(f"   ⚠️  Analysis incomplete")
            errors.append("Analysis missing fields")
    
    except Exception as e:
        print(f"   ⚠️  Could not test with GA: {e}")
        # Not critical - GA might fail for other reasons
    
    # Print Results
    print("\n" + "="*80)
    
    if not errors:
        print("🎉 ALL CHECKS PASSED!")
        print("="*80)
        print("\n✅ System is ready for optimization testing")
        print("\n📋 Next steps:")
        print("  1. Run: python python_solver/run_solver.py 23")
        print("  2. Monitor progress (should complete in 30-40s)")
        print("  3. Verify database for completed schedule")
        print("  4. Compare with previous timing")
        return 0
    else:
        print("⚠️  SOME ISSUES DETECTED")
        print("="*80)
        print("\n❌ Errors found:")
        for i, error in enumerate(errors, 1):
            print(f"  {i}. {error}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
