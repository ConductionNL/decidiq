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
