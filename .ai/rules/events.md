---
paths:
  - 'app/Filament/Admin/Resources/Events/**'
---

# Events

## Keep event list tabs chronological and time-aware
The Events index defaults to the My Events tab and sorts by start_time ascending. Its Future and My Events tabs use Event's not-passed constraint (end_time when present), Past Events uses the passed constraint, and My Events also applies the current user's personal-schedule constraint. Course-restricted admins have no tabs, so they do not receive a default active tab.
