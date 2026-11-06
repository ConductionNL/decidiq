---
kind: code
depends_on: [voting-chair-close-and-amendment-rounds]
---

# Proposal: voting-written-resolution

## Summary

A board often decides between meetings: the secretary sends a proposal to every member, and it is adopted when all of them agree in writing. decidiq cannot do this. Every vote needs a meeting. This change lets the chair or secretary of a body put a decision to the body in writing, lets each member answer from the decision page, and adopts the decision when the body's rule is met, or rejects it when the rule can no longer be met or the deadline passes.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### vot-16, adopt a resolution in writing, without a meeting

Own rating `no`, built.state `none`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> grep written resolution/buiten vergadering/circular resolution in lib/, src/, openspec/specs: none; voting rounds require a linked meeting (VotingRoundPanel disables open without meetingId); approval-route threshold class lib/Service/ApprovalThresholdCalculator.php is self-declared unreachable

Matrix note, verbatim:

> A decision can be recorded without a meeting, but members cannot adopt it by written consent in the app.

Competitor cells rated `yes`, verbatim:

- diligent-boards: https://www.diligent.com/features/boards/boards-voting states votes are cast anytime without waiting for the next meeting, and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/approval/creating-a-signature-approval-bwa.htm collects signed approvals of a document

Lane decision: `build`. Reason, verbatim: "Archived 2026-05-11-p2-motion-and-voting-core-t3 specified written resolution approval and 2026-06-12-board-meeting-resolutions shipped a WrittenResolutionService that 2026-06-14-retire-board-portal deleted; the row is still none. Treated as build per the rule."

## Why

Dutch law allows it and expects a rule. Burgerlijk Wetboek (BW) article 2:40 gives a unanimous decision of all voting members of an association, taken outside a meeting with the board's knowledge, the force of a general meeting decision. BW 2:238 lets shareholders of a BV decide outside a meeting when everyone with meeting rights agreed to that way of deciding. Board and supervisory board regulations usually allow the same, most often unanimously.

decidiq had this once. `WrittenResolutionService` shipped with the board portal and was deleted with it, because the portal kept its own parallel board schemas. Nothing replaced it on the universal entities. Today a secretary runs a written resolution by email and types the outcome in afterwards.

## What changes

1. A voting round can have `procedure: written`. It then has no meeting. It names the governance body, a deadline, and a snapshot of the members entitled to answer, taken when the round opens.
2. The chair, vice-chair or secretary of that body opens it on any decision in lifecycle `proposed` or `deliberating` that has no meeting.
3. Each entitled member answers for, against, abstain, or "Discuss this in a meeting". The last one is an objection to deciding in writing.
4. The result is counted against the whole snapshot, not against the answers received. By default every entitled member must answer for. A body may choose a lower threshold for itself, and even then a single objection ends the round.
5. The round closes as soon as every member has answered, as soon as the outcome can no longer change, or at the deadline. Silence at the deadline is not consent.
6. The decision moves to `decided` with the outcome, and the widget keeps the record: who was entitled, who answered what, and when.

## Out of scope

- Qualified electronic signatures on each answer. `decision-methods` already has a `signature` stage method for signing a document, and a body that wants both can add that stage after the written round.
- Written resolutions of people without a Nextcloud account. That would go through portaliq's contribution contract (ADR-046) and is not asked for by the row.
- Asking a body whether it allows written decisions at all. The chair or secretary who opens the round is responsible for that, as they are today for calling a meeting.

## Supersedes

- The written resolution part of archived change `2026-05-11-p2-motion-and-voting-core-t3` (a `written-resolution` voting method on the retired Motion schema).
- REQ-011 "Written resolution outside meeting" of archived change `2026-06-12-board-meeting-resolutions`, whose service was deleted by `2026-06-14-retire-board-portal`.

## Builds on

- The voting round, vote and tally code that meetings already use, and `decision-methods` (vote sub-variants are voting round fields). A written round resolves the decision's active vote stage when there is one, through the existing stage resolution at close.
- `GovernanceScopeGuard::isInBodyScope()` with the `signatory` scope for who may open it.
- `voting-chair-close-and-amendment-rounds` for the permissions pattern the widget follows.

## Why not the approval route engine

An approval route could ask every member to grant. It was considered and not chosen. The step threshold it would need (`ApprovalThresholdCalculator`) is unreachable today by its own header, and the route engine has no notion of a body's entitled members, abstention or an objection. A written resolution is a vote held in writing, and the voting code already counts votes, applies thresholds, feeds the decision stage and publishes results.

## Risks

- A member who leaves the body during the round stays in the snapshot. That is deliberate: who had to agree is fixed when the proposal went out.
- The deadline close runs in a background job. If the job does not run, the round stays open past its deadline. The widget shows "deadline passed, waiting to close" so nobody reads an open round as undecided by choice.
- A secretary could open a written round on a body whose rules forbid it. decidiq records the procedure openly on the decision, so the choice is visible and contestable.
