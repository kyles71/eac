---
paths:
  - 'app/Filament/User/**'
---

# User

## Keep task notices consolidated and separate from managed banners
Authenticated user task notices come from UserAttention and render once as the compact global UserBanners summary with a Review slide-over. Do not add a duplicate dashboard task widget. Managed Banners are announcements and remain independent; CheckoutSuccess intentionally suppresses the task summary.
