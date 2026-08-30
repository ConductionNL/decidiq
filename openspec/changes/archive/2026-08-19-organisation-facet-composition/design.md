# Design: organisation-facet-composition

## Context

`GovernanceBodyDetail` (`src/manifest.json`) is a `type: "detail"` page for schema
`governance-body`. It already carries 8 widgets: `body-data`, `body-members`
(custom component `GovernanceBodyMembersTab`), `body-meetings` (`object-list`,
schema `meeting`, filter `{governanceBody: "@objectId"}`), `body-files`
(integration), and four `type: "custom"` tabs (`body-template`, `body-efficiency`,
`body-retention`, `body-evaluations`). Layout occupies `gridY` 0–29.

Six sibling capabilities each ship a body-scoped register with its own index +
detail page and each one's manifest fragment explicitly defers the reverse-facet
on `GovernanceBodyDetail`:

| Capability | Fragment | Schema(s) | Scoping field | Quoted note |
|---|---|---|---|---|
| `appointments-and-terms` | `61-appointments-and-terms.json` | `rooster-van-aftreden`, `rooster-regel`, `termijn-regeling` | `body` | (no explicit GovernanceBodyDetail note, but the pages stand alone) |
| `interests-and-integrity` | `62-interests-and-integrity.json` | `nevenfunctie`, `geschenk` | `governanceBody` | "the per-body compliance panel on the Nevenfuncties index... [is] NOT part of this declarative-core fragment" |
| `shared-governance-bodies` | `56-shared-governance-bodies.json` | `body-participation`, `zienswijzeronde`, `zienswijze` | `sharedBody` / `participant` | "The 'Deelnemende organisaties' section on GovernanceBodyDetail... live[s] in the base `src/manifest.json`... out of scope for this declarative-core fragment" |

All three quote the same root cause: **`mergePages` replaces a same-id page
wholesale** (design D6). A `manifest.d` fragment cannot patch one widget onto an
existing page — it can only fully redefine the page (clobbering whatever the base
file or another fragment already declared) or add nothing. `GovernanceBodyDetail`
is defined once, in the base `src/manifest.json`, so the only place these
reverse-facets can land without a wholesale-redefinition collision is that base
file, edited in place. That is what this change does.

Separately, `GovernanceBody.bodyType` has no `faction`/`fractie` value. A stale
draft change, `fractievoorzitter-fractie-koppeling` (created 2026-05-22), proposes
a full parallel schema set (`Fractie`, `FractieLidmaatschap`, `SchriftelijkeVraag`,
`PolitiekePartij`, `Kandidatenlijst`, `FractieOndersteuning`) to model this — but
it predates ADR-006 (accepted 2026-06-14), which now explicitly forbids "a new
schema that duplicates an existing concept 'for a different audience'" and
mandates the same discriminator pattern already used for corporate boards
(`bodyType=supervisory-board`/`executive-board`, REQ-GBD-012). Three already-built
fragments (`vragenuur-interpellatie`, `raadsinformatiebrieven`,
`constituency-consultation`) carry plain-string placeholder "Fractie" fields
explicitly waiting on that draft landing.

## Goals / Non-Goals

**Goals**
- Compose the six body-scoped registers' reverse-facets onto `GovernanceBodyDetail`, declaratively, with zero new Vue components.
- Give `GovernanceBody` an ADR-006-compliant way to represent a faction, closing the gap the stale draft change was trying to close with a forbidden pattern.
- Keep the change `kind: config` — schema-register JSON + manifest JSON only.

**Non-Goals**
- Migrating `GovernanceBodyMembersTab` off the deprecated `participant` schema onto `membership` + Person (see proposal Open Questions — separate `kind: code` follow-up).
- Resolving the fate of `fractievoorzitter-fractie-koppeling` or the three fragments' placeholder Fractie fields (human decision, out of this change's authority).
- A Person/Membership directory page — Person has no dedicated UI anywhere in the app today; not introduced here.
- Enforcing `parentBody` as conditionally required when `bodyType=faction` (no conditional-required dialect in this register; would need an imperative service, which is `kind: code`).

