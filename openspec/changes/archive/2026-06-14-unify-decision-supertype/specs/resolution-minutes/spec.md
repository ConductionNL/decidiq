# Spec delta: Resolution and Minutes — resolutions stored as typed decisions

This file contains delta specifications for the `unify-decision-supertype` change against the existing `resolution-minutes` capability. Per ADR-005/ADR-006 the separate `resolution` schema is retired; a resolution is now a `decision` with `decisionType = resolution`. The Resolution Generation requirement is updated so the generated resolution is stored as a `decisionType = resolution` decision (carrying the folded resolution fields) rather than a standalone `resolution` object. All minute-taking, approval and proof-package requirements are unchanged.

---

## MODIFIED Requirements

### Requirement: Resolution Generation

The system MUST support generating formal resolution texts from adopted decisions. Resolutions MUST include the decision text, voting results, legal basis, date of adoption, and governing body. A generated resolution MUST be stored as a `decision` OpenRegister object with `decisionType = resolution` (the retired standalone `resolution` schema is replaced per ADR-005), carrying the folded resolution fields (`resolutionNumber`, resolution `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `adoptionDate`, `effectiveDate`). Resolutions MAY be rendered as documents via Docudesk.

**Feature tier**: V1

#### Scenario: Generate a resolution as a typed decision

@e2e exclude resolution records are generated server-side by the decision enact transition (decision-state-machine-v1); the triggering UI is the DecisionLifecycleTab covered by the decision-management spec's e2e suite — no separate minutes-side surface exists by design

- GIVEN a decision that has been adopted with voting results (14 for, 5 against, 1 abstain)
- WHEN the secretary triggers "Generate Resolution"
- THEN the system MUST create a `decision` object with `decisionType = resolution` carrying the decision text, voting results, adoption date, and governing body
- AND the resolution decision MUST have a unique sequential `resolutionNumber` per body (e.g., "2026-BES-042")
- AND the resolution MUST be available for export as PDF via Docudesk

#### Scenario: Generate a resolution with legal basis references

@e2e exclude backend template rendering with no UI surface of its own (PHPUnit-covered in MinutesGenerationServiceTest / ResolutionServiceTest); the legal-basis text appears inside the generated document verified server-side

- GIVEN an adopted decision referencing Gemeentewet article 160
- WHEN the resolution is generated
- THEN the resolution decision MUST include the legal basis ("Gelet op artikel 160 van de Gemeentewet")
- AND the resolution text MUST follow Akoma Ntoso structure (preface, body, conclusions)

#### Scenario: Provide proof of proper adoption for notarial deed

- GIVEN a statute amendment resolution adopted with qualified majority
- WHEN the notary requests proof of proper adoption
- THEN the system MUST generate a complete package including: convocation proof, quorum verification, voting results, and the resolution text
- AND the package MUST be verifiable and tamper-evident
