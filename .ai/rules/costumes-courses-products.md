---
paths:
  - 'app/Filament/Admin/Resources/{Costumes,Courses,Products}/**/*.php'
---

# Costumes Courses Products

## Manage singular product listings directly
Course and Costume each support one Product. Expose a direct create/edit Product Listing action and a compact single-listing summary on detail screens; do not present a full relationship table for a singular listing. When linking an item in ProductForm, fill Store Name from the linked item's name only when Store Name is blank.

## Keep required purchasing Product-level
Required purchasing is an expectation used for reporting and reminders; it never blocks checkout or other portal activity. Require `available_until` as the deadline, and keep `purchase_reminder_on` optional and inside the availability window.

A required Product with no configured audience applies only to households with an assigned Student enrollment in the current Academic Term. A non-required Product with no audience remains public. Student exclusions override student assignments, course enrollments, and team membership for both requirement calculations and storefront visibility, but direct User and team-staff qualification remain valid.

Ordinary required Products require one unit per household. Costume Products require one unit per included dancer or unassigned enrollment seat. Requirement reports, action items, and reminder emails must use `ProductPurchaseRequirementService`.
