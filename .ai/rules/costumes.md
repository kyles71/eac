---
paths:
  - 'app/Filament/Admin/Resources/Costumes/**/*.php'
---

# Costumes

## Keep costume course selection term-aware
Filter Costume course choices with a separate, non-dehydrated Academic Term selector. Default new Costumes to the current term, default edits to the linked Course's term, and preserve an explicit All Academic Terms option.

## Derive costume purchasing from the linked course
Costume purchase audiences always originate from the linked Course. Specific Students narrow that audience and Excluded Students are then subtracted. Course-wide requirements count each distinct assigned Student and each unassigned enrollment seat; specific-student requirements count each non-excluded assigned Student.
