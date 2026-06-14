# Spec delta: ORI API — motions serialized from typed decisions

This file contains delta specifications for the `unify-decision-supertype` change against the existing `ori-api` capability. Per ADR-003/ADR-005 the ORI Motion serialization is sourced from `decision` objects where `decisionType = motion` (the retired `motion` schema is gone), as a thin Popolo projection at the serialization boundary. The endpoint path and response shape are unchanged; only the storage source changes. The existing `/api/ori/v1/events` requirements are unaffected.

---

## ADDED Requirements

### Requirement: REQ-ORI-006 — ORI Motion endpoint sourced from typed decisions

The system SHALL expose motions via the ORI-compatible endpoint `GET /api/ori/v1/motions`, serializing `decision` objects where `decisionType = motion` into Popolo/ORI Motion format. The folded decision fields SHALL map to Popolo Motion fields (`title → name`, `text → text`, `proposer → creator`, `coSigners → cosignatories`, `outcome → result`, `legalBasis → legislativeReference`). Storage SHALL be the unified `decision` schema; the Popolo mapping SHALL remain a boundary projection (ADR-001 §Consequences). The endpoint SHALL be publicly accessible (`#[PublicPage]`, `#[NoCSRFRequired]`) and SHALL only serialize decisions whose `isPublished = public`. The response shape SHALL be byte-compatible with the pre-fold ORI Motion output so existing ORI consumers (e.g. Dutch municipalities) require no change.

#### Scenario: REQ-ORI-006-S1 — Motion decisions serialized as ORI Motions

@e2e exclude open-data API contract — covered by Newman, not a UI flow

- **GIVEN** decisions exist with `decisionType = motion` and `isPublished = public`
- **WHEN** GET `/api/ori/v1/motions` is called
- **THEN** each published motion decision is returned as a Popolo/ORI Motion with `name`, `text`, `creator`, and `result` mapped from the folded decision fields

#### Scenario: REQ-ORI-006-S2 — Non-motion and non-public decisions are excluded

@e2e exclude open-data API contract — covered by Newman, not a UI flow

- **GIVEN** decisions exist with `decisionType = resolution` and decisions with `decisionType = motion` but `isPublished = internal`
- **WHEN** GET `/api/ori/v1/motions` is called
- **THEN** neither resolution decisions nor non-public motion decisions appear in the response

#### Scenario: REQ-ORI-006-S3 — Response shape unchanged for consumers

@e2e exclude open-data API contract — covered by Newman contract test asserting shape parity

- **GIVEN** the ORI Motion response shape recorded before the supertype fold
- **WHEN** GET `/api/ori/v1/motions` is called after the fold
- **THEN** the response shape (fields, JSON-LD `@context`, Popolo namespaces) is identical, now sourced from `decision` objects
