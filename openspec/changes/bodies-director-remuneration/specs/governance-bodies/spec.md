# governance-bodies Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-director-remuneration](../../) (this delta)

## Purpose

Records what the holders of a body's positions are paid, per year, with the decision that set it, and discloses it when the organisation marks it for disclosure. Closes decidiq matrix row bod-17.

**Standards**: Schema.org `MonetaryAmount`, Wet normering topinkomens (WNT) disclosure, Dutch Corporate Governance Code (remuneration of the supervisory board).

## ADDED Requirements

### Requirement: REQ-DRM-001 A position holder's remuneration is recorded per year

The app SHALL let the secretariat record, per position hold and year, a fixed annual fee, a fee per meeting, an expense allowance, the currency, the decision that set them, and whether the record is disclosed with a publication date. Only the secretariat and administrators SHALL read these records, except that anyone SHALL read a record that is disclosed and whose publication date has passed.

#### Scenario: The company secretary records the chair's fee
- GIVEN the general meeting decided the supervisory board's fees for 2026
- WHEN the company secretary records 32000 EUR fixed and 1500 EUR expenses for the chair, linked to that decision
- THEN the record is saved and shows on the chair's position hold

#### Scenario: A member cannot read a colleague's pay
- GIVEN the chair's record is not disclosed
- WHEN a member of the supervisory board lists remuneration
- THEN the chair's record is not returned

#### Scenario: Disclosed pay is public
- GIVEN the chair's record is disclosed with publication date 30 April 2026
- WHEN an anonymous visitor reads it on 1 May 2026
- THEN the record is returned

### Requirement: REQ-DRM-002 The body page shows this year's remuneration and its total

The governance body page SHALL show the remuneration records of the current year and the total of their fixed fees, to readers allowed to read them.

#### Scenario: The secretary checks the board's cost
- GIVEN two records for 2026, of 32000 and 24000 EUR fixed
- WHEN the company secretary opens the supervisory board's page
- THEN she sees both records and a total of 56000 EUR

### Requirement: REQ-DRM-003 A position hold lists its remuneration over the years

The position hold page SHALL list that hold's remuneration records, newest year first.

#### Scenario: Two years of fees
- GIVEN the chair's hold has records for 2025 and 2026
- WHEN the company secretary opens the hold
- THEN 2026 is listed above 2025
