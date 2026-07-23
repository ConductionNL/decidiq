---
kind: code
depends_on: [portal-contribution]
---

# Proposal: portal-citizen-create-actions

## Why

Decidesk's `portal-contribution` gives an accountless **citizen** a
READ + inbox window into their own participation through **portaliq**, the
shared external portal (hydra ADR-046, contribution contract v2.2). Its `citizen`
manifest ships `actions: []` (`REQ-DKPORT-006`): a resident can see their own
consultation reactions, votes, budget proposals and notification inbox — but
cannot actually REACT to a consultation or PROPOSE a budget item from the portal.
Both creates were deferred because, at the time, "the parent relation they need
has no writable scalar property to whitelist and cannot be constrained to an open
parent by the flat create vocabulary" (`portal-contribution` design.md "Deferred
creates").

That blocker is gone. The portaliq scoped-create path now **server-stamps the
scope** from the resolved subject (the write-IDOR class filed as portaliq#16,
which procest's citizen `bezwaar` creates also hit): the owner/scope field is set
server-side from `subjectRef`, never accepted from the client, so a citizen can
only ever create a row scoped to themselves. The whole point of a participation
portal is account-less participation — reacting to a consultation and proposing a
budget item are the two primary citizen contributions, and a read-only portal
leaves the participation loop half-built.

## What Changes

- **`createReaction` create-action on `consultation-reaction`.** Extend
  `lib/Portal/PortalContributionProvider.php` (still plain, dependency-free) so
  the `citizen` manifest declares a `type: create` action `createReaction`
  against the `ConsultationReaction` schema, with a WHITELIST of client-writable
  fields `{consultation, body}` and server-stamped `set` `{submitterId:
  subjectRef, moderationStatus: 'pending', submittedAt: now}`. It is allowed only
  for a parent `PublicConsultation` whose `status` is `open` (a declared filter
  or a server guard).
- **`createBudgetProposal` create-action on `budget-proposal`.** Declare a
  `type: create` action `createBudgetProposal` against the `BudgetProposal`
  schema, whitelist `{participatoryBudget, title, description, requestedAmount,
  category}`, server-stamped `set` `{submitter: subjectRef, status:
  'submitted'}`. It is allowed only for a parent `ParticipatoryBudget` whose
  `status` is `submission` (open for submissions).
- **Scope is always server-stamped (write-IDOR closed).** For both actions the
  owner/scope field (`submitterId` / `submitter`) is stamped from the verified
  `subjectRef`; a client-supplied scope, status or moderation field is ignored.
- **Both actions `minTrust: low`** — account-less participation is the point
  (DigiD/eHerkenning stays deferred, exactly as the read collections) — with a
  rate-limit note.
- **Moderation stays internal.** The moderation / status lifecycle
  (`moderationStatus`, `status`) is owned by staff; the citizen only ever sets
  the intake state and sees their own submission through the existing
  `citizenReactions` / `citizenBudgetProposals` read collections.
- **`getContribution` updated** to return the two create-actions on the citizen
  manifest.

## Capabilities

### Added Capabilities

- `portal-citizen-create-actions`: an accountless resident reacts to an open
  consultation and proposes a participatory-budget item from portaliq's SPA
  through server-stamped `create` actions — the scope is stamped from the
  subject so a citizen can only ever create a submission owned by themselves,
  and moderation stays internal.

## Affected Projects

- [x] Project: `decidesk` — extend `lib/Portal/PortalContributionProvider.php` (`citizen` manifest `actions`); unit tests under `tests/unit/Portal/`; this OpenSpec change. (The scoped-create receiver + server-stamping is portaliq's shared path; Decidesk declares the create-actions and the whitelists/stamps as pure manifest data.)
- Contract: `apps-extra/portaliq` — the contract-v2.2 `type: create` action vocabulary (whitelisted `fields` + server-stamped `set`/`defaults`, subjectRef scope-stamp) and the open-parent constraint this manifest is templated against; runtime consumer that renders the create forms and stamps the scope.
- Reference: `hydra` ADR-046 (portaliq external portal); portaliq#16 (write-IDOR closed by server-stamped scope).
- Depends on: `portal-contribution` (citizen manifest, subjectRef scoping).

## Out of Scope

- Any portal UI, session, auth edge or rendering — portaliq owns the entire
  external surface (ADR-046); Decidesk ships zero portal frontend, only the
  manifest declaration.
- The scoped-create receiver, server-side scope-stamping and open-parent
  enforcement mechanics — owned by portaliq's shared create path; this change
  declares the actions and their whitelists/stamps.
- The moderation / publication lifecycle (`moderationStatus`, DIWOO/WOO
  `publicatiedatum` controls, staff review) — internal, never citizen-writable.
- `citizen-vote` create (casting an advisory vote) and any public-browse
  (unscoped list) surface — a separate later wave.
- DigiD / eHerkenning identity assurance — the creates stay at `minTrust: low`
  exactly like the read collections.

## Success Criteria

- `openspec validate portal-citizen-create-actions --strict` exits 0.
- The `citizen` manifest declares exactly `createReaction` and
  `createBudgetProposal` as `type: create` actions, each `minTrust: low`, with
  the field whitelists and server-stamped scope above; no other audience or
  collection gains a write action.
- A client-supplied `submitterId` / `submitter` / `moderationStatus` / `status`
  is ignored — the scope is stamped from `subjectRef` and the intake state from
  the server, closing write-IDOR (portaliq#16).
- `createReaction` is constrained to a parent consultation in `status: open`;
  `createBudgetProposal` to a parent participatory budget in `status:
  submission`.
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the unit suite pass
  on the new files with zero new violations.
