---
kind: code
---

# Proposal: simple-structure-profile

## Summary

decidiq gets two structures, built from one manifest. The simple structure is the new default: eight menu entries under three captions. The full structure is the menu as it is today. An administrator brings it back with the app setting `menu_structure=full`. No page and no route is removed in either.

This is the first of three changes that bring the Zuiddrecht design to decidiq. The second gives the decision page a step bar and one next-step button. The third rebuilds the dashboard and the proposal and decision lists.

## Motivation

The menu holds 44 entries today: 24 in the main list (6 at the top, 18 nested under them), 4 in the footer and 16 in settings. Somebody who prepares a proposal or follows up a commitment needs a handful of them. The Zuiddrecht design (`Vereenvoudiging.dc.html`, `AppZijbalk.dc.html`) sets the shape for all four workplace apps: at most ten entries, in three groups, daily work first, the rest one step further.

Ruben's decisions (5 October 2026): the simple structure is the default, the full one returns with an admin setting, nothing is deleted.

## The simple menu

| Caption | Entry (Dutch) | Menu id | Opens |
| --- | --- | --- | --- |
| Start | Dashboard | `Dashboard` | Dashboard |
| | Mijn acties | `ActionItems` | the action items list |
| Besluitvorming | Voorstellen | `Proposals` (new) | the Motions list |
| | Vergaderingen | `Meetings` | Meetings |
| | Besluiten | `Decisions` | Decisions |
| | Toezeggingen | `Commitments` | Commitments |
| Organisatie | Organen en leden | `GovernanceBodies` | Governance bodies |
| | Registers | `Registers` | the new Registers page |

Settings (16 entries) and the footer (4 entries) are the same in both structures.

## Where the rest goes

The full structure nests 18 entries under its six top entries. Commitments is in the simple menu itself. The other 17 are one link away from a page the simple menu opens:

| Entry | Reached from |
| --- | --- |
| Consultations, Works-council consultations, Public consultations, Urgent decisions | links in the header of Voorstellen |
| Long-term agenda, P&C cycles | links in the header of Vergaderingen |
| Position holders (also in settings, as today) | a link in the header of Organen en leden |
| Governing documents, Delegations & mandates, Confidentiality register, Gifts, Other positions, Proxy authorizations, Onboarding, Offboarding, Audit statements, Goals, Archive | a tile each on the Registers page |

The Registers page (`RegistersHub`, `/registers`) is new. It is a dashboard page with one counted tile per register. The manifest's `Registers` entry is a group with no page of its own, and the simple menu is flat, so it needed one.

## What the design asks for and decidiq does not have yet

- **Consultations and urgent decisions as filters on Voorstellen.** The three consultation lists are schemas of their own (`governance-consultation`, `consultation-request`, `public-consultation`), not decisions, so no filter on the proposals list can show them. They are links in this change. Urgent decisions are decisions (`isUrgent`), and become a real view in the third change.
- **Voorstellen as every proposal under way.** Today the Motions list shows decisions of type `motion`. A college or council proposal of another type is on the Decisions list. Widening the list is part of the third change, with the views and their counts.
- **Mijn acties as only my actions.** The entry opens the full action items list. A view on the signed-in person is part of the third change.
- **A help menu.** The library's navigation has no help group. Documentation, Store, Reports and Features & roadmap stay in the footer.

## What the library cannot express

Reported to the nextcloud-vue lane, worked around in `src/utils/structureProfile.js`:

- an order or a label per layout (worked around: the profile's `menu` key, merged first);
- captions that survive a relocation step (worked around: the simple file has no `relocations`);
- a page that differs per profile by less than a whole page (worked around: `pages` overlays);
- an active state that reads `query`, so two entries on one route both light up (avoided: Voorstellen and Besluiten open different pages).

## ADR-004 and the six-entry ceiling

ADR-004 caps the navigation at six top-level entries, and `scripts/check-nav-ceiling.js` holds the full structure there. That does not change. The simple structure shows eight, under the ceiling of ten the Zuiddrecht design sets. The gate reads `src/menu-layout.json` only, so the count of the simple menu is held by `tests/vitest/structureProfile.spec.js`.

## Affected projects

- [x] Project: `decidiq`

## Risks

- **Every instance changes menu on update.** That is the decision. The PR body says how to switch back.
- **The e2e suite walks the full menu.** `tests/e2e/ci-seed.sh` puts the CI instance on `full`. The simple menu has its own spec, which sets `simple` and restores what it found.
- **The Registers tiles count through OpenRegister's aggregation endpoint.** Each is a plain count of one schema with no filter, the one form that endpoint is known to answer.
- **nextcloud-vue moves from ^2.57.1 to ^2.60.0** (a move within 2.x). The captions and the header links need it.
