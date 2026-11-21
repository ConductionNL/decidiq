# Design: motions-submission-window

Read at decidiq development `4d7430ff`.

## What exists

| Piece | Where |
|---|---|
| Deadline field | `lib/Settings/decidesk_register.json:960` `Meeting.submissionDeadline` (date-time, empty means no deadline) |
| Enforcement | `lib/Listener/SubmissionDeadlineListener.php`: on OpenRegister's `ObjectCreatingEvent`, for schema `decision` with `decisionType` motion or amendment, resolves the meeting (`resolveMeetingId()`), reads the deadline (`resolveSubmissionDeadline()`), and when it has passed calls `$event->setErrors()` and `stopPropagation()` so the object API answers 422 (`REJECTION_MESSAGE`, `:59`); registered `lib/AppInfo/Registrar/ObjectListenerRegistrar.php:136` |
| Existing requirement | `openspec/specs/motion-amendment/spec.md` "Motion Submission", scenario "Reject submission after the meeting deadline" |
| Meeting page | `src/manifest.json:561` `MeetingDetail`, widget `meeting-planning` includes `submissionDeadline` |
| Question windows | `openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md:69-71` `AgendaItemType.submissionWindowHours` |

## Approach

1. **Field.** Fragment `lib/Settings/register.d/92-motion-submission-window.json` adds `Meeting.submissionOpensAt` (date-time, nullable, "Empty = submission is open from the moment the meeting exists").
2. **Enforce the opening.** `SubmissionDeadlineListener` reads both times in one lookup. When `submissionOpensAt` is set and lies in the future it sets the error "Submission of motions and amendments for this meeting opens on <date and time>." and stops propagation, exactly as the deadline branch does. The class keeps its name so the registration and the unit tests stay put; its docblock and constant list both messages.
3. **Keep the window sane.** The same listener also handles `ObjectCreatingEvent` and `ObjectUpdatingEvent` for schema `meeting`: when both times are set and the opening is not before the deadline, it refuses with "The submission window opens after it closes."
4. **Show it.** `meeting-planning` includes `submissionOpensAt` next to `submissionDeadline`.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| The field | Declarative schema | Plain data |
| Refusing early submissions and an inverted window | Imperative, the existing listener | Cross-object (motion to meeting) and cross-field date comparisons; no `x-openregister-*` extension compares dates across objects, which is why the deadline check is a listener today |

## Seed data

The municipality example set's next council meeting gets `submissionOpensAt` ten days before and `submissionDeadline` one day before the meeting date.

## Files

- `lib/Settings/register.d/92-motion-submission-window.json`, `lib/Settings/profiles/municipality.json`
- `lib/Listener/SubmissionDeadlineListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php` (meeting events)
- `src/manifest.json` (`MeetingDetail` planning include list)
- `tests/Unit/Listener/SubmissionDeadlineListenerTest.php`, `tests/newman/motion-submission-window.json`
