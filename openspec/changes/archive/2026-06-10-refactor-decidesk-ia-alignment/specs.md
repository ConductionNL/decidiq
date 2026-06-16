# Specs: Decidesk IA Alignment

**Change:** refactor-decidesk-ia-alignment
**App:** Decidesk
**Affected specs:** `p2-minutes-and-decisions`, `p2-motion-and-voting`

Per-spec relocation in OpenSpec ADDED / REMOVED Requirements format.
Only requirements that describe surface placement are touched; all
existing data-model, lifecycle, and authorization requirements remain
in place unchanged.

---

## Spec: p2-minutes-and-decisions

### ADDED Requirements

### Requirement: Per-meeting Notulen authoring tab

A secretary working on a meeting SHALL be able to create, list, and
deep-link to Minutes objects for that meeting from within the meeting
detail surface, without leaving the meeting context.

#### Scenario: Notulen tab is present on MeetingDetail

- **GIVEN** a user opens a meeting at `/meetings/:id`
- **WHEN** the detail sidebar renders
- **THEN** a tab labelled "Notulen" (key: `minutes`) is visible
  alongside Overview, Agenda, Participants, and Audit

#### Scenario: Listing minutes scoped to the current meeting

- **GIVEN** the Notulen tab is opened for a meeting
- **WHEN** the tab loads
- **THEN** the tab lists every `minutes` object whose meeting link
  resolves to the current meeting
- **AND** each row deep-links to `MinutesDetail` for that minutes
  object
- **AND** when no minutes exist, an empty state with a single
  "Notulen aanmaken" action is shown

#### Scenario: Creating minutes pre-fills the meeting reference

- **GIVEN** the Notulen tab is open for a meeting
- **WHEN** the user clicks "Notulen aanmaken"
- **THEN** a new `minutes` object is created with `lifecycle: draft`
  and its meeting link pre-set to the current meeting
- **AND** the user is navigated to `MinutesDetail` for the new
  object

### Requirement: Per-meeting Besluiten authoring tab

A secretary working on a meeting SHALL be able to create, list, and
deep-link to Decision objects for that meeting from within the meeting
detail surface.

#### Scenario: Besluiten tab is present on MeetingDetail

- **GIVEN** a user opens a meeting at `/meetings/:id`
- **WHEN** the detail sidebar renders
- **THEN** a tab labelled "Besluiten" (key: `decisions`) is visible
  alongside Overview, Agenda, Participants, Notulen, and Audit

#### Scenario: Listing decisions scoped to the current meeting

- **GIVEN** the Besluiten tab is opened for a meeting
- **WHEN** the tab loads
- **THEN** the tab lists every `decision` object whose meeting link
  (direct or via parent agenda-item) resolves to the current meeting
- **AND** each row deep-links to `DecisionDetail`
- **AND** when no decisions exist, an empty state with a single
  "Besluit aanmaken" action is shown

#### Scenario: Creating a decision pre-fills the meeting reference

- **GIVEN** the Besluiten tab is open for a meeting
- **WHEN** the user clicks "Besluit aanmaken"
- **THEN** a new `decision` object is created with its meeting link
  pre-set to the current meeting
- **AND** the user is navigated to `DecisionDetail` for the new
  object

### Requirement: Register-side browse surfaces remain canonical

The top-level `Minutes` and `Decisions` index pages SHALL remain the
canonical cross-meeting browse surfaces. The new MeetingDetail tabs
SHALL NOT replace, hide, or supersede them.

#### Scenario: Top-level Minutes page still lists across meetings

- **GIVEN** the `Minutes` menu item is clicked
- **WHEN** the index page renders
- **THEN** all `minutes` objects across all meetings are listed
- **AND** the page is unchanged by this refactor

#### Scenario: Top-level Decisions page still lists across meetings

- **GIVEN** the `Decisions` menu item is clicked
- **WHEN** the index page renders
- **THEN** all `decision` objects across all meetings are listed
- **AND** the page is unchanged by this refactor

---

## Spec: p2-motion-and-voting

### ADDED Requirements

### Requirement: Per-meeting Stemmingen overview tab

A user reviewing a meeting after it has closed SHALL be able to see
every voting round and its tally for that meeting on a single
read-only surface, without entering LiveMeeting.

#### Scenario: Stemmingen tab is present on MeetingDetail

- **GIVEN** a user opens a meeting at `/meetings/:id`
- **WHEN** the detail sidebar renders
- **THEN** a tab labelled "Stemmingen" (key: `votes`) is visible
  alongside Overview, Agenda, Participants, Notulen, Besluiten, and
  Audit

#### Scenario: Listing voting rounds for the meeting

- **GIVEN** the Stemmingen tab is opened for a meeting
- **WHEN** the tab loads
- **THEN** the tab lists every `voting-round` linked to a motion that
  is linked to an agenda-item of the current meeting (or whose
  voting-round directly references the meeting)
- **AND** each row shows: motion title, motion type, votes for /
  against / abstain, round result, round timestamp
- **AND** each row deep-links to `MotionDetail` with the `votes` tab
  active for the row's motion

#### Scenario: Read-only posture

- **GIVEN** the Stemmingen tab is open
- **WHEN** the tab renders
- **THEN** no action allows casting or editing a vote from this tab
- **AND** all vote authoring continues to be handled exclusively by
  the existing `LiveMeetingView` (during the meeting)

#### Scenario: Empty state when no voting has taken place

- **GIVEN** the Stemmingen tab is opened for a meeting with no
  voting rounds
- **WHEN** the tab loads
- **THEN** an empty state reading "Geen stemmingen vastgelegd voor
  deze vergadering" is shown
- **AND** no create action is offered (rounds are created inside
  LiveMeeting)

### Requirement: Motions top-level register and MotionDetail votes tab remain canonical

The top-level `Motions` page and the `votes` tab on `MotionDetail`
SHALL remain unchanged. The new Stemmingen tab on MeetingDetail is
an additional aggregate surface, not a replacement.

#### Scenario: MotionDetail votes tab is unchanged

- **GIVEN** a user opens a motion at `/motions/:id`
- **WHEN** the detail sidebar renders
- **THEN** the existing `votes` tab (`MotionVotesTab` component) is
  present and unchanged
