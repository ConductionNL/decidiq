# Design: model-debt-cleanup-schema

## Context

decidesk's OpenRegister schema register is built from a base file
(`lib/Settings/decidesk_register.json`, 39 top-level schemas) plus modular
fragments under `lib/Settings/register.d/*.json` (ADR-037), deep-merged in
filename order by `SettingsService::mergeRegisterFragments()` at settings-load
time and handed to `ConfigurationService::importFromApp()` (version-gated by a
hash of the merged fragment signature, so a fragment edit alone triggers
re-import). This change is a sweep of six previously-identified schema-model
defects across that register — an undeclared core join, two references that
survived their own shim's deprecation notice, a duplicate proxy schema, a
missing convenience property, and two non-kebab-case slugs. It is the
declarative half of a chained pair; `model-debt-cleanup-code`
(`depends_on: [model-debt-cleanup-schema]`) carries every PHP/Vue change and
every live-data repair step this schema change's retargets require.

## Goals / Non-Goals

**Goals:**
- Declare the `Decision` ↔ `Meeting`/`AgendaItem` join that two Vue tabs already write silently.
- Retarget the two `$ref: Participant` uses that contradict `Participant`'s own deprecation notice.
- Fold `BoardProxy` into `ProxyAuthorization` at the schema level (approval-state property + retirement flag).
- Close the `GoverningDocument.currentEffectiveDate` gap `register-detail-optimisation` already flagged and scoped out.
- Bring `adviceRequest`/`proxyAuthorization` in line with the fleet's kebab-case slug convention.

