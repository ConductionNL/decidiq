# public-publication Specification (delta)

## MODIFIED Requirements

### Requirement: Publication eligibility gates

The system SHALL allow publication only of: `Decision` objects in status `decided` or `enacted`; meeting agendas whose parent `Meeting` has `isPublic: true` and whose convocation has been sent; `Minutes` objects in lifecycle `approved`; and `IngekomenStuk` objects in lifecycle `routering-vastgesteld` or `afgedaan` whose confirming meeting is public (toezeggingen-ingekomen-stukken). `Toezegging` objects publish via a `publicatiedatum` predicate on the live object (their schema carries no non-public fields by construction) and are therefore outside the derived-payload eligibility matrix; the predicate SHALL still only ever be set by an explicit staff action. Eligibility SHALL be enforced server-side on every publish request, independent of UI state. The system SHALL maintain a type-level deny-list — `BoardMeeting`, `BoardMinutes`, `BoardMaterial`, `BoardVote`, `ConflictOfInterest`, `BoardAuditLogEntry`, `Vote`, `VotingRound`, and `Resolution` objects of boards with a confidentiality classification — for which publication payload construction SHALL be structurally refused.

#### Scenario: Publish an enacted decision

- **GIVEN** a staff member with governance-body authority and a decision in status `enacted`
- **WHEN** they trigger the publish action on the decision detail view
- **THEN** a publication payload is created and the decision shows as published

#### Scenario: Draft decision refused

@e2e exclude eligibility-matrix contract — covered by PHPUnit (PublicationEligibilityServiceTest::testDraftDecisionRefused) and Newman against the publish endpoint
- **GIVEN** a decision in status `draft`
- **WHEN** a publish request is made for it
- **THEN** the request is rejected with an eligibility error and no publication payload or `PublicationRecord` is created

#### Scenario: Agenda of a non-public meeting refused

@e2e exclude eligibility-matrix contract — covered by Newman against the publish endpoint
- **GIVEN** a meeting with `isPublic: false` and a finalized agenda
- **WHEN** an agenda publish request is made
- **THEN** the request is rejected and nothing is published

#### Scenario: Board material structurally refused

@e2e exclude type deny-list contract — covered by PHPUnit on the payload service plus Newman negative test
- **WHEN** a publish request targets a `BoardMinutes` or `BoardMaterial` object (any status)
- **THEN** the payload service refuses with a not-publishable error before any eligibility evaluation and no object is created

#### Scenario: Non-staff publish rejected

@e2e exclude API authorization contract — covered by Newman, not a UI flow
- **WHEN** an authenticated user without governance-body authority calls the publish endpoint for an eligible decision
- **THEN** the request is rejected with HTTP 403 via OpenRegister per-object RBAC and nothing is published

#### Scenario: Unconfirmed ingekomen stuk refused

@e2e exclude eligibility-matrix contract — covered by PHPUnit on the eligibility service plus Newman negative test
- **GIVEN** an `IngekomenStuk` in lifecycle `geregistreerd` or `geagendeerd`
- **WHEN** a publish request is made for it
- **THEN** the request is rejected with an eligibility error and no publication payload or `PublicationRecord` is created
