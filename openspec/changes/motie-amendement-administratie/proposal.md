---
kind: code
depends_on: [decidesk-base, decidesk-besluitvorming-workflow]
---

# Decidiq — Motie en Amendement Administratie

## Why

Nederlandse gemeenteraden gebruiken moties en amendementen als hun twee meest gebruikte politieke instrumenten om het college aan te sturen en raadsvoorstellen te wijzigen. Echter, zonder gestructureerde administratie verdwijnen uitvoeringsstatus uit beeld, kunnen raadsleden niet snel stemgedrag van fracties terugvinden, en verliest de griffie de context bij raadswisselingen. Daarnaast is het zogenaamde "motie-bingo" — collegeleden nemen moties bij voorbaat over zonder degelijk uitvoerings-spoor — een hardnekkig probleem.

Deze spec levert een geïntegreerde administratie waarin moties en amendementen vanaf indiening tot afhandeling worden gevolgd, met:

- Volledige hoofdelijke stemming per raadslid
- Fractie-snapshot op stemdatum (voor historische correctheid bij fractiewisseling)
- Koppeling aan agendapunten en raadsvoorstellen
- Uitvoeringsstatus bijgehouden door college met automatische rappels bij stilte >90 dagen
- Doorzoekbaar historisch archief over raadsperiodes heen
- Automatische publicatie naar publiek griffieportaal conform WCAG AA
- Expliciete scheiding motie (politieke opdracht aan college) vs. amendement (wijziging raadsvoorstel)

De spec sluit aan op decidesk-base (vergaderingen, agendapunten, raadsvoorstellen) en de fractie-administratie uit decidesk-besluitvorming-workflow.

## What Changes

- **NEW** Motion (Motie) schema: titel, indiener (fractie + raadslid), mede-indieners, constaterende overwegingen, dispositief, agendapunt-koppeling, vergadering-koppeling, indienings-datum, status (ingediend / behandeling / aangenomen / verworpen / aangehouden / ingetrokken / overgenomen-door-college), stemming-type (hoofdelijk / bij-zitten-en-opstaan / unaniem), uitvoering-status (niet-van-toepassing / in-behandeling / uitgevoerd / afgewezen / gedeeltelijk-uitgevoerd), uitvoeringsdatum, portefeuillehouder, publicatie-datum.

- **NEW** Amendment (Amendement) schema: titel, indiener (fractie + raadslid), mede-indieners, raadsvoorstel-koppeling (verplicht), originele-tekst, gewijzigde-tekst, toelichting, status (ingediend / aangenomen / verworpen / aangehouden / ingetrokken / overgenomen-door-college), stemming-type, indienings-datum.

- **NEW** Vote Result (Stemresultaat) schema: motie-of-amendement-koppeling, raadslid-koppeling, fractie-koppeling (snapshot), stem (voor / tegen / onthouden / afwezig / niet-deelgenomen), stemverklaring (optioneel), stemming-datum.

- **NEW** Execution Update (UitvoeringsUpdate) schema: motie-koppeling, datum, status-wijziging, toelichting (rich text), bijlagen (collegebrief, voortgangsrapportage), update-door-gebruiker.

- **NEW** UI für Motie-indiening, stemregistratie (hoofdelijke matrix per raadslid), uitvoeringsstatus-beheer door collegelid, zoekopdracht over motie-archief, publieke motie-pagina met OWMS-metadata.

- **NEW** API-endpoints: GET/POST /motions, GET/POST /amendments, GET/POST /voteresults, POST /executionupdates, GET /motions/{id}/executions, GET /motions/search.

- **NEW** workflows: motie-indiening → behandeling → stemming → uitvoering → rapportage; amendement-indiening → gekoppeld aan raadsvoorstel → stemming → integratie in raadsvoorstel-tekst.

- **NEW** automatische publicatie naar /griffie/moties/{nummer} bij aanname/verwerping, met WCAG AA toegankelijkheid.

- **NEW** eindrapport-raadsperiode generatie (PDF) met alle moties, uitvoeringsstatus, openstaande motie-overdracht naar nieuwe raad.

