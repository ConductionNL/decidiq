# Design: portal-contribution

## Context

hydra ADR-046 defines **portaliq** as the ONE shared external portal for people
without a Nextcloud account. Contribution contract v2.2: an app contributes via
a single plain class at the convention FQCN
`OCA\{App}\Portal\PortalContributionProvider`, duck-typed by portaliq
(`method_exists()`, never `instanceof`) — inert without portaliq installed
(amendment A1). Decidesk ships that one class; nothing else in the runtime app
is touched.

All register facts below were verified against HEAD
(`lib/Settings/decidesk_register.json`, 34 schemas). Decidesk uses **one**
OpenRegister register, `decidesk`, and the citizen portal reads four schemas
from it:

| Register slug | Schema (slug at HEAD) | Role |
|---|---|---|
| `decidesk` | `consultation-reaction` | Citizen's own consultation reaction / idea |
| `decidesk` | `citizen-vote` | Citizen's own advisory vote |
| `decidesk` | `budget-proposal` | Citizen's own participatory-budget proposal |
| `decidesk` | `notification` | Citizen's own notification inbox |

The manifest is an explicit **allowlist**: every register/schema not named below
is out of portal scope by default.

## The authentication decision — password edge, no broker

DigiD/eHerkenning is **deferred**. Citizens authenticate at portaliq's ordinary
password/`portalAccount` edge, at trust `low`, exactly like pipelinq's `client`
/ `customer` audiences. There is **no credential broker and no BSN** in this
slice. The rationale is that Decidesk's citizen data is already portal-shaped and
subject-keyed by a pseudonymous token (below), so scoping needs neither a broker
nor a national identity number.

## Pseudonymous-token scoping (why the DEFAULT subjectRef, not a claim)

Every citizen scope field on the four schemas is documented as **"a Nextcloud UID
OR an opaque pseudonymous token"**:

| Schema | Scope property | HEAD description (verbatim, abridged) |
|---|---|---|
| `consultation-reaction` | `submitterId` | "Nextcloud UID for authenticated submissions, or an opaque pseudonymous token for anonymous submissions. Never a name, email or other contact detail." |
| `citizen-vote` | `voterId` | "Stable identifier for the citizen voter (Nextcloud UID or pseudonymous …)" |
| `budget-proposal` | `submitter` | "Nextcloud UID or pseudonymous identifier of the citizen submitter." |
| `notification` | `recipientId` | "Nextcloud UID or stable pseudonymous identifier of the recipient." |

For an accountless portal citizen the value stored is the **pseudonymous token** —
and portaliq's auth edge derives exactly that token as the subject's
`subjectRef`. The record's own scope field therefore already equals the subject
ref, so each collection scopes by the **default** (`scopeField == subjectRef`, no
`scopeClaim`). This is the contract's "DEFAULT (omit scopeClaim) scopes by the
subject's own subjectRef — use the default where the record's scope field IS the
pseudonymous subject token" case, verbatim.

**Reader fit (verified against `portaliq/lib/Service/PortalObjectReader.php` +
`ContributionController.php` at HEAD):** the reader filters
`$filters[$scopeField] = $subjectRef` and re-checks every row
(`verifyScope`: `(string) $row[$scopeField] !== $subjectRef` ⇒ dropped). The
controller passes `subjectRef: $subject['subjectRef']` for both the read and (if
any) the create. Because this slice uses only default subjectRef scoping, it
needs **no** `scopeClaim` resolution and **no** `via`-join support — it works
against the deployed reader as-is, and the per-row re-check is the security
boundary.

## Scoping map (audience → schema → scopeField → claim → kind → minTrust)

| Audience | Collection id | Register | Schema | scopeField | scopeClaim | kind | minTrust |
|---|---|---|---|---|---|---|---|
| `citizen` | `citizenReactions` | `decidesk` | `consultation-reaction` | `submitterId` | *(default subjectRef)* | — | `low` |
| `citizen` | `citizenVotes` | `decidesk` | `citizen-vote` | `voterId` | *(default subjectRef)* | — | `low` |
| `citizen` | `citizenBudgetProposals` | `decidesk` | `budget-proposal` | `submitter` | *(default subjectRef)* | — | `low` |
| `citizen` | `citizenNotifications` | `decidesk` | `notification` | `recipientId` | *(default subjectRef)* | `inbox` | `low` |

