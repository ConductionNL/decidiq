---
status: draft
---

# Spec: leaf-integrations — calendar, contacts, polls and forms leaves + Mail-sidebar linking

## Purpose

decidiq adopts four more app-agnostic integration leaves from the OpenRegister/nc-vue
integration registry (ADR-019) — `calendar`, `contacts`, `polls`, `forms` — and wires the
Mail sidebar (`configuration.linkedTypes` + `configuration.mailObjectTemplate`) that its
existing email tabs presuppose. Everything is declarative: manifest widgets and register
configuration, no bespoke components.

## ADDED Requirements

### Requirement: REQ-LEAF-001 — The meeting detail SHALL offer a calendar leaf

The `MeetingIntegrations` page SHALL declare a widget
`{"type": "integration", "integrationId": "calendar"}` so a meeting — a `schema:Event` with
`scheduledDate`, `endDate`, `location`, `virtualLocation` and `eventAttendanceMode` — is
surfaced through the registry's calendar leaf alongside the existing `mi-deck`, `mi-talk`,
`mi-files` and `mi-notes` widgets. The system SHALL NOT add a calendar surface for
action-item deadlines: `ActionItem` is a read-only CalDAV VTODO projection whose `dueDate`
already surfaces via the existing `tasks` leaf and the Tasks app.

#### Scenario: Calendar leaf renders on the meeting integrations page
- **GIVEN** the Nextcloud Calendar app is enabled
- **WHEN** a participant opens a meeting's integrations page
- **THEN** the calendar leaf widget renders next to the existing deck/talk/files/notes widgets

#### Scenario: No duplicate deadline surface
- **GIVEN** the manifest after this change
- **WHEN** all `integrationId: "calendar"` widgets are collected
- **THEN** exactly one exists, on `MeetingIntegrations`, and none on `ActionItemDetail` or `AgendaItemIntegrations`

### Requirement: REQ-LEAF-002 — People and body pages SHALL offer a contacts leaf

The `ParticipantDetail` and `GovernanceBodyDetail` pages SHALL each declare a widget
`{"type": "integration", "integrationId": "contacts"}`, surfacing Nextcloud Contacts against
the people-shaped data decidiq stores (`Participant.email`, `Participant.nextcloudUserId`;
`Person.email`, `Person.contactDetails`; the `ContactDetail` schema's `type`/`value`/`label`
records with `person` and `governanceBody` refs). Because no Person detail page exists in the
manifest, the participant page is the person-facing placement; the widget SHALL move to a
Person page if one is introduced.

#### Scenario: Contacts leaf on the participant page
- **GIVEN** the Nextcloud Contacts app is enabled
- **WHEN** a user opens a participant's detail page
- **THEN** the contacts leaf widget renders

#### Scenario: Contacts leaf on the governance-body page
- **GIVEN** the Nextcloud Contacts app is enabled
- **WHEN** a user opens a governance body's detail page
- **THEN** the contacts leaf widget renders alongside the existing `body-files` widget

### Requirement: REQ-LEAF-003 — Straw polls SHALL be offered as an advisory polls leaf, upstream of formal voting

The `ConsultationDetail` page and the `DecisionIntegrations` page SHALL declare a widget
`{"type": "integration", "integrationId": "polls"}` titled as a straw poll, so citizen
participation and pre-decision sounding can run an informal poll before a formal
`VotingRound` is opened. The poll surface SHALL be advisory only: it SHALL NOT create,
mutate or substitute for `VotingRound`, `Vote` or `CitizenVote` objects, and formal voting
SHALL remain exclusively with decidiq's voting system (`VotingRoundOpener`,
`VoteCastingService`, `VotingRoundCloser`).

#### Scenario: Straw poll on a consultation
- **GIVEN** a `PublicConsultation` and the Nextcloud Polls app enabled
- **WHEN** staff open the consultation detail page
- **THEN** the polls leaf widget renders with an advisory ("Straw poll") title

#### Scenario: Straw poll on a draft decision
- **GIVEN** a `Decision` with `lifecycle: draft`
- **WHEN** a participant opens the decision integrations page
- **THEN** the polls leaf widget is available for an informal sounding

#### Scenario: A poll never becomes a ballot
- **GIVEN** a completed straw poll linked to a decision
- **WHEN** decidiq objects for that decision are inspected
- **THEN** no `VotingRound`, `Vote` or `CitizenVote` object was created or modified by the poll surface

