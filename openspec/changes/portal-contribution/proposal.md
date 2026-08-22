---
kind: code
tracking_issue: https://github.com/ConductionNL/decidiq/issues/113
---

# Proposal: portal-contribution

## Why

hydra ADR-046 establishes **portaliq** as the ONE shared external portal for
people WITHOUT a Nextcloud account, and its contribution contract v2.2 defines
how a domain app opts in: by shipping a single plain, dependency-free class at
the convention FQCN `OCA\{App}\Portal\PortalContributionProvider`, which
portaliq discovers and duck-types (`method_exists`, never `instanceof`). The
class is inert when portaliq is not installed (amendment A1).

Decidiq has one natural external audience that today has **no** self-service
surface: the **citizen** — a resident who submits a reaction to a public
consultation, an idea to an idea-box, a proposal to a participatory-budget
round, or an advisory vote, all WITHOUT a Nextcloud account. That is exactly the
"person without a Nextcloud account" portaliq exists for.

**Why now, without a broker:** DigiD/eHerkenning is DEFERRED. Citizens log in
through portaliq's ordinary password/`portalAccount` edge at trust `low` —
exactly like pipelinq's `client` / `customer` audiences. Decidiq's citizen data
is already portal-shaped: the scope fields `submitterId` (ConsultationReaction),
`voterId` (CitizenVote), `submitter` (BudgetProposal) and `recipientId`
(Notification) each hold "a Nextcloud UID OR an opaque pseudonymous token". For
an accountless portal citizen the value IS the pseudonymous token — which
portaliq derives as the subject's `subjectRef`. So every collection scopes by
the subject directly (`scopeField == subjectRef`, the default): no broker, no
BSN, no claim indirection. This is why the slice can ship today.

Tracking issue: Conduction/decidiq#113 (referenced, not closed).

## What

Ship one plain class `lib/Portal/PortalContributionProvider.php` (no portaliq
import, no `implements`, no info.xml dependency, no constructor) that returns a
pure-data manifest for the `citizen` audience over register `decidiq`:

1. **`citizenReactions`** — read `consultation-reaction`, scoped by
   `submitterId` (default subjectRef), projected to the citizen's own content +
   own-submission status; the staff `moderationReason` and the WOO/DIWOO
   moderator publication controls are dropped.
2. **`citizenVotes`** — read `citizen-vote`, scoped by `voterId`.
3. **`citizenBudgetProposals`** — read `budget-proposal`, scoped by `submitter`.
4. **`citizenNotifications`** — `kind: 'inbox'` read `notification`, scoped by
   `recipientId`.

Every collection is gated at `minTrust: low` (password edge; design.md documents
the optional raise to `substantial` for votes once a broker lands) and ships an
explicit `fields` whitelist. `actions` and `notifications` are empty this wave.

No register JSON is modified: every scopeField and projected field already
exists on the shipped schemas at HEAD. No routes, controllers, services,
frontend or info.xml change.

## Capabilities

### Added Capabilities

- `portal-contribution`: Decidiq contributes a `citizen` read + inbox surface
  to portaliq via a plain, dependency-free provider class with server-side field
  projection and default pseudonymous-token (subjectRef) scoping (ADR-046 v2.2).

## Affected Projects

- [x] Project: `decidiq` — new `lib/Portal/PortalContributionProvider.php`, unit tests under `tests/Unit/Portal/`, this OpenSpec change. No register or runtime-wiring changes.
- Reference: `apps-extra/pipelinq` — multi-audience + field-projection + register-drift-pin reference (`client`/`customer` password-edge audiences).
- Reference: `apps-extra/docudesk` — read-only wave + field-projection + inbox-analysis reference.
- Reference: `hydra` ADR-046 (portaliq external portal, contribution contract v2.2).
- Runtime consumer: `apps-extra/portaliq` — discovers and renders the contribution when installed.

## Out of Scope

- Any portal UI, auth edge, inbox rendering, session or password edge — portaliq
  owns the entire external surface (ADR-046); Decidiq ships zero portal
  frontend.
- The **external board-member** audience — a separate later slice.
- DigiD/eHerkenning and any BSN-based scoping / credential broker — deferred; the
  citizen edge is portaliq's ordinary password login at trust `low`.
- **Citizen creates** (react to a consultation, submit a budget proposal) —
  deferred; the parent relation each needs has no writable scalar property (see
  design.md "Deferred creates").
- **Public consultation / participatory-budget browse + results lists** — these
  are non-per-subject public lists the per-subject reader cannot express safely;
  deferred to a public read surface (see design.md "Deferred public lists").
- Any change to `lib/Settings/decidesk_register.json` — the manifest reuses
  existing schema properties verified at HEAD.

## Success Criteria

- `openspec validate portal-contribution --strict` exits 0.
- `new PortalContributionProvider()` constructs with no dependencies; the class
  declares no `implements` clause and references no portaliq symbol.
- `getAudiences()` returns `['citizen']`; `getAudience()` returns `'citizen'`; a
  non-served audience yields `null`.
- Each read collection scopes to the subject (`scopeField == subjectRef`,
  default) and projects only the documented subject-safe fields; no staff,
  moderation or other-citizen column appears in any whitelist.
- The register-drift pin proves every scopeField and projected field exists on
  the shipped schema at HEAD.
- `composer phpcs`, `phpstan`, `psalm` and the unit suite pass on the new files
  with zero new violations.
