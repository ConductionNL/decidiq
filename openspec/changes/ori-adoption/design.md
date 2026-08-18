# Design: ori-adoption

Paired with procest `ori-removal`. This document is the canonical home of the
ORI → Popolo mapping table; the procest change references it and must not fork it.

## Principles

1. **Popolo is the storage model; ORI is a wire format.** Every ORI concept is
   stored in decidesk's existing English/Popolo schemas. Dutch statutory field
   names (`vergadering`, `aangenomen`, `zetels`, …) appear only in the
   `OriExportMapper` adapter.
2. **Additive schema deltas only.** No decidesk property is renamed or removed;
   ORI properties with no Popolo counterpart become additive properties or enum
   values on the existing schemas (listed per-schema below). Existing decidesk
   objects remain valid, so no data migration is needed *inside* decidesk — the
   only data migration is the ORI-source import.
3. **Never fabricate data.** Where ORI stores an aggregate that Popolo models
   per-entity (party-level vote results vs per-participant `Vote`), the
   aggregate is preserved as-is in an additive property; it is not decomposed
   into invented per-person records.
4. **Idempotent, traceable import.** Every imported object records its source
   ORI object uuid in `externalReference` (`ori:<source-uuid>`); re-running the
   import updates rather than duplicates, and rollback = delete-by-tag.

## Schema mapping table (canonical)

Enum-value translations are part of the mapping: **a property or enum-value
rename is a data migration**, executed by `occ decidesk:import-ori`, never by
hand-editing schemas.

### `vergadering` → `Meeting`

| ORI property | decidesk target | Notes |
| --- | --- | --- |
| `name` | `Meeting.title` | |
| `type` | `Meeting.meetingType` | `raadsvergadering`→`regular`, `commissievergadering`→`committee`, `hearing`→`public hearing`, `informatiebijeenkomst`→`informational-session` (**new enum value**) |
| `status` | `Meeting.lifecycle` | `planned`→`draft`, `confirmed`→`scheduled`, `afgelast`→`cancelled` (**new enum value**) |
| `startDate` | `Meeting.scheduledDate` | required on both sides |
| `endDate` | `Meeting.endDate` | |
| `location` | `Meeting.location` | |
| `organisation` | `Meeting.governanceBody` | resolved/created as a `GovernanceBody` (`bodyType: legislative`, e.g. the municipal council); the original organisation *name* survives as that body's `name` |
| `committee` | `Meeting.governanceBody` | committee meetings reference the committee `GovernanceBody` instead (created with `bodyType: advisory-body`, per `commissievergaderingen` / `shared-governance-bodies` modelling); `organisation` then only disambiguates the parent |
| — | `Meeting.meetingMode` | required in decidesk, absent in ORI → import default `in-person` |
| — | `Meeting.isPublic` | ORI data is public by definition → import sets `true` |

### `agendapunt` → `AgendaItem`

| ORI property | decidesk target | Notes |
| --- | --- | --- |
| `subject` | `AgendaItem.title` | |
| `omschrijving` | `AgendaItem.description` | |
| `sortOrder` | `AgendaItem.orderNumber` | |
| `vergadering` | `AgendaItem.meeting` | slug ref → imported `Meeting` ref |
| `parentAgendaItem` | `AgendaItem.parentItem` | |
| `attachments` | `AgendaItem.documents` | **new additive property**: array of `DigitalDocument` refs |
| — | `AgendaItem.itemType` | required in decidesk, absent in ORI → derived: `decision` when a `stemming` references the item, else `discussion` when `omschrijving`/attachments present, else `informational` (opening/closing/procedural items) |

### `raadsdocument` → `DigitalDocument`

| ORI property | decidesk target | Notes |
| --- | --- | --- |
| `title` | `DigitalDocument.name` | |
| `type` | `DigitalDocument.documentType` | value map: `motion`→`motion`, `amendment`→`amendment`, `decision`→`decision`, `brief`→`letter` (**value translation**), `report`→`report`, `minutes`→`minutes` |
| `classification` | `DigitalDocument.classification` | **new additive property** (free-text category) |
| `url` | `DigitalDocument.url` | **new additive property** (`format: uri`, schema.org `url`) |
| `fileName` | `DigitalDocument.fileName` | **new additive property** |
| `fileSize` | `DigitalDocument.contentSize` | integer bytes → string (decidesk/schema.org `contentSize` is a string; importer stringifies) |
| `contentType` | `DigitalDocument.encodingFormat` | |

### `stemming` → `VotingRound` + `Decision`

One ORI `stemming` becomes a `Decision` (the thing voted on) plus a
`VotingRound` (the vote event). decidesk's per-participant `Vote` objects are
**not** created: ORI only stores party-level aggregates, and per-person votes
cannot be reconstructed without inventing data (principle 3).

