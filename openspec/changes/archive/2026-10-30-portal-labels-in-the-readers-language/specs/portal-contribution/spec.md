# portal-contribution Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [portal-labels-in-the-readers-language](../../) (this delta)

## ADDED Requirements

### Requirement: REQ-PLR-001 A resident reads decidiq's portal labels in their own language

Every string of decidiq's citizen contribution that a portal visitor reads (`label` and `group`, on the contribution, its collections and its actions) SHALL be translated through decidiq's own catalogue in the language Nextcloud selects for the request. Identifiers, schema slugs, field names and filter values SHALL never be translated. The section heading SHALL name what the section is about, not the product. A provider built without a translation factory SHALL answer in the English source.

#### Scenario: A Dutch resident opens the menu
- GIVEN a resident whose language is Dutch
- WHEN portaliq builds the resident menu from decidiq's contribution
- THEN the section reads "Raad en inspraak" with "Mijn stemmen", "Mijn meldingen" and "Vergaderkalender", and no English label

#### Scenario: A new label without a translation
- GIVEN a developer adds a collection with an English label and no Dutch entry
- WHEN the unit tests run
- THEN they fail and name the label
