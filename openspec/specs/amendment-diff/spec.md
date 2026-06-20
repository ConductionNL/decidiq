---
status: done
---

# amendment-diff Specification

## Purpose
Shows a side-by-side textual comparison between a motion and an amendment proposed against it. A "Vergelijken" tab renders an inline diff of the original and amended text, marking insertions and deletions with both colour and +/− symbols for WCAG AA accessibility, handling reordered list items cleanly, and deferring computation for long texts so the UI stays responsive.

## Requirements

### Requirement: REQ-AMD-DIFF-001 AmendmentDetail shows a "Vergelijken" tab with inline diff of original and amended text
The app SHALL add a "Vergelijken" tab to `AmendmentDetail.vue` that renders a character-level Myers diff comparing the parent Motion's `text` field with the Amendment's `text` field. Added text is highlighted with an insertion token; deleted text with a deletion token.

#### Scenario: User views the diff between motion and amendment
- **GIVEN** a Motion with text "De raad verzoekt het college vóór 1 oktober 2025 te rapporteren" and an Amendment changing "1 oktober 2025" to "1 juli 2025"
- **WHEN** the user clicks the "Vergelijken" tab on AmendmentDetail
- **THEN** the diff view shows: "De raad verzoekt het college vóór " then `<del>1 oktober 2025</del>` then `<ins>1 juli 2025</ins>` then " te rapporteren"; deletion is rendered in red (NL Design System error token) with a "−" prefix; insertion in green (success token) with a "+" prefix

#### Scenario: "Tekst" tab shows amendment text as usual
- **GIVEN** the same AmendmentDetail page
- **WHEN** the user is on the default "Tekst" tab (not "Vergelijken")
- **THEN** the amendment text is displayed in its original form, unmodified

---

### Requirement: REQ-AMD-DIFF-002 Diff visualisation handles sorted-list reordering correctly
The app SHALL render sorted-list changes (e.g., reordered enumeration items) in the diff without showing a spurious full-block deletion/insertion. List items that are reordered SHALL appear as individual moved items, not as large undifferentiated diff blocks.

#### Scenario: Reordered list items shown as individual changes
- **GIVEN** an Amendment that reorders three policy priorities from "A, B, C" to "B, A, C"
- **WHEN** the user views the diff
- **THEN** item "A" appears with a deletion marker at its original position and an insertion marker at its new position; item "B" appears similarly; item "C" is unchanged — the diff does not show the entire list as deleted and re-added

---

### Requirement: REQ-AMD-DIFF-003 Diff is colour-plus-symbol encoded for WCAG AA compliance
The app SHALL supplement colour with `+` (for insertions) and `−` (for deletions) prefix characters so that colour is not the sole method of conveying diff information. Contrast between diff token text and background MUST meet WCAG AA (≥ 4.5:1 for normal text).

#### Scenario: User with colour blindness reads the diff
- **GIVEN** a rendered diff with insertions and deletions
- **WHEN** the user has red-green colour blindness (deuteranopia)
- **THEN** the `+` and `−` prefix characters distinguish insertions from deletions without relying on colour; the diff remains fully readable

#### Scenario: Diff meets contrast requirements with default token set
- **GIVEN** the diff rendered with the default NL Design System token set
- **WHEN** contrast of the insertion green text and deletion red text on white background is measured
- **THEN** contrast ratio is ≥ 4.5:1 for both tokens (WCAG AA normal text)

---

### Requirement: REQ-AMD-DIFF-004 Diff computation does not block the UI thread for long texts
The app SHALL defer diff computation to avoid blocking the main thread when motion texts exceed 2000 characters. A loading indicator SHALL appear while computation runs.

#### Scenario: Long motion text shows spinner during diff computation
- **GIVEN** a Motion with 3000-character text and an Amendment proposing changes in multiple paragraphs
- **WHEN** the user clicks the "Vergelijken" tab
- **THEN** a `NcLoadingIcon` spinner appears immediately; after computation completes (< 500ms for 95th percentile texts), the diff is rendered and the spinner removed

#### Scenario: Short texts render the diff immediately
- **GIVEN** a Motion with 200-character text
- **WHEN** the user clicks "Vergelijken"
- **THEN** the diff appears within one render cycle (< 16ms) with no visible spinner