| ORI property | decidesk target | Notes |
| --- | --- | --- |
| `subject` | `Decision.title` (and `Decision.text`) | |
| `type` | `Decision.decisionType` | `motion`→`motion`, `amendment`→`amendment`, `proposal`→`resolution` (**value translation**) |
| `result` | `Decision.outcome` + `VotingRound.result` | `aangenomen`→`adopted`, `verworpen`→`rejected` (**value translation**); `Decision.lifecycle` set to `decided` |
| `agendapunt` | `Decision.subjectSchema`/`subjectId`/`subjectLabel` | existing generic subject linkage: `subjectSchema: 'agenda-item'`, `subjectId: <AgendaItem uuid>` — no schema change needed |
| `votesFor` | `VotingRound.votesFor` | |
| `votesAgainst` | `VotingRound.votesAgainst` | |
| `onthoudingen` | `VotingRound.votesAbstain` | |
| `politicalGroupResults[]` | `VotingRound.partyResults[]` | **new additive property**: array of `{party (GovernanceBody ref or name), value (for/against/abstain), seatCount}`; sub-field renames `fractie`→`party`, `stem`→`vote value`, `zetels`→`seatCount` are part of the import |
| — | `VotingRound.subjectDecision` | **new additive property**: ref to the created `Decision` (the round↔decision link without forcing a full `DecisionStage` route for imported historical votes) |
| — | `VotingRound.votingMethod` / `isSecret` | required in decidesk → import defaults `for-against-abstain` / `false` (ORI results are public party-level votes) |

### `raadslid` → `Person` + `Membership`(s)

| ORI property | decidesk target | Notes |
| --- | --- | --- |
| `name` | `Person.name` | |
| `role` | `Membership.role` on the council body | `councilMember`→`member`, `mayor`→`chair`, `clerk`→`secretary` — all on the legislative `GovernanceBody`; `alderman`→`member` on the **executive** `GovernanceBody` (`bodyType: executive-board`), created on demand |
| `fractie` | `Membership.party` + second `Membership` | the council membership carries the party *name* in the existing `Membership.party` string; additionally a `Membership` (`role: member`) binds the person to the political-group `GovernanceBody` (consistent with `fractievoorzitter-fractie-koppeling`) |
| `actief` | `Membership.endDate` | Popolo derives "active" from an open-ended membership. `actief: true` → no `endDate`; `actief: false` → `endDate` set to the import cutover date (the true historical end date is unknown in ORI — the approximation is recorded in the import log; the original flag also survives verbatim in the import report) |

### `fractie` → `GovernanceBody`

| ORI property | decidesk target | Notes |
| --- | --- | --- |
| `name` | `GovernanceBody.name` | |
| — | `GovernanceBody.bodyType` | `political-group` (**new enum value**) |
| — | `GovernanceBody.domain` | required in decidesk → inherits the council body's domain |
| `zetels` | `GovernanceBody.seatCount` | **new additive property** (integer). Steady-state it is derivable from memberships; kept as a stored aggregate because imported ORI data has seats without complete member lists |
| `classification` | `GovernanceBody.coalitionRole` | **new additive property**, enum `coalition` \| `opposition`; value map `coalitiepartij`→`coalition`, `oppositiepartij`→`opposition` (**value translation**) |

## Import order and referential integrity

The importer resolves refs by importing in dependency order:

1. `fractie` → `GovernanceBody` (political groups) + council/executive bodies
2. `raadslid` → `Person` + `Membership`
3. `vergadering` → `Meeting`
4. `raadsdocument` → `DigitalDocument`
5. `agendapunt` → `AgendaItem` (needs Meeting + documents)
6. `stemming` → `Decision` + `VotingRound` (needs AgendaItem)

ORI slug references (`agendapunt.vergadering`, `stemming.agendapunt`,
`raadslid.fractie`, `agendapunt.attachments[]`) are resolved through the
importer's slug→uuid map built during the run; a dangling ref is reported (and
fails the run in strict mode) rather than silently dropped — a filter on a
missing reference must not "match nothing, silently".

## ORI interop adapter (wire format)

`OriExportMapper` inverts the storage mapping for the read side: it renders
`Meeting`/`AgendaItem`/`DigitalDocument`/`VotingRound` objects in the ORI wire
shape (Dutch field names, Dutch enum values such as `aangenomen`). It is the
**only** component allowed to emit Dutch identifiers, and it is stateless — a
pure projection over the Popolo objects. The RSS feed endpoints replacing
procest's `/apps/procest/feed/ori/*.rss` are thin controllers over this mapper
and are `#[PublicPage]` + `#[NoCSRFRequired]` (read-only, public council data —
matching the ORI register's `authorization: {read: [public]}` posture, and
scoped to objects whose visibility is public).

## Rollback

- Schema deltas are additive → rollback is a no-op for existing data.
- Imported objects are tagged `externalReference: ori:<uuid>` → rollback =
  delete objects carrying the tag (`occ decidesk:import-ori --rollback`,
  dry-run first). The source `ori` register is untouched by this change, so the
  pre-import state remains fully recoverable until procest's `ori-removal`
  deletes it — which that change only does after verified import parity.

## Alternatives considered

- **Import the six Dutch schemas as-is into decidesk.** Rejected by the product
  owner: perpetuates a second, Dutch-named model of decidesk's own domain and
  violates the English-identifier fleet rule.
- **Map `raadsdocument` motions/amendments to `Decision` instead of
  `DigitalDocument`.** Rejected: an ORI `raadsdocument` is a *file record*
  (url, fileName, fileSize, MIME type), not the decision act; the decision act
  is what `stemming` maps to. A motion PDF becomes a `DigitalDocument`
  (`documentType: motion`) attachable to the agenda item.
- **Decompose `politicalGroupResults` into per-participant `Vote` objects.**
  Rejected: fabricates individual votes from party aggregates.
- **A dedicated `PoliticalGroup` schema.** Rejected: Popolo models parties as
  organizations; decidesk's organization concept is `GovernanceBody`, so a
  `political-group` bodyType keeps one organization model.
