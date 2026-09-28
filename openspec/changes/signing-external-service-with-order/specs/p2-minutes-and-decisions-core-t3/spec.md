# p2-minutes-and-decisions-core-t3 Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [signing-external-service-with-order](../../) (this delta)

## Purpose

Signers can be chosen for minutes and a backend sends minutes to a signing service through integriq, but no screen starts it, there is no signing order, and decision lists, proposals and motions cannot be sent. Moves decidiq matrix rows min-17 toward built.

## ADDED Requirements

### Requirement: REQ-SES-001 Send for signature in a chosen order and store the signed copy

Minutes, decision lists and motions SHALL be sendable to the external signing service with signers in a chosen order, and the signed copy SHALL be stored back on the record.

#### Scenario: The decision list is signed
- GIVEN the decision list of 14 October with signers chair then griffier
- WHEN the griffier presses Send for signature
- THEN the signing service receives both signers in that order and, once signed, the signed copy is linked on the decision list
