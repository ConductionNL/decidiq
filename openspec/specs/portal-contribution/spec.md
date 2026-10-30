---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: decidiq
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — ADR-046 v2.2 provider class contributing the `citizen` read + inbox surface with field projection and default pseudonymous-token (subjectRef) scoping (kind: code)

## Purpose

Decidiq contributes a `citizen` section to **portaliq**, the shared external
portal for people without a Nextcloud account (hydra ADR-046, contribution
contract v2.2). The contribution is one plain, dependency-free provider class
(`OCA\Decidiq\Portal\PortalContributionProvider`, duck-typed by FQCN — inert
without portaliq) that returns a pure-data manifest for the `citizen` audience:
the citizen reads their own consultation reactions, advisory votes and
participatory-budget proposals, and their own notification inbox. Every read is
scoped by the subject's own pseudonymous token (`scopeField == subjectRef`, no
claim — Decidiq already stores that token in each record's scope field) and
projected server-side to subject-safe fields only. The citizen edge is
portaliq's ordinary password login at trust `low`; DigiD/eHerkenning and any
credential broker are deferred.

## Requirements

Detailed requirements (REQ-DKPORT-001 … REQ-DKPORT-006) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Decidiq ships the ADR-046 portal contribution (REQ-DKPORT-000)

The app MUST serve its entire portal contribution through the single artefact
this capability owns: the plain, dependency-free
`OCA\Decidiq\Portal\PortalContributionProvider` class (duck-typed by FQCN, inert
without portaliq). It declares the `citizen` read + inbox manifest — default
pseudonymous-token scoping, server-side field projection, `minTrust: low` gating
and the notification inbox — and no other portal *contribution* logic, UI,
dependency, create-action or endpoint-action may ship outside it in this wave.

#### Scenario: The provider is the sole portal-contribution artefact

- GIVEN Decidiq installed with portaliq present
- WHEN portaliq resolves `OCA\Decidiq\Portal\PortalContributionProvider` by FQCN and calls `getAudiences()` / `getContribution()`
- THEN the `citizen` read + inbox manifest is served from that class alone
- AND no route, controller, service, frontend or `info.xml` dependency is added for the contribution
- @e2e exclude backend-only contribution class rendered inside portaliq, not Decidiq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider is a plain, dependency-free class (REQ-DKPORT-001)

The app MUST ship `OCA\Decidiq\Portal\PortalContributionProvider` as a plain PHP
class: no imports from portaliq, no `implements` clause, no `info.xml` dependency
on portaliq, and no constructor dependencies. portaliq discovers it by convention
FQCN and duck-types it via `method_exists` (never `instanceof`), so without
portaliq installed the class MUST be inert and MUST NOT change any app behaviour
(ADR-046 amendment A1). It MUST NOT be registered in `lib/AppInfo/Application.php`.

#### Scenario: Provider constructs standalone

- GIVEN a PHP runtime where portaliq is not installed and no portaliq class is autoloadable
- WHEN `new PortalContributionProvider()` is called
- THEN the class instantiates without error
- AND it declares no `implements` clause, no parent class, and no constructor
- AND its code references no portaliq symbol (import or FQCN)
- @e2e exclude backend-only contract class with no Decidiq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php::testProviderIsPlainAndDependencyFree)

### Requirement: Provider declares both v2 and v1 audience methods (REQ-DKPORT-002)

The provider MUST implement `getAudiences(): array` returning `['citizen']`
(contract v2, preferred by the registry) AND `getAudience(): string` returning
`'citizen'` (v1 fallback), so it works against both registry generations (ADR-046
amendment A2). `getContribution(array $subject): ?array` MUST return `null` for
any audience other than `citizen`, and for an absent/empty audience
(fail-closed).

#### Scenario: Audience methods agree and unknown audiences fail closed

- GIVEN a constructed provider
- WHEN `getAudiences()`, `getAudience()` and `getContribution()` are called
- THEN `getAudiences()` returns exactly `['citizen']`
- AND `getAudience()` returns `'citizen'`, which is contained in `getAudiences()`
- AND `getContribution()` returns `null` for audience `'client'`, `'signer'`, `[]`, and an empty-string audience
- @e2e exclude backend-only contract methods with no Decidiq UI surface — covered by PHPUnit (testAudiencesOnBothContractVersions, testUnknownAudienceYieldsNull)

### Requirement: Citizen reads their own participation data, subject-scoped and projected (REQ-DKPORT-003)

For a `citizen` subject, `getContribution()` MUST return a manifest labelled
`Decidiq` with exactly four collections over register `decidiq`, each scoped by
the DEFAULT subjectRef (the record's own pseudonymous token — NO `scopeClaim` and
NO `via`), each `listable` and gated at `minTrust: low`:

- `citizenReactions` — schema `consultation-reaction`, `scopeField: submitterId`,
  projected to exactly `body`, `submittedAt`, `moderationStatus`, `voteCount`,
  `proposalTitle`, `proposalAmount`. It MUST NOT expose `moderationReason`,
  `publicatiedatum`, `depublicatiedatum` or the scope field `submitterId`.
- `citizenVotes` — schema `citizen-vote`, `scopeField: voterId`, projected to
  exactly `voteValue`, `motionId`, `proposalId`, `citizenPanelId`, `weight`,
  `isProxy`, `castAt`, `notes`.
- `citizenBudgetProposals` — schema `budget-proposal`, `scopeField: submitter`,
  projected to exactly `title`, `description`, `requestedAmount`, `category`,
  `status`, `votesFor`, `votesAgainst`.

Every projected field and every `scopeField` MUST exist on its schema in
`lib/Settings/decidesk_register.json` at HEAD.

