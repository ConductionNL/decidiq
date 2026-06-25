# Design — Decidesk Motie en Amendement Administratie

## Context

Decidesk levert vandaag Meeting, AgendaItem, en Proposal uit decidesk-base, en Fractie-register/Raadslid-fractie-historie uit decidesk-besluitvorming-workflow. De motie-administratie-spec koppelt hieraan:

- Moties worden geagendeerd als AgendaItem en vergaderden als Meeting
- Amendementen wijzigen een gekoppelde Proposal
- Stemmingen gebeuren op basis van aanwezigheid in Meeting
- Fractie-context wordt via Raadslid-fractie-historie opgehaald op stemdatum

**Huidige staat decidesk:**

- Meeting, AgendaItem, Proposal schema's bestaan (decidesk-base)
- Fractie-register + Raadslid-fractie-historie bestaan (decidesk-besluitvorming-workflow)
- Geen motie/amendement-administratie vandaag
- Geen stemming-administratie per raadslid

**Stakeholders:** raadsleden (indienen), griffier (beheer vergadering + publicatie), collegeleden (uitvoering), fractie-ondersteuners (zoeken + coördinatie), inwoners (publiek archief).

## Goals / Non-Goals

**Goals:**

- Implementeer Motie, Amendement, Stemresultaat, UitvoeringsUpdate schema's op openregister
- Zorg voor fractiële onveranderlijkheid op stemdatum (REQ-004)
- Zorg voor automatische escalatie bij stilte (REQ-005: rappels >90 dagen)
- Publiek archief + WCAG AA toegankelijk (REQ-007)
- Eindrapport-raadsperiode generatie (REQ-009)
- Motie-bingo detectie met waarschuwing (REQ-013)

**Non-Goals:**

- Interpellatie, motie van wantrouwen — aparte specs
- Commissieadvies integratie — optioneel later
- iBabs sync — openconnector-spec
- Machine-learning detectie — heuristische waarschuwing genoeg
- Livestream-deeplinks — opentalk-spec
- Per-role tool hiding (MCP) — openregister-level

## Decisions

### D1: Vier aparte schema's (Motie, Amendement, Stemresultaat, UitvoeringsUpdate)

Motie en Amendement zijn juridisch/procedureel anders → aparte schema's. Stemresultaat is snapshots per raadslid (1:many relatie met Motie/Amendement), dus aparte entiteit. UitvoeringsUpdate is event-log (1:many van Motie).

**Why:** Scherpe scheiding motie (politieke opdracht) vs. amendement (wijziging raadsvoorstel); onveranderlijkheid van Stemresultaat-fractie gegarandeerd door structuur (geen update-na-fractiewisseling). Event-log patroon voor execution-status makkelijker queryable.

### D2: Fractie als snapshot, niet als foreign key

Stemresultaat draagt `fractie_id` + `fractie_name_at_vote_time` (beide onveranderlijk vastgelegd op stemdatum), niet een mutable verwijzing naar huidige Fractie.

**Why:** Raadsleden wisselen fractie (afsplitsing, partijwisseling). Historische stemgedrag-analyse moet per fractie-periode kunnen gebeuren, niet alles aan huidige fractie toeschrijven. Fractie is snapshot, niet mutable relatie.

### D3: Motie-status machine met 7 toestanden

```
ingediend → (behandeling →)? aangenomen / verworpen / aangehouden / ingetrokken / overgenomen-door-college
```

Aangehouden → herplaatsing op volgende agenda (automatisch). Ingetrokken → indiener trekt motie terug. Overgenomen-door-college → vervalt stemming, college-toezegging als verplichte UitvoeringsUpdate.

**Why:** Overgenomen-door-college is kritieke state voor motie-bingo detectie (REQ-013); expliciet onderscheid van aanname/verwerping.

### D4: Uitvoerings-status apart van motie-status

Motie kan `aangenomen` zijn, maar uitvoering-status is `in-behandeling` / `uitgevoerd` / `gedeeltelijk-uitgevoerd` / `afgewezen`. Decoupling motie-staatsmachine van execution-progress.

**Why:** Motie-status is vastgelegd (kan niet wijzigen), uitvoerings-status is proces. Raadslid ziet `aangenomen`, collegelid ziet `in-behandeling` (wat is progress?).

### D5: UitvoeringsUpdate als event-log, niet als state replace

Elke UitvoeringsUpdate draagt datum + status-wijziging + toelichting + bijlagen. Motie.uitvoering_status is altijd de _latest_ UitvoeringsUpdate status; historie is leesbaar via UitvoeringsUpdate lijst.

**Why:** Audit trail. Raad kan zien wanneer college voor het laatst iets zei, wat gezegd werd. Rappels >90 dagen zijn query: `latest UitvoeringsUpdate.datum < now() - 90 days`.

