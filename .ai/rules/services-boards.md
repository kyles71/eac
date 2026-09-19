---
paths:
  - 'app/Services/Boards/**'
---

# Services Boards

## Resolve board moves against occupied database positions
Treat drag neighbor IDs as stale client hints, not authoritative ordering. Resolve them to an adjacent interval while holding locks, and include hidden and archived board items because their positions still participate in the stage-position unique index.
