# portal-contribution Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [portal-voting-record-and-confidential-papers](../../) (this delta)

## Purpose

decidiq contributes the data for two public-site pages that portaliq builds: a member's public voting record, and the confidential papers of an agenda item for a named reader signed in with DigiD or eHerkenning. Closes the decidiq half of matrix rows pub-14 and plt-02 (decision 83).

**Standards**: ADR-046 (portal contribution contract), eIDAS levels of assurance (substantial, high), Open Raadsinformatie (ORI) votes and persons.

## ADDED Requirements

### Requirement: REQ-PSH-001 decidiq offers each public member's voting record to the portal without a session

decidiq's portal contribution SHALL declare a public record list `memberVotingRecords` whose list provider returns exactly the people the ORI `persons` resource names, and whose record provider returns, for one of them, the public votes the ORI `votes?voter=` resource returns, with the date, the decision, the member's choice and the result, and a summary of participation and the counts for, against and abstain. The record provider SHALL return nothing for a person the list does not name. Neither provider SHALL return a secret vote, a vote in an open round, a vote on an unpublished decision or a vote in a body that does not publish its voting records.

#### Scenario: A resident reads a councillor's record
- GIVEN Sanne Mulder is a member of Gemeenteraad Zuiddrecht, which publishes its voting records, and voted for the published motion "Groen dak op het stadhuis" in a closed, open round
- WHEN portaliq asks the record provider for Sanne Mulder
- THEN the record contains that vote with the choice for and the result adopted, and the summary counts it under Voor

#### Scenario: A secret vote stays out
- GIVEN Sanne Mulder also voted in a secret round on an appointment
- WHEN portaliq asks the record provider for her
- THEN that vote is not in the rows and does not count in the summary

#### Scenario: A person who holds no public office
- GIVEN a guest who attended one meeting of the council
- WHEN portaliq asks the record provider for that person's id
- THEN the provider returns nothing and the list provider does not name them

### Requirement: REQ-PSH-002 The griffie names a reader without a Nextcloud account on a confidential item

The secretariat, or the chair or secretary of the item's meeting, SHALL be able to add a person as a reader of a non-public agenda item by giving that person's BSN or KvK number. decidiq SHALL ask portaliq to provision a pending portal account for that identity and to write the claim `decidiq.personId` on it, SHALL store only the returned portal reference, and SHALL never store the BSN or KvK number. Any other caller SHALL be refused. When portaliq is not installed or does not confirm the claim, the request SHALL fail with a reason and change nothing.

#### Scenario: The griffier adds a commissielid
- GIVEN the non-public agenda item "Grondaankoop Lindelaan" and the person Pieter Bos, who has no Nextcloud account
- WHEN the griffier adds Pieter Bos as a reader with his BSN
- THEN Pieter Bos is in the item's authorised persons, his pending portal account carries the claim `decidiq.personId`, and no BSN is stored in decidiq

#### Scenario: A council member tries to add a reader
- GIVEN a council member without the secretariat group or a chair or secretary role on the meeting
- WHEN he tries to add a reader to the item
- THEN the request is refused with 403

#### Scenario: Portaliq is not installed
- GIVEN an instance without portaliq
- WHEN the griffier adds a reader
- THEN she is told to install portaliq first and the item is unchanged

### Requirement: REQ-PSH-003 Confidential papers reach only a named reader at the declared trust level

decidiq's portal contribution SHALL declare the collection `confidentialAgendaItems` over non-public agenda items, scoped by the claim `decidiq.personId` against `authorisedPersons`, with `minTrust` taken from the setting `portal_confidential_min_trust` (`substantial` by default, `high` when set so, `high` for any unknown value). Its documents provider SHALL list the item's papers while the item is non-public and SHALL return an empty list once the item is public again.

#### Scenario: The named reader signed in with DigiD
- GIVEN Pieter Bos is an authorised person on "Grondaankoop Lindelaan" and signs in to the portal with DigiD at trust substantial
- WHEN portaliq aggregates his contribution
- THEN `confidentialAgendaItems` lists the item and its documents provider lists its two papers

#### Scenario: The same reader with a password account
- GIVEN Pieter Bos signs in with a portal password at trust low
- WHEN portaliq aggregates his contribution
- THEN `confidentialAgendaItems` is not offered

#### Scenario: Someone else signed in with DigiD
- GIVEN a resident with no `decidiq.personId` claim signs in with DigiD
- WHEN she asks for the item
- THEN no confidential item is listed and a direct request for it answers 404

#### Scenario: The secrecy is lifted
- GIVEN the griffier sets "Grondaankoop Lindelaan" to public
- WHEN Pieter Bos opens his confidential papers
- THEN the item no longer appears there

### Requirement: REQ-PSH-004 Every portal opening of a confidential paper is in decidiq's audit log first

Before portaliq streams a confidential paper, decidiq's `opened` provider method SHALL append a `material-access` entry to the hash-chained audit log naming the portal subject, the person, the item, the paper, the channel `portal` and the session's trust level. The method SHALL report failure when the entry was not written, and the paper SHALL then not be streamed.

#### Scenario: The opening is logged
- GIVEN Pieter Bos at trust substantial
- WHEN he opens the paper "Taxatierapport Lindelaan"
- THEN the audit log holds a `material-access` entry for that paper with channel portal and trust substantial, and the chain still verifies

#### Scenario: The audit log cannot be written
- GIVEN the audit append fails
- WHEN Pieter Bos opens the paper
- THEN the provider reports failure and he does not receive the file
