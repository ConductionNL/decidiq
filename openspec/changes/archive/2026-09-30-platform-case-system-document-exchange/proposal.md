---
kind: code
depends_on: []
---

# Proposal: platform-case-system-document-exchange

## Summary

A raadsvoorstel starts life as a case in the organisation's case management
system, and after the meeting the decision and the minutes belong back in that
case. Today decidiq exchanges nothing with a case system, and the meeting file
it builds after the meeting lands only in Nextcloud Files. This change lets a
clerk link an agenda item to a case, fetch the case's documents onto the item,
and after the minutes are approved send the whole meeting file back: the
decisions to each item's case, and the complete file to a case for the meeting.
integriq speaks the ZGW APIs or StUF-ZKN; decidiq decides what goes where.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`. Two rows, one
cluster: both are the same exchange with the same case system.

### plt-23, exchange documents with the case management system over StUF-ZKN or the ZGW APIs

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`.

Demand row: origin `tender`, originUrl
https://www.tenderned.nl/aankondigingen/overzicht/415915 (TenderNed 415915).

Competitor cells rated yes, verbatim:

- ibabs = yes | evidence: https://support.ibabs.com/docs/introductie-8.md states the link is based on the Zaak- en documentservices (StUF-ZKN) versions 1.1 and 1.2; https://support.ibabs.com/docs/techniek-1.md lists the messages used
- go-raadsinformatie = yes | evidence: https://www.gemeenteoplossingen.nl/producten/integratie/go._koppelvlak/ lists StUF-ZKN and StUF-ZDS among supported standards for case and DMS links

### plt-24, write the full meeting file back to the document store after the meeting

Name in the matrix: "Write the full meeting file, with agenda, items, signed
decision list and minutes, back to the document store after the meeting." Own
rating `partial`, built.state `built`, owner `ConductionNL/decidiq`.

Demand row: origin `changelog`, originUrl
https://support.ibabs.com/docs/introductie-9.md

Competitor cells rated yes, verbatim:

- ibabs = yes | evidence: https://support.ibabs.com/docs/introductie-9.md (updated 2026-03-24) states the agenda with items, documents, signed agenda, decision list and minutes is returned to SharePoint Online; https://support.ibabs.com/docs/introductie-8.md does the same into a vergaderzaak in the case system
- go-raadsinformatie = yes | evidence: https://www.gemeenteoplossingen.nl/producten/integratie/koppeling_zaaksysteem_go._raadsinformatie/ states after the council cycle the handled cases and a new case per meeting with agenda and bundle of papers are archived in the case system, and https://www.gemeenteoplossingen.nl/blogs/vergaderen__makkelijk_voor_gebruikers_en_goed_voor_het_archief,_kan_het_eigenlijk_/ states Mijn Vergaderingen archives documents and agenda items in Corsa within 48 hours

The matrix note, verbatim: "After the meeting the minutes PDF and a sealed
proof package with votes and decisions are written into the meeting's
Nextcloud Files folder, by hand from the minutes page. Nothing writes the file
to an external document management system, and the signed decision list is
not a separate document."

## Why

A municipality's documents of record live in its case system. Two tenders and
two competitors say the council's documents must flow in and out of it. The
clerk today downloads from one system and uploads into the other, per item and
per meeting, which is slow and loses the link between the case and the
decision.

## What changes

1. A `case-system` integriq connection. integriq holds the ZGW (Zaken API and
   Documenten API) and StUF-ZKN adapters and maps decidiq's document kinds to
   the organisation's case and document types.
2. `AgendaItem.caseReference`: the case an item belongs to, by its address in
   the case system and its case number.
3. "Fetch documents from the case" on the agenda item: list the case's
   documents and copy the chosen ones into the item's folder, once each.
4. A decision list document: the decisions of the meeting with their outcome,
   rendered through filinq when the minutes are approved, and carrying the
   approval and the signers recorded on the minutes.
5. The meeting file: the published agenda, each item's documents, the decision
   list, the approved minutes and the proof package, assembled after minutes
   approval.
6. "Send the meeting file to the case system" on the minutes page (and, when an
   administrator turns it on, automatically on approval): each item's decisions
   go to its own case, and the whole file goes to a new case for the meeting.
   Every send is recorded, visible on the meeting page, and retried from there
   when it failed.

## How it relates to other changes

- `records-management-archiving` (open) forms an archival dossier per meeting
  for the e-depot through openregister's archival stack. That is transfer at
  the end of retention; this is exchange now. The meeting file here is the
  natural member list of that dossier, and neither change needs the other.
- `notubiz-ibabs-griffie-koppeling` (open, `kind: openconnector`) syncs with a
  griffie system. It does not touch case systems.
- A case app on openregister, such as dossiq, needs no ZGW: a decision raised
  from its case already carries `subjectRegister`, `subjectSchema` and
  `subjectId`, and `DecisionConcludedEvent` tells that app the outcome. This
  change is for case systems outside openregister.

## Out of scope

- Signing the decision list separately. It carries the signatures of the
  approved minutes it belongs to.
- SharePoint as a target. A document library is a different connection; the
  meeting file assembly here is reusable for it.
- Creating cases in the case system for new agenda items.

## Risks

- **Confidential items.** An item under a confidentiality restriction sends its
  documents marked confidential with the ground, and integriq maps that to the
  case system's confidentiality level. Nothing confidential goes to the
  meeting case unmarked.
- **A half-sent file.** Each document is a line in the exchange record with its
  own status, so a retry sends only what failed.
- **The same document twice.** A fetched document records its address in the
  case system and is not fetched again; a sent document records the address it
  got, and is not sent again.
