# Tasks: Public publication via OpenCatalogi

## 1. Schemas + configuration (decidesk_register.json)
- [ ] 1.1 Add `PublicationRecord` schema (source object reference + type, payload object reference, payload version, `oriType`, catalog publication reference, `publishedBy`, `publishedAt`, `withdrawnAt`, `withdrawReason`, `rectifiesVersion`, catalog-retraction status; `hardDelete: false`) with an ADR-031 `x-openregister-notifications` rule (created → body members; updated covers withdraw).
- [ ] 1.2 Add publication payload schemas (DecisionPublication / AgendaPublication / MinutesPublication or one polymorphic PublicationPayload with `oriType`) carrying the ORI-mapped field sets from the spec — allow-list construction, no UID/contact fields present in the schema at all.
- [ ] 1.3 Document the spec-governed semantics of `Decision.isPublished`/`publishedAt` (flow-owned) and `Meeting.isPublic` (staff intent flag) in the register JSON descriptions; no structural change to those fields.
- [ ] 1.4 Verify all rules still pass the notification-dialect gate after the edits.

## 2. Eligibility + payload backend (lib/)
- [ ] 2.1 Verify on the deployed OR version that `@self.published` can be set via the OR object API for decidesk register objects (known magic-mapper gap — if blocked, raise an OR issue and gate this phase on it; NO app-local public page fallback). Shared finding with citizen-participation task 4.1 — coordinate.
- [ ] 2.2 `PublicationEligibilityService`: server-side gates (decision `decided|enacted`; agenda `Meeting.isPublic` + convocation sent; minutes lifecycle `approved`) and the structural type deny-list (board-governance family, Vote, VotingRound, confidential Resolution) evaluated before eligibility.
- [ ] 2.3 `PublicationPayloadService`: field-by-field allow-list payload construction per type (decision totals never voters; agenda confidential-item strip; minutes attendance per body policy), ORI mapping (`oriType` + ORI field names), immutability of created payloads.
- [ ] 2.4 Server-side guard rejecting direct client writes to `Decision.isPublished`/`publishedAt` (same derived-field pattern as citizen-participation `submissionCount`).
- [ ] 2.5 Publish/withdraw/rectify endpoints (staff RBAC guard per method — no `#[NoAdminRequired]` without a per-object guard, per the no-admin-idor gate); withdraw requires a reason; rectify = new version + withdraw old in one operation; audit-trail entries on the source decision. Routes in `appinfo/routes.php` for these actions ONLY — plain CRUD stays on the OR object API per ADR-022 (run the redundant-controller gate).
- [ ] 2.6 OpenCatalogi routing: create/retract publications in the per-body target catalog; store the catalog reference on `PublicationRecord`; degrade with a staff-visible warning when OpenCatalogi is absent; surface + retry failed retractions (never silent success).

## 3. Frontend
- [ ] 3.1 Publish/withdraw actions on decision, meeting (agenda), and minutes detail views, visible only when eligible; withdraw-reason and rectify confirmation dialogs in `src/modals/` (modal-isolation gate); i18n keys in English source, nl + en translations.
- [ ] 3.2 "Published" overview list per governance body (payload version, catalog status, withdraw/rectify history) via `useObjectStore` against the OR object API.
- [ ] 3.3 `prompt-on-transition` policy: non-blocking publish prompt when a decision reaches `enacted` for bodies configured so; dismissal never publishes.
- [ ] 3.4 Admin settings: per-body target catalog, per-type publication policy, attendance-rendering policy — via IInitialState/loadState, rendered by the NC settings framework, NOT added to the vue-router (admin-router gate).

## 4. Tests + verification
- [ ] 4.1 PHPUnit: eligibility matrix per type, deny-list refusal, payload allow-list shape (decision totals only, agenda confidential strip, attendance policies), flow-owned field guard, withdraw/rectify versioning, catalog-retraction failure branch.
- [ ] 4.2 Newman (`tests/integration/`): publish/withdraw 403 for non-staff (IDOR), eligibility 4xx contract, published-predicate read of each payload type with negative PII assertions (no voter IDs/UIDs), `oriType` + ORI field shape assertions, payload immutability, direct `isPublished` write rejection, negative routing assertion (no unauthenticated read on app routes).
- [ ] 4.3 Playwright (UI only, per the Playwright-UI/Newman-API split): staff publishes an enacted decision; publish action absent on a draft; mixed agenda publish shows the stripped result; withdraw with reason; rectify flow; OpenCatalogi-absent warning; admin configures catalog + policy; prompt-on-transition appears and dismisses without publishing. Annotate for gate-19 (API/backend excludes already inline in the spec deltas).
- [ ] 4.4 Run hydra gates (notification-dialect, no-admin-idor, redundant-controller, route-auth/reachability, spec-coverage with `@spec` tags on all new methods, e2e-coverage) and `composer check:strict`; fix anything pre-existing that the touched files surface.
- [ ] 4.5 Live verify against the dev container: publish a decision end-to-end and read it anonymously via the OR/OpenCatalogi surface; withdraw it; verify the predicate clears; bump `appinfo/info.xml` version (immutable-cache bust).

## 5. Docs + follow-ups
- [ ] 5.1 Update docs/intro + feature docs: the public decision register / agenda / minutes publication story, the WOO positioning, and the explicit no-app-local-portal posture.
- [ ] 5.2 File follow-up issues: ORI/OAI-PMH harvester feed via OpenConnector over the published payloads; import from incumbent systems (Notubiz/iBabs/GO) as OpenConnector source connectors; alignment review with `citizen-participation` publication code for a shared `PublicationService` base.
