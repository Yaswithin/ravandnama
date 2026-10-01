# Canonical Task Due Dates

## Decision

- Keep nullable `tasks.due_at DATETIME(6)` as legacy timezone-less wall-clock data.
- Add nullable `tasks.due_at_utc DATETIME(6)` for the new canonical instant. Its stored components are UTC by application convention; its API representation is RFC3339 with an explicit offset, normalized to UTC. API responses emit `Z` and preserve supported millisecond precision.
- The task UI uses the centralized Date/Time adapter, presents Jalali first and Gregorian second, and interprets new input in the authenticated user's saved IANA timezone.
- Legacy-only rows (`due_at_utc IS NULL`, `due_at IS NOT NULL`) display their original clock components. Their Gregorian calendar date may be converted to Jalali, but no timezone is assigned and no clock shift is applied.
- Editing a legacy task preserves `due_at` when a new canonical value is set. An explicit Clear action clears both fields.

## Existing data

The previous browser control supplied local `datetime-local` components without a timezone. The old API documentation referred to the PHP configured timezone, but neither that setting nor the source timezone is recorded per row. The additive migration therefore does not copy, rewrite, or backfill `due_at`. A future backfill requires independently proven per-record timezone provenance; unknown rows remain legacy data.

## User input and DST

The browser submits `due_at_utc` as a timezone-explicit RFC3339 instant. The backend rejects timezone-less values, unknown `-00:00` offsets, invalid Gregorian date/time values, and precision finer than milliseconds, then stores UTC components in `DATETIME(6)`. The UI accepts Jalali date and 24-hour minute input and resolves it in the saved IANA timezone. Nonexistent spring-forward and ambiguous fall-back local times are rejected. Clearing sends null for both due-date fields.