### Requirement: REQ-LEAF-004 — Consultation intake SHALL be offered via the forms leaf into the existing reaction path

The `ConsultationDetail` page SHALL declare a widget
`{"type": "integration", "integrationId": "forms"}` linking a Nextcloud Forms form as the
structured intake channel of a `PublicConsultation`. Imported form responses SHALL enter as
`ConsultationReaction` objects with `moderationStatus: pending` through the existing
`ReactionIntakeService` moderation flow, and SHALL NOT bypass moderation.

#### Scenario: Forms leaf on a consultation
- **GIVEN** the Nextcloud Forms app is enabled
- **WHEN** staff open a consultation's detail page
- **THEN** the forms leaf widget renders and a form can be linked to the consultation

#### Scenario: Imported responses land in the moderation queue
- **GIVEN** a linked form with 3 responses
- **WHEN** the responses are imported
- **THEN** 3 `ConsultationReaction` objects exist with `moderationStatus: pending` and the `consultation` ref set
- **AND** none is publicly visible before moderation

### Requirement: REQ-LEAF-005 — Mail-sidebar linking SHALL be declared on the schemas that carry email surfaces

`lib/Settings/decidesk_register.json` SHALL declare `configuration.linkedTypes` — including
the registry's mail linked-type id (validated against `IntegrationRegistry::listIds()` plus
the legacy allow-list; import fails loudly on an unknown id) — on exactly `Meeting`,
`Decision`, `AgendaItem` and `ActionItem`, so the Mail sidebar can link an email to the
objects whose detail pages already render email/files tabs. The register `version` SHALL be
bumped in the same change (schema re-import is version-gated).

#### Scenario: The Mail sidebar offers the four schemas
- **GIVEN** the register has been re-imported after the version bump
- **WHEN** a user opens the Mail sidebar's link action on an email
- **THEN** `meeting`, `decision`, `agenda-item` and `action-item` are offered as link targets
- **AND** no other decidiq schema is offered

#### Scenario: Unknown linked-type id fails at import
- **GIVEN** a `linkedTypes` entry not present in the registry ids or the legacy allow-list
- **WHEN** the register is imported
- **THEN** the import fails with an `InvalidArgumentException` naming the allowed ids, rather than silently accepting the value

### Requirement: REQ-LEAF-006 — Create-from-email SHALL exist for draft decisions only

The `Decision` schema SHALL declare `configuration.mailObjectTemplate` mapping `title` ←
`{{subject}}`, `text` ← `{{preview}}`, `externalReference` ← `{{mailRef}}`, with the verbatim
values `lifecycle: "draft"` and `decisionType: "resolution"`, so the Mail sidebar's
create-from-email button produces an inert draft decision. No other decidiq schema SHALL
declare `mailObjectTemplate`; in particular `ActionItem` SHALL NOT, because create-from-email
writes through `ObjectService::saveObject()` and the `action-item` schema is a read-only
CalDAV VTODO projection that rejects such writes.

#### Scenario: A decision is created from an email as a draft
- **GIVEN** an email with subject "Parking garage contract" open in Mail
- **WHEN** the user invokes create-from-email and picks the decision schema
- **THEN** a `Decision` is created with `title: "Parking garage contract"`, `lifecycle: draft`, and `externalReference` carrying the mail reference
- **AND** no lifecycle notification, publication or voting side effect fires

#### Scenario: Exactly one schema is creatable from email
- **GIVEN** the register JSON after this change
- **WHEN** all `mailObjectTemplate` declarations are collected
- **THEN** exactly one exists, on the `Decision` schema

### Requirement: REQ-LEAF-007 — Every new leaf SHALL degrade gracefully when its app is absent

For each widget added by this change, when the leaf's `requiredApp` (`calendar`, `contacts`,
`polls`, `forms`) is not installed/enabled, the surface SHALL degrade per the registry
contract — hidden or replaced by the registry's unavailable state — with no error, no blank
page, and no effect on the page's other widgets. This mirrors REQ-AI-DECK-009 for the deck
leaf.

#### Scenario: Polls app absent
- **GIVEN** the Nextcloud Polls app is not enabled
- **WHEN** a user opens the consultation or decision integrations page
- **THEN** the page renders without error and all other widgets (files, email, deck) work unchanged

#### Scenario: No console errors on a leaf-less instance
- **GIVEN** an instance with none of calendar/contacts/polls/forms enabled
- **WHEN** each page touched by this change is opened
- **THEN** the browser console shows zero errors attributable to the integration widgets
