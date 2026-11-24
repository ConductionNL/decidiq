# Proposal: meeting-requested-event

## Why
dossiq's `case-session-with-parties-and-minutes` (task 2.1, decision 191) opens a
case session as a decidiq meeting. `Meeting` already carries `subjectRegister`,
`subjectSchema` and `subjectId` (fragment `123-meeting-subject.json`), but nothing
lets another app ask decidiq to hold one: per ADR-041 a cross-app command needs a
typed event, and the REST surface refuses an in-process call.

## What changes
- `MeetingRequestedEvent` (public contract, positional constructor) with result
  slots `meetingId`, `created`, `handled`.
- `MeetingRequestedListener`, registered in `CrossAppEventRegistrar`.
- `MeetingCommandService`: idempotent upsert keyed on the subject; a repeat patches
  title, start and the listed attributes and keeps the lifecycle.

## Impact
New classes only; no schema change (the subject fields already exist).
