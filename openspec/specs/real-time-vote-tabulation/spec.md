---
status: done
---

# real-time-vote-tabulation Specification

## Purpose
Shows a live vote tally during an open voting round, refreshed automatically and gated by role and ballot secrecy. The chair and secretary see a per-member breakdown on non-secret rounds while ordinary members see only aggregate counts, secret rounds hide all individual attribution until the round closes, and after closing every role sees the full result. The tally panel meets WCAG 2.1 AA, conveying vote options by text label and not colour alone.

## Requirements

### Requirement: REQ-RVT-001 Live vote tally is refreshed every 3 seconds during an open VotingRound
The app SHALL poll for updated Vote counts every 3 seconds while a VotingRound is in the open state (`closedAt` is null). The poll interval SHALL be cleared when the component is unmounted or the round closes.

#### Scenario: Chair monitors live tally during voting
- **GIVEN** an open VotingRound with 10 votes cast so far
- **WHEN** the chair views `VotingRoundPanel.vue`
- **THEN** the tally panel shows "Uitgebracht: 10 / 32 — Voor: 7, Tegen: 2, Onthouding: 1" and refreshes automatically every 3 seconds without a manual page reload

#### Scenario: Polling stops when the round closes
- **GIVEN** a VotingRoundPanel actively polling with `setInterval`
- **WHEN** the chair closes the round via "Stemronde sluiten"
- **THEN** the interval is cleared (no further GET requests) and the final tally is displayed immediately from the close response

---

### Requirement: REQ-RVT-002 Chair sees per-member vote breakdown during an open non-secret round
The app SHALL display a per-Participant vote breakdown to users with role `chair` or `secretary` during an open `VotingRound` with `isSecret: false`.

#### Scenario: Chair sees who voted and how
- **GIVEN** an open VotingRound with `isSecret: false` and 12 votes cast
- **WHEN** the chair views the tally panel
- **THEN** a table shows each voting Participant's display name alongside their vote value (Voor / Tegen / Onthouding) and, for proxy votes, the delegator's name in parentheses

#### Scenario: Per-member breakdown is hidden for secret ballots
- **GIVEN** an open VotingRound with `isSecret: true`
- **WHEN** any user views the tally panel
- **THEN** only aggregate counts are shown ("Uitgebracht: 12 / 32") — no individual attribution is visible to any role until the round closes

---

### Requirement: REQ-RVT-003 Member sees only aggregate count during an open VotingRound
The app SHALL show only the aggregate vote count ("Uitgebracht: X / Y") to Participants with role `member`, `observer`, or `guest` during an open VotingRound, regardless of `isSecret`.

#### Scenario: Member views tally panel
- **GIVEN** an open VotingRound with 18 votes cast out of 32 eligible voters
- **WHEN** a user with role `member` views the tally panel
- **THEN** the panel shows "Uitgebracht: 18 / 32" with no breakdown of individual or per-option counts

#### Scenario: All roles see full result after the round closes
- **GIVEN** a VotingRound that has just been closed with `result: "adopted"`, `votesFor: 23`, `votesAgainst: 8`, `votesAbstain: 1`
- **WHEN** any authenticated user views the panel
- **THEN** the full result is displayed: vote totals, result badge "Aangenomen", majority threshold calculation, and (for non-secret rounds) per-Participant breakdown

---

### Requirement: REQ-RVT-004 Tally panel is accessible and colour is not the sole indicator
The app SHALL meet WCAG 2.1 AA for the tally panel: vote options must not rely on colour alone, and keyboard users must be able to navigate the per-member table.

#### Scenario: Keyboard user navigates the per-member tally table
- **GIVEN** a tally table showing 32 Participant vote entries
- **WHEN** the user navigates with Tab / arrow keys
- **THEN** each row is reachable via keyboard and the vote value is conveyed by both colour token and text label ("Voor", "Tegen", "Onthouding")

#### Scenario: Vote option colour tokens meet contrast requirements
- **GIVEN** the tally panel rendered with the default NL Design System token set
- **WHEN** the contrast of "Voor" (green token) and "Tegen" (red token) text on white background is measured
- **THEN** contrast ratio is ≥ 4.5:1 (WCAG AA normal text)
