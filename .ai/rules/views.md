---
paths:
  - 'resources/views/**'
---

# Views

## Render Markdown through the safe helpers
Render Markdown from APIs, administrators, or other non-literal sources with `safe_markdown()` or `safe_inline_markdown()`. Do not feed it to raw Blade output or an unsanitized Markdown renderer; reserve `{!! !!}` for content whose trusted/sanitized HTML boundary is explicit and tested.
