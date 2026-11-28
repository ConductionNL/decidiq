# ingekomen-stukken-register Specification

## Purpose

Requirements introduced by the toezeggingen-ingekomen-stukken change.

## Requirements

### Requirement: REQ-005 Public publication with WOO-aware anonymisation

Publication of the lijst ingekomen stukken SHALL go through the existing public-publication machinery (derived, immutable, allow-list payload + `publicatiedatum` predicate; OpenCatalogi routing when configured): an IngekomenStuk is eligible once it reaches `routering-vastgesteld` (or `afgedaan`) and its meeting is public. The payload SHALL carry title, category, receivedAt, routing decision, and sender rendered per senderType: for `natuurlijk-persoon` the sender SHALL be anonymised to a neutral label (e.g. "Inwoner") — the payload SHALL NOT contain the natural person's name, address, or contact details; for `organisatie`/`bestuursorgaan` the sender name is carried as-is. Anonymisation SHALL be enforced structurally in payload construction, independent of UI state. The system SHALL NOT serve app-local anonymous pages for ingekomen stukken.

#### Scenario: Natural-person sender anonymised

@e2e exclude a payload contract with no page of its own: IncomingDocumentPublicationTest::testAResidentSenderIsAnonymised asserts the sender reads Inwoner and the name and contact details appear nowhere, and flips when the sender type changes

- GIVEN an IngekomenStuk with senderType `natuurlijk-persoon` and sender "J. Jansen" in `routering-vastgesteld`
- WHEN staff publish it and the payload is read through the OR published-predicate surface
- THEN the payload shows sender "Inwoner" and contains no occurrence of "J. Jansen" or contact details

#### Scenario: Organisation sender published as-is

@e2e exclude a payload contract with no page of its own: IncomingDocumentPublicationTest::testTheSenderFollowsItsType asserts an organisation's name is carried as-is

- GIVEN an IngekomenStuk with senderType `organisatie` and sender "Stichting Dorpsbelang"
- WHEN it is published
- THEN the payload carries "Stichting Dorpsbelang" as sender

#### Scenario: Unconfirmed stuk not eligible

@e2e exclude eligibility contract — covered by PHPUnit on the eligibility service plus Newman negative test
- GIVEN an IngekomenStuk in `geregistreerd`
- WHEN a publish request is made for it
- THEN the request is rejected with an eligibility error and no payload is created
