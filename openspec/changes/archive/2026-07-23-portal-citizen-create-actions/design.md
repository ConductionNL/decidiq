# Design: portal-citizen-create-actions

## Context

hydra ADR-046 makes **portaliq** the ONE external portal for accountless people.
Contribution contract v2.2 now expresses `type: create` actions: an app declares
`{id, label, type: 'create', register, schema, fields (client-writable
whitelist), set/defaults (server-stamped), minTrust}` and portaliq's shared
create receiver stamps the scope from the resolved subject, enforces the
whitelist, and writes the row. `portal-contribution`'s `citizen` manifest
deferred both creates (`actions: []`, `REQ-DKPORT-006`) precisely because the
create receiver did not yet stamp scope; it now does, closing the write-IDOR
class (portaliq#16).

### Verified facts (HEAD, decidesk — `lib/Settings/decidesk_register.json`)

- **`ConsultationReaction`** properties: `body`, `moderationStatus`
  (enum `pending|approved|rejected`), `submitterId`, `submittedAt`,
  `moderationReason`, `voteCount`, `proposalTitle`, `proposalAmount`,
  `consultation` (the parent `PublicConsultation` reference),
  `publicatiedatum`/`depublicatiedatum` (staff WOO/DIWOO controls). The portal
  provider scopes `citizenReactions` by `submitterId` (== subjectRef).
- **`PublicConsultation`** has a `status` enum including `open` (also `draft`,
  `closed`, `results-published`, `submission`, `voting`, …). The
  open-for-reactions state is `open`. `anonymousReactionsAllowed` +
  `moderationPolicy` (`pre-moderation`/`post-moderation`) exist.
- **`BudgetProposal`** properties: `title`, `description`, `requestedAmount`
  (number), `submitter`, `category`, `status`
  (enum `submitted|validated|voting|awarded|rejected`), `votesFor`,
  `votesAgainst`, `participatoryBudget` (the parent `ParticipatoryBudget`
  reference). The portal provider scopes `citizenBudgetProposals` by `submitter`
  (== subjectRef). NOTE: `BudgetProposal` has NO `submittedAt` property — so the
  create-action stamps only `submitter` + `status`, never a non-existent field.
- **`ParticipatoryBudget`** has a `status` enum `draft|submission|voting|
  tallying|closed`; the open-for-submissions state is `submission`.

## Field mapping (task intent → real schema)

The change brief named generic fields; the manifest MUST bind the REAL schema
properties verified above:

| Brief | Real property |
| --- | --- |
| reaction parent `consultationRef` | `consultation` |
| reaction `status = received` | `moderationStatus = 'pending'` (intake state) |
| reaction stamp `subjectRef` | `submitterId = subjectRef` |
| reaction `submittedAt` | `submittedAt = now` (property exists) |
| proposal `amount` | `requestedAmount` |
| proposal parent | `participatoryBudget` |
| proposal `status = received` | `status = 'submitted'` (intake state) |
| proposal stamp `subjectRef` | `submitter = subjectRef` |
| proposal `submittedAt` | (no such property — NOT stamped) |

## The create declarations (declarative, pure data)

```
createReaction:
  type: create, register: decidesk, schema: consultation-reaction
  fields (client-writable whitelist): [consultation, body]
  set (server-stamped): { submitterId: <subjectRef>,
                          moderationStatus: 'pending', submittedAt: <now> }
  parentConstraint: PublicConsultation.status == 'open'
  minTrust: low

createBudgetProposal:
  type: create, register: decidesk, schema: budget-proposal
  fields (client-writable whitelist): [participatoryBudget, title,
                                        description, requestedAmount, category]
  set (server-stamped): { submitter: <subjectRef>, status: 'submitted' }
  parentConstraint: ParticipatoryBudget.status == 'submission'
  minTrust: low
```

These are constants on the manifest — no I/O — keeping the provider a plain,
dependency-free, duck-typed class (ADR-046 A1). The provider does not run the
create; it declares what portaliq's shared receiver is allowed to write.

## Why the whitelist + server-stamp is the whole security story

- **Write-IDOR closed (portaliq#16):** the scope field (`submitterId` /
  `submitter`) is in the `set` server-stamp, NOT the client whitelist, so a
  citizen can never create a submission attributed to another subject. A
  client-supplied `submitterId`/`submitter` is ignored.
- **Moderation stays internal:** `moderationStatus` / `status` are stamped to
  the intake state by the server and are NOT in the client whitelist, so a
  citizen can never self-approve or self-publish. `moderationReason`,
  `publicatiedatum`, `depublicatiedatum`, `voteCount`, `votesFor`/`votesAgainst`
  are all excluded from the whitelist.
- **Open-parent constraint:** a citizen can only react to an `open` consultation
  and propose into a `submission`-phase participatory budget, so the portal
  cannot be used to inject into closed or draft processes.
- **`minTrust: low`:** account-less participation is the design intent; the
  creates match the read collections' trust. A rate-limit (per-subject create
  throttle) is noted for the apply phase to blunt spam, since low trust means no
  strong identity gate.

## eIDAS / identity

No identity assurance beyond portaliq's password/`portalAccount` edge (trust
`low`). DigiD/eHerkenning remain deferred. The subject's pseudonymous token
(== subjectRef) is the stamped scope, exactly as the read collections already
rely on.

## Seed Data

No new OpenRegister schema or register — reuses `ConsultationReaction`,
`BudgetProposal`, `PublicConsultation`, `ParticipatoryBudget` verified at HEAD.
Unit tests construct the provider directly (no container) and assert the manifest
data (whitelists, stamps, minTrust, parent constraints); synthetic subjects use
the nil-UUID pattern so fixtures are self-evidently fake.

## Open questions (apply-time)

1. **Open-parent enforcement locus.** Confirm whether portaliq's create receiver
   enforces the `parentConstraint` (declared filter) or whether Decidesk needs a
   server guard on the write; the manifest declares the constraint either way and
   the create MUST fail closed when the parent is not open.
2. **Rate-limit surface.** Confirm where the per-subject create throttle lives
   (portaliq edge vs a Decidesk guard) at apply.
3. **Notification on submission.** Whether creating a reaction/proposal emits a
   citizen inbox notification (the `notification` schema the read manifest
   already surfaces) is a follow-up, not required here.
