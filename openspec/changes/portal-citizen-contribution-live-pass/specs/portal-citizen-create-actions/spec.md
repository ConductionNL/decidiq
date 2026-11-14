## ADDED Requirements

### Requirement: A resident's contribution reaches the griffie and comes back on Mijn inspraak (REQ-DKPCA-005)

A reaction or budget proposal a resident submits through the public portal SHALL arrive in decidiq as a `ConsultationReaction` or `BudgetProposal` at moderation status `pending`, visible to the griffie on the consultation's moderation list. The resident SHALL see the contribution on Mijn inspraak with its status, and SHALL see the new status there after the griffie approves or rejects it.

#### Scenario: A resident reacts and the griffie approves

- GIVEN portaliq and decidiq on one instance and the open consultation Herinrichting Marktplein
- WHEN resident Jan-Willem submits a reaction on the portal
- THEN the griffie sees it on the consultation's moderation list as pending
- AND Mijn inspraak lists it as in behandeling
- AND after the griffie approves it, Mijn inspraak shows it as approved

#### Scenario: A reaction on a closed consultation is refused end to end

- GIVEN a consultation whose status is closed
- WHEN a resident submits a reaction to it on the portal
- THEN the portal shows that reacting is closed
- AND no `ConsultationReaction` is created in decidiq
