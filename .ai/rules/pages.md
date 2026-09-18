---
paths:
  - app/Filament/User/Pages/PayPaymentPlan.php
---

# Pages

## Separate missed balance, eligibility, and confirmation state
On Pay Missed Installments, totals reflect all failed/overdue installments, while selection eligibility may exclude installments reserved by active attempts. Only active customer-origin attempts may drive the Payment Element or recovery UI. Show a successful customer confirmation only immediately after that payment completes or returns, never from general attempt history.
