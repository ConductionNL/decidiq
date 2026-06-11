# Proposal: Citizen participation (public consultations + participatory budgeting)

## Why

Citizen participation is one of the **five governance domains** advertised in decidesk's App Store description, README, and docs — yet it is the only domain with **zero spec coverage** (FEATURE-REEVALUATION-2026-06-11: "a direct promise-vs-spec hole and a gate-16/19 traceability blind spot").

The data model already exists: the archived `2026-05-11-p3-citizen-participation` change shipped six schemas into `lib/Settings/decidesk_register.json` (`CitizenVote`, `CitizenPanel`, `ParticipatoryBudget`, `BudgetProposal`, `PublicConsultation`, `Deliberation`), two of which already carry ADR-031 `x-openregister-notifications` rules. But the archived spec never landed as a main spec, and parts of its design predate current conventions: it specced ~20 app-local public `/api/citizens/*` endpoints (an app-local public portal — exactly the anti-pattern the WOO-transparency gap in the same report says must be routed through OpenCatalogi / the OR published-predicate instead) and an app-local `Notification` preference engine (superseded by the ADR-031 declarative dialect).

## What Changes

- **Create the canonical `citizen-participation` capability spec** over the existing p3 schemas — no new parallel data model.
- **Consultation lifecycle**: `draft → open → closed → results-published`, staff-driven transitions, deadline auto-close. The existing `PublicConsultation.status` enum value `summarised` is renamed to `results-published` via a declarative schema version bump.
- **Public submission of reactions/ideas** on open consultations, with an explicit auth posture: NC-account submission by default; **anonymous submission only when staff enables it per consultation**, protected by `#[AnonRateLimit]` brute-force throttling, and **always routed through a moderation queue** (pending → approved/rejected) before a reaction counts or publishes. A new small `ConsultationReaction` schema is added (the only schema addition — p3 modelled reactions only as a `submissionCount` integer).
- **Participatory budgeting**: proposal submission during the submission phase, staff validation, and **advisory citizen voting that reuses the voting-system machinery** (tally engine, deadline enforcement, one-vote-per-citizen integrity) — extended via a voting-system spec delta, not duplicated.
- **Result publication via OpenCatalogi / OR published-predicate**: when a round reaches `results-published`, the result summary objects get `@self.published` set and are routed to the configured OpenCatalogi catalog. **No app-local anonymous portal pages or `/api/citizens/*` read endpoints** — anonymous read access happens through OpenCatalogi's existing publication surface.
- **Admin configuration of participation rounds**: staff create/configure rounds (deadlines, anonymous-reactions toggle, moderation policy, target catalog) through in-app staff views; round defaults live in the existing admin settings surface.

## Capabilities

### New Capabilities

- `citizen-participation`: public consultations (lifecycle, reaction intake with moderation, result publication) and participatory budgeting (proposal submission, advisory voting, allocation results) over the existing p3 OpenRegister schemas.

### Modified Capabilities

- `voting-system`: ADDED requirement — advisory citizen voting on budget proposals reuses the existing tally/majority machinery in an "advisory" mode (no quorum, no secret ballot, no proxy), instead of a parallel citizen-vote tally implementation.

## Impact

- **Schemas**: existing `PublicConsultation`, `ParticipatoryBudget`, `BudgetProposal`, `CitizenVote` (reused as-is, except the `summarised → results-published` enum rename); new `ConsultationReaction` schema in `decidesk_register.json`. `CitizenPanel` / `Deliberation` stay dormant (out of scope — no requirements yet, explicitly deferred).
- **Storage / RBAC / notifications**: all from OpenRegister — objects in the decidesk register, per-object authorization via OR RBAC, notifications via the ADR-031 `x-openregister-notifications` dialect (rules already present on `PublicConsultation` and `BudgetProposal`; one added for `ConsultationReaction` moderation). No app-local notification engine; the p3 `Notification` schema is NOT given a spec home and is flagged for retirement in a follow-up.
- **No app-local contact schema**: submitters are NC UIDs (or a pseudonymous token for anonymous reactions); any staff need for contact details goes through the NC addressbook abstraction, never a decidesk schema.
- **Backend**: thin intake/moderation/transition endpoints only (reaction intake, moderation actions, lifecycle transitions, publication trigger). Plain object CRUD stays on `useObjectStore` → `/apps/openregister/api/objects` per ADR-022 (no redundant pass-through controllers).
- **Frontend**: staff views for consultations and budget rounds (list/detail/moderation queue), citizen-facing in-app participation views for NC-account users. No public Vue pages.
- **Dependency**: OpenCatalogi (publication surface); OpenRegister published-predicate. Graceful degradation: without OpenCatalogi, `results-published` still sets `@self.published`; the catalog routing step is skipped with a staff-visible warning.
- **Out of scope**: citizen panels, deliberation threads, offline/QR paper forms, ORI export of participation data (companion `publish-decisions-via-opencatalogi` proposal), Notification-schema retirement.
