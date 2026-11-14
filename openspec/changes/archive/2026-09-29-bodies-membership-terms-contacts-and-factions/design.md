# Design: bodies-membership-terms-contacts-and-factions

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Members widget | `src/components/tabs/GovernanceBodyMembersTab.vue` lists active memberships (no endDate); Remove sets endDate |
| Add dialog | `src/modals/MemberAddDialog.vue` creates the Membership with a role |
| Payload | `src/components/tabs/useRelationStore.js` buildMembershipPayload() never sets startDate |
| Schemas | `lib/Settings/decidesk_register.json` Membership (startDate, endDate, party), ContactDetail (:449, type, person, governanceBody), GovernanceBody bodyType faction and parentBody |
| Collectives leaf | `lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json` declares a collectives leaf on governance-body; no page wires it |

## Approach

1. buildMembershipPayload() takes startDate; MemberAddDialog gets an NcDateTimePickerNative field.
2. GovernanceBodyMembersTab keeps its active filter and adds a past filter (endDate set) behind a toggle, with from and to columns.
3. A new dialog `src/modals/ContactDetailsDialog.vue` lists and saves ContactDetail objects for a person or a body through the object store.
4. A register fragment adds Membership.faction (uuid reference to governance-body); the dialogs offer factions whose parentBody is the current body.
5. The GovernanceBodyDetail manifest page gets the collectives integration widget, shown when bodyType is faction.

Design corrected at build (29 Sep):
- The body's own contact details open from a Contact details button on the Members widget (same dialog as a member's), not from a separate widget.
- A faction page's Members widget lists the faction's own memberships and the council memberships whose `faction` names it, so the faction shows its members without a second membership per person. Fragment 106 adds Membership.faction (Membership 0.3.0).
- The Workspace (collectives integration) widget sits on every body page: committees and task groups use workspaces too, and the registry hides it when Collectives is absent.
- Remove from body used to save only id, person, body, role and endDate, which dropped the start date (the save replaces the object); it now keeps start date, faction, party and voting weight, so Past members can say from when.
- Member rows show email and phone from ContactDetail (first of each kind), falling back to Person.email.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- vitest: buildMembershipPayload writes startDate; the tab lists past members with dates; the contact dialog payload validates against the real ContactDetail schema.
- PHPUnit: the Membership fragment with faction validates in the register walk test.
