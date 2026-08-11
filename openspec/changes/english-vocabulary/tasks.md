# Tasks — english-vocabulary (decidesk)

Scan: **36 schemas / 109 Dutch properties** across 15 register fragments; code layer
almost clean (**0 files, 0 classes, 1 method**). The vocabulary is Dutch municipal
governance law, so this app is where the statutory rule does most of its work.

## 1. Check the slug namespace before choosing any English name

- [ ] 1.1 Check every proposed English schema name against **all apps'** declared slugs.
      Resolution is instance-global. decidesk is already party to five collisions
      (`decision`, `product`, `notification`, `adviesaanvraag`, and `Toezegging`'s
      prospective `Commitment`).
- [ ] 1.2 `Toezegging` → **`CouncilCommitment`**, not `Commitment` — shillinq's PR #495
      introduces `Commitment*` on merge. The collision is latent today and lands then.
- [ ] 1.3 Do **not** rename decidesk's `Decision` schema (the ADR-005 governance
      supertype). It already collides with procest's `decision`; resolving that belongs
      to the fleet change.
- [ ] 1.4 Flag `Adviesaanvraag`: procest declares it too, and both apps' changes
      independently plan `AdviceRequest`. Do not claim the name unilaterally.

## 2. Count stored objects

- [ ] 2.1 Count objects per schema across all 36 — too many to assume greenfield. Read
      the per-schema shard tables, exclude soft-deleted rows.

## 3. Rename schemas with statute markers

- [ ] 3.1 `TermijnagendaItem` → `ScheduledAgendaItem`; `Regeling`/`RegelingVersie`/
      `RegelingExportPackage` → `Regulation`/`RegulationVersion`/`RegulationExportPackage`;
      `Bevoegdheidstoedeling` → `PowersAssignment`; `Zienswijze` → `FormalView`;
      `Nevenfunctie` → `AncillaryPosition`; `Geheimhouding` → `Confidentiality`;
      `Voordracht` → `Nomination`; `Raadsinformatiebrief` → `CouncilInformationLetter`;
      `Interpellatieverzoek`/`MondelingeVraag` → `InterpellationRequest`/`OralQuestion`;
      `KascommissieVerklaring` → `AuditCommitteeStatement`; `OnboardingTraject`/
      `OffboardingTraject` → `OnboardingProcess`/`OffboardingProcess`;
      `RoosterVanAftreden`/`RoosterRegel` → `RetirementSchedule`/`RetirementScheduleEntry`;
      `TermijnRegeling` → `TermRule`; `GoverningDocumentVersie` → `GoverningDocumentVersion`.
- [ ] 3.2 Attach jurisdiction + instrument markers to each statutory schema.

## 4. Rename properties, adopting the fleet words

- [ ] 4.1 The publication pair on all 15 schemas that carry it → the fleet's
      `publicationDate`/`depublicationDate`. decidesk is its heaviest user; it must not
      invent an alternative.
- [ ] 4.2 Validity boundaries → `validFrom`/`validUntil`: `geldigVanaf`/`geldigTot`,
      `vervaldatum`, `ingangsdatum`, `eindeDatum`, `eindeTermijnDatum`.
- [ ] 4.3 **Event dates keep event names** — `toetredingsDatum`/`uittredingsDatum` →
      `joinedOn`/`leftOn`, `beëdigingsDatum` → `swornInOn`, `aktedatum` → `deedDate`,
      `ingediendDatum` → `submittedOn`, `ontvangenOp` → `receivedOn`. Do not flatten these
      into the validity pair; the semantics differ and a lifecycle rule would read the
      wrong field.
- [ ] 4.4 `besluit*` **properties** → `decision*`; `bekrachtigingsbesluit`/
      `opheffingsbesluit` → `ratificationDecision`/`revocationDecision`. Properties only —
      the `Decision` schema is out of scope per 1.3.
- [ ] 4.5 `Regeling`'s `citeertitel` + `officieleTitel` → `citationTitle` + `officialTitle`.
      The one place fleet-wide where `titel` → `title` is insufficient.
- [ ] 4.6 The remainder: `onderwerp` → `subject`, `toelichting` → `notes`, `omschrijving`
      → `description`, `fractie` → `politicalGroup`, `bijlagen` → `attachments`,
      `afdoeningsToelichting` → `dispositionNotes`, `redenVervallen` → `lapseReason`,
      `geschatteWaarde` → `estimatedValue`, `geschenkDrempelbedrag` → `giftThresholdAmount`,
      `indieningstermijnUren` → `submissionWindowHours`,
      `interpellatieSteunDrempelWaarde` → `interpellationSupportThreshold`,
      `technischeVragenEind` → `technicalQuestionsClose`, `commissieDatum`/
      `behandelingDatum` → `committeeDate`/`hearingDate`, `behandelendeCommissie`/
      `besluitvormendOrgaan` → `handlingCommittee`/`decidingBody`, `verwerking`/
      `verwerkingsToelichting` → `processing`/`processingNotes`, `termijnDuurMaanden`/
      `maxAansluitendeTermijnen`/`termijnNummer` → `termLengthMonths`/`maxConsecutiveTerms`/
      `termNumber`, `persoonNaam`/`externeNaam` → `personName`/`externalName`,
      `voordragendePartij.naam` → `nominatingParty.name`, `bronSchriftelijkeVraag` →
      `sourceWrittenQuestion`, `raadsbesluitDatum` → `councilDecisionDate`,
      `behandeldIn`/`behandelingVerslag` → `handledIn`/`hearingReport`.
- [ ] 4.7 Enumerate the two diacritic properties (`beëdigingsDatum`,
      `beëdigingsVergadering`) by hand — ASCII-oriented matching skips them and the sweep
      still reports complete.

## 5. Fragment safety

- [ ] 5.1 Before editing any `required` list, check whether a second fragment declares the
      same schema. ADR-037 concatenates list values on merge — two declaring fragments
      produce a schema demanding both vocabularies, which is the shillinq#485 defect.
      `46-urgency-policy.json` and `55-governing-documents-register.json` are already
      additive deltas on `Decision`.

## 6. Code, translations, gates

- [ ] 6.1 Rename `createDossierFolder` in `VotingRoundCloser` — the app's only Dutch method.
- [ ] 6.2 `l10n/nl.json` re-pointed not re-extracted; `check-l10n`.
- [ ] 6.3 Re-run the token-aware scan; require 0/0.
- [ ] 6.4 Full suite plus hydra gates 46/53/54/55/57/61.

## Acceptance criteria

- Token-aware scan reports decidesk at 0/0.
- Every proposed schema name checked against the fleet slug list; no new collision added.
- `CouncilCommitment` used, not `Commitment`; decidesk's `Decision` schema untouched.
- Event dates keep event names; only true validity boundaries take `validFrom`/`validUntil`.
- Both diacritic properties renamed.
- Dutch UI labels unchanged; `check-l10n` passes.
