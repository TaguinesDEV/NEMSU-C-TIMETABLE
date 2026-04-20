#!/usr/bin/env python3
"""
Project Cleanup Verification
Confirms all necessary files exist and unnecessary files deleted
"""
import os
from pathlib import Path

def check_files():
    root = Path(".")
    
    print("\n" + "="*80)
    print("📊 PROJECT CLEANUP VERIFICATION")
    print("="*80)
    
    # Files that should exist
    required_files = {
        "python_solver/run_solver.py": "Main orchestrator",
        "python_solver/preprocessing.py": "Problem analysis",
        "python_solver/simulated_annealing.py": "SA optimizer",
        "python_solver/cpsat_engine.py": "CP-SAT interface",
        "python_ga/genetic_algorithm.py": "GA solver",
        "README.md": "Main documentation",
        "OPTIMIZATION_GUIDE.md": "Optimization guide",
        "DEPLOYMENT_GUIDE.md": "Deployment guide",
        "check_pending_jobs.py": "Job checker",
        "verify_optimization.py": "System validator",
    }
    
    # Files that should NOT exist
    deleted_files = [
        "check_job23_details.py",
        "check_job23_status.py",
        "continuous_monitor.py",
        "debug_csp.py",
        "test_csp.py",
        "csp_backtracking.py",
        "run_solver_optimized.py",
    ]
    
    print("\n✅ REQUIRED FILES (should exist):")
    print("-" * 80)
    all_exist = True
    for file_path, description in required_files.items():
        exists = (root / file_path).exists()
        status = "✅" if exists else "❌"
        print(f"{status} {file_path:40} - {description}")
        if not exists:
            all_exist = False
    
    print("\n🗑️  DELETED FILES (should NOT exist):")
    print("-" * 80)
    all_deleted = True
    for file_path in deleted_files:
        exists = (root / file_path).exists()
        status = "✅" if not exists else "❌"
        print(f"{status} {file_path:40} - {'' if not exists else 'STILL EXISTS!'}")
        if exists:
            all_deleted = False
    
    print("\n" + "="*80)
    if all_exist and all_deleted:
        print("✅ PROJECT CLEANUP SUCCESSFUL!")
        print("="*80)
        print("\n✓ All required files present")
        print("✓ All unnecessary files deleted")
        print("✓ Project is clean and ready for production")
        return True
    else:
        print("⚠️  SOME ISSUES FOUND")
        print("="*80)
        if not all_exist:
            print("Missing required files (see above)")
        if not all_deleted:
            print("Some deleted files still exist (see above)")
        return False

if __name__ == "__main__":
    success = check_files()
    exit(0 if success else 1)
