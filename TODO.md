# Manage Subjects UI Enhancement
**Status**: In Progress

**Breakdown of Approved Plan:**
- [x] Create TODO.md with steps
- [x] Create TODO.md with steps
- [x] Step 1: Update HTML labels in Add Subject modal (#add_major_breakdown) to include "| units" display
- [x] Step 2: Update HTML labels in Edit Subject modal (#edit_major_breakdown) to include "| units" display  
- [x] Step 3: Add CSS for .units-display styling
- [x] Step 4: Add JS function to dynamically update units display based on credits input (both modals)
- [x] Step 5: Change "Weekly Total:" labels to "Total Hours and Units:" in both modals
- [x] Step 6: Test modals and verify functionality
- [x] Step 7: Mark complete and attempt_completion

**Task Complete!** 🎉

**Changes Summary:**
- Moved Credits input to Time Breakdown section (removed from Basic Information)
- Added "| X units" next to Lecture/Laboratory labels in Add/Edit modals (dynamic via JS)
- Changed "Weekly Total" to "Total Hours and Units"
- Units update live when credits input changes (total credits shown for both lec/lab)
- CSS styling for units display

View changes: `admin/manage_subjects.php`

**Original Request**: Add credits(units) next to Lecture/Lab in time breakdown. Change weekly total to "total hours and units".

**Plan Details**: Use total credits (single DB field) displayed dynamically via JS next to both labels.
