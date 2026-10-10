# Design: meeting-rules-from-body-and-type

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Body rules | `lib/Settings/decidesk_register.json:583,612` quorumRule, quorum, votingDefault |
| Meeting types | `lib/Settings/register.d/70-configurable-types.json:12` MeetingType defaults; pages in `src/manifest.d/configurable-types.json` |
| Preflight | `lib/Service/VotingRoundPreflight.php:103` |
| Panel | `src/components/VotingRoundPanel.vue` sends fixed rules and no governanceBody |
| Quorum | `lib/Service/QuorumVerificationService.php` has no caller; `lib/Service/VotingRoundOpener.php:106-139` reads Meeting.quorumRequired only |

## Approach

1. A pre-save listener on meeting create (ObjectCreatingEvent, the pattern of lib/Listener/SubmissionDeadlineListener.php) fills empty fields from the referenced MeetingType, then from the GovernanceBody.
2. VotingRoundPanel passes governanceBody; the fixed defaults move into the preflight as the last fallback.
3. VotingRoundOpener calls QuorumVerificationService.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: listener test with the real MeetingType fragment: a meeting with type Commissie gets its duration and quorum (red before).
- PHPUnit: VotingRoundOpener refuses when the body quorum rule is not met.
- vitest: VotingRoundPanel open payload carries governanceBody and no fixed rule.

## As built (2026-09-29, read at development `0ce394b0`)

The design did not fit the code at two points; both are corrected here.

- **Quorum.** `QuorumVerificationService` counts `membership` rows of a board integration (`boardIntegration`) against a `Meeting.attendance` map and reads `quorumRule` off the meeting, not the body. Calling it from the opener would have made every council meeting without a board integration fail its quorum. Instead a pure `BodyQuorum` sets the threshold: `Meeting.quorumRequired`, else `GovernanceBody.quorum` (a member count), else `GovernanceBody.quorumRule` over the body's current members (`majority` and `simple-majority` more than half; two-thirds, three-quarters, unanimous). Present means marked present or proxy; with no attendance taken, every member who has not left counts, as before. `MeetingRuleSource` reads the meeting, type and body for the opener. `QuorumVerificationService` keeps its board-integration role and is not touched.
- **The body.** The panel has the meeting id but not its body, so the server resolves the body from the meeting (`ParticipantResolver::resolveGovernanceBodyId`) when the request names none. That also covers other callers of the voting API.
- **The panel.** Each rule select starts on "The body's rule" and sends null for it (`chosenRules()` in `src/utils/votingPermissions.js`); a rule the chair picks is still sent and wins.
- **Rule order for the vote threshold:** the chair's pick, then the meeting type's `defaultVoteThreshold`, then the body's process template `votingRule`, then simple majority.
- **Meeting defaults.** `MeetingDefaultsListener` on ObjectCreatingEvent (meeting) fills through `setModifiedData()`: `governanceBody` from the type, `quorumRequired` from `defaultQuorum` else the body's `quorum`, `endDate` from `scheduledDate` plus `defaultDurationMinutes`, and `lifecycle` from `initialLifecycle`. OpenRegister applies schema defaults before the hook, so `lifecycle` arrives as `draft` (replaced by the type's stage) and `isPublic` as `false` (so the type's `isPublic` applies only when no value arrives at all). The test stub of ObjectCreatingEvent gained the real `setModifiedData()` / `getModifiedData()`.
