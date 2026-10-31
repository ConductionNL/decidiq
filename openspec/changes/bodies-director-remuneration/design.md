# Design: bodies-director-remuneration

Read at decidiq development `c0b2f5bb`.

## What exists

| Piece | Where |
|---|---|
| Positions held | `lib/Settings/register.d/70-configurable-types.json` `PositionHold` (`membership`, `position`, `governanceBody`, `holdType`, `startDate`, `endDate`, `termNumber`, `appointedBy`) |
| Memberships and people | `lib/Settings/decidesk_register.json` `Membership` (`person`, `governanceBody`, `role`, `independenceStatus`), `Person` |
| Outside positions | `lib/Settings/register.d/81-integrity-disclosures-in-plain-words.json` `AncillaryPosition.remunerated` (boolean, "deliberately carries NO remuneration AMOUNT"); read rule `{ group: public, match: { publicationDate: { $lte: $now } } }` plus `authenticated` |
| Body page | `src/manifest.json:454` `GovernanceBodyDetail`, widgets including `body-position-holders` (object-list on `position-hold`, filter `governanceBody = @objectId`) and `body-ancillary-positions` |
| Decisions | `Decision` in `lib/Settings/decidesk_register.json` |
| Year token | nextcloud-vue `src/utils/resolveFilterTokens.js` `@currentFiscalYear` |

## Approach

### Schema

Fragment `lib/Settings/register.d/92-mandate-remuneration.json`, schema `MandateRemuneration` (slug `mandate-remuneration`, Schema.org `MonetaryAmount` as `x-schema-org`):

| Property | Type | Notes |
|---|---|---|
| `positionHold` | uuid, `$ref` PositionHold | required |
| `person` | uuid, `$ref` Person | required, facetable |
| `governanceBody` | uuid, `$ref` GovernanceBody | required, facetable |
| `year` | integer | required, facetable |
| `fixedFee` | number | annual fee, 0 or more |
| `meetingFee` | number | per meeting attended |
| `expenseAllowance` | number | fixed annual allowance |
| `currency` | string | ISO 4217, default `EUR`, facetable |
| `setByDecision` | uuid, `$ref` Decision | the decision that set the amount |
| `disclosed` | boolean | default false |
| `publicationDate` | date | when disclosure starts |
| `note` | string | for example a WNT maximum applied |

`authorization`: read `decidiq-secretariat`, `decidiq-administrators`, and `{ "group": "public", "match": { "disclosed": true, "publicationDate": { "$lte": "$now" } } }`; create, update and delete `decidiq-secretariat`, `decidiq-administrators`. Deliberately not `authenticated`: members do not read each other's pay unless it is disclosed.

### Screens

- `GovernanceBodyDetail` gets two widgets in the layout's last row: `body-remuneration` (object-list on `mandate-remuneration`, filter `governanceBody = @objectId` and `year = @currentFiscalYear`, columns person, fixedFee, meetingFee, expenseAllowance, currency, disclosed (badge), `allowCreate: true`) and `body-remuneration-total` (stat, sum of `fixedFee` with the same filter plus `currency = EUR`, format currency EUR). The page's widgets render only what the reader's rights return, so members see an empty list, not someone's pay.
- `PositionHoldDetail` (`src/manifest.d/configurable-types.json:668`) gets `hold-remuneration` (object-list on `mandate-remuneration`, filter `positionHold = @objectId`, sorted by year).
- Disclosed records reach the public through OpenRegister's anonymous read on the `public` rule, and through portaliq only if a later change adds them to decidiq's contribution.

## Declarative or imperative

Everything is declarative: a schema fragment with its read rule, and manifest widgets. No service, no listener. The yearly total is a manifest stat widget over OpenRegister's aggregation, not an `x-openregister-aggregations` field, because the year is the reader's choice rather than a property of the body.

## Seed data

Corporate example set (`lib/Settings/profiles/corporate.json`), supervisory board of the example company, year 2026:

1. Chair of the supervisory board: fixedFee 32000, meetingFee 0, expenseAllowance 1500, EUR, set by the decision "Bezoldiging raad van commissarissen 2026" of the general meeting, disclosed, publication date 2026-04-30.
2. Member of the supervisory board: fixedFee 24000, expenseAllowance 1000, EUR, same decision, disclosed.
3. Association example set: treasurer of the board, fixedFee 0, expenseAllowance 600, not disclosed.

## Files

- `lib/Settings/register.d/92-mandate-remuneration.json`, `lib/Settings/profiles/corporate.json`, `lib/Settings/profiles/association.json`
- `src/manifest.json` (`GovernanceBodyDetail`), `src/manifest.d/configurable-types.json` (`PositionHoldDetail`)
- `tests/newman/mandate-remuneration.json` (the three readers), `tests/e2e/body-remuneration.spec.ts`
