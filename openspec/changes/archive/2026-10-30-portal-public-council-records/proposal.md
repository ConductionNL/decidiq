---
kind: code
depends_on:
  - portal-labels-in-the-readers-language
---

# Proposal: portal-public-council-records

## Summary

A visitor of a municipality's public site finds no council meetings, no decisions and no named votes, although decidiq holds them. This change lets decidiq offer three public record lists to portaliq's public records block: the published meetings with their agenda, the published decisions with their outcome, and each public member's voting record (task 1 of `portal-voting-record-and-confidential-papers`).

## Why

Found in the live test of the Zuiddrecht demo portal on 13 and 14 October 2026:

- `/bestuur/vergaderingen` was static text, and the "Council calendar" showed "Geen items" while decidiq held six meetings.
- `/bestuur/hoe-stemden-de-raadsleden` asked decidiq for the list `memberVotingRecords`, which decidiq did not declare.

The calendar collection could not work for a visitor: portaliq's collection endpoint answers 401 without a session and scopes every read by the subject. The only anonymous read path portaliq offers is `publicRecords`: provider methods returning plain rows, cached, without personal data beyond public office holders.

## What changes

1. `PortalContributionProvider` declares `publicRecords`: `publicMeetings`, `publicDecisions` and `memberVotingRecords`, each naming a list and a record method. The services come in as optional constructor arguments; `new` with no arguments answers empty lists.
2. `PublicMeetingRecords` reads only `publication-payload` objects that are live: `publicationDate` passed, no `depublicationDate` or one still ahead. That is the rule OpenRegister's public read rule on that schema applies, and the only way decidiq marks a meeting or decision public. A meeting record lists its published agenda; a decision record shows its date, outcome, the vote totals when there was a vote, and its text.
3. `PublicVotingRecords` builds on `OriPersonPublicationRule` and `OriVotePublicationRule`, so the site and the ORI API never disagree: only public role holders in bodies that publish voting records, only votes in closed, not secret rounds on published decisions. Participation counts open rounds only.
4. The Zuiddrecht example set gains the publication payloads staff would have published: the agendas of the three public council meetings and the six published decisions (totals only).
5. Visible words go through decidiq's catalogue; dates read as the reader's long date ("9 oktober 2026").

## Out of scope

- The site pages: portaliq places the blocks.
- The confidential papers half of `portal-voting-record-and-confidential-papers` (tasks 2 to 4).
- Linking a meeting to its decisions: a payload carries no reference to its source.