## Decisions

### Decision 1: Edit the base `src/manifest.json` in place, not a new `manifest.d` fragment
**Chosen.** `GovernanceBodyDetail` is defined once, in the base file. Editing it
in place is the only way to add widgets without wholesale-redefining (and
risking clobbering) the page — the exact hazard three sibling fragments already
called out and deliberately avoided.
**Alternative considered:** a new `manifest.d/organisation-facet-composition.json`
fragment that redefines `GovernanceBodyDetail` wholesale (copying the base
page's `widgets`/`layout` and appending to it). Rejected: `mergePages` replacing
same-id pages wholesale means whichever of (base file, this fragment) loads
second wins outright — there is no guaranteed fragment-load order documented
against the base file itself, so a wholesale redefinition in a fragment is a
silent-clobber risk the base three fragments explicitly chose not to take. Since
this change is the terminal one composing the page (no other change also needs
to touch `GovernanceBodyDetail`'s widget list concurrently — `meeting-facet-composition`
and `decision-facet-composition` touch different pages entirely), editing the
base file directly is strictly safer and matches how the page's own widgets
(`body-meetings`, etc.) were added originally.

### Decision 2: Every new facet is a declarative `object-list` widget — no new registry component
**Chosen.** Every facet is "list of `<schema>` objects where `<scopingField> =
this body's object id`" — structurally identical to the existing `body-meetings`
widget (`filter: {governanceBody: "@objectId"}`). `object-list` already handles
fetch, empty state, pagination limit, row click-through, and an optional
"view all" deep link. No facet in this change needs aggregation, computation, or
write behavior — see the Declarative-vs-imperative section below.
**Alternative considered:** a single custom `OrganisationFacetsTab.vue` component
fetching all six registers itself (mirroring `GovernanceBodyMembersTab`'s
pattern). Rejected: `GovernanceBodyMembersTab` is a custom component because it
needs write actions (add/remove/role-change/CSV-and-group import) that
`object-list` cannot express. None of the six new facets need writes from this
page — editing already happens on each register's own detail page
(`RoosterDetail`, `TermijnRegelingDetail`, `NevenfunctieDetail`,
`GeschenkDetail`), which already exist and already have full CRUD. Building a
custom component to re-implement what `object-list` already does, for read-only
lists, would be unjustified code for a purely compositional change.

### Decision 3: Faction = `bodyType` discriminator + new `parentBody` self-reference (not the draft's parallel schema)
**Chosen.** Per ADR-006, adds `bodyType: "faction"` and one new nullable
`parentBody` property (`$ref: GovernanceBody`) to the existing `GovernanceBody`
schema. A faction's members use the existing `Membership.governanceBody` relation
exactly like any other body — no new membership schema.
**Alternative considered A:** land the stale draft's `Fractie` schema instead.
Rejected: directly violates ADR-006's "Forbidden" list; also would need to
migrate the three fragments' plain-string Fractie placeholders onto a real
`$ref`, which is a much larger, code-adjacent effort outside this change's scope.
**Alternative considered B:** add only the `bodyType=faction` enum value, no
`parentBody` field (the literal reading of the product decision). Rejected: without
a relation field, a "Factions" facet on a council's detail page cannot be
filtered to "factions of THIS council" — it would have to list every faction in
the register, an unscoped/global listing that defeats the purpose of a per-body
facet. `parentBody` is deliberately generic (not named `parentFaction` or scoped
to `bodyType=faction`) so it can also serve non-faction sub-body hierarchies later
(e.g. a corporate board's sub-committee) without a second schema delta — flagged
as a DEFERRED_QUESTION in the proposal since it goes beyond the literal
instruction.

### Decision 4: `Participating organisations` / `Shared-body participations` widgets link through their referenced object, not their own row
**Chosen.** `body-participation` has no dedicated detail page anywhere in the
manifest (`shared-governance-bodies` ships pages for `zienswijzeronde` and
`zienswijze` only). These two widgets therefore omit `rowRoute` and instead use
a `widget: "link"` column on the referenced field (`participant` /
`sharedBody` respectively) — the same column-level link mechanism already used
on `Roosters`/`Nevenfuncties`/`Geschenken`/`Zienswijzerondes` index pages (e.g.
`{ "key": "body", "label": "Governance body", "widget": "link" }`), which
resolves and links to the *referenced* object's own detail page independently of
any row-level `rowRoute`. Clicking a row in "Participating organisations" opens
that participant's own `GovernanceBodyDetail`, which is the more useful
destination anyway (there is nothing to see on a bare `body-participation`
object beyond the two references it carries).
**Verify during apply:** confirm the object-list *widget* renderer (as opposed to
the index-page `CnDataTable`) honours `widget: "link"` columns identically; if it
does not, fall back to a plain-text column for just these two widgets (degrades
to a non-clickable but still correct list — not a blocking risk either way).

## Risks / Trade-offs

- **[Risk] Eight new widgets substantially lengthen `GovernanceBodyDetail`.** →
  **Mitigation:** every widget degrades to its own `emptyText` when the body has
  no matching objects (a non-shared body's participation widgets, a body with no
  factions, etc.) — consistent with the empty-state convention already used
  throughout the app. `MeetingDetail` already carries a comparable widget count.
- **[Risk] `parentBody` has no conditional-required or uniqueness enforcement.**
  → **Mitigation:** documented as a soft convention in the schema description,
  consistent with how this register already documents other unenforceable
  conditional rules (e.g. `constituency-consultation`'s at-least-one-of rule) when
  no imperative layer exists in the change's scope. A future change may add
  save-time validation if griffiers report mis-set `parentBody` values in
  practice.
- **[Risk] `widget: "link"` on an object-list *widget* column is unverified for
  this exact context** (Decision 4). → **Mitigation:** see "Verify during apply"
  above; failure mode is cosmetic (non-clickable cell), not broken data.
- **[Trade-off] `bodyType=faction` + `parentBody` is a minimal discriminator, not
  the stale draft's full fraction-lifecycle model** (no split/merge/succession
  tracking, no `SchriftelijkeVraag`, no `FractieOndersteuning` funding). →
  **Accepted**: this change's scope is composing existing facets onto a detail
  page, not building a fraction-lifecycle capability. The discriminator is
  additive and does not foreclose a future ADR-006-compliant change adding those
  capabilities as further discriminators/relations on the universal model.

## Migration Plan

Two independent, additive schema/config edits — see `migration.md` for the full
OpenRegister-schema-change checklist. Summary:

1. Deploy `lib/Settings/decidesk_register.json` (`GovernanceBody.bodyType` enum
   `+faction`, new `parentBody` property) — additive, no existing object is
   invalidated (no existing `GovernanceBody` has `bodyType=faction` before this
   change ships, and `parentBody` is optional/nullable).
2. Deploy `src/manifest.json` (`GovernanceBodyDetail` widgets/layout) — additive,
   no existing widget is removed or renamed.
3. Re-import the register (`ConfigurationService::importFromApp()` via the
   existing repair step) so the schema delta and seed additions land.

**Rollback:** revert either file independently; no data migration is needed in
either direction (see proposal Rollback Strategy).

## Seed Data

New seed objects, appended to the existing `governance-body` array in
`lib/Settings/decidesk_register.json` (base file, same array as
`gemeenteraad-amsterdam` etc. — see line ~221), so the new `bodyType=faction` +
`parentBody` fields are demonstrable on a clean install (ADR-016 testability),
using the existing municipal seed org (`gemeenteraad-amsterdam`) that already
has real `Membership` seed data to extend:

### Schema: `governance-body` (new rows)

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | `groenlinks-fractie-amsterdam` | `d66-fractie-amsterdam` |
| name | GroenLinks-fractie | D66-fractie |
| bodyType | `faction` | `faction` |
| domain | `municipal` | `municipal` |
| parentBody | `gemeenteraad-amsterdam` | `gemeenteraad-amsterdam` |
| votingDefault | `for-against-abstain` | `for-against-abstain` |

### Schema: `membership` (new row, demonstrates faction membership via the existing relation — REQ-GBD-013 scenario 3)

| Field | Object 1 |
|-------|----------|
| slug | `m-marie-groenlinks-fractie` |
| role | `member` |
| label | Fractielid |
| party | GroenLinks |
| votingWeight | 1 |
| startDate | `2022-03-16T00:00:00Z` |
| person | `marie-janssen` (existing seed) |
| governanceBody | `groenlinks-fractie-amsterdam` |

**Related items per object:** none (Files/Notes/Tasks/Contacts) — these are
lightweight demo rows whose only purpose is to make the new `Factions` facet and
`bodyType=faction` value non-empty on a fresh install; `marie-janssen` already
carries a `gemeenteraad-amsterdam` Membership (`m-marie-amsterdam`) from the base
seed, so this is her *second* Membership, itself demonstrating "a person can have
multiple memberships in different governance bodies" (Membership schema
description).

No new seed rows are needed for the other five facets — `rooster-van-aftreden`,
`termijn-regeling`, `nevenfunctie`, `geschenk`, `body-participation`, and
`zienswijzeronde` are already seeded and scoped to `gemeenteraad-amsterdam` /
`auditcommissie-provincie-nh` / `raad-van-commissarissen-acme-bv` /
`bestuur-noz-organisatie` by their owning fragments; those bodies' detail pages
will show non-empty facets the moment the widgets land, with no seed changes.

## Trade-offs

See Decisions above (each decision lists its rejected alternative and why).

## Declarative-vs-imperative decision (ADR-031)

This change introduces **declarative relations between OR objects only** — the
ADR-031 trigger category that applies here. Every one of the eight new facets is
a plain filtered listing (`object-list` widget, `filter: {<field>: "@objectId"}`),
identical in kind to the already-shipped `body-meetings` widget. None of the six
registers being surfaced needs a lifecycle, aggregation, calculation, or
notification declared *by this change* — those, where they exist, already live
on the owning schema (e.g. `rooster-regel`'s `x-openregister-notifications`
herbenoemingsrappel triggers, declared by `appointments-and-terms`, untouched
here). The default declarative path (`x-openregister-relations` /
plain `object-list` filter — no new `lib/Service/*Service.php` class) is used
throughout; no ADR-031 exception applies because no behaviour here needs an
imperative implementation.

| Facet | Path | Rationale |
|---|---|---|
| Retirement schedule | Declarative (`object-list`, filter `body`) | Plain filtered listing; the rooster's own generation logic is out of this change's scope (owned by `appointments-and-terms`'s `RoosterService`) |
| Term rules | Declarative (`object-list`, filter `body`) | Plain filtered listing, explicitly read-only from this page |
| Other positions / Gifts | Declarative (`object-list`, filter `governanceBody`) ×2 | Plain filtered listings |
| Participating organisations / Shared-body participations | Declarative (`object-list`, filter `sharedBody` / `participant`) ×2 | Plain filtered listings, see Decision 4 for the link-column detail |
| Zienswijze rounds | Declarative (`object-list`, filter `sharedBody`) | Plain filtered listing |
| Factions | Declarative (`object-list`, filter `parentBody` + `bodyType`) | Plain filtered listing over the same schema, once the `parentBody` field exists |

## Open Questions

Carried from the proposal (repeated here for design traceability):
1. Should `GovernanceBodyMembersTab` (and its four dialogs) be migrated from
   `participant` to `membership` + Person resolution in a follow-up change? See
   proposal Open Questions #1.
2. Is adding `parentBody` (beyond the literal "add the bodyType enum value"
   instruction) the right call, or should Factions ship without a parent
   relation (global unscoped listing) until a human confirms? See proposal Open
   Questions #2.
3. What should happen to `fractievoorzitter-fractie-koppeling` and the three
   fragments' placeholder Fractie fields now that ADR-006 forbids the draft's
   core approach? See proposal Open Questions #3.
