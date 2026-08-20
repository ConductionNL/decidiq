# meeting-management Specification (delta)

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- urgent-decision-procedure

## Purpose

Delta for the urgent-decision-procedure change: lets the existing convocation flow deliberately deviate below the body's regular notice period for an emergency (`meetingType=extraordinary`) meeting, recording the deviation, while enforcing the per-body minimum notice floor. The existing convocation requirement ("Meeting Convocation and Notice" — per-recipient delivery tracking, `noticePeriodDays` default 15, 3-day deadline warning) is unchanged; this only ADDS the deviation-recording path.

## ADDED Requirements

### Requirement: Shortened-notice deviation recording for emergency convocations

For a meeting with `meetingType=extraordinary` that is linked to an urgent decision's expedited procedure, the system SHALL allow the convocation to be sent with an actual notice below the body's regular notice period (`noticePeriodDays`), and MUST record the deviation on the meeting: `shortenedNotice` (boolean), `actualNoticeHours` (the notice actually given at send time), and `noticeDeviationReason` (string, required when `shortenedNotice` is true — defaulting to the decision's `urgencyReason`). The send MUST be rejected when the actual notice is below the governing body's `urgencyPolicy.minimumNoticeFloorHours`, and MUST be rejected for a `regular` meeting (the deviation path is exclusive to `extraordinary` meetings). The existing "overdue/warning" pre-send hint (noticeRules) SHALL, for a recorded deviation, present as a deliberate shortened-notice state rather than an error. Per-recipient delivery tracking SHALL apply unchanged.

#### Scenario: Deviation recorded on an emergency convocation

- GIVEN an `extraordinary` meeting for an urgent decision, a body with `noticePeriodDays=15` and `urgencyPolicy.minimumNoticeFloorHours=48`, and a meeting scheduled 72 hours out
- WHEN the secretary sends the convocation confirming the shortened notice
- THEN the notice is delivered to all members with per-recipient delivery entries
- AND the meeting records `shortenedNotice=true`, `actualNoticeHours=72`, and the deviation reason

#### Scenario: Floor enforcement rejects too-short notice

- GIVEN the same body (`minimumNoticeFloorHours=48`) and a meeting scheduled 24 hours out
- WHEN the secretary attempts to send the convocation
- THEN the send is rejected naming the 48-hour floor and no deliveries or deviation are recorded

#### Scenario: Regular meetings cannot use the deviation path

- GIVEN a `regular` meeting whose convocation would be sent after the statutory deadline
- WHEN the secretary sends the notice
- THEN the existing overdue warning behaviour applies unchanged and no `shortenedNotice` deviation can be recorded