#### Scenario: Citizen receives the low-gated, subject-scoped, projected read collections

- GIVEN a subject array with `audience` `'citizen'`, a `subjectRef` pseudonymous token, an organisation and trust `low`
- WHEN `getContribution($subject)` is called
- THEN it returns a manifest labelled `Decidiq` with the three read collections `citizenReactions`, `citizenVotes`, `citizenBudgetProposals` over register `decidiq`
- AND each is scoped by its documented `scopeField` (`submitterId` / `voterId` / `submitter`), carries no `scopeClaim` and no `via`, and is gated `minTrust: low`
- AND `citizenReactions.fields` is exactly the six documented fields and excludes `moderationReason`, `publicatiedatum`, `depublicatiedatum` and `submitterId`
- @e2e exclude manifest is consumed and rendered by portaliq, not by any Decidiq UI — covered by PHPUnit (testCitizenManifestShape, testCitizenCollectionScopingAndProjection)

### Requirement: Citizen has a notification inbox, subject-scoped and projected (REQ-DKPORT-004)

For a `citizen` subject, the manifest MUST include one `kind: 'inbox'` collection
`citizenNotifications` over register `decidiq`, schema `notification`, scoped by
`scopeField: recipientId` (default subjectRef), `listable`, gated `minTrust:
low`, projected to exactly `type`, `subject`, `content`, `channel`, `status`,
`sentAt`, `readAt`. It MUST NOT expose the scope field `recipientId`. No other
collection may declare `kind`.

#### Scenario: Citizen inbox is the sole inbox collection and is subject-scoped

- GIVEN a `citizen` subject
- WHEN `getContribution($subject)` is called
- THEN `citizenNotifications` is present with `kind: 'inbox'`, `scopeField: recipientId` and the seven documented projected fields
- AND none of `citizenReactions`, `citizenVotes`, `citizenBudgetProposals` declares a `kind`
- @e2e exclude backend-only manifest shape; the inbox renders inside portaliq — covered by PHPUnit (testCitizenCollectionScopingAndProjection, testOnlyNotificationsCollectionIsInbox)

### Requirement: Manifest matches the shipped register schemas (REQ-DKPORT-005)

The unit suite MUST pin the manifest against the shipped register: every declared
`scopeField` and every projected read field MUST exist as a property on the
declared schema (matched by its `slug`) in `lib/Settings/decidesk_register.json`.
A register drift (renamed scope property, dropped whitelist field) MUST therefore
break the unit suite instead of silently emptying a portal scope or dropping a
projected column.

#### Scenario: Register-drift pin holds against HEAD

- GIVEN the shipped `decidesk_register.json` and the citizen manifest
- WHEN every collection's `scopeField` and projected `fields` are checked against the schema's properties (keyed by slug)
- THEN each declared property exists on its schema
- @e2e exclude declarative register/manifest cross-check with no UI surface — covered by PHPUnit (testManifestMatchesShippedRegisterSchemas)

### Requirement: No create or public-browse surfaces this wave (REQ-DKPORT-006)

This wave MUST declare read + inbox collections only. The manifest's `actions`
MUST be empty and its manifest-level `notifications` MUST be empty. No citizen
create ships, because each candidate create (`consultation-reaction`,
`budget-proposal`) needs a parent relation (`consultation` /
`participatoryBudget`) that has no writable scalar property on its schema, so the
flat create vocabulary cannot attach the new record to an open parent without
orphaning it (and a client-supplied parent ref would be an unverifiable
cross-reference, cf. portaliq#16). No public consultation / participatory-budget
browse or results collection ships, because those are non-per-subject public
lists the per-subject reader cannot express safely. Both are deferred (tracking
issue Conduction/decidiq#113).

#### Scenario: Citizen manifest is read-only with no create actions

- GIVEN the manifest for the `citizen` audience
- WHEN its `actions`, manifest-level `notifications` and each collection are inspected
- THEN `actions` is empty and `notifications` is empty
- AND no collection targets `public-consultation` or `participatory-budget`
- @e2e exclude backend-only manifest shape with no Decidiq UI surface — covered by PHPUnit (testCitizenManifestShape)

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

### Requirement: REQ-PCR-001 A visitor finds the published meetings with their agenda

decidiq's portal contribution SHALL declare a public record list `publicMeetings` that lists every meeting publication (`publication-payload` with `oriType: Vergadering`) whose `publicationDate` has passed and that is not withdrawn, the latest first, and whose record shows the meeting's date, time and location and its published agenda items in order. A meeting that was not published, or was withdrawn, SHALL NOT be listed and SHALL have no record.

#### Scenario: A resident looks up the next council meeting
- GIVEN the griffie published the agenda of the council meeting of Thursday
- WHEN a resident opens the meetings page of the public site
- THEN the meeting is listed with its date and place, and opening it shows the agenda items in order

#### Scenario: A meeting that is not published
- GIVEN a meeting whose agenda is not published
- WHEN a resident opens the meetings page
- THEN that meeting is not listed

### Requirement: REQ-PCR-002 A visitor reads the published decisions with their outcome

decidiq's portal contribution SHALL declare a public record list `publicDecisions` that lists every live decision publication (`oriType: Besluit`), and whose record shows the decision's date, its outcome, its text and, when it was put to a vote, the totals for, against and abstained. It SHALL never show who voted how; that is the voting record's, under the ORI vote rule.

#### Scenario: A resident reads a council decision
- GIVEN the council adopted the credit for the Lindelaan lighting with 22 for and 5 against, and it was published
- WHEN a resident opens the decision on the public site
- THEN she reads the decision text, "Aangenomen", and the totals 22 and 5
