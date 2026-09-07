---
paths:
  - 'app/**'
---

# App

## Characterize workflow behavior before replacing services
Before replacing or substantially refactoring an existing workflow/domain service, inspect the master implementation and add characterization tests for every existing conflict input and consumer. Cover rendered UI option sets, forged server submissions, and stale-state revalidation at acceptance. Preserve baseline behavior unless a product change is explicitly approved, and document any intentional parity difference.

## Keep reusable behavior behind focused boundaries
Apply DRY and SOLID boundaries pragmatically. Shared workflow behavior belongs in focused Actions, Services, Support classes, model methods, or reusable Filament components when it has one responsibility and more than one consumer; keep panel/page classes as orchestration surfaces.

## Route stateful workflows through domain actions
Cart, checkout, credit, payment-plan, refund, fulfillment, course-hold, recurring-lesson, event-assignment, and substitution mutations must go through their existing Actions and Services. Do not update statuses, ledgers, capacity, payment allocations, or workflow relationships directly from Filament pages or controllers; preserve transaction locks, stale-state revalidation, idempotency, and side effects.

## Separate storage and display timezones
Store datetime instants in `config('app.timezone')` (UTC) and interpret/display business times in `config('app.display_timezone')`. Filament's timezone and display formats are configured globally in `FilamentUiServiceProvider`; do not add per-component timezone overrides unless that surface intentionally uses a different timezone. Convert local date boundaries to storage time before querying.

## Represent money as integer cents
Persist and calculate monetary values as integer cents; never use floats for domain arithmetic. In Filament use the shared `moneyCents()` macros for inputs, columns, and entries, and use `format_money()` elsewhere so dollar conversion stays at the presentation boundary.

## Protect private media at both storage and download boundaries
Choose media disks through `MediaDisks`; public visibility must be explicit and is appropriate only for intentionally public assets. Sensitive documents, reports, and user media stay private. Download endpoints must authorize the parent record and verify the media morph owner and collection (or report owner, permission, status, and expiry) before streaming.

## Treat event staffing records as canonical
`event_teacher_assignments` and per-teacher `event_substitute_coverages` are the source of truth for who teaches an event. Legacy `teacher_id`, `substitute_teacher_id`, and `substitute_needed_at` accessors exist only for compatibility and must not drive new queries or writes. Use `ManageEventTeacherAssignments` and `ManageEventSubstitution` so conflicts, rotations, coverage history, and acceptance-time revalidation remain intact.

## Preserve both attendance participant types
`event_attendees` is polymorphic and supports both `Student` and `User`. Standalone-event rosters may contain either type; course rosters derive rows from assigned student enrollments and use attendance rows only for status/notes overrides. Use `EventAttendanceService` rather than assuming every attendee is a student or writing rows directly.

## Preserve product association cardinality
Course and GiftCardType each have one storefront Product, while reusable Gear may have many Product listings. Enforce singular associations through `ProductAssociationService`. Never confuse GiftCardType's own `product()` listing with its `products()` restriction set.

## Treat the Shield catalog as the permission source of truth
Define custom permissions in `config/filament-shield.php` or the relevant report/widget enum and let `PermissionCatalogSynchronizerService` reconcile the database; do not create permissions ad hoc. If owners or teachers should receive a permission by default, update `ShieldSeeder`. Permission changes must preserve policy/resource alignment and the authorization matrix tests; synchronization intentionally deletes obsolete permissions and grants the complete catalog to super administrators.

## Queue application email through the shared mail actions
Send managed templates through `QueueManagedEmail` and handcrafted messages through `QueueHandcraftedEmail`; do not mail directly from domain workflows. These boundaries enforce Mail Manager enablement, normalized/deduplicated recipients, archive-copy behavior (including Textmagic limitations), and `afterCommit()` delivery.

## Use ApplicationDateTime for timezone boundaries
Use `App\Support\ApplicationDateTime` when converting between user-facing business times and stored instants. Choose the source-aware method explicitly: `fromDisplayInput()` for local form input, `fromStorage()` for persisted/dehydrated values, `forDisplay()` for presentation, and `endOfDisplayDay()` for inclusive local date boundaries; do not guess a string's source timezone.
