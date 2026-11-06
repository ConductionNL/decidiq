# decidesk-contract-decision-hub Delta

**Status**: proposed
**Scope**: decidiq
**OpenSpec changes**:

- [decisions-leaf-drafter-name](../../)

## ADDED Requirements

### Requirement: The decisions leaf names the drafter by display name

The decisions leaf SHALL name a decision's drafter by its `proposer` when it has one, else by the display name of its
owner's account, read once per account from Nextcloud's user directory, else by the account id when the name cannot be
read. The row's initials SHALL follow that name.

#### Scenario: DqZaakBesluiten

- **GIVEN** a decision without a proposer, owned by `pieter`, whose display name is Pieter Jansen
- **WHEN** the card shows it
- **THEN** the row reads "opgesteld door Pieter Jansen" with the initials PJ