**Non-Goals:**
- No PHP/Vue/TS edit of any kind (that is `model-debt-cleanup-code`).
- No live-data migration of existing rows (also the code chain — schema declaration and data repair are deliberately sequenced by `depends_on`).
- No deletion of the `Participant` schema (see Decision 2).
- No fold of `ConflictOfInterest` into the interests-and-integrity models (see Decision 3).
- No wiring of `GoverningDocument.currentEffectiveDate` into the register-detail index UI (separate follow-up, per `register-detail-optimisation`'s own scoped-out note).

## Decisions

### Decision 1: Fragment overrides for object-shaped properties; direct edits for the two list-valued renames

`SettingsService::deepMergeConfig()` (lib/Service/SettingsService.php:439) unions
object keys recursively — an overlay fragment declaring `ConflictOfInterest.properties.boardMember`
replaces exactly that nested value, leaving every sibling property untouched, the
same mechanism `55-governing-documents-register.json` already uses to add
`Decision.citesGoverningDocuments` onto the base-file `Decision` schema. But
list-valued keys **concatenate** rather than merge-by-identity: an overlay
`components.registers.decidesk.schemas` array would *append* `advice-request`
alongside the still-present `adviceRequest` rather than rename it in place.
Consequence: every property-level change in this proposal (Decision's two new
refs, ConflictOfInterest's retarget, ProxyAuthorization's retarget + new field,
BoardProxy's retirement flag, GoverningDocument's new property, Participant's
narrowed description) goes through a new fragment
(`lib/Settings/register.d/67-model-debt-cleanup.json`) or a direct edit to a
fragment the change already owns (`60-advisory-opinion-workflow.json`,
`63-member-proxy-authorization.json`, `55-governing-documents-register.json`).
The two slug renames in `components.registers.decidesk.schemas` are direct,
surgical string edits to `decidesk_register.json` — the one place in this
change that touches the base monolith directly, because the fragment mechanism
cannot express a rename on that specific key shape. `tests/Unit/RegisterJsonTest.php`
was checked and confirmed to read only `components.schemas` from the raw base
file — it never touches `components.registers` — so this direct edit carries
zero PHPUnit risk.

### Decision 2: Keep the Participant schema; narrow its documented consumer set instead of deleting it

Grepped every `$ref: Participant` in the register (4 hits): `Vote.participant`
(decidesk_register.json), `EngagementRecord.participant`
(decidesk_register.json), `ConflictOfInterest.boardMember`
(decidesk_register.json), `ProxyAuthorization.grantor`/`holder`
(register.d/63-member-proxy-authorization.json). `Participant`'s own
description already names its retained purpose as "quorum aggregation +
vote-casting resolver" — which maps exactly to `Vote.participant` and
`EngagementRecord.participant` (both feed vote-casting/engagement-tracking
paths) and to `Meeting`'s quorum aggregation + `VotingService::resolveParticipantUuid()`
(confirmed present, not scoped for removal). `ConflictOfInterest` and
`ProxyAuthorization` are NOT part of that stated purpose — they are retargeted
by this change. Deleting `Participant` outright would break the two remaining
`$ref`s and the quorum/vote-casting resolver path, none of which this change
touches. **Decision: retarget only the two named refs; keep `Participant`
active; narrow its description to name the four exact remaining touch points
(REQ-PCR-010 MODIFIED); document full shim retirement as a follow-up once
`Vote.participant`/`EngagementRecord.participant` have their own retarget
plan** — that plan is explicitly out of scope here (it requires redesigning
vote-casting and quorum aggregation around Membership, a materially larger
change than a `$ref` swap).

### Decision 3: ConflictOfInterest and the interests-and-integrity models (Nevenfunctie/Geschenk/Integriteitsbeleid) stay separate

Evaluated fold with evidence, not assumption:

| | `ConflictOfInterest` | `Nevenfunctie` (register.d/62) |
|---|---|---|
| Trigger | A specific agenda item / decision moment | A standing outside role, independent of any meeting |
| Required fields | `boardMember`, `declarationType` (financial/personal/competing-business/prior-involvement), `actionTaken` (recusal from discussion/vote) | `person`, `governanceBody`, `organisation`, `role`, `remunerated`, `startDate`, `declaredAt`, `lifecycle` (gemeld → openbaar\|intern → beëindigd) |
| Free text | `description` (unbounded) | **None by design** — the schema description states this is deliberate: "CONSTRAINT (design D4): this schema contains ONLY publishable fields by construction — NO ... free-form internal-remarks property" |
| Publication | Not public | Live public `nevenfunctiesregister` per body via the OpenRegister published-predicate |
| Consumers | `ConflictOfInterestController`/`Service` (declare/query per-item conflicts), `AuditLogService` (`conflict-declaration` event type — a generic audit category, not schema-coupled), `GovernanceReportingService` (independence-ratio + COI-volume flags) | Not consumed by any of the three above |

The two model genuinely different things: a **contextual recusal event** tied
to one agenda item/decision, carrying free-text rationale and a mitigating
action, versus a **standing, publication-scoped role disclosure** that is
schema-constrained to contain *only* publishable fields. Folding them would
either (a) force `ConflictOfInterest`'s free-text `description` into a schema
that is deliberately publishable-only by design (`Nevenfunctie`'s D4
constraint), or (b) strip `Nevenfunctie`'s public-register guarantee to
accommodate COI's private fields. Both break an explicit, documented design
constraint on the other side. **Decision: leave separate, per ADR-006's escape
clause for schemas that model genuinely distinct concerns** — no schema edit,
no task. Recorded here so a future sweep doesn't have to re-derive this from
scratch.

**Observation (out of scope, not actioned):** a *third* COI-adjacent mechanism
exists — `openspec/specs/conflict-of-interest/spec.md` (REQ-COI-001..004)
documents an entirely different, note-based mechanism ("Belangenverstrengeling
melden" — a structured OpenRegister note on `AgendaItem`, Participant-scoped)
that is unrelated to both the `ConflictOfInterest` schema object and
`Nevenfunctie`. This item's scope named only "ConflictOfInterest vs
register.d/62"; the note-based mechanism is a third, separate overlap
discovered during this sweep and flagged for a future audit rather than acted
on here.

### Decision 4: Person for grantor/holder, Membership for boardMember

`Person` (identity only — name, contact, memberships) vs. `Membership`
(role/relationship in one governance body — `role`, `votingWeight`, `party`,
`independenceStatus`). `ProxyAuthorization.grantor`/`holder` identify *who*
authorized/received the proxy — an identity question, satisfied by `Person`.
`ConflictOfInterest.boardMember` is inherently about someone's *role in a
specific body* — the schema already has an `independenceStatus`-adjacent
concern (MCCG conflict-of-interest is fundamentally a governance-role
question, not an identity question), and `Membership` is the schema that
already carries `independenceStatus`. This split was named explicitly in the
task brief ("Person (grantor/holder) / Membership as domain-appropriate") and
is reinforced by the schemas' own field sets.

### Decision 5: BoardProxy retirement mechanism — inactive, not deleted

Every schema in this register uses `hardDelete: false`; nothing in the codebase
deletes a schema definition outright, only marks `x-openregister.active: false`.
`BoardProxy`'s own shipped description already reads "the signed-document side
of a volmacht lives on ProxyAuthorization" — the fold direction was already
implied by the schema's own text. `proxyStatus` (BoardProxy's approval-workflow
concept: pending-approval/active/suspended/revoked) is added additively to
`ProxyAuthorization` rather than reusing `signatureStatus`
(unsigned/signed/refused), because `signatureStatus` is a strictly guarded
declarative lifecycle (`x-openregister-lifecycle`, ADR-031) writable ONLY from
a signing-provider completion — decidesk's own approval workflow (pending →
active → suspended/revoked) is a different axis entirely and must not be
force-fit onto a provider-gated field.

