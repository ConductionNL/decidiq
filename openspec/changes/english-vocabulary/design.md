## Context

Token-aware scan: **36 schemas / 109 Dutch properties**, spread across 15 register
fragments, and a code layer that is almost clean — **0 Dutch files, 0 Dutch classes,
1 Dutch method** (`createDossierFolder`).

That ratio is the opposite of docudesk's and it says something useful: decidesk's *code*
was written in English from the start; only its *domain vocabulary* is Dutch. And that
vocabulary is Dutch municipal governance law — `Toezegging`, `Termijnagenda`, `Regeling`,
`Bevoegdheidstoedeling`, `Zienswijze`, `Nevenfunctie`, `Geheimhouding`, `Voordracht`,
`Raadsinformatiebrief`, `Interpellatieverzoek`.

This is the app where the statutory rule does most of its work, and where the temptation
to keep Dutch "because it is the official term" is strongest.

## Goals / Non-Goals

**Goals:**

- Rename all 36 schemas and 109 properties to English, with statute markers on the
  concepts that genuinely have no counterpart.
- Adopt the fleet words, especially the validity-date collapse and the
  `publicatiedatum`/`depublicatiedatum` pair, which decidesk carries more often than any
  other app.
- Keep the Dutch visible to Dutch users through `l10n/nl.json`.

**Non-Goals:**

- Preserving Dutch schema names because they are the standardised municipal terms. The
  ratified policy overrules that.
- Changing any statutory *citation* — Gemeentewet articles, Woo article references and
  the like are values, not identifiers.

## Decisions

### 1. `publicatiedatum` / `depublicatiedatum` is a fleet word and decidesk is its heaviest user

The pair appears on 14 of decidesk's schemas — `ParticipatoryBudget`,
`PublicConsultation`, `ConsultationReaction`, `PublicationPayload`, `BoardEvaluation`,
`Toezegging`, `TermijnagendaItem`, `Raadsinformatiebrief`, `TechnischeVraag`,
`Bevoegdheidstoedeling`, `Adviesaanvraag`, `Advies`, `RoosterVanAftreden`,
`Nevenfunctie`, `Geschenk`.

It is also in opencatalogi, softwarecatalog and procest, which makes it a ratified fleet
word: `publicationDate` / `depublicationDate`. decidesk must not invent its own.

Note the tell: several of these schemas already have **English names** (`ParticipatoryBudget`,
`PublicConsultation`, `BoardEvaluation`, `GoverningDocument`) with **Dutch properties**.
The app has been drifting toward English one fragment at a time; this change finishes it.

### 2. Statutory concepts: English name plus marker

| Dutch | English | basis |
|---|---|---|
| `Toezegging` | `Commitment`* | Gemeentewet — council undertaking |
| `TermijnagendaItem` | `ScheduledAgendaItem` | Gemeentewet |
| `Regeling` / `RegelingVersie` / `RegelingExportPackage` | `Regulation` / `RegulationVersion` / `RegulationExportPackage` | Gemeentewet, verordeningenregister |
| `Bevoegdheidstoedeling` | `PowersAssignment` | Awb — delegation/mandate register |
| `Zienswijze` | `FormalView` | Awb |
| `Nevenfunctie` | `AncillaryPosition` | Gemeentewet integrity rules |
| `Geheimhouding` | `Confidentiality` | Wet open overheid / Gemeentewet |
| `Voordracht` | `Nomination` | Gemeentewet |
| `Raadsinformatiebrief` | `CouncilInformationLetter` | Gemeentewet |
| `Interpellatieverzoek` / `MondelingeVraag` | `InterpellationRequest` / `OralQuestion` | Gemeentewet |
| `Adviesaanvraag` / `Advies` | `AdviceRequest` / `Advice` | |
| `KascommissieVerklaring` | `AuditCommitteeStatement` | VvE / BW 5 |
| `OnboardingTraject` / `OffboardingTraject` | `OnboardingProcess` / `OffboardingProcess` | |
| `RoosterVanAftreden` / `RoosterRegel` | `RetirementSchedule` / `RetirementScheduleEntry` | |
| `TermijnRegeling` | `TermRule` | |

\* ⚠️ **`Toezegging` → `Commitment` would collide with shillinq — but not yet.** shillinq's
`Verplichting` → `Commitment` rename is in PR #495, which is **still open**; on
`development` shillinq's registers still declare `Verplichting*`, and the `Commitment*`
names exist only in `src/manifest.d/`. So the collision is *latent*, not current — it
lands the moment #495 merges. Since `$ref` slug resolution is **instance-global**, a
decidesk `Commitment` and a shillinq `Commitment` on one instance can then bind to each
other's schema.

**Decision: `CouncilCommitment`**, not `Commitment`. Picking the plain word now would
create the collision on #495's merge date, with no failure at the moment of the mistake.

### 3. `besluit` inside decidesk is the *instrument* sense

`ConsultationRequest.besluitOutcome/besluitText/besluitDate`,
`Bevoegdheidstoedeling.besluit`, `Voordracht.besluit`, `Geschenk.besluit`,
`Geheimhouding.bekrachtigingsbesluit`/`opheffingsbesluit` — all decisions taken by a
governing body, not decision *letters*.

