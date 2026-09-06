---
paths:
  - 'tests/**'
---

# Tests

## Use the shared authenticated test baseline
`tests/TestCase.php` already refreshes the database, seeds Shield permissions, creates the payment-plan terms document, authenticates a super administrator, bypasses compromised-password network checks, and disables Vite. Do not duplicate that setup; replace the actor when testing authorization, select the Filament panel explicitly when relevant, and call `withVite()` only for browser/assets coverage.
