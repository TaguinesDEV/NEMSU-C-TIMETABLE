#!/usr/bin/env python3
"""
Lightweight CSP-style solver wrapper.

The original project routed tiny/small problems through a CSP module for speed.
This implementation provides a fast, dependency-free fallback by repeatedly
building candidates using the GA's constructive heuristics and validating them
with hard-constraint fitness (100 = feasible).
"""

from __future__ import annotations

import time
from typing import Any, Dict, List, Optional, Tuple


class ScheduleCSPSolverOptimized:
    def __init__(self, ga, max_depth: int = 80, max_time: int = 8):
        self.ga = ga
        self.max_depth = int(max_depth or 0)
        self.max_time = float(max_time or 0)

    def solve(self) -> Tuple[Optional[List[Dict[str, Any]]], bool]:
        start = time.monotonic()
        best: Optional[List[Dict[str, Any]]] = None
        best_fit = -1

        # Attempt multiple constructive builds within the time budget.
        # For tiny/small instances this typically succeeds quickly.
        attempts = 0
        while True:
            if self.max_time > 0 and (time.monotonic() - start) >= self.max_time:
                break

            candidate = self.ga.create_individual()
            attempts += 1
            fit = int(self.ga.calculate_fitness(candidate) or 0)

            if fit > best_fit:
                best_fit = fit
                best = candidate
                if best_fit == 100:
                    break

            # Prevent runaway loops if max_time is unset.
            if self.max_time <= 0 and attempts >= 50:
                break

        if best_fit == 100:
            return best, True
        return None, False

