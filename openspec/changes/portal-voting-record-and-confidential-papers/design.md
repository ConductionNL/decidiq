# Design: portal-voting-record-and-confidential-papers

Read at decidiq development `e46f58fa`. Decision 83 (7 Oct 2026) puts the pages in portaliq; this change is the data half.

## Screen

No board on the Zuiddrecht canvas (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a, page dcb81aee8d83) draws either public page, so the figures follow the nearest drawn screens.

| Board | What this change takes from it |
|---|---|
| **DcPersoon**, tab Stemgedrag | The summary a voting record shows: participation as a percentage ("96%, deelname aan 86 stemmingen") and the counts Voor, Tegen, Onthouden. The public record counts public votes only, so its participation is over open rounds. Secret rounds and proxies are not public and do not appear |
| **DcAgendapunt**, Kerngegevens "Openbaar" | Where Lezers toevoegen sits once `agenda-item-confidential-papers` builds it. This change adds the person picker beside the Nextcloud user picker |
| **Inloggen** (portaliq) | The trust levels a resident meets: DigiD as a resident, eHerkenning for a company. A password account cannot reach the papers |

## What exists

| Piece | Where |
|---|---|
| Portal contribution | `lib/Portal/PortalContributionProvider.php`: audience `citizen`, collections at `minTrust: low`, anonymous `publicCalendar` and `publicBroadcasts`, `castMotionAdvice` at `minTrust: substantial` |
| Public votes | `lib/Service/OriVotePublicationRule.php:93` `votes(?string $voter)`: closed, not secret, decision published, body `publishVotingRecords` (:281) |
| Public people | `lib/Service/OriPersonPublicationRule.php`: public office roles in a body that publishes voting records; name, image and biography only |
| In-app record | `lib/Controller/VotingRecordController.php:77` (vot-18), login required |
| Confidential item | `agenda-item-confidential-papers` (fragment `124`, not built yet): `AgendaItem.public`, `authorisedReaders`, `DigitalDocument.public`, the listener that makes an item's papers non-public |
| Audit log | `lib/Service/AuditLogService.php:157` `append()`, action `material-access` already in `ACTIONS` |
| Portaliq contract | `IPortalContributionProvider`: `scopeClaim`, strict list membership on an array scope field, `minTrust`, `documents: {label, provider}`, `anonymous`. Claims arrive through `PortalAccountProvisionRequestedEvent` and `PortalAccountClaimRequestedEvent` (portaliq REQ-PIS-001, REQ-PIS-003) |

## Approach

### 1. The public voting record

