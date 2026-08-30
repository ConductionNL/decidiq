## 1. Register Definition

- [x] 1.1 Create `lib/Settings/decidesk_register.json` as an OpenAPI 3.0.0 document with `x-openregister` extensions and the `decidesk` register metadata
- [x] 1.2 Define the `GovernanceBody` schema in the register JSON with all ADR-000 properties, required flags, enum values, and `schema:Organization` type annotation
- [x] 1.3 Define the `Meeting` schema with all properties, `schema:Event` type, lifecycle enum (`draft`, `scheduled`, `opened`, `paused`, `adjourned`, `closed`)
- [x] 1.4 Define the `AgendaItem` schema with `itemType` enum (`informational`, `discussion`, `decision`) and relation to Meeting
- [x] 1.5 Define the `Participant` schema with `role` enum and `schema:Person` type; add `x-openregister` relation to GovernanceBody
- [x] 1.6 Define the `Motion` schema with lifecycle enum and `x-openregister` relations to AgendaItem
- [x] 1.7 Define the `Amendment` schema with lifecycle enum and relation to Motion
- [x] 1.8 Define the `VotingRound` schema with `votingMethod` enum and relation to Motion
- [x] 1.9 Define the `Vote` schema with `value` enum and relations to VotingRound and Participant
- [x] 1.10 Define the `Decision` schema with `outcome` enum and relations to Motion
- [x] 1.11 Define the `ActionItem` schema with `taskStatus` enum and relations to Decision and Meeting
- [x] 1.12 Define the `Minutes` schema with lifecycle enum and one-to-one relation to Meeting
- [x] 1.13 Define the `DigitalDocument` schema (`schema:DigitalDocument`) with required `name` and `documentType`
- [x] 1.14 Define the `MonetaryAmount` schema (`schema:MonetaryAmount`) with required `value` and `currency`
- [x] 1.15 Define the `Offer` schema (`schema:Offer`) with required `name`, `price`, `priceCurrency`
- [x] 1.16 Define the `Order` schema (`schema:Order`) with required `orderNumber`, `orderDate`, `orderStatus`, `totalPrice`, `currency`
- [x] 1.17 Define the `Product` schema (`schema:Product`) with required `name`, `unitPrice`, `currency`
- [x] 1.18 Define the `Report` schema (`schema:Report`) with required `name` and `reportType`

## 2. Seed Data

- [x] 2.1 Add 3 GovernanceBody seed objects (Dutch municipality, water board, association) to the register JSON using `@self` envelopes
- [x] 2.2 Add 3 Meeting seed objects linked to the GovernanceBody seed entries
- [x] 2.3 Add 3–4 Participant seed objects with diverse roles (chair, secretary, member, observer)
- [x] 2.4 Add 3 AgendaItem seed objects linked to a Meeting seed entry
- [x] 2.5 Add 3 Motion seed objects (motion, amendment, procedural) with varied lifecycle states
- [x] 2.6 Add 3 Amendment seed objects linked to a Motion seed entry
- [x] 2.7 Add 3 VotingRound seed objects with result data (votesFor, votesAgainst, votesAbstain)
- [x] 2.8 Add 4 Vote seed objects linked to VotingRound and Participant seed entries
- [x] 2.9 Add 3 Decision seed objects including one with `isPublished: true` and `publishedAt`
- [x] 2.10 Add 3 ActionItem seed objects with varied `taskStatus` values including one `completed`
- [x] 2.11 Add 3 Minutes seed objects with varied lifecycle states and `signedBy` arrays

## 3. Install / Repair Step

- [x] 3.1 Create `lib/Migration/RepairStep.php` implementing `IRepairStep` that calls `ConfigurationService::importFromApp('decidesk')`
- [x] 3.2 Register the RepairStep in `appinfo/info.xml` under `<repair-steps><post-migration>`
- [x] 3.3 Verify that running the RepairStep twice does not create duplicate objects (idempotency via slug upsert)

## 4. Verification

- [x] 4.1 Install the app on a test Nextcloud instance with OpenRegister active; confirm the `decidesk` register appears in the OpenRegister admin UI
- [x] 4.2 Verify all 17 schemas are listed in the register with correct property counts
- [x] 4.3 Verify seed data is present: ≥3 objects per core schema (GovernanceBody, Meeting, Motion, Decision, Minutes)
- [x] 4.4 Create a test GovernanceBody with `bodyType: "legislative"` via the OpenRegister REST API; confirm HTTP 201
- [x] 4.5 Attempt to create a GovernanceBody with `bodyType: "invalid"`; confirm HTTP 400 validation error
- [x] 4.6 Create a VotingRound with `isSecret: true`; confirm the boolean is stored correctly
- [x] 4.7 Create a Decision with `isPublished: true` and `publishedAt`; confirm both fields are filterable
- [x] 4.8 Run the RepairStep a second time; confirm no duplicate seed slugs are created
- [x] 4.9 Validate that schema.org type annotations (`schema:Event`, `schema:Organization`, `schema:Person`) are present in the register JSON
- [x] 4.10 Verify that `_mail` metadata column is configured on the Decision schema to support email linking (REQ-SDM-020)
