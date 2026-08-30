# Design: Citizen participation

## Context

The archived `p3-citizen-participation` change shipped six schemas (`CitizenVote`, `CitizenPanel`, `ParticipatoryBudget`, `BudgetProposal`, `PublicConsultation`, `Deliberation`) into `lib/Settings/decidesk_register.json` plus ADR-031 notification rules on two of them, but its spec was never promoted to `openspec/specs/` and its architecture predates three current conventions:

1. It defined ~20 app-local unauthenticated `/api/citizens/*` read endpoints — an app-local public portal. The current convention (and the WOO-transparency recommendation in FEATURE-REEVALUATION-2026-06-11) routes public read access through **OpenCatalogi / the OR published-predicate**.
2. It defined an app-local `Notification` engine with preference endpoints — superseded by the declarative `x-openregister-notifications` dialect (ADR-031).
3. It speced PDF/QR offline participation and citizen panels — far beyond what the App Store description promises today.

This change specs the capability honestly over what exists, at the scope the description promises: public consultations and participatory budgeting.

## Goals / Non-goals

- **Goal:** a canonical `citizen-participation` main spec with gate-16/19 traceability over the existing schemas.
- **Goal:** a defensible auth posture for public input (the only genuinely public write surface in decidesk).
- **Goal:** result publication that composes with the WOO/OpenCatalogi publication route rather than inventing a portal.
- **Non-goal:** citizen panels, deliberation threads, offline/QR forms (schemas stay dormant; spec coverage deferred until the features are actually scheduled).
- **Non-goal:** statutory voting changes — citizen votes are advisory and never touch quorum/secret-ballot/proxy machinery.

## Decisions

### D1 — Auth posture: NC-account by default, anonymous as per-consultation opt-in, moderation always

- **Submission of budget proposals and casting of votes ALWAYS require an authenticated NC account.** One-vote-per-citizen and one-proposal-attribution cannot be enforced without identity; this matches the p3 `CitizenVote.voterId` design.
- **Consultation reactions default to NC-account.** Staff may enable `anonymousReactionsAllowed: true` per consultation (Awb inspraak often legally requires open access). Anonymous intake goes through a single `#[PublicPage]` endpoint protected with `#[AnonRateLimit(limit: 5, period: 3600)]` plus the NC brute-force throttler; the payload is size-capped and stores **no PII** (no email/phone fields exist on the reaction schema — follow-up contact for anonymous submitters is explicitly unsupported).
- **Every reaction enters a moderation queue** (`moderationStatus: pending`). Only `approved` reactions count toward `submissionCount` and are eligible for publication. Authenticated reactions MAY be auto-approved when the consultation's `moderationPolicy` is `post-moderation`; anonymous reactions are ALWAYS pre-moderated.

### D2 — Reactions get a schema; counters stay derived

p3 modelled reactions only as a `submissionCount` integer on `PublicConsultation`. We add one `ConsultationReaction` schema (consultation relation, body text, `moderationStatus`, `submitterId` NC UID or `"anonymous:<token>"` pseudonym, `submittedAt`). `submissionCount` becomes a derived counter maintained on approval, never client-written. No app-local contact schema: identity is the NC UID; the NC addressbook abstraction covers any staff contact need (per the fleet "contact is a Nextcloud entity" rule).

### D3 — Budget voting reuses the voting-system machinery in advisory mode

The voting-system spec already owns tally calculation, majority rules, deadline/phase enforcement, and count-integrity verification. Citizen voting on `BudgetProposal` objects is an **advisory** application of that machinery: simple voor/tegen, one `CitizenVote` per citizen per proposal (duplicate detection on `voterId`+`motionId`-equivalent relation), tallies written atomically to `votesFor`/`votesAgainst`, ranking = votesFor descending with greedy allocation within `totalAmount`. Explicitly NOT reused: quorum (advisory votes have none), secret ballot, proxy, weighted/ranked methods (deferred). This lands as a **delta on the voting-system spec**, not a parallel citizen tally service.

### D4 — Publication via OpenCatalogi / OR published-predicate, not app-local pages

When staff transition a consultation to `results-published` (or set `resultsPublished: true` on a budget round):

1. The app writes a **result summary object** (consultation: reaction digest + staff response; budget: ranked proposals + allocation) and sets `publicatiedatum` on it via OR. The schema declares a public-group `authorization.read` rule matching `publicatiedatum <= $now`, so OR's RBAC engine makes the object readable on the anonymous published-predicate surface.
2. If OpenCatalogi is installed and a target catalog is configured for the governance body, the summary is routed into that catalog as a publication.
3. Approved reactions selected for publication get `publicatiedatum` individually (opt-in per reaction by the moderator, never blanket); the ConsultationReaction read rule additionally requires `moderationStatus: approved`.

**Note:** `@self.published` is deprecated and removed from OpenRegister; the live anonymous-publication model is the RBAC `publicatiedatum` predicate above. The earlier "magic-mapped objects cannot set the predicate" framing was a misdiagnosis — these are register-owned objects on the normal RBAC save path, where `publicatiedatum` is just a field written through the standard OR object API.

No decidesk-served anonymous pages or read APIs exist; without OpenCatalogi the predicate step still runs and the catalog step degrades with a staff-visible warning.

### D5 — Lifecycle stored on the existing enums; one rename

`PublicConsultation.status` enum becomes `draft | open | closed | results-published` (rename `summarised` → `results-published`, declarative schema version bump 0.1.0 → 0.2.0 with value migration — same pattern as the quorum/actionitem declarative migrations). `ParticipatoryBudget` keeps its richer `draft/submission/voting/tallying/closed` + `resultsPublished` flag; the spec maps "results-published" for budgets to `status: closed && resultsPublished: true`. A daily scheduled job auto-closes consultations past `submissionDeadline` (reuses the existing ADR-031 scheduled-trigger infrastructure cadence; the close itself is a small background job registered via `Application::register()` — the valid `IBootstrap` path, not the invalid `registerJob` pattern).

### D6 — Storage, RBAC, notifications: OpenRegister only

Objects live in the decidesk register; staff-vs-citizen authority is OR per-object RBAC (staff = governance-body roles already used elsewhere in decidesk; citizens get read on open rounds + create on intake types). Notifications are declarative ADR-031 rules in `decidesk_register.json` (two already exist: `consultationDeadline`, `budgetProposalVotingDeadline`; this change adds a moderation-queue rule on `ConsultationReaction`). No imperative dispatch, no app-local preference store.

## Risks

- **Anonymous intake abuse.** Mitigated by per-consultation opt-in, `#[AnonRateLimit]`, brute-force throttling, payload caps, and mandatory pre-moderation. Residual risk (distributed spam) is accepted; moderators can close intake at any time.
- **Published-predicate model.** RESOLVED: anonymous visibility uses the OR RBAC published-predicate — the published schemas declare a public-group `authorization.read` rule matching `publicatiedatum <= $now`, and publication sets `publicatiedatum` (a normal field) via the standard OR object API. The earlier "magic-mapped objects can't set the predicate" risk was a misdiagnosis (these are register-owned objects on the normal RBAC save path; `@self.published` is deprecated/removed). No app-local public page is needed or used.
- **Enum rename touches existing data.** `summarised` is believed unused in production (feature was never implemented); the declarative migration still maps values defensively.
- **Vote-count race on tallies.** Tally writes go through the voting machinery's atomic update path (same integrity requirement the voting-system spec already imposes), not read-modify-write in the controller.