The provider gets a manifest key `publicRecords` (new in portaliq's change):

```
publicRecords: [{
  id: memberVotingRecords,
  label: "How council members voted",
  group: "Gemeenteraad",
  listProvider: publicMembers,
  recordProvider: publicVotingRecord
}]
```

- `publicMembers(): array` returns `[{id, title, subtitle, image?}]`: the person id, the name, and the role and party in their current body ("Raadslid, Groen Zuiddrecht"). It calls `OriPersonPublicationRule`, so it lists exactly the people the ORI `persons` resource names.
- `publicVotingRecord(string $personId): ?array` returns null for anyone `publicMembers()` would not list. Otherwise:

```
{
  title: "Sanne Mulder",
  subtitle: "Raadslid, Groen Zuiddrecht",
  summary: [
    {key: participation, label: "Deelname", value: 96, unit: "%", detail: "aan 86 openbare stemmingen"},
    {key: for, label: "Voor", value: 61},
    {key: against, label: "Tegen", value: 22},
    {key: abstain, label: "Onthouden", value: 3}
  ],
  columns: [date, subject, choice, result],
  rows: [{date, subject, subjectUrl?, choice, result}],
  note: "Alleen openbare stemmingen over gepubliceerde besluiten. Geheime stemmingen staan hier niet."
}
```

  Rows come from `OriVotePublicationRule::votes(voter: $personId)` joined with `voteEvents()` for the date and result. `subjectUrl` is the decision's public publication when it has one. Participation is the member's public votes over the open, closed rounds of their bodies while they were a member.

The provider class has no constructor dependencies (amendment A1). The two methods resolve the rules from the server container by class name and return an empty list or null when decidiq's services cannot load, the same way the class stays inert without portaliq.

### 2. Naming a reader without a Nextcloud account

Fragment `lib/Settings/register.d/126-portal-confidential-readers.json`:

- `AgendaItem.authorisedPersons`: array of person ids (`$ref` person), default empty.
- `Person.portalAccountRef`: the portal `subjectRef` returned by provisioning, read only for everyone but the system.

`lib/Controller/ConfidentialReaderController.php`, `#[NoAdminRequired]`:

- `POST /api/agenda-items/{id}/portal-readers` with `{personId, identityType: digid|eherkenning, identityRef}`. Only the secretariat (group `decidiq-secretariat` or the chair or secretary role on the item's meeting, `ParticipantResolver::hasRole()`) may call it, and only on an item with `public: false`. It validates the BSN (elfproef) or KvK number (8 digits), dispatches `PortalAccountProvisionRequestedEvent` (audience `citizen`, the identity, `provisionedBy` the griffier), stores the returned `subjectRef` on the person, dispatches `PortalAccountClaimRequestedEvent` with `personId`, and adds the person to `authorisedPersons`. The identity reference goes to portaliq and is never written to decidiq.
- `DELETE /api/agenda-items/{id}/portal-readers/{personId}` removes the person from the list. The claim stays: it names the person, not the item.

When portaliq is not installed the events have no listener. The controller answers 409 "Install portaliq to give people without an account access" and changes nothing. It checks the result slot, never assumes success.

### 3. Offering the papers

`citizenCollections()` gets:

```
id: confidentialAgendaItems, register: decidiq, schema: agenda-item,
scopeClaim: decidiq.personId, scopeField: authorisedPersons,
minTrust: <portal_confidential_min_trust, default substantial>,
label: "Confidential papers", listable: true,
defaultFilters: {public: false},
fields: [title, meetingTitle, meetingDate, bodyName, confidentialityGround],
documents: {label: "Papers", provider: confidentialPapers, opened: confidentialPaperOpened}
```

- `confidentialPapers(string $itemId): array` returns the item's papers as `{id, title, kind: document, date, file: {register, schema, id, fileId}, mimeType, size}`. It returns an empty list when the item is public again, so a lifted geheimhouding ends the portal access too.
- `confidentialPaperOpened(string $itemId, string $documentId, array $subject): bool` appends `material-access` with actor `portal:<subjectRef>`, objects `[itemId, documentId]` and payload `{channel: portal, personId, trust, identityType}`. It returns false when the append fails. Portaliq then refuses the download.

The minimum trust is the app setting `portal_confidential_min_trust` (`substantial` or `high`, default `substantial`), set on decidiq's admin page. Any other value reads as `high`, so a typo never lowers the bar. Substantial means DigiD Midden or Substantieel, or eHerkenning EH3. High means DigiD Hoog or EH4.

### 4. Who decides what

| Rule | Owner |
|---|---|
| Which people and votes are public | decidiq, the two ORI rules |
| Who may read a confidential item | decidiq, `authorisedPersons` written by the griffie |
| Trust of the session | portaliq, from the login envelope; never from the client |
| Per row check of the scope, the trust gate, streaming the file | portaliq |
| The audit entry | decidiq's hash-chained log, plus portaliq's own `PortalAuditHook::download` |

## The DigiD dependency

Plt-02 only works end to end once a DigiD or eHerkenning login yields a session at trust substantial. Portaliq's broker login and integriq's browser half are built; the vendor SAML Service Provider or OIDC Relying Party behind integriq's `GovernmentIdpAdapterInterface` is not. That waits on open decision D1 (ConductionNL/integriq#1495). This change is specified in full regardless: the collection, the claim and the audit work against any session that carries the trust level, and the tests use a stub envelope.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Fields | Declarative register fragment | Plain fields |
| Collections, trust, scope | Declarative portal contribution | ADR-046 contract |
| Public record | Provider methods over the ORI rules | The visibility rule spans votes, rounds, decisions and bodies; reusing the rules keeps one source of truth |
| Provisioning a reader | Imperative controller | Cross-app typed events and a result slot |
| Audit entry | Imperative provider method | The hash-chained log is decidiq's |

## Seed data

Municipality example set: the agenda item "Grondaankoop Lindelaan" (public false, ground "art. 25 Gemeentewet") with two papers and one authorised person, Pieter Bos, commissielid without a Nextcloud account. Sanne Mulder's votes already seed the public record.

## Files

- `lib/Settings/register.d/126-portal-confidential-readers.json`, `lib/Settings/profiles/municipality.json`
- `lib/Portal/PortalContributionProvider.php`
- `lib/Controller/ConfidentialReaderController.php`, `appinfo/routes.php`
- `lib/Settings/AdminSettings.php` and its Vue section for `portal_confidential_min_trust`
- `src/manifest.json` (`AgendaItemDetail`, the person picker in Lezers toevoegen)
- `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Controller/ConfidentialReaderControllerTest.php`, `tests/newman/portal-voting-record-and-confidential-papers.json`
