# roll-call-publication Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting-core-t2. Update Purpose after archive.

## Requirements

### Requirement: REQ-RCP-001 Chair can close, publish, and anonymise a roll-call VotingRound in one atomic action
The app SHALL provide a single "Afronden & publiceren" action on the VotingRound close dialog that performs, in sequence: (1) tally and store result, (2) publish result to ORI API, (3) null individual `Vote.value` fields. The action completes atomically from the user's perspective — all three steps execute before the dialog closes.

#### Scenario: Chair performs atomic close-publish-anonymise on a roll-call vote
- **GIVEN** an open VotingRound with `votingMethod: "for-against-abstain"` and 32 Vote objects with non-null values
- **WHEN** the chair clicks "Afronden & publiceren" with "Anonimiseren" checked
- **THEN** (1) `VotingRound.result`, `votesFor`, `votesAgainst`, `votesAbstain`, `closedAt` are set; (2) the ORI publication HTTP call succeeds; (3) each Vote object has `value` set to `null` via `ObjectService.saveObject()`; (4) the dialog closes showing "Stemronde afgerond. Resultaat gepubliceerd. Stemmen geanonimiseerd."

#### Scenario: Anonymisation is skipped when checkbox is unchecked
- **GIVEN** the close dialog with "Anonimiseren" unchecked
- **WHEN** the chair clicks "Stemronde sluiten"
- **THEN** the round is closed and published (if configured), but individual `Vote.value` fields remain intact; the per-member breakdown remains visible in the result view

---

### Requirement: REQ-RCP-002 Tally is captured before anonymisation so VotingRound totals remain correct
The app SHALL store `votesFor`, `votesAgainst`, and `votesAbstain` counts on `VotingRound` before nulling individual `Vote.value` fields. The aggregate result SHALL remain queryable after anonymisation.

#### Scenario: Result is readable after individual votes are anonymised
- **GIVEN** a VotingRound that was closed with `anonymise: true`, with `votesFor: 29`, `votesAgainst: 3`, `votesAbstain: 0`, `result: "adopted"`
- **WHEN** any user queries the VotingRound object
- **THEN** `votesFor`, `votesAgainst`, `votesAbstain`, and `result` are present and correct; `VotingRound.isSecret` is still `false` (anonymisation is distinct from secret ballot)

#### Scenario: Audit trail records the anonymisation event
- **GIVEN** a VotingRound that was anonymised via the atomic action
- **WHEN** the chair views the Audit tab in `CnObjectSidebar` for the VotingRound
- **THEN** an audit entry is visible showing "Stemmen geanonimiseerd" with the actor UID and timestamp

---

### Requirement: REQ-RCP-003 ORI publication uses aggregate totals, not individual vote values
The app SHALL ensure the JSON-LD payload sent to the ORI endpoint contains only `votesFor`, `votesAgainst`, `votesAbstain`, `result`, and VotingRound metadata — never individual `Vote.value` or Participant identity in the payload for roll-call votes marked for anonymisation.

#### Scenario: ORI payload contains only aggregate data
- **GIVEN** a VotingRound with `anonymise: true` and 32 individual Vote objects
- **WHEN** `OriPublicationService.publish()` is called
- **THEN** the HTTP POST body contains `votesFor`, `votesAgainst`, `votesAbstain`, `result`, `openedAt`, `closedAt`, and the linked Motion URI — no individual Participant identifiers

---

### Requirement: REQ-RCP-004 Anonymised rounds display "Geanonimiseerd" in the result view instead of per-member breakdown
The app SHALL display a "Stemmen zijn geanonimiseerd" notice in `VotingRoundPanel.vue` and suppress the per-member vote breakdown table when a closed round has all null `Vote.value` fields.

#### Scenario: Result view shows anonymisation notice
- **GIVEN** a closed VotingRound where all Vote objects have `value: null`
- **WHEN** any user opens `MotionDetail.vue`
- **THEN** the VotingRound result section shows aggregate totals, result badge, and a grey notice "Individuele stemwaarden zijn geanonimiseerd" — the per-member table is not rendered
