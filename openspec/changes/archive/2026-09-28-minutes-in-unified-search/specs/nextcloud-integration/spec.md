# nextcloud-integration Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [minutes-in-unified-search](../../) (this delta)

## Purpose

Adds minutes to decidiq's section of Nextcloud's unified search, as archived REQ-NSP-002 of `2026-05-11-p2-minutes-and-decisions-core-t2` asked. Closes decidiq matrix row min-15.

**Standards**: Nextcloud `OCP\Search\IProvider`, OpenRaadsinformatie `Verslag`.

## ADDED Requirements

### Requirement: REQ-MUS-001 Minutes appear in Nextcloud's unified search

decidiq's unified search provider SHALL search minutes as well as decisions and meetings. A minutes hit SHALL show the minutes title, the word Minutes, its lifecycle and its approval date when it has one, and SHALL open the minutes page.

#### Scenario: A member finds what was said
- GIVEN the minutes of the council meeting of 14 October mention "woningbouw Noord"
- WHEN council member Pieter types "woningbouw" into Nextcloud's search bar
- THEN the Decidiq governance section lists those minutes with their approval date, and clicking opens the minutes page

### Requirement: REQ-MUS-002 Search shows only minutes the searcher may read

The provider SHALL return only minutes the searcher may read under OpenRegister's read rules.

#### Scenario: Closed-session minutes stay hidden
- GIVEN minutes of a closed session that council member Pieter may not read
- WHEN he searches a word that appears in them
- THEN those minutes are not listed
@e2e exclude which minutes a member may read is OpenRegister's read rule, not the provider's; the provider keeps that rule on, proven by tests/Unit/Search/DecidiqSearchProviderTest.php testSearchKeepsOpenRegisterReadRules
