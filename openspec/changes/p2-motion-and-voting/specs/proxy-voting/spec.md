## ADDED Requirements

### Requirement: REQ-PRX-001 Participant can delegate voting right to another Participant
The app SHALL allow an active Participant to delegate their voting right (volmacht) for a specific VotingRound to another active Participant in the same GovernanceBody. The delegation SHALL be stored as an OpenRegister relation `delegator` from Vote → Participant (proxy giver) and the Vote SHALL have `isProxy: true`.

#### Scenario: Participant grants proxy before round opens
- **GIVEN** a VotingRound that has not yet opened
- **WHEN** Participant A uses "Volmacht verlenen" and selects Participant B
- **THEN** an OpenRegister relation `proxy` is stored from Participant A → Participant B for the VotingRound, and Participant B sees a notification "U heeft een volmacht ontvangen van [A] voor stemronde [title]"

#### Scenario: Proxy vote is cast by the delegate
- **GIVEN** a VotingRound where Participant B holds a proxy from Participant A
- **WHEN** Participant B casts a vote "Voor"
- **THEN** `VotingService::castVote()` creates TWO Vote objects: one for Participant B (`isProxy: false`) and one for Participant A (`isProxy: true`, `value: "for"`, with relation `delegator` → Participant A) — unless Participant A has already voted directly

---

### Requirement: REQ-PRX-002 Each Participant may hold at most one proxy per VotingRound
The app SHALL enforce that a Participant can hold a maximum of one proxy delegation per VotingRound. Attempting to accept a second proxy SHALL be rejected with a clear error message.

#### Scenario: Participant already holds a proxy — second delegation rejected
- **GIVEN** Participant B already holds a proxy from Participant A in a VotingRound
- **WHEN** Participant C also tries to delegate to Participant B in the same VotingRound
- **THEN** `VotingService::castVote()` returns `400 Bad Request` with message "Een lid kan maximaal één volmacht ontvangen per stemronde" and Participant C's delegation is not stored

---

### Requirement: REQ-PRX-003 Proxy delegation is revocable before the VotingRound opens
The app SHALL allow the delegating Participant to revoke their proxy delegation before the VotingRound is opened by the chair. After the round opens, revocation is no longer possible.

#### Scenario: Participant revokes proxy before round opens
- **GIVEN** Participant A has delegated to Participant B and the VotingRound has not yet opened (`openedAt` is null)
- **WHEN** Participant A clicks "Volmacht intrekken"
- **THEN** the proxy relation is removed, Participant B receives a notification "De volmacht van [A] is ingetrokken", and Participant B's vote count no longer includes A's proxy

#### Scenario: Revocation blocked after round opens
- **GIVEN** a VotingRound with `openedAt` set (round is open)
- **WHEN** Participant A tries to revoke the proxy
- **THEN** the system returns an error "Volmacht kan niet worden ingetrokken na opening van de stemronde"

---

### Requirement: REQ-PRX-004 Proxy votes are identified in the audit trail and result display
The app SHALL clearly identify proxy votes in the audit trail and, for non-secret ballots, in the vote result breakdown. The delegating Participant's name SHALL be visible alongside the proxy flag.

#### Scenario: Audit trail shows proxy vote
- **GIVEN** a Vote with `isProxy: true` and a `delegator` relation to Participant A
- **WHEN** an auditor views the VotingRound audit trail
- **THEN** the Activity entry shows "Stem uitgebracht door [B] namens [A] (volmacht) — Voor"

#### Scenario: Result breakdown shows proxy flag
- **GIVEN** a closed non-secret VotingRound with proxy votes present
- **WHEN** the chair views the detailed result breakdown
- **THEN** proxy votes are marked with "(volmacht van [A])" next to the delegate's name in the participant vote list

---

### Requirement: REQ-PRX-005 Proxy voting is limited to GovernanceBody members only
The app SHALL prevent delegation to or from users who are not active Participants in the same GovernanceBody as the meeting. Observer and guest roles may NOT receive proxy delegations.

#### Scenario: Observer cannot receive a proxy
- **GIVEN** a Participant with role `observer` in a GovernanceBody
- **WHEN** another Participant tries to delegate a proxy to the observer
- **THEN** the observer does NOT appear in the proxy recipient selector and an error is returned if attempted via the API: "Waarnemers en gasten kunnen geen volmacht ontvangen"

#### Scenario: Guest cannot grant a proxy
- **GIVEN** a user with role `guest`
- **WHEN** the user opens the voting panel
- **THEN** the "Volmacht verlenen" action is not visible or accessible
