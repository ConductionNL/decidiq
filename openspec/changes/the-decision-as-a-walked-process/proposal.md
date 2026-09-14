---
kind: code
depends_on: [approval-routes]
---

# Proposal: the-decision-as-a-walked-process

## Summary

A decision is walked, not typed. Somebody has to sign, a share of a
committee has to agree, intake has to be judged admissible, the decision
can be withdrawn, and the remedy open against it has to be printed on it.
Decidiq models the route and nothing that happens along it. This change
gives the route its missing acts.

## Candidates and cluster

Cluster 22 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The decision as a walked
process". Owner decidiq, size L, no cluster decision. Eleven candidates,
seven of them `must`, ten passers of which eight driven, one matrix hole.
Proving system forgejo. The cluster's mechanism line: "extend decidiq's
decision and approval specs; dossiq consumes the outcome onto the case".

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-decisions-1 | must | no | an approval is a work item of its own and it blocks the case |
| C-decisions-2 | must | no | a decision is withdrawn, and who withdrew it is recorded |
| C-decisions-3 | must | no | a document gets a future effective date and is released that day |
| C-decisions-13 | must | partial | intake ends with an admissibility judgement that can end the case |
| C-decisions-17 | must, documented | partial | the case goes to a committee as an agenda item and the decision comes back |
| C-decisions-22 | must | yes | a decision the case type does not allow is refused |
| C-decisions-25 | must, matrix hole | partial | the remedy is declared per case type and printed on the decision |
| C-decisions-4 | should | partial | a share of a step's approvers must accept, recomputed when the share changes |
| C-decisions-7 | should, documented | partial | a risk score computed from the case content before approval |
| C-decisions-10 | should | no | an approval is withdrawn when what was approved changes |
| C-decisions-16 | could, documented | no | the applicant names their own approver |

## The evidence, verbatim

Each clause is quoted from `procest/_round4/discovery/candidates.md`, with
the candidate's own best-evidence column.

- **C-decisions-1**, relevance "must, the mandaat and parafeerroute a
  besluit needs, and dossiq deleted its parafeerroute surface
  (src/manifest.json retirement note, 2026-09-03)". Best evidence:
  "request-tracker: Tools, Approval (share/html/Approvals/), approvals
  lifecycle". dossiq reads "no, SettingsService.php:332 mentions an
  ApprovalChainMapper that has no page; row 3.13 covers document review
  only".
- **C-decisions-2**, "must, intrekking door de belanghebbende and
  intrekking door het bestuursorgaan have different consequences and both
  happen". Best evidence: "dimpact-zac: Besluiten
  (decision-management/spec.md)".
- **C-decisions-3**, "must, inwerkingtreding of a besluit or verordening is
  a date somebody sets in advance". Best evidence: "huly:
  controlled-documents plannedEffectiveDate / effectiveDate". dossiq: "no,
  effectivedate hits are ZGW mappings and a mock template engine".
- **C-decisions-13**, "must". Best evidence: "dimpact-zac: Ontvankelijkheid
  in the intake flow". dossiq is partial.
- **C-decisions-17**, "must, bestuurlijke besluitvorming as a module on the
  case system is where a collegebesluit actually lives, and our 7.3 asks
  only whether a decision record exists". Best evidence: "decos-join:
  documented, /oplossingen/modules (Besluitvorming)". Documented, never
  counted in a driven tally (D21). Its note: "One runs the round inside the
  product, the other pushes the agenda to the meeting application.
  dossiq-only 7.9".
- **C-decisions-22**, "must, the result and the besluit are the same act
  and separating them is how a case closes with no decision on file". Best
  evidence: "dimpact-zac: Besluiten (docs/user-manual-features.md)". dossiq
  reads `yes` here: "lib/Service/ZgwZrcRulesService.php:362 refuses a
  resultaat whose type the case zaaktype does not carry".
- **C-decisions-25**, "must, the rechtsmiddelenclausule is required on a
  besluit and leaving it to the template is how it goes missing". Best
  evidence: "xxllnc-zaken: Case type > Documentatie
  (case-type-editor-anatomy.md)". Two driven passers, opencase and
  xxllnc-zaken. This is the cluster's matrix hole.
- **C-decisions-4**, "should, a bezwaarcommissie is a quorum not a single
  approver". Best evidence: "glpi: Approvals (front/ticketvalidation.php,
  src/ValidationStep.php:83, src/ITIL_ValidationStep.php:175-201)". dossiq:
  "partial, lib/Service/Bezwaar/CommitteeDelegationService.php has
  delegation, no threshold recompute".
- **C-decisions-7**, "should, dq 2.34 records an assessed risk level; this
  computes one and is the half that is missing". Best evidence:
  "jira-service-management: Assess potential risks to your changes with Ops
  Expert". Documented.
- **C-decisions-10**, "should". Best evidence: "forgejo: dismiss stale
  approvals on a new push". Two driven passers, forgejo and gitea.
- **C-decisions-16**, "could, a bedrijf naming its own tekenbevoegde". Best
  evidence: "jira-service-management: Allow customers to choose approvers".
  Documented.

## What decidiq builds

- **An approval as a work item.** `ApprovalAction` gains a due date, an
  assignee and an open or closed state, so an outstanding approval is
  listed, reminded and reported on like any other task, and the subject it
  blocks says it is blocked.
- **A threshold per step.** A step accepts a share rather than a person.
  Changing the share recomputes the step's outcome from the actions already
  recorded, and says so.
- **Stale approvals.** A route step declares what it was given for. When
  that content changes, the approvals recorded against the step are
  withdrawn and the step reopens, with the reason on the action.
- **Admissibility as a named verdict.** An intake step yields
  `ontvankelijk` or `niet-ontvankelijk` with a ground, and an inadmissible
  verdict closes the route rather than advancing it.
- **Withdrawal with an actor kind.** A decision is withdrawn by the
  government or by the interested party, and the two are separate values,
  not one free-text note.
- **A future effective date.** A governing document carries a planned
  effective date. A scheduled job releases it on that day and records the
  release.
- **The remedy declared per type.** A decision type declares which remedies
  are open, in what term and to which body. The decision inherits them, and
  a decision without a remedy declaration is refused.
- **A risk score.** A declarative score over the subject's own fields,
  computed before the route opens, labelled as documented in the spec.
- **An approver named by the applicant.** A step may accept a nominated
  approver, bounded by the route's own rule about who is eligible.

## How dossiq consumes it

There is no dossiq change for cluster 22 yet. dossiq needs one, and it
consumes rather than models:

- The case reads the approval outcome and shows the case as blocked while
  an approval is open.
- The case type declares which remedy applies, and the case prints the
  clause decidiq resolves.
- The intake phase reads the admissibility verdict and closes on
  `niet-ontvankelijk`.
- dossiq keeps `ZgwZrcRulesService`'s refusal of a result the zaaktype does
  not carry. C-decisions-22 already reads `yes` there and this change does
  not duplicate it.

## Affected projects

- [x] `decidiq`: this change.
- [ ] `dossiq`: the consumer half. Needs a change of its own.
- [ ] `openregister`: the audit trail each approval action is written to.
      Unchanged by this proposal.

## Out of scope

- The document itself. A future effective date releases a decidiq
  governing document, not a filinq template.
- A second approval engine. This extends `approval-routes`, it does not
  replace it.
