---
paths:
  - 'app/Filament/**'
---

# Filament

## Keep admin record actions grouped at the left
Admin table row actions belong before the data cells and every non-empty record-action list must contain one `ActionGroup`, even when it currently wraps a single action. The global position is configured in `FilamentUiServiceProvider`; preserve `AdminUsabilityTest` coverage when changing tables.

## Use slideovers and natural scrolling for short forms
Prefer Create and Edit actions in slideovers on list and view pages; register dedicated create/edit pages only when the workflow requires them. Global Create/Edit action configuration already enables slideovers. For action modals with only a few fields, call `stickyModalHeader(false)` and `stickyModalFooter(false)` so the short form scrolls naturally.

## Reuse global Filament defaults and project macros
Check `FilamentUiServiceProvider` and `FilamentUiMacros` before configuring fields locally. Selects are searchable by default and should preload only small option sets; use `phone()`, `moneyCents()`, `searchableRelationship()`, `userRelationship()`, `studentRelationship()`, and `allowVideo()` instead of duplicating their behavior.

## Keep strict authorization and global search aligned
The admin panel uses `strictAuthorization()`, and a resource title attribute opts the resource into global search. Searchable resources need an authorized View or Edit destination and every policy ability Filament checks; list/modal-only resources or resources without record-level view ability must set `$isGloballySearchable = false`. Update `tests/Feature/Filament/Components/GlobalSearchTest.php` whenever resource titles, pages, query scopes, or policy abilities change.

## Enforce record scope beyond UI visibility
Course-restricted staff, private courses/events, households, and board memberships require record-level query and policy scoping; hidden navigation or action visibility is not authorization. Cover list queries, global search, direct page access, and forged action submissions when a resource's access rules change.
