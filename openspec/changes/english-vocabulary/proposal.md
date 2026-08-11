# English vocabulary for decidesk — governance / Woo domain

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.
> decidesk is the app where the **Woo decision** in §4 actually bites.

## Why

An anchored scan found **10 Dutch-named schemas and 51 Dutch property names**.
decidesk's vocabulary is municipal-governance Dutch: `Regeling`,
`RegelingVersie`, `TermijnRegeling`, `MondelingeVraag`, `TechnischeVraag`,
`KascommissieVerklaring`, `RoosterRegel`, `TermijnagendaItem`,
`WooBestuursorgaan`.

## What changes

### Internationalised (§2)

| Dutch | English |
|---|---|
| `Regeling` / `RegelingVersie` | `Regulation` / `RegulationVersion` |
| `RegelingExportPackage` | `RegulationExportPackage` |
| `TermijnRegeling` | `DeadlineRule` |
| `TermijnagendaItem` | `AgendaDeadlineItem` |
| `MondelingeVraag` / `TechnischeVraag` | `OralQuestion` / `TechnicalQuestion` |
| `KascommissieVerklaring` | `AuditCommitteeStatement` |
| `RoosterRegel` | `ScheduleRule` |
| `Interpellatieverzoek` | `InterpellationRequest` |
| `Fractie` | `PoliticalGroup` |

### Woo is internationalised, NOT statutory-preserved (§4)

Freedom-of-information is international law — FOIA (US), Regulation 1049/2001
and the Open Data Directive (EU). So:

`WooBestuursorgaan` → `PublicAuthority`, and the Woo family becomes
`PublicRecordsRequest*` / `FreedomOfInformation*`, each carrying:

```json
"x-statutory-basis": { "jurisdiction": "NL", "instrument": "Woo", "term": "Woo-verzoek" }
```

The marker records that the NL implementation is the Woo; the identifier stays
international so the app is sellable outside NL.

### Statutory marker only where there is no counterpart (§4)

`Kascommissie` and `Interpellatie` are Dutch municipal-governance instruments.
English identifier + `x-statutory-basis` (`instrument: Gemeentewet`).

### Dutch → l10n (§3)

English source strings in `title`/`description`; Dutch in `l10n/nl.json`,
re-pointed not re-extracted; `check-l10n` must pass.

## Tasks

- [ ] Inventory per schema and per lib/+src/ file — real counts.
- [ ] Confirm the Woo → `PublicRecordsRequest` word with whoever owns the Woo
      surface elsewhere in the fleet, so two apps do not diverge.
- [ ] Rename schemas, properties, enum values, lifecycle transitions.
- [ ] Add `x-statutory-basis` to the Woo, Kascommissie and Interpellatie schemas.
- [ ] Rename classes, methods and files; update DI + any register fragment
      wiring a guard/listener by class name.
- [ ] Diff every filter/read key against the new schema.
- [ ] `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates before the PR.

## Risks

- decidesk has prior form for relation-dialect debt (ADR-062 rule 7 retired the
  per-schema `x-openregister-relations` block *because of* decidesk) — expect
  gate-54 to fire once these files enter diff scope, as it did in shillinq.
- Renamed keys read with `??` fail silently.
