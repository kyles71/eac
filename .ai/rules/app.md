---
paths:
  - 'app/**'
---

# App

## Characterize workflow behavior before replacing services
Before replacing or substantially refactoring an existing workflow/domain service, inspect the master implementation and add characterization tests for every existing conflict input and consumer. Cover rendered UI option sets, forged server submissions, and stale-state revalidation at acceptance. Preserve baseline behavior unless a product change is explicitly approved, and document any intentional parity difference.
