# ingekomen-stukken-register Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [toezeggingen-ingekomen-stukken](../../changes/toezeggingen-ingekomen-stukken/)

## Purpose

The lijst ingekomen stukken: registration of documents received by the council (letters from residents and organisations, college submissions, petitions, invitations), a griffie routing advice per document, placement on the dedicated "Lijst ingekomen stukken" agenda item of the next council meeting, bulk council confirmation of the routing decisions via the existing hamerstuk flow (`agenda-live-management` REQ-LIV-003), follow-up status tracking, and WOO-aware public publication in which natural-person senders are anonymised. Files attach through the existing Files leaf; e-mail intake automation and full DMS are out of scope.

## ADDED Requirements

> Decision (24 Nov 2026): since documents-as-agenda-items an incoming document is an agenda item of a kind flagged `incomingDocument`, placed with `PutOnAgendaDialog` and adopted through the consent agenda (`openspec/specs/documents-as-agenda-items`). The requirements this change first drafted for a separate `IngekomenStuk` schema, its lifecycle, placement, bulk confirmation and list page (REQ-001 to REQ-004 and REQ-006) were superseded by that model and are dropped here. REQ-005 was built on the new model (`incoming-document` publication) and stays.

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