**Decision:** `decision*` as the *property* prefix, matching procest and openconnector,
not opencatalogi's `decisionLetter`. `bekrachtigingsbesluit`/`opheffingsbesluit` →
`ratificationDecision`/`revocationDecision`.

⚠️ **This applies to properties only. decidesk's `Decision` *schema* keeps its name and
must not be touched here** — it is the universal governance-decision supertype (ADR-005)
that folded Motion, Amendment and Resolution into one schema. It already collides with
procest's `decision` schema at the slug level (see the fleet-level finding below); this
change must not deepen that.

### 4. Validity dates take the fleet collapse

`geldigVanaf`/`geldigTot` (`Bevoegdheidstoedeling`), `vervaldatum` (`RegelingVersie`,
`GoverningDocumentVersie`), `ingangsdatum`, `eindeDatum` (`OffboardingTraject`),
`eindeTermijnDatum` (`RoosterRegel`) → `validFrom` / `validUntil`.

⚠️ Exceptions that are **events, not boundaries**: `toetredingsDatum`/`uittredingsDatum`
(`BodyParticipation`) → `joinedOn`/`leftOn`; `beëdigingsDatum` (`OnboardingTraject`) →
`swornInOn`; `aktedatum` (`GoverningDocumentVersie`) → `deedDate`; `ingediendDatum`
(`Zienswijze`) → `submittedOn`; `ontvangenOp` (`Geschenk`) → `receivedOn`. The collision
test permits one word for the validity pair; it does not license flattening every date
into it.

### 5. `Regeling` carries the one `title` collision in the fleet

`Regeling` has both `citeertitel` and `officieleTitel` — the short citation title and the
full official title, which are distinct fields in the verordeningenregister.

**Decision:** `citationTitle` and `officialTitle`. This is the single case fleet-wide
where `titel` → `title` is not sufficient.

### 6. Diacritics are a rename hazard of their own

`beëdigingsDatum` and `beëdigingsVergadering` contain a non-ASCII character. Any
matching that assumes ASCII word characters will miss them, and they will survive a
sweep that reports itself complete.

**Decision:** enumerate these two explicitly rather than relying on the scan.

## Risks / Trade-offs

- **`Toezegging` → `Commitment` collides with shillinq** → instance-global `$ref`
  resolution means the wrong schema binds silently. Mitigated by `CouncilCommitment`.
- **A statutory concept is flattened to a generic English word and loses its legal
  meaning** → mitigated by the marker carrying jurisdiction and instrument.
- **A date that records an event is renamed `validFrom`** → the semantics quietly change
  and a lifecycle rule starts reading the wrong field. Mitigated by decision 4's
  exception list.
- **The two diacritic properties survive the rename** → mitigated by decision 6.
- **15 register fragments, and two fragments both declaring one schema's `required`** →
  ADR-037 concatenates list values on merge, which is what produced the shillinq#485
  unsatisfiable schema. Any fragment touched here must be checked for a second declaring
  fragment before its `required` is edited.

## Migration Plan

1. Count stored objects per schema; 36 schemas is too many to assume greenfield.
2. Rename schemas — `CouncilCommitment` first, since it is the collision case.
3. Rename properties, adopting the fleet words; apply the decision-4 exception list.
4. Attach statute markers.
5. Rename `createDossierFolder`, the one Dutch method.
6. `l10n/nl.json`, `check-l10n`, gates.

**Rollback:** app-local throughout; decidesk holds no cross-app foreign keys. Reverts
cleanly until step 1's migration runs.

## ⚠️ Fleet-level finding this app surfaced

Checking the `Commitment` question turned up something larger. A fleet-wide slug survey
finds **31 schema slugs already declared by two or more apps**, including:

`contract` (shillinq + pipelinq + softwarecatalog) · `decision` (procest + decidesk) ·
`document` (procest + opencatalogi) · `task`, `project`, `service`, `resource`,
`booking`, `product`, `expense`, `employee`, `notification`, `mandaat`, `adviesaanvraag`,
`decisiontable` …

decidesk is party to five of them (`decision`, `product`, `notification`,
`adviesaanvraag`, and `Toezegging`'s prospective `Commitment`). Since `$ref` slug
resolution is instance-global, these are pre-existing hazards — and **the English rename
programme makes them worse**, because translating distinct Dutch words funnels them into
a small pool of common English nouns.

`Adviesaanvraag` is the clean illustration: procest and decidesk both declare it today,
and both per-app changes independently plan to rename it `AdviceRequest`. Two apps, two
changes, neither aware of the other, converging on one slug.

**This is recorded here, and escalated to the fleet policy.** decidesk should not resolve
it alone.

## Open Questions

- `Toezegging` → `CouncilCommitment` assumes procest does not model the same concept.
  A grep of procest's registers for `toezegging` returns nothing, so the name looks free —
  but that only checks the Dutch spelling, not whether procest plans an English
  `Commitment` of its own.
- Whether decidesk's `Decision` or procest's `decision` should yield the slug. Neither app
  can decide that; it belongs in the fleet change.
