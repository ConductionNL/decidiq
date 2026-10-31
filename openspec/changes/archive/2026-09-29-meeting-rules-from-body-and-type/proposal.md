---
kind: code
depends_on: []
---

# Proposal: meeting-rules-from-body-and-type

## Summary

A body's rules (quorum, majority, voting method) and a meeting type's defaults can be set, but meetings and votes do not follow them. This change prefills a new meeting from its type and body and makes opening a vote use the body's rules instead of fixed values.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### bod-14, set a body's rules once, such as quorum, majority and allowed voting methods, and have its meetings follow them

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> GovernanceBody quorumRule/quorum/votingDefault at lib/Settings/decidesk_register.json:583,612; MeetingType defaults (defaultQuorum, defaultVotingRule, defaultVoteThreshold) in lib/Settings/register.d/70-configurable-types.json; lib/Service/VotingRoundPreflight.php:103 resolves body template voting defaults only when governanceBodyId is passed, but src/components/VotingRoundPanel.vue:725-742 never sends governanceBody and always sends hard-coded rules from :507-514; lib/Service/QuorumVerificationService.php (quorumRule logic) has no caller in lib/; lib/Service/VotingRoundOpener.php:106-139 checks quorum only against Meeting.quorumRequired; no code reads MeetingType defaults (grep 'meeting-type', 'defaultDurationMinutes' in src and lib/Service: none)

Matrix note, verbatim:

> Rules can be set on a body, its process template and its meeting types. Meetings do not follow them from the UI: opening a vote sends fixed defaults, the body-quorum-rule service has no caller, and the quorum check reads only the per-meeting quorumRequired field.

No competitor cell is rated `yes`.

### pla-11, define meeting types, each with its own default settings

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/register.d/70-configurable-types.json:12 MeetingType (defaultQuorum, defaultVotingRule, defaultVoteThreshold, defaultDurationMinutes, initialLifecycle, isPublic); src/manifest.d/configurable-types.json MeetingTypes/MeetingTypeDetail; Meeting.type reference; no code reads the defaults (grep 'meeting-type', 'defaultDurationMinutes', 'initialLifecycle' in src/ and lib/Service, lib/Listener: none)

Matrix note, verbatim:

> Meeting types with default settings can be defined and chosen on a meeting. The defaults are not applied: nothing prefills quorum, voting rule, duration or initial state from the chosen type.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/overview-of-the-settings-for-an-agenda-type.md states each agendatype sets default time, chair, location, participants, fixed items and rights
- openslides: source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/organization/pages/committees/pages/committee-detail/modules/committee-detail-meeting/components/meeting-edit/meeting-edit.component.html:117 a meeting can be set as template (meeting.yml:77 template_for_organization_id) and new meetings are duplicated from it through meeting.clone (openslides-client/client/src/app/gateways/repositories/meeting-repository.service.ts:243, called from meeting-edit.component.ts:375), carrying all its settings.

## Why

Both rows are in the core area; pla-11 is rated yes by two competitors. Settings that nothing reads mislead the clerk who set them.

## What is built today

- GovernanceBody quorumRule, quorum and votingDefault; MeetingType defaults (defaultQuorum, defaultVotingRule, defaultVoteThreshold, defaultDurationMinutes, initialLifecycle, isPublic) with their own pages.
- VotingRoundPreflight resolves body template voting defaults when a governanceBodyId is passed.

## What changes

1. When a meeting is created with a type, empty fields are filled from the type: quorumRequired, duration, lifecycle, isPublic; then from the body where the type is silent.
2. VotingRoundPanel sends the meeting's governance body when opening a round and no longer sends fixed rules; the preflight fills the rules from the body.
3. VotingRoundOpener checks quorum with QuorumVerificationService, which reads the body's quorumRule, instead of only Meeting.quorumRequired.

## Out of scope

- Making every type a configuration record (configurable-types-domain-model).
