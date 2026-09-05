---
paths:
  - 'app/Filament/Admin/Resources/{Costumes,Courses,Products}/**/*.php'
---

# Costumes Courses Products

## Manage singular product listings directly
Course and Costume each support one Product. Expose a direct create/edit Product Listing action and a compact single-listing summary on detail screens; do not present a full relationship table for a singular listing. When linking an item in ProductForm, fill Store Name from the linked item's name only when Store Name is blank.
