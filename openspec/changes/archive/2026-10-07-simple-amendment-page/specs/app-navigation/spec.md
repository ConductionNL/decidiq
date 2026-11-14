# app-navigation Specification (delta)

**Scope**: decidiq
**OpenSpec changes**:
- [simple-amendment-page](../../)

## ADDED Requirements

### Requirement: REQ-SAP-006 My actions opens on the reader's own action items

In the simple structure, the page the menu entry My actions opens MUST show the action items assigned to the reader first. It MUST find them by the `assignee` field, with the reader filled in when the list is fetched. The list of every action item MUST stay one view away. A view's count MUST ask the same question as its list. The full structure MUST keep the list as the manifest declares it.

#### Scenario: A member opens My actions
@e2e exclude The filter is resolved with the library's resolveFilterMap in simpleAmendmentPage.spec.js; the suite has no fixture with action items for two users.
- **GIVEN** the simple structure and action items assigned to several people
- **WHEN** a member opens My actions
- **THEN** the list MUST show the items whose assignee is that member
- **AND** the view Everyone MUST show all of them

#### Scenario: A count and its list
@e2e exclude Both addresses are built with the library's own calls and compared in simpleAmendmentPage.spec.js.
- **GIVEN** the views Mine and Everyone
- **WHEN** their counts are asked
- **THEN** each count MUST carry the filter its view lists with, and no token

#### Scenario: Pending votes and commitments
@e2e exclude A reading of the schemas, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** a vote names a participant and a commitment names a member by record id
- **WHEN** My actions is built
- **THEN** neither MUST be filtered by the reader, and their pages MUST equal the full structure's
