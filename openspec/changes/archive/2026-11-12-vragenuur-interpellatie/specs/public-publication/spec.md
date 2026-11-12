# public-publication Specification (delta)

## ADDED Requirements

### Requirement: REQ-VRI-015 Publication of oral questions and interpellation requests

The system SHALL extend the existing publication machinery (eligibility gate, derived allow-list payloads, OR published-predicate + OpenCatalogi routing, withdraw/rectify — all per the canonical `public-publication` requirements, unchanged) with two payload types. `MondelingeVraag` objects SHALL be eligible in lifecycle `toegelaten`, `ingepland`, or `beantwoord` when the target meeting is public; the payload allow-list SHALL carry vraagNummer, onderwerp, motivering, indiener name, fractie name, portefeuillehouder name, target meeting reference, lifecycle, and — once answered — antwoordSamenvatting and beantwoordDoor name; `afgewezen` and `ingetrokken` questions SHALL NOT be publishable. `Interpellatieverzoek` objects SHALL be eligible in lifecycle `toegelaten`, `geagendeerd`, or `behandeld`; the payload allow-list SHALL carry verzoekNummer, onderwerp, vragen, verzoeker name, fractie name, portefeuillehouder name, support count (never the individual supporter list), raadsbesluitDatum, lifecycle, and — once treated — behandelingVerslag. Eligibility SHALL be enforced server-side on every publish request; payload shapes SHALL follow the existing OpenRaadsinformatie-aligned conventions (ORI mapping: both types publish as ORI question-type documents attached to the meeting event; Akoma Ntoso `question`/`answer` semantics noted in the payload metadata). This requirement is deliberately ADDED-only — it extends the eligibility matrix without restating it, so it composes with the concurrent `toezeggingen-ingekomen-stukken` modification of the matrix requirement.

#### Scenario: Answered oral question published with its answer

- GIVEN a `beantwoord` MondelingeVraag whose target meeting is public
- WHEN a staff member publishes it
- THEN a derived payload is created containing the question, the answer summary, and the answerer's name
- AND the payload appears on the public surface via the existing predicate/OpenCatalogi routing

#### Scenario: Rejected question refused

@e2e exclude eligibility-matrix contract — covered by PHPUnit on PublicationEligibilityService plus Newman negative test
- GIVEN a MondelingeVraag in lifecycle `afgewezen`
- WHEN a publish request is made for it
- THEN the request is rejected with an eligibility error and no publication payload is created

#### Scenario: Interpellation payload carries the support count, never the supporters

@e2e exclude payload-shape contract — covered by PHPUnit asserting supporter names are structurally absent (mutation-guarded: adding a supporter changes only the count)
- GIVEN a `behandeld` interpellatieverzoek with 5 steunbetuigingen
- WHEN its payload is built
- THEN the payload contains the support count 5
- AND no individual supporter reference or name appears anywhere in the payload