### D6: Amendement-originele-tekst matching strikt (letterlijk)

Bij amendement-indiening: originele-tekst MOET letterlijk in het gekoppelde raadsvoorstel-tekst voorkomen, anders reject. Gewijzigde-tekst hoeft niet (da's de voorgestelde wijziging).

**Why:** Foute amendementen (verkeerde tekst gesneden, leesbaarheid-versie) moeten kapot gaan, niet stil foutief geverkt worden.

### D7: Publieke motie-pagina onder /griffie/moties/{nummer}

Unieke URL per motie (M-{jaar}-{volgnummer}), publiceerbaar naar OWMS-metadata+Atom-feed voor zoekmachines. Stemresultaten zichtbaar met fractie-context, maar geen persoonlijke stemverklaringen zonder expliciete opt-in (AVG).

**Why:** Gemeentewet artikel 169 (collegeverantwoording), Woo (proactieve openbaarmaking categorie 6), WCAG AA eisen.

### D8: Automatische rappel-banen voor >90 dagen stilte

BackgroundJob `ReminderJob` runt daily; query `uitvoerings-status = in-behandeling AND latest UitvoeringsUpdate.datum < now() - 90 days` → email aan portefeuillehouder + vlag in UI.

**Why:** Motie-bingo driver: zichtbaarheid in dagelijks signaal. Rappels zijn "ik zie je nog steeds" → actie dwingt.

### D9: Eindrapport-raadsperiode is handmatig triggered, niet automatisch

Griffier runt `/api/motions/endofterm-report` voordat raadsperiode eindigt → PDF met alle moties (status, uitvoering), openstaande overdracht naar nieuwe raad.

**Why:** Timing varieert (verkiezingen ~4 jaar, maar onderbreking kan langer zijn). Handmatig runt raad-cycle-overgangen correct.

### D10: Motie-bingo detectie is heuristische waarschuwing

Bij `motie_status = overgenomen-door-college`: systeem waarschuwt griffier "geen concrete uitvoerings-actie gekoppeld", zet op "vage-overname" lijst totdat concrete UitvoeringsUpdate geregistreerd. Raad ziet dit, kan druk zetten.

**Why:** Technisch niet te voorkomen (college beslist ter plekke), maar zichtbaarheid dwingt accountability. Niet-perfecte detectie is beter dan niets.

## Reuse Analysis

| Code path | Source | Reuse strategy |
|---|---|---|
| Meeting, AgendaItem, Proposal schemas | decidesk-base (openregister) | Foreign-key koppelingen; geen wijzigingen. |
| Fractie-register + Raadslid-fractie-historie | decidesk-besluitvorming-workflow | Snapshot op Stemresultaat.created_at; geen mutaties. |
| List/search/filter motions | `OCA\OpenRegister\Service\ObjectService` | Standaard `findAll` met filtering; geen custom code. |
| Auth: requireMember, requireChair | lib/Service/AuthHelper (bestaand) | Reuse, geen wijzigingen. |
| Full-text search (motie-titel + dispositief) | openregister FTS index | Geautomatiseerd door openregister; geen custom code. |
| Audit trail (wie, wat, wanneer) | openregister auditTrail (built-in) | Automatisch; geen custom code. |
| CSV/JSON export | openregister API | Standaard export per schema; geen custom code. |
| PDF rendering (motie, amendement, rapport) | docudesk (soft dependency) | Call docudesk `/api/render` endpoint; fallback naar HTML-as-PDF. |
| OWMS metadata + Atom feed | opencatalogi (soft dependency) | Creëer motie-publicatie als catalogus-object; fallback naar custom /griffie route. |

Geen nieuwe business logic buiten motie/amendement/stemming/execution: alles is data-entry + orchestratie van bestaande services.

## Seed Data

**Motie:**

```json
{
  "id": "m-2024-001",
  "title": "Aanvraag herinrichting Marktplein volgens burgerinitiatief",
  "proposer_id": "councilor-alice-vvd",
  "proposer_party_id": "frac-vvd",
  "co_signers": ["councilor-bob-vvd"],
  "preamble": "Gelet op het veel ondertekende burgerinitiatief met 2.450 handtekeningen...",
  "dispositif": "De raad draagt het college op om...",
  "meeting_id": "mtg-2024-06-14",
  "agenda_item_id": "ai-marktplein-herinrichting",
  "motie_status": "aangenomen",
  "voting_type": "hoofdelijk",
  "execution_status": "in-behandeling",
  "execution_deadline": "2024-12-31",
  "portfolio_holder_id": "alderman-carol-vvd",
  "submitted_at": "2024-06-14T10:30:00Z",
  "published_at": "2024-06-15T09:00:00Z"
}
```

**Amendement:**

```json
{
  "id": "a-2024-002",
  "title": "Wijziging artikel 3 raadsvoorstel herinrichting Marktplein",
  "proposer_id": "councilor-diana-greens",
  "proposer_party_id": "frac-greens",
  "proposal_id": "prop-2024-marktplein",
  "original_text": "Bij de herinrichting wordt betonverharding toegepast.",
  "modified_text": "Bij de herinrichting wordt groene verharding en boomenlaan aangelegd.",
  "rationale": "Voor klimaatadaptatie en biodiversiteit.",
  "amendement_status": "aangenomen",
  "voting_type": "hoofdelijk",
  "submitted_at": "2024-06-14T11:00:00Z"
}
```

**Stemresultaat:**

```json
{
  "id": "sr-2024-m001-alice",
  "motie_id": "m-2024-001",
  "raadslid_id": "councilor-alice-vvd",
  "fractie_id": "frac-vvd",
  "fractie_name_snapshot": "VVD",
  "vote": "voor",
  "voting_explanation": null,
  "voted_at": "2024-06-14T11:15:00Z"
}
```

**UitvoeringsUpdate:**

```json
{
  "id": "eu-2024-m001-001",
  "motie_id": "m-2024-001",
  "status_change": "in-behandeling",
  "explanation": "Plan van aanpak opgesteld; meerjarenprogramma in voorbereiding.",
  "attachments": ["mcplan-marktplein-2024.pdf"],
  "updated_by": "alderman-carol-vvd",
  "updated_at": "2024-09-30T14:00:00Z"
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| **R1 — Fractie-snapshot correctheid.** Stamdata Raadslid-fractie-historie ontbreekt of is incorrect bij stemdatum. | Unit test assert snapshot gelezen op stemdatum; geen hardcoded fictie-ID. Integration test met real fractie-register data. |
| **R2 — Amendement-matching false negative.** Originele-tekst in raadsvoorstel is ander woordwrapping/spacing → letterlijke match fails. | Strip whitespace bij matching; advies: indiener copy-paste uit digitaal raadsvoorstel. Reject-error helpt user troubleshoot. |
| **R3 — Motie-bingo niet perfect detecteerbaar.** College bedenkt toezegging maar zet niet direct UitvoeringsUpdate (vergadering voorbij). Pas volgende dag update → heuristische waarschuwing gemist. | Waarschuwing is heuristisch, niet juridisch dwingend. Raad ziet vage-overname lijst, kan vervolgvragen stellen. Beter dan niets. |
| **R4 — PDF rendering voor eindrapport scaling.** Grote raadsperiodes (200+ moties) → PDF generation slow / memory-heavy. | Async job + queue; user krijgt email met link naar PDF in storage. Fallback: HTML-view met print-to-PDF instructies. |
| **R5 — Archief-zoekopdracht performance.** FTS-index op titel + dispositief + toelichting kan groot worden (gemeenten × jaren). | OpenRegister FTS is geoptimaliseerd; filteren op jaar/fractie/portefeuillehouder reduceert resultaatset. Paginatie 20/pagina. |
| **R6 — AVG: stemverklaringen met namen.** Raadslid voegt stemverklaring toe → PII in openbaar archief. | Expliciete opt-in vereist ("Mijn verklaring publiceren"). Standaard anonyme tekst. AVG-grondslag in terms. |
| **R7 — Raadsperiode-overdracht dubbel werk.** Griffier moet openstaande moties manueel naar nieuwe raad overbrengen; geen bulk-import. | Geef griffier CSV-export openstaande moties; bulk-import UI (taak 2.4) laadt per motie-id + wijzigt portefeuillehouder. |

## Migration Plan

**Forward path:**

1. Land decidesk-base (Meeting, AgendaItem, Proposal) en decidesk-besluitvorming-workflow (Fractie, Raadslid-fractie-historie).
2. Patch openregister met Motie/Amendement/Stemresultaat/UitvoeringsUpdate schema's (OR-PR).
3. Land deze decidesk PR:
   - Add lib/Service/MotionService, AmendmentService, VotingService, ExecutionService
   - Add lib/Controller/MotionController, AmendmentController, VotingController, ExecutionController
   - Add lib/Jobs/ReminderJob, PublishMotionJob
   - Add resources/js/views/* en components/VotingMatrix.vue
   - Add tests/Unit/Service/* en tests/Integration/MotionWorkflowTest.php
   - Add docs/features/motions-amendments.md, docs/features/execution-tracking.md

**No data migration.** Moties/amendementen zijn per raadsvergadering (handmatig ingediend), geen bulkdata.

**Rollback:** revert PR. Motie-schema's op openregister verstoppen (soft-delete); gee Motie/Amendement-controllers terug op 404. Geen data cleanup (historisch archief behouden).

**Compatibility:** Opt-in via raadsvergadering-agendapunt type. Bestaande vergaderingen werken zonder moties.