No changes to existing decidesk-base schemas. No changes to fractie-administratie.

## Capabilities

### New Capabilities

- `motie-administratie`: Volledige levenscyclus van motie-indiening tot uitvoering, met fractie-snapshot stemming, automatische escalatie bij stilte, publieke archief-publicatie, en raadsperiode-rapportage.
- `amendement-administratie`: Amendement-indiening gekoppeld aan raadsvoorstel, diff-weergave, stemming, en automatische integratie van aangenomen amendementen in raadsvoorstel-tekst.
- `stemming-administratie`: Hoofdelijke stemming per raadslid met fractie-snapshot (onveranderlijk), stemverklaring bij afwijkend stemgedrag, en publieke stemverklaring-publicatie.
- `uitvoerings-tracking`: Motie-uitvoeringsstatus beheerd door college, tijdlijn van updates, automatische rappels bij >90 dagen stilte, "vage-overname" detectie, bulk-import van openstaande moties bij raadswissel.

### Modified Capabilities

None. Decidesk-base (vergaderingen, agendapunten, raadsvoorstellen) levert de koppelingspunten; dit is puur additief.

## Impact

**Code:**

- `lib/Schema/Motion.php`, `lib/Schema/Amendment.php`, `lib/Schema/VoteResult.php`, `lib/Schema/ExecutionUpdate.php` (new — schema definitions on openregister).
- `lib/Controller/MotionController.php`, `lib/Controller/AmendmentController.php`, `lib/Controller/VotingController.php`, `lib/Controller/ExecutionController.php` (new — CRUD + business logic endpoints).
- `lib/Service/MotionService.php`, `lib/Service/AmendmentService.php`, `lib/Service/VotingService.php`, `lib/Service/ExecutionService.php` (new — business logic).
- `lib/Jobs/ReminderJob.php` (new — 90-day execution reminder background job).
- `lib/Jobs/PublishMotionJob.php` (new — publish approved motion to /griffie/moties/{id}).
- `resources/js/views/MotionList.vue`, `resources/js/views/MotionDetail.vue`, `resources/js/views/MotionCreate.vue`, `resources/js/components/VotingMatrix.vue` (new — frontend).
- `resources/js/views/AmendmentList.vue`, `resources/js/views/AmendmentCreate.vue` (new — frontend).
- `resources/js/views/ExecutionTimeline.vue` (new — frontend).
- `docs/features/motions-amendments.md`, `docs/features/execution-tracking.md` (new — operator docs).
- `tests/Unit/Service/MotionServiceTest.php`, `tests/Unit/Service/VotingServiceTest.php`, `tests/Integration/MotionWorkflowTest.php` (new — test coverage).

**Dependencies:**

- Hard dependency on decidesk-base (Meeting, AgendaItem, Proposal schemas exist).
- Hard dependency on decidesk-besluitvorming-workflow (Fractie-register + raadslid-fractie-historie beschikbaar).
- Soft dependency on docudesk (for PDF rendering of motions/amendments, optional).
- Soft dependency on opencatalogi (for public motion catalogue publication with TOOI metadata, optional).

**Reused (no changes needed):**

- `Meeting`, `AgendaItem`, `Proposal` from decidesk-base — motie/amendement koppelen via foreign keys.
- Fractie-register + Raadslid-fractie-historie from decidesk-besluitvorming-workflow — voor Stemresultaat-snapshot.
- `OCA\OpenRegister\Service\ObjectService` — list/filter/search motions/amendments.
- Bestaande auth helpers (requireChair, requireMember, etc.) — geen nieuwe per-role checks.

**Out of scope (explicit non-goals):**

- Interpellatie, motie van wantrouwen, raadsvoorstel-indiening door raadslid — andere specs.
- Commissieadvies op amendement — optioneel uitbreiding later.
- Machine learning detectie "motie-bingo" — heuristische waarschuwing (zie REQ-013).
- Bi-directionele sync met iBabs/GemeenteOplossingen — openconnector-spec later.
- Livestream-deeplinks naar stem-moment per motie — opentalk-spec later.
- Raadslid-fractie-administratie — aparte spec.