### minTrust decisions

- **All four collections = `low`.** The citizen edge is portaliq's password
  login (DigiD/eHerkenning deferred), so the whole surface sits at trust `low`.
  Each surface is the citizen's **own** benign participation data (their own
  reactions, votes, proposals, notifications) — no other-party and no
  special-category data — so a password-verified citizen may see their own
  records.
- **Optional future raise for `citizenVotes` → `substantial`.** An advisory vote
  is the most identity-sensitive of the four (it links a stance to a subject). If
  and when the DigiD/eHerkenning broker lands, `citizenVotes` may be raised to
  `minTrust: substantial` so casting/vote-history disclosure sits above
  eIDAS-substantial assurance. It ships at `low` now because the password edge is
  the only edge available and under-gating would make votes invisible; the raise
  is a one-line manifest change gated on the broker.

## Field whitelists (read-side projection — every field justified subject-safe)

portaliq projects each verified row down to the collection's `fields` whitelist
**after** per-row verification (identifiers always survive; a malformed
declaration degrades to identifiers-only). Every field below exists on the schema
at HEAD (pinned by `testManifestMatchesShippedRegisterSchemas`).

### `citizenReactions` → `consultation-reaction` (6 fields)

| Field | Why subject-safe |
|---|---|
| `body` | The citizen's own reaction / idea text. |
| `submittedAt` | When the citizen themselves submitted. |
| `moderationStatus` | The moderation **state of the citizen's own reaction** (`pending`/`approved`/`rejected`) — self-service transparency on their own submission, not another party's data (cf. docudesk exposing `consentStatus`). |
| `voteCount` | The number of citizen votes the citizen's **own** idea/proposal received — a public aggregate on their own record. |
| `proposalTitle` | The citizen's own budget-proposal-style reaction title (participatory-budget type). |
| `proposalAmount` | The citizen's own budget-proposal-style reaction amount. |

### `citizenVotes` → `citizen-vote` (8 fields)

| Field | Why subject-safe |
|---|---|
| `voteValue` | The citizen's own vote value. |
| `motionId` | The motion the citizen's own vote applies to — reference to what they voted on. |
| `proposalId` | The budget proposal the citizen's own advisory vote applies to. |
| `citizenPanelId` | The citizen panel the citizen themselves represents (their own membership). |
| `weight` | The citizen's own vote weight. |
| `isProxy` | Whether the citizen's own vote was cast via proxy. |
| `castAt` | When the citizen themselves cast the vote. |
| `notes` | The citizen's own reasoning attached to the vote. |

### `citizenBudgetProposals` → `budget-proposal` (7 fields)

| Field | Why subject-safe |
|---|---|
| `title` | The citizen's own proposal title. |
| `description` | The citizen's own proposal description. |
| `requestedAmount` | The amount the citizen's own proposal requests. |
| `category` | The citizen's own proposal category. |
| `status` | The lifecycle status of the citizen's **own** proposal (`submitted`…`awarded`/`rejected`) — self-service transparency on their own submission. |
| `votesFor` | Public vote tally in favour of the citizen's own proposal. |
| `votesAgainst` | Public vote tally against the citizen's own proposal. |

### `citizenNotifications` → `notification` (7 fields)

| Field | Why subject-safe |
|---|---|
| `type` | The event type of the citizen's own notification. |
| `subject` | The subject line addressed to the citizen. |
| `content` | The body addressed to the citizen. |
| `channel` | The delivery channel of the citizen's own notification. |
| `status` | The delivery state of the citizen's own notification. |
| `sentAt` | When the citizen's own notification was sent. |
| `readAt` | When the citizen read their own notification. |

## Exclusions (every dropped column / schema, with reason)

### `consultation-reaction` — excluded (4 of 10 properties)

| Field | Reason excluded |
|---|---|
| `submitterId` | The scope field itself — portaliq preserves identifiers separately; not projected content. |
| `moderationReason` | "Optional staff-supplied reason recorded on approval or rejection" — staff-only free text. |
| `publicatiedatum` | WOO/DIWOO publication date, set by a moderator opt-in — a moderation/publication control, not citizen content. |
| `depublicatiedatum` | WOO/DIWOO depublication date, moderator-controlled — same reason. |

