---
kind: code
depends_on:
  - agenda-item-confidential-papers
---

# Proposal: portal-voting-record-and-confidential-papers

## Summary

decidiq hands portaliq two things for the public site. First, each public member's voting record: the list of people the ORI API may name, and per person the public votes with their counts. Second, the confidential papers of an agenda item, offered only to a person the griffie named as a reader and only after that person signed in to the portal with DigiD or eHerkenning at the declared trust level. Every opening of such a paper lands in decidiq's own audit log. Portaliq builds the pages (`portaliq/site-member-voting-record-and-confidential-papers`); decidiq only contributes the data.

## The rows this covers

Source: decidiq `openspec/parity/capabilities.json`. Decision 83 (Ruben, 7 Oct 2026): portaliq owns the public pages for both rows, decidiq contributes the data and offers confidential papers at a raised trust level.

- **pub-14** Publish each member's voting record for citizens to see (specified, partial). The ORI API half is built: `lib/Service/OriVotePublicationRule.php` returns a member's public votes with `?voter=`, `lib/Service/OriPersonPublicationRule.php` names public role holders. The row's note says what is missing: no citizen-facing page renders that record.
- **plt-02** Open confidential papers after signing in with DigiD or eHerkenning (specified, partial). Portaliq's broker login is built. decidiq's half is not: `lib/Portal/PortalContributionProvider.php:18` still calls DigiD/eHerkenning deferred, every collection sits at trust `low`, and no paper is offered to a portal session at a raised trust level.

## Why

NotuBiz (Politiek Portret), iBabs (Stemgedrag under Wie is wie) and GO (GO.Stemgedrag) all show a member's votes on the public council site. A resident who wants to know how their councillor voted can only read decidiq's ORI JSON today.

Confidential papers are the other half. Burgerleden, commissieleden without a Nextcloud account and outside advisers need to read a besloten stuk. Today the griffie mails it, which leaves no trace of who read what. A portal login at a verified trust level, scoped to the people the griffie named, gives the same access with an audit trail.

## What changes

1. `PortalContributionProvider` declares a public record list `memberVotingRecords` (anonymous, no session). It names two provider methods: one lists the public members, one returns a member's public voting record. Both delegate to the two ORI publication rules, so the public site and the ORI API can never disagree.
2. An agenda item gets `authorisedPersons` beside `authorisedReaders`: decidiq person ids of readers without a Nextcloud account. The griffier adds one from the item page with Lezers toevoegen, entering the person's BSN (DigiD) or KvK number (eHerkenning). decidiq asks portaliq to provision a pending portal account for that identity and to write the claim `decidiq.personId` on it. decidiq never stores the BSN.
3. `PortalContributionProvider` declares a collection `confidentialAgendaItems` on agenda items that are not public, scoped by the claim `decidiq.personId` against `authorisedPersons`, at `minTrust: substantial` (an admin setting can raise it to `high`). Its `documents` provider lists the item's papers.
4. Before portaliq streams a paper it calls the provider's `opened` method. decidiq appends a `material-access` entry to its hash-chained audit log with the person, the item, the paper, the channel `portal` and the trust level. No audit entry, no paper.
5. The provider docblock stops calling DigiD and eHerkenning deferred.

## Out of scope

- The pages themselves (portaliq).
- The geheimhouding lifecycle (`embargo-geheimhouding`, `confidentiality-in-plain-words`) and the read rules for Nextcloud users (`agenda-item-confidential-papers`).
- Watermarking the paper a portal reader opens (age-19, `agenda-paper-watermark`).
- The live DigiD and eHerkenning connection. It waits on open decision D1 (broker vendor and contract, ConductionNL/integriq#1495). Until then an organisation's own OIDC broker that reports a trust level works, and so does a stub envelope in tests.

## Risks

- A wrong person reads a confidential paper. The scope is a server-managed claim the griffie set, the read is per row verified by portaliq, and the trust gate keeps a password login out.
- The public record names someone who should not be named. Both methods only return what the ORI rules already publish: public role holders in bodies with `publishVotingRecords: true`, votes in closed, open rounds on published decisions.
