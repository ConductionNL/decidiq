# Design: constituency-consultation

## Architecture Overview

Thin-client extension (ADR-022/ADR-037). Two new OpenRegister schemas — `MemberConsultation` and `MemberConsultationResponse` — ship as one `lib/Settings/register.d/48-constituency-consultation.json` fragment (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; the base file is never edited; fragment number 48 is assigned to this change, 40–47/49–65 belong to sibling changes). Lifecycle and notifications are declared in OpenRegister dialects; UI is manifest-v2 pages in `src/manifest.d/constituency-consultation.json` rendered by `CnPageRenderer` (frontend talks to `/apps/openregister/api/objects` via the shared object stores — no decidesk CRUD controllers, per the redundant-controller gate).

Imperative code is limited to the three places declarative dialects genuinely cannot reach:

1. `ConsultationAudienceService` — resolves the audience (active Memberships of a body, party-filtered fractie subset, or NC group members via `IGroupManager`).
2. `ConsultationResponseService` — the guarded response intake/edit path (audience check, respond-once, window enforcement over stale status).
3. `ConsultationSummaryService` — computes the identity-free results summary on the `gesloten → verwerkt` transition.

Cross-references, not duplication: `MemberConsultation.agendaItem` → AgendaItem and `MemberConsultation.decision` → Decision are plain relations; the meeting-context input section is a reverse lookup (`fetchUsed`) on those relations. Nothing in `voting-system`, `preferential-ballot`, or `citizen-participation` is touched.

## Decisions

### D1: Own schemas, not a reuse of PublicConsultation or VotingRound

`PublicConsultation` (citizen-participation) is a staff-run round with a moderation queue, anonymous-public intake, and OpenCatalogi publication — none of which apply to an authenticated member poll, and reusing it would blur the public/member boundary the market gap exists on. `VotingRound` is a formal ballot with tallies, quorum, and legal effect — exactly what a raadpleging must never be (REQ-CCO-005). Distinct schemas (`MemberConsultation`/`MemberConsultationResponse`) make the boundary structural, mirroring how the toezeggingen register refused to be a Decision subtype or a VTODO.

**Alternative considered:** `PublicConsultation` with an `audience` field — rejected: incompatible lifecycle (`draft → open → closed → results-published` vs `concept → open → gesloten → verwerkt`), incompatible auth posture (public intake endpoint vs members-only), and the citizen capability owns that schema.

### D2: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Consultation status workflow (`concept → open → gesloten → verwerkt`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Audience notified on open | `x-openregister-notifications` transition trigger, nl/en subjects | ADR-031 default; notification-dialect gate (gate-18) hard-fails imperative dispatch |
| Closing-soon reminder to non-responders | `x-openregister-notifications` scheduled trigger (filter: lifecycle `open`, `closesAt` within window, recipient has no response) | Same; no bespoke ReminderJob |
| Index/detail pages, columns, quick filters, meeting-context section | Manifest-v2 fragment pages + reverse-lookup section | Declarative like every existing page |
| Audience resolution (active Membership / party subset / NC group) | **Imperative** — `ConsultationAudienceService` | Membership-window and group evaluation against a dynamic audience is not expressible as an RBAC rule or dialect |
| Response intake guard (audience, respond-once, window-over-stale-status) | **Imperative** — `ConsultationResponseService` | Cross-object uniqueness + time-window + dynamic-audience checks exceed schema validation and RBAC predicates |
| Results summary computation | **Imperative** — `ConsultationSummaryService` on `gesloten → verwerkt` | Aggregation over child objects into a parent property; no dialect builds derived payloads |

If the closing-soon "recipient has not responded" filter proves inexpressible in the notification dialect's filter DSL, fallback: the closing-soon trigger notifies the whole audience (documented, not silent) — never an imperative dispatch path.

### D3: Fractie audience = `Membership.party` today, `Fractie` schema later

`person-and-membership` already carries `party` on the Membership; filtering active memberships of `audienceBody` on `audienceParty` gives a working fractie audience with zero new dependencies. The sibling change `fractievoorzitter-fractie-koppeling` introduces a first-class `Fractie` schema; when it lands, the fractie audience can gain an optional `audienceFractie` reference in a follow-up — additive, no migration (the party string keeps working).

**Alternative considered:** hard-depend on the Fractie schema — rejected: creates cross-change coupling in the same wave and blocks the 1115-demand use case on an unrelated change's landing order.

### D4: Anonymity is display-level, honestly labelled

