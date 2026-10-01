# Date and time foundation

## Decision

- Jalali is the primary user-facing calendar; Gregorian dates are available as a reference.
- The user's saved IANA timezone is authoritative when interpreting or displaying local wall-clock time. Browser-local timezone is never an implicit input.
- A UTC RFC3339 instant with an explicit offset is the canonical representation. `canonicalizeRfc3339()` converts explicit-offset input to a `Z` instant.
- Frontend date/calendar/timezone conversion goes through `public/assets/js/utils/date-time.js`.
- Jalali calendar conversion uses the vendored `jalaali-js` 2.0.1 Borkowski implementation (MIT, approximately 9.7 KB). Its documented Jalali year range is -61 through 3177; do not assume historical equivalence outside the algorithm's documented behavior.
- IANA-zone wall-time resolution uses vendored Luxon 3.7.2 (MIT, approximately 262 KB ESM source) and browser `Intl` timezone data. No npm runtime or build step is needed. This focused library is used because native `Intl` formats zoned times but does not resolve wall times to an instant while exposing DST folds safely.

## Input and DST behavior

The foundation accepts strict Jalali `YYYY-MM-DD`, local `HH:mm:ss`, and an explicit IANA timezone. It converts the Jalali date to Gregorian components, resolves those components in the given zone, and returns a UTC RFC3339 instant. Nonexistent spring-forward times and repeated fall-back times are rejected; callers must request a valid, unambiguous local time. RFC3339 inputs require seconds and an explicit `Z` or numeric offset; fractional seconds are supported through millisecond precision.

## Scope

This foundation does not change Task Due Date behavior or the task API/database contract. Existing `tasks.due_at` values are not migrated. `created_at`, `updated_at`, and `completed_at` are out of scope.
