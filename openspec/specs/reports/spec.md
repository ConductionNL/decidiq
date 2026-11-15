# reports Specification

## Purpose
A Reports page gathers decidiq's reports behind one footer entry, and two of them answer how voting went and who attended. Every report is a declarative dashboard page over decidiq's own register: OpenRegister counts and groups the objects, and decidiq ships no report controller and no report component. The reports cover all time; there is no per-member or per-period view (that is ins-02, specified separately).

Built after the fact: shipped by commit ca2a0265 (3 September 2026) with no OpenSpec change; this spec, written 2026-10-07, describes what that code does. Covers matrix row ins-03. Screen: board DcRapportages on the Zuiddrecht design canvas.

Code: `src/manifest.json` menu entry `ReportsMenu` and pages `Reports` (`/reports`), `VotingReport` (`/reports/voting`) and `MeetingsReport` (`/reports/meetings`); aggregation by OpenRegister's aggregation endpoint through the shared `stat`, `chart` and `object-table` widgets of nextcloud-vue.

## Requirements

### Requirement: REQ-RPT-001 One Reports page opens every report

The app SHALL offer a "Reports" entry in the navigation footer that opens `/reports`. The page SHALL show one card per report, grouped as "Decisions and votes" (Decisions, Voting) and "Meetings and people" (Meetings and attendance, Engagement), and a card SHALL open its report.

#### Scenario: The griffier opens the voting report from the footer

- GIVEN a signed-in griffier
- WHEN she clicks Reports in the navigation footer and then the Voting card
- THEN the voting report opens at `/reports/voting`
- @e2e exclude reachability is asserted by tests/e2e/app-chrome.spec.ts ("the voting report reads the kebab-case slug"), written before this spec without a scenario anchor

### Requirement: REQ-RPT-002 The voting report counts rounds and votes

The voting report SHALL show, over every voting round in the register: the number of rounds held, the number adopted (`result` adopted) and the number tied (`result` tied); the rounds grouped by result and by voting method; every cast vote grouped by its value ("How votes fell"); and the eight most recently closed rounds. The counts SHALL be computed by OpenRegister on read over the `voting-round` and `vote` schemas; the report MUST NOT store a copy.

#### Scenario: Rounds and votes are counted

- GIVEN three closed voting rounds, two adopted and one tied, with eleven votes cast
- WHEN the griffier opens the voting report
- THEN "Rounds held" shows 3, "Rounds adopted" 2 and "Rounds tied" 1
- AND "How votes fell" splits the eleven votes by for, against and abstain
- @e2e exclude declarative dashboard over OpenRegister aggregation; tests/e2e/app-chrome.spec.ts asserts that a number reaches the page, not the counts

#### Scenario: No votes yet

- GIVEN a register with no voting rounds
- WHEN the voting report opens
- THEN the charts read "No voting rounds yet" and "Nobody has voted yet"
- @e2e exclude empty labels come from the manifest's emptyLabel; no fixture without rounds exists in the e2e seed

### Requirement: REQ-RPT-003 The meetings report shows attendance

The meetings and attendance report SHALL show the number of scheduled and of closed meetings, the number of recorded absences (`meeting-attendance` with status absent), the meetings grouped by stage and by mode (in person, digital, hybrid), the recorded attendance grouped by status, and the eight most recent meetings. The counts SHALL be computed by OpenRegister on read over the `meeting` and `meeting-attendance` schemas.

#### Scenario: Absences are counted across meetings

- GIVEN two closed meetings, with one member recorded absent at each
- WHEN the griffier opens the meetings and attendance report
- THEN "Recorded absences" shows 2
- AND the Attendance chart splits the recorded attendance by present, absent, excused and proxy
- @e2e exclude tests/e2e/app-chrome.spec.ts ("the meetings report is reachable and titled") asserts the page and the Recorded absences card, not the count
