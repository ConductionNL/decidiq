# Changelog

All notable changes to Decidesk are documented in this file.

## [0.1.7] - 2026-06-01

### Added

- **MeetingDetail IA alignment** (`refactor-decidesk-ia-alignment`):
  three new sidebar tabs on the meeting detail surface so secretaries
  can author and review meeting-scoped records without leaving the
  meeting context.
  - **Minutes** (Notulen) tab — lists `minutes` scoped to the current
    meeting, creates a draft with the meeting reference pre-filled, and
    deep-links each row to MinutesDetail.
  - **Decisions** (Besluiten) tab — lists `decision` objects for the
    meeting, creates one with the meeting reference pre-filled, and
    deep-links to DecisionDetail.
  - **Votes** (Stemmingen) tab — read-only post-meeting overview that
    walks meeting → agenda-item → motion → voting-round, shows each
    round's tally and result, and deep-links to MotionDetail's votes
    tab. Vote casting stays exclusively in LiveMeeting.

### Notes

- The top-level Minutes / Decisions / Motions register pages are
  unchanged — the new tabs are an additive per-meeting surface (the
  "split" placement), not a replacement.
- Dutch + English translations added for all new strings.
- No backend, schema, lifecycle, or permission changes.
