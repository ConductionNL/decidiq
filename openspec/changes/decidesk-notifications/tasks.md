# Tasks

- [ ] Add `x-openregister-notifications` to `Meeting` (meetingScheduled created + meetingReminder scheduled)
- [ ] Add `x-openregister-notifications` to `ActionItem` (actionAssigned created + actionOverdue scheduled)
- [ ] Add `x-openregister-notifications` to `Motion` (motionSubmitted created)
- [ ] Add `x-openregister-notifications` to `Decision` (decisionRecorded created)
- [ ] Add `x-openregister-notifications` to `PublicConsultation` (consultationDeadline scheduled, filter status=open)
- [ ] Add `x-openregister-notifications` to `BudgetProposal` (deadline scheduled, filter status=voting)
- [ ] Route all recipients to `object-acl` + `groups` (NOT `field`) for Meeting/ActionItem/Motion/Decision since assignee/proposer/participant are not uids
- [ ] Provide inline `subject{nl,en}` for every rule
- [ ] Validate `lib/Settings/decidesk_register.json` parses as JSON and every block uses verified keys only

## Acceptance criteria

- Every rule uses `trigger.type` from the verified set (`created`/`scheduled`), `channels[]`, `recipients[]` with `kind` of `object-acl|groups`, and inline `subject{nl,en}`.
- No `kind:field` recipient references a non-uid field (assignee, proposer, participant email/displayName).
- `scheduled` rules carry `intervalSec >= 60` and a `filter` on the relevant lifecycle/status field.
- The register JSON validates against OpenRegister's register schema after the additions.
- Assignee-direct and lifecycle-entered-X deferrals are documented in the proposal's Caveats.
