---
paths:
  - 'app/Filament/**/*.php'
---

# Filament

## Required selects cannot return to blank
For every required single-value Filament Select, call `->selectablePlaceholder(false)`. If requiredness is conditional, make placeholder selectability match the same condition; intentionally optional selects may keep a selectable placeholder.