`respondentId` is always stored: respond-once and edit-own-response are hard requirements and need identity linkage. `anonymousResponses: true` therefore governs *display and summary only* — no view, list, export, or summary renders identities. This is stated in the UI copy ("antwoorden worden niet op naam getoond") rather than over-promising at-rest anonymity. No writeOnly marking is used on `respondentId` (the writeOnly render boundary has two strip paths and nested-path pitfalls; display-level filtering in the response section + identity-free summary construction is the simpler, testable contract here — PHPUnit asserts no UID appears in `results`, Playwright asserts no name in the anonymous response view).

**Alternative considered:** pseudonymous token like citizen-participation's anonymous intake — rejected: members must be able to *edit* their response and the initiator must see response *counts* against a known audience; a token adds machinery without adding real anonymity against the server.

### D5: Summary lives on the consultation; the meeting context reads it via reverse lookup

The `results` property on `MemberConsultation` is the single artifact (audience size, response count, per-option counts, optional `openTextDigest`). Agenda-item and decision detail pages gain a "Raadpleging (niet-bindend)" section that reverse-looks-up consultations relating to them — no new schema, no denormalised copy on the agenda item, and the summary stays live in one place. `results` is server-written only (summary step); client writes to it are rejected.

**Alternative considered:** a separate `ConsultationSummary` object attached to the agenda item — rejected: a second object to keep consistent with its parent lifecycle for zero query benefit (the reverse lookup is one relation query either way).

## Nextcloud Integration

- Controllers: one thin `ConsultationController` (respond/edit-response + summary-transition actions), `#[NoAdminRequired]` with per-object guards in the method bodies (no-admin-idor gate); registered in `appinfo/routes.php` (route-reachability gate). No CRUD pass-throughs — consultation CRUD goes through the OR object API from the manifest pages.
- Services: `ConsultationAudienceService` (Membership queries via ObjectService — one `$config` array signature — plus `IGroupManager` for `nc-group`), `ConsultationResponseService`, `ConsultationSummaryService`. All saves carry ALL fields forward (OR `saveObject` is PUT-semantic).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — lifecycle and notifications are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; respond surface uses standard NC components (`NcSelect` with `inputLabel`, modals in `src/modals/` per modal-isolation gate).

## Security Considerations

- **Audience enforcement (high):** audience is resolved server-side on every response write; never from the client. Newman tests submit as a non-audience authenticated user and assert 403 (IDOR class).
- **No public surface:** neither schema declares a public-group read rule; no `#[PublicPage]` routes; the only surfaces are authenticated manifest pages and guarded controller actions. Explicit negative scenario in REQ-CCO-005.
- **Non-binding boundary:** the summary step writes only to `MemberConsultation.results`; it has no code path into VotingRound/Vote objects (orphan-capability and phantom-RPC gates keep it that way).
- **Response privacy:** members read only their own response objects (OR RBAC owner rule); the initiator's response section respects `anonymousResponses`; `results` is constructed identity-free (PHPUnit mutation-style test: flip the flag, assert the view flips — guarding against a no-op fake green).
- **CSRF/auth posture:** standard NC attributes on all controller methods; semantic-auth gate checked (annotation matches the in-body guard).

## File Structure

```
lib/Settings/register.d/48-constituency-consultation.json   (new — schemas + dialects + seed)
src/manifest.d/constituency-consultation.json               (new — index + detail pages, menu, meeting-context section)
lib/Service/ConsultationAudienceService.php                 (new — audience resolution)
lib/Service/ConsultationResponseService.php                 (new — guarded intake/edit)
lib/Service/ConsultationSummaryService.php                  (new — results summary)
lib/Controller/ConsultationController.php                   (new — respond/edit + verwerkt transition)
appinfo/routes.php                                          (edit — consultation routes)
tests/Unit/Service/...                                      (new — audience, guard, summary, anonymity)
tests/e2e/...                                               (new — scenario coverage per gate-19)
docs/features/achterbanraadpleging.md                       (new)
```

## Seed Data

Realistic Dutch examples (fictional municipality "Gemeente Voorbeeldingen" and a fictional OR); references use existing decidesk seed objects (council governance body, scheduled raadsvergadering, agenda items) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. Seeded via the fragment's `x-openregister.seedData` path (`@self`: register `decidesk`, schema slug per table).

