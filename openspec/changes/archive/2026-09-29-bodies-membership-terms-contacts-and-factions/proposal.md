---
kind: code
depends_on: []
---

# Proposal: bodies-membership-terms-contacts-and-factions

## Summary

A body's members widget shows who sits on it today, but not since when, not who left, not how to reach them, and not which faction they belong to. This change records a membership's start and end date, shows past members, shows and edits a member's contact details, links a membership to its faction body and gives each faction its shared workspace widget.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### bod-02, record who sits on a body, in which role, and from when to when

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/tabs/GovernanceBodyMembersTab.vue:240-266 lists active Memberships (no endDate) joined to Person; :279-288 'Remove from body' writes Membership.endDate=today; src/modals/MemberAddDialog.vue:162-168 creates Membership with role; src/components/tabs/useRelationStore.js:201-219 buildMembershipPayload never sets startDate; lib/Settings/decidesk_register.json:334 Membership has startDate/endDate

Matrix note, verbatim:

> Who sits on a body and in which role works (add, change role, remove). 'From when' is never written: the add/import payload has no startDate and there is no Membership page to edit dates; the tab only lists current members, so past members and their end dates are not visible.

Competitor cells rated `yes`, verbatim:

- go-raadsinformatie: https://gemeenteraad.groningen.nl/api/v2/positions returns positions per person with role (for example Raadsadviseur), group (fractie), startDate, endDate and hasVotingRight

### bod-06, group council members by faction and give each faction its own shared workspace

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/decidesk_register.json:553 bodyType 'faction' and :655 parentBody; src/manifest.json:485 body-factions object-list (filter parentBody+bodyType=faction) on GovernanceBodyDetail; lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json declares a collectives leaf bound to governance-body, but no collectives integration widget appears on any page (grep integrationId in src/manifest.json: files/deck/talk/notes/email/tasks only)

Matrix note, verbatim:

> Factions can be registered as sub-bodies and listed under the council. The faction's own shared workspace is only declared (Collectives leaf in the register); no page wires a Collectives widget, so there is no workspace. Membership.party is free text, not linked to the faction body.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/gebruik-van-gedeelde-en-vergadermappen states Gedeelde (partij)mappen where all fractieleden add and read folders and documents; https://amsterdam.raadsinformatie.nl/leden groups members by faction
- ibabs: https://support.ibabs.com/docs/inrichting-2.md states a group is created per political party for voting per fractie; https://support.ibabs.com/docs/document-folder-web.md states a top-level folder can be shared with users or groups with read or write rights
- go-raadsinformatie: https://gemeenteraad.groningen.nl/api/v2/groups returns groups of type Fractie; https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/mijn_vergaderingen/ states fracties plan and run their own internal meetings with shared agendas, documents and notes in Mijn Vergaderingen

### bod-16, keep contact details for members and bodies

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/decidesk_register.json:449 ContactDetail (type email/phone/address, person, governanceBody) has no page in src/manifest.json or src/manifest.d; Person.email written by src/modals/MemberAddDialog.vue:31 and the group/CSV imports; Participant.email shown on ParticipantDetail (src/manifest.json:751, no menu entry)

Matrix note, verbatim:

> A member's email is captured when adding or importing them. Phone, address and body contact details exist only as the ContactDetail schema, which no page shows or edits, and the members table does not display the email.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/raadplegen-politiek-portret states contact information on member profiles; https://www.kennisbank.notubiz.nl/kennisclips/mijn-adresboek describes a personal address book with contacts and lists
- ibabs: https://support.ibabs.com/docs/contacten.md states contact persons or groups (for example a fractie) with email and phone are shown on the Publieksportaal; https://support.ibabs.com/docs/publieke-profielen.md holds address, phone and email per profile
- go-raadsinformatie: https://gemeenteraad.groningen.nl/api/v2/persons returns persons with salutation and email address
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/user-management/managing-company-contacts-bwa.htm and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-ios/additional-features/boards-ios-contacts-bios.htm state contact details of members and company contacts are kept and can be edited

## Why

All three rows sit in the core area (bodies). bod-06 and bod-16 are rated yes by three and four competitors. The data model already carries every field: the gap is that no screen writes or shows it.

## What is built today

- Adding a member with a role, changing the role and removing a member (endDate set to today) on the Members widget.
- Factions registered as sub-bodies with bodyType faction and listed under the council.
- A member's email captured when adding or importing.

## What changes

1. The add-member dialog asks for a start date (default today) and writes Membership.startDate.
2. The Members widget gets a Past members toggle that lists memberships with an end date, showing from and to.
3. A member row shows email and phone from ContactDetail, and a Contact details action opens a dialog that creates or edits ContactDetail records for the person (email, phone, address); the body detail page gets a Contact details widget for the body itself.
4. Membership.faction references the faction body; the add and edit dialogs offer the council's factions; the members table can group by faction.
5. A faction's detail page shows the Collectives workspace widget declared by the collectives leaf.

## Out of scope

- Term-of-office planning and step-down schedules (bod-07, its own change).
- Public member profiles (bod-05, bodies-member-profile-and-voting-record).
