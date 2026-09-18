---
paths:
  - 'app/Forms/**'
---

# Forms

## Student waivers use academic-year seasons
There is one active student-waiver form, and each current version covers the full dance/academic year from September 1 through the following September 1. During legacy cutover, require the sole legacy waiver to be currently active but do not require its historical `valid_until` to equal the new season boundary; normalize migrated responses to the blueprint's academic-year end.