### Schema: `member-consultation`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | raadpleging-fractie-parkeervisie | ledenraadpleging-alv-contributie | achterban-or-thuiswerkregeling |
| question | "Steunt de fractie het raadsvoorstel Parkeervisie 2026–2030 in de huidige vorm?" | "Welke contributieverhoging heeft uw voorkeur voor 2027?" | "Wat vindt de achterban van de voorgestelde thuiswerkregeling (instemmingsaanvraag art. 27 WOR)?" |
| description | "Niet-bindende peiling ter voorbereiding op de raadsvergadering van 12 maart." | "Peiling onder de leden ter voorbereiding op de ALV; het bestuur legt het definitieve voorstel voor aan de vergadering." | "Informele raadpleging van de achterban vóór het OR-besluit over de instemmingsaanvraag." |
| responseType | single-choice | multi-choice | open-text |
| choiceOptions | ["Voor", "Tegen", "Voor, mits aangepast"] | ["Geen verhoging", "+2%", "+5%", "Gedifferentieerd naar leeftijd"] | — |
| audienceType | fractie | body-members | nc-group |
| audienceBody | (seed gemeenteraad body ref, nil-UUID placeholder) | (seed vereniging/ALV body ref, nil-UUID placeholder) | — |
| audienceParty | "Groen Voorbeeldingen" | — | — |
| audienceGroup | — | — | or-achterban-acme |
| agendaItem | (seed agenda item ref, nil-UUID placeholder) | (seed agenda item ref, nil-UUID placeholder) | — |
| decision | — | — | (seed decision ref, nil-UUID placeholder) |
| opensAt | 2026-02-20T09:00:00Z | 2026-03-01T09:00:00Z | 2026-02-01T09:00:00Z |
| closesAt | 2026-03-10T17:00:00Z | 2026-04-01T17:00:00Z | 2026-02-15T17:00:00Z |
| anonymousResponses | false | true | true |
| lifecycle | open | concept | verwerkt |
| results | — | — | audience 12, responses 9, openTextDigest: "Meerderheid positief; zorgen over bereikbaarheid van de servicedesk en over ongelijkheid tussen kantoor- en productiefuncties." |

Object 1 gets a `closesAt` in the near future at seed time so the closing-soon notification path and the respond surface are demoable on a fresh install; object 3 is `verwerkt` so the meeting-context summary section is non-empty on install (ADR-016 testability).

**Related items per object:**
- Files: concept-parkeervisie PDF on object 1's agenda item (existing Files leaf); none on 2–3.
- Notes/Tasks/Contacts: none (follow-up work is out of scope for a raadpleging; a resulting internal task would be a VTODO via the existing action-item flow).

### Schema: `member-consultation-response`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | respons-parkeervisie-lid-1 | respons-parkeervisie-lid-2 | respons-or-achterban-1 |
| consultation | raadpleging-fractie-parkeervisie (nil-UUID placeholder) | raadpleging-fractie-parkeervisie (nil-UUID placeholder) | achterban-or-thuiswerkregeling (nil-UUID placeholder) |
| respondentId | (seed NC user, e.g. `jvandenberg`) | (seed NC user, e.g. `mjansen`) | (seed NC user, e.g. `pdevries`) |
| choices | ["Voor, mits aangepast"] | ["Tegen"] | — |
| openText | "Mits de parkeernorm voor de binnenstad wordt verlaagd." | — | "Positief, maar de regeling moet ook voor de productieploegen een alternatief bieden." |
| submittedAt | 2026-02-21T10:15:00Z | 2026-02-22T08:40:00Z | 2026-02-03T12:05:00Z |

Two responses on the open object 1 make the initiator's progress view ("2 van N gereageerd") non-trivial on install; object 3's response backs the seeded `verwerkt` summary.

## Migration Plan

1. Land register.d fragment 48 + manifest.d fragment, services, controller/routes, seed data, tests, docs in one decidesk PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schemas on upgrade).
2. No landing-order dependency on any sibling change: the fractie audience uses `Membership.party` (already live), and `works-council-consultation` references this change (not the reverse).
3. Rollback: revert the PR — fragments disappear, pages unregister, routes/services vanish. Existing consultation/response objects remain soft-retained in OR. No data migration to undo.

## Risks / Trade-offs

- [Raadpleging read as a formal vote] → structural boundary (no code path into VotingRound/Vote) + mandatory "niet-bindende raadpleging" label asserted by Playwright on all four surfaces.
- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`member-consultation`, `member-consultation-response`), never PascalCase.
- [Closing-soon "non-responder" filter unsupported by the notification DSL] → documented fallback in D2 (notify whole audience); never a silent gap and never an imperative dispatch.
- [Anonymity over-promise] → D4: display-level anonymity, honest UI copy, mutation-style tests on the flag.
- [Audience snapshot vs live membership] → audience is evaluated live at each response write and at summary time (audience size at `verwerkt`); a membership ending mid-window simply loses respond access — documented behaviour, matching `person-and-membership` REQ-PMB-002 semantics.
- [`results` written by clients] → server-written-only rule enforced in the summary path and covered by a Newman negative test.

## Open Questions

- Closing-soon window (provisional 48h) — griffie/bestuur-configurable tuning deferred to a future admin-settings change.
- Notification-dialect filter expressiveness for "recipient has not responded" (see D2 fallback) — verify against the OR notification rule engine during apply.