### `citizen-vote` — excluded (1 of 9 properties)

| Field | Reason excluded |
|---|---|
| `voterId` | The scope field itself — preserved as identifier, not projected content. (No staff/moderation column exists on this schema.) |

### `budget-proposal` — excluded (1 of 8 properties)

| Field | Reason excluded |
|---|---|
| `submitter` | The scope field itself — preserved as identifier, not projected content. (`status`, `votesFor`, `votesAgainst` are the citizen's own-proposal outcome/tallies and are intentionally kept.) |

### `notification` — excluded (1 of 8 properties)

| Field | Reason excluded |
|---|---|
| `recipientId` | The scope field itself — preserved as identifier, not projected content. |

### Whole schemas excluded from the citizen surface

| Schema (slug) | Reason excluded |
|---|---|
| `notification-preference` | Participant/governance **settings** object keyed by `person` ("Nextcloud UID or participant UUID") and carrying governance PII (`governanceEmail`, `urgentPhone`, `delegate`, `delegationFrom/Until`). It is a board-member/participant surface, not an accountless-citizen surface, and its key is not the pseudonymous subject token. |
| `public-consultation`, `participatory-budget` | Public, non-per-subject browse/results lists — deferred (below). |
| all governance/meeting/decision schemas (`meeting`, `decision`, `voting-round`, `citizen-panel`, `deliberation`, `engagement-record`, `conflict-of-interest`, `publication-record`, `publication-payload`, …) | Staff/governance internals or not per-subject; out of the citizen self-service scope. |

## Deferred creates (no create actions this wave)

The task asks for citizen creates on `consultation-reaction` and `budget-proposal`
with the subject's scope key server-stamped. Both are **deferred**, because
verification against HEAD found each create needs a parent link the flat
create-action `fields[]` vocabulary cannot express safely:

- **React to a consultation — deferred.** The scope key `submitterId` IS
  server-stampable (the writer stamps `data[scopeField] = subjectRef`, always
  winning over client input — no scope-IDOR). BUT the reaction→consultation link
  is an OpenRegister **relation** (`consultation-reaction`
  `x-openregister-relations.consultation` → `public-consultation`, many-to-one)
  with **no writable scalar property** (`consultationId` is absent from
  `properties`; contrast `citizen-vote`, which pairs its `motion` relation with a
  `motionId` scalar). The flat `fields[]` whitelist can only carry scalar
  properties, so a create could not attach the reaction to any consultation — it
  would produce an **orphaned** reaction (and fire the `reactionPendingModeration`
  moderator notification for an unattached record). Even if a scalar
  `consultationId` existed, it would be a client-supplied cross-reference the
  writer runs with `_rbac: false` and could not constrain to an **open/published**
  consultation (write-IDOR-adjacent, cf. portaliq#16). Deferred.
- **Submit a budget proposal — deferred.** Same shape: the scope key `submitter`
  is server-stampable, but the proposal→ParticipatoryBudget link
  (`budget-proposal` `x-openregister-relations.participatoryBudget`, many-to-one)
  has no writable scalar property (`participatoryBudgetId` absent), so a create
  would orphan the proposal from its budget round. Deferred.

**Unblocking follow-up:** add a scalar parent-ref property to each schema PLUS a
receiver-side guard that verifies the target parent is open/published, OR give
portaliq an endpoint-action vocabulary that verifies the parent server-side.
Recorded on Conduction/decidesk#113.

## Deferred public lists (no public browse/results surface this wave)

The task asks whether published consultation / participatory-budget **results**
can be a per-subject or cleanly-scoped read. Verified answer: **no — deferred.**
`public-consultation` and `participatory-budget` are public lists with **no
per-subject scope field**. portaliq's reader can only express a per-subject read:
with a non-empty `scopeField` it filters `scopeField == subjectRef` (nothing on a
public consultation equals a citizen), and with an **empty** `scopeField` it runs
with `_rbac: false` and returns **every** row of the schema — including drafts and
pre-publication rows with staff `resultsSummary` — a governance-internals leak.
There is thus no way to express a safely-filtered public list in the per-subject
reader. A public consultation/results browser belongs on a **different surface**
(a public read API that keeps OR's `publicatiedatum`/RBAC publication filters
on), not the "my own data" portal. Recorded on Conduction/decidesk#113.

## Declarative vs imperative

**Decision: fully declarative — a pure-data manifest, zero I/O.** The provider
branches only on `$subject['audience']` (server-derived per ADR-005) and returns
constants. Rejected alternatives:

- *Imperative provider* (query OR to tailor collections per subject): portaliq
  already scopes reads server-side and verifies per row; app-side queries would
  duplicate the authz path (ADR-022 violation) and add OR coupling to a class
  whose entire value is being dependency-free.
- *Reusing Decidesk's stores/services*: couples the contribution to services
  with constructor dependencies, breaking the duck-typed inertness guarantee.

Consequence: anything needing per-subject logic stays in portaliq; the manifest
stays audit-readable data. A *class* (not a JSON file) is the delivery vehicle
only because ADR-046 mandates FQCN discovery.

## Claim / scope contract (STABLE)

This slice ships **no** `scopeClaim`: the scope value is the subject's own
`subjectRef` (the pseudonymous token). The load-bearing contract with portaliq
operators is therefore only the pairing **"the `portalAccount` for a Decidesk
citizen carries, as its `subjectRef`, the same pseudonymous token that Decidesk
stamps into `submitterId` / `voterId` / `submitter` / `recipientId`."** If a
future edge (a broker) mints a different subject identifier than the token stored
on the record, this slice must switch to an explicit `scopeClaim` mapping that
identifier onto the token — a breaking change gated on that broker.

## Mixed-spec rationale

This change is `kind: code`: it ships a provider class + unit tests and makes
**no** register JSON edit, because every scopeField and projected field already
exists on the shipped schemas at HEAD (verified). There is therefore no
schema-version bump and no data migration; the register-drift pin test guards
against future drift.

## Seed Data

The provider performs no I/O, so this change creates **no** OpenRegister objects,
registers or schemas. Unit tests construct the provider directly (no container)
and feed a synthetic subject built on the **nil-UUID pattern** so fixtures are
self-evidently fake and can never collide with live data:

```php
$citizen = [
    'subjectRef'   => '00000000-0000-0000-0000-000000000001',
    'audience'     => 'citizen',
    'organisation' => '00000000-0000-0000-0000-000000000002',
    'trust'        => 'low',
];
```

Live-portal seeding (a `portalAccount` whose `subjectRef` is the citizen's
pseudonymous token) belongs to portaliq's own e2e environment, keyed by the scope
contract above.

## Security Considerations

- **Server-derived subject only** (ADR-005): the `$subject` array is built by
  portaliq's auth edge; the provider only *reads* `audience` to branch and never
  echoes subject data back or trusts client input.
- **Pseudonymous-token scoping, never NC uid** (A4): every collection scopes on
  the record's own subject token (== subjectRef), never a Nextcloud user id.
- **Fail-closed audience filter**: any audience other than `citizen` yields
  `null`.
- **Server-side projection**: every collection ships a `fields` whitelist;
  staff/moderation columns (`moderationReason`, WOO/DIWOO publication dates) are
  dropped by portaliq after per-row verification (fail-closed to
  identifiers-only on a malformed declaration).
- **No writes this wave**: both creates are deferred precisely because their
  parent link cannot be safely stamped, avoiding a write-IDOR-adjacent surface.
- **No secrets, tokens, routes or endpoints** in this change.

## Risks

- The scope contract (subjectRef == the record's pseudonymous token) is
  load-bearing once portaliq operators provision citizen accounts — hence the
  STABLE marker; a broker that mints a different subject identifier is a breaking
  change (switch to an explicit `scopeClaim`).
- If a future register edit adds a staff/moderation property to any of the four
  schemas, it is NOT auto-exposed (the whitelist is positive), but the exclusion
  tables above are the review checklist for register PRs touching these schemas.
- `citizenVotes` ships at `minTrust: low` because the password edge is the only
  edge available; the documented raise to `substantial` is gated on the
  DigiD/eHerkenning broker.