## Seed Data (ADR-001)

OpenRegister seed import is **create-only** — patching an existing seeded
object's new field is inert (memory: "OR seed import CREATE-ONLY"). Every new
or retargeted property below is demonstrated on a **freshly-created** seed
object in the new fragment's `objects` block, referencing existing base seed
slugs by string per the established convention
(`register.d/47-works-council-consultation.json`'s seed description: "References
resolve by slug against existing decidesk base seed objects").

- **Decision.meeting/agendaItem** — one new `decision` seed object,
  `besluit-verordening-parkeren-2026` (municipality domain), with
  `meeting: "raadsvergadering-2025-01-15"` and
  `agendaItem: "begroting-2026-bespreking"` (both existing base seed slugs).
- **ConflictOfInterest.boardMember → Membership** — one new
  `conflict-of-interest` seed object, `coi-wethouder-vastgoedbelang`,
  referencing a `membership` seed slug (e.g. `lidmaatschap-devries-rvc`, an
  association RvC context) rather than a `participant` slug.
- **ProxyAuthorization.grantor/holder → Person, + proxyStatus** — extend the
  existing `machtiging-vandam-begroting` seed pattern with a NEW sibling
  object `machtiging-jansen-alv-2026` using `person` seed slugs for
  `grantor`/`holder` and `proxyStatus: "active"` explicitly set (the
  pre-existing seed objects in `63-member-proxy-authorization.json` keep
  their current `Participant`-typed `grantor`/`holder` values as historical
  fixtures — they are the code chain's migration input, not something this
  schema-only change silently rewrites).
- **GoverningDocument.currentEffectiveDate** — one new seed object under the
  existing `governing-document` collection (travel-agency or consultancy
  domain, e.g. `huishoudelijk-reglement-reisburo-noord`) with
  `currentEffectiveDate: "2025-09-01"`.
- **BoardProxy retirement** — no new seed object (retiring a schema does not
  need a demonstration row); existing `board-proxy` seed rows are left as-is
  for the code chain's migration task to read.

## Declarative-vs-imperative decision (ADR-031)

Every change in this proposal is a **declarative relation** (a plain `$ref`
property, the established dialect already used by `EngagementRecord.meeting`,
`ProxyAuthorization.meeting`, and every other cross-schema reference in this
register) or a **declarative convenience field** (`GoverningDocument.currentEffectiveDate`,
matching the existing, non-computed `Regeling.currentEffectiveDate` pattern —
a plain data property populated by whatever writes `GoverningDocumentVersie`
transitions, not a working aggregation; see the design note already recorded
on `Regeling.currentEffectiveDate`'s own description about the "maintenance/dialect
caveat"). No new lifecycle, aggregation, calculation, notification, or widget
behaviour is introduced. `proxyStatus` is a plain enum property, not a new
`x-openregister-lifecycle` block — decidesk's approval-transition rules
(who may move pending-approval → active, etc.) are already imperative in
`ProxyVoteService::transition()` and stay there in the code chain; this
change only adds the storage slot on the surviving schema.

**Data migration is the one exception, and it is fully deferred to the code
chain.** Retargeting `$ref: Participant` → `$ref: Membership`/`Person` on
schemas with existing rows, and folding `board-proxy` rows into
`proxyAuthorization`, requires resolving stale UUIDs against a crosswalk with
no existing key (`Participant` and `Person`/`Membership` share no common
identifier such as `nextcloudUserId`) — this is exactly the "external
integration / one-time data repair" exception ADR-031 carves out for
imperative work, landed as a Nextcloud `<repair-steps>` class in
`model-debt-cleanup-code`, never as a declarative field.

**Judge amendment (2026-08-19): Person gains an optional `nextcloudUserId`
property in this schema change** (string, nullable, described as the
Nextcloud account linkage carried over from the retired Participant shim).
Rationale: the crosswalk's weakness IS the missing common key — migrating
Participant rows without a slot for `nextcloudUserId` on Person would
permanently discard the one strong identity link the shim holds, and every
future person-matching operation would be stuck with email heuristics. The
code chain's repair step SHALL copy `Participant.nextcloudUserId` onto the
matched-or-created Person, and the crosswalk match order becomes:
nextcloudUserId exact (when a Person already carries one) → email exact →
create new. Conservative and non-destructive as before, but lossless.

## Risks / Trade-offs

[Risk] A schema whose `$ref` target changed now rejects an old-shaped write
before the code chain's repair step runs → [Mitigation] this is intended
sequencing: the schema declares the new contract; the repair step (code
chain, ordered after this change's `<post-migration>` slot per the existing
`InitializeSettings` → `RenameDutchDecideskValues` precedent) brings existing
rows into conformance. Any write attempted against a stale `Participant` UUID
between the two changes shipping fails loudly (a validation error) rather
than silently accepting mismatched data — an acceptable, visible failure
mode during the gap, not a silent one.

[Risk] `BoardProxy` staying declared-but-inactive could confuse a future
reader into thinking it's still the live schema → [Mitigation] its retired
description explicitly names `ProxyAuthorization` + `proxyStatus` as the
replacement, matching the pattern already used elsewhere in this register for
retired schemas (e.g. the historical Board-portal retirement referenced in
`openspec/specs/governance-bodies/spec.md`).

## Migration Plan

Schema propagation itself needs no `lib/Migration/` class — see migration.md
for the full mechanism description (ADR-037 fragment merge +
`ConfigurationService::importFromApp()` version-gated reimport). Live-data
repair (the `Participant`→`Membership`/`Person` crosswalk and the
`board-proxy`→`proxyAuthorization` row migration) is entirely
`model-debt-cleanup-code`'s responsibility; this design.md's migration.md
sibling documents the schema-propagation mechanism only and cross-references
the code chain's migration.md for the data side.

## Open Questions

None outstanding for this schema-only change — see the parent report's
`DEFERRED_QUESTIONS` for the one item flagged for human confirmation
(whether `ConflictOfInterest.boardMember`'s Membership retarget should also
rename the property itself from `boardMember` to `membership` for clarity,
which this design chose NOT to do — see design note under REQ-SDM-023 in the
schemas-and-data-model delta: only the `$ref` target changes, the property
name stays, to keep the change a pure retarget rather than a rename+retarget
that would also touch every consumer's field-access code unnecessarily).
