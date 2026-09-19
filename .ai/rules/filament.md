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

## Normalize mixed Filament datetime validation state
A DateTimePicker custom validation rule can receive its own `$value` in the display timezone while sibling values read through `Get` are already dehydrated to the storage timezone. Normalize all datetime inputs to storage instants before comparing or combining them, and assert the displayed local interval in regression tests.

## Keep Filament tables stacked and searchable on phones
Shared Filament tables stack below 640px; preserve intentional custom card layouts by opting those tables out. Mobile table-search inputs must retain focus and selection across full and partial Livewire morphs so delayed live-search responses cannot reverse subsequent typing. Keep sticky record-action cells at 640px and above.

## Keep mobile record controls together
In stacked mobile tables, render the record checkbox at the top left and place record actions immediately after it in the same control row. Keep record data below that row and use a strong two-pixel divider between mobile records; desktop action positioning remains unchanged.
