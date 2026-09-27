# Design: bodies-board-composition-skills-and-diversity

Kind: code. Two new schemas and one body property in a register fragment, one widget with two sections and one modal on the body page, and a confirm action behind the existing body scope. The JSON is a real share of the work, but the matrix and the figures are code, so the change is `code` (ADR-032).

Read at development `4d7430ff`.

## What is there today

- `Person` (`lib/Settings/decidesk_register.json:183`) carries `gender` (free string, seeded `female` and `male`), `birthDate` and `nationality`. `Membership` (`:334`) carries `independenceStatus` and `external`.
- The body page `GovernanceBodyDetail` (`src/manifest.json:454`) has seven widgets: data, members, files, template, efficiency, retention and evaluations, each custom one wired in `slots` (`:457` to `:463`).
- `GovernanceBodyMembersTab` (`src/components/tabs/GovernanceBodyMembersTab.vue:245` to `:256`) already loads the body's active memberships and their persons. The composition widget needs the same two lists.
- `GovernanceReportingService::generateAnnualReport()` (`lib/Service/GovernanceReportingService.php:70`) computes an independence ratio (`computeIndependenceRatio()`, `:495`) for `/api/governance-reports` (`appinfo/routes.php:221` to `:224`), which no page calls. It reads fields of the retired board portal (`meetingIntegration`, `resolutionKoppeling`), so it is not a base to build on.
- Who presides over a body: the `signatory` scope of `GovernanceScopeGuard::isInBodyScope()` (`lib/Service/GovernanceScopeGuard.php:119`), projected from the roster by `GovernanceRoleScopeProjector`.
- Declared aggregations exist on two schemas (`decidesk_register.json:984` and `:2506`) and are served by OpenRegister's `AggregationController`; each groups one schema by its own fields.
- The corporate example set asks "What skills or perspectives is the board missing?" in its self-evaluation (`lib/Settings/profiles/corporate.json:1123`).

## Decisions

### D1. Competences are per body, and held per membership

`board-competence`: `governanceBody` (required), `name` (required), `description`, `requiredHolders` (integer, minimum 1, default 1), `order`, `active` (default true). A body names its own competences; a housing corporation needs "Real estate" and a water board "Water management", so there is no fleet-wide list.

`member-competence`: `membership` (required), `competence` (required, a `board-competence` of the same body), `level` (enum `basic`, `experienced`, `expert`), `note`, `confirmedBy`, `confirmedAt`. It points at the membership, not the person, because a person's finance expertise matters to the board they sit on in that capacity, and it ends with the membership.

### D2. Who writes what

- A body's competences are created and edited by users in its `signatory` scope.
- A member records their own competences; a signatory may record them for any member.
- Only a signatory may confirm one. Confirming sets `confirmedBy` and `confirmedAt`; changing the level clears both.

These rules are declared as property and object authorization on the two schemas where OpenRegister can express them, and enforced in a small `CompetenceConfirmationGuard` for the confirm action, which OpenRegister cannot express because it depends on the body scope of the related membership.

### D3. The composition widget

`GovernanceBodyCompositionTab.vue` in `src/components/tabs/`, registered in `src/registry.js` and wired on `GovernanceBodyDetail` as widget `body-composition` with a slot and a layout cell.

- **Skills matrix**: rows are the current members, columns the body's active competences, cells the level with "unconfirmed" when not confirmed. Under each column: confirmed holders at level `experienced` or `expert` against `requiredHolders`, marked "Gap" when short. A "Record competence" action opens `src/modals/MemberCompetenceModal.vue`.
- **Diversity**: counts and shares of current members by gender value, by age band (under 40, 40 to 54, 55 to 69, 70 and over, from `birthDate` on the day), by nationality, by `independenceStatus` and by `external`. Every dimension has a "not recorded" count. Targets from D4 are shown beside their dimension as met or not met.

Both sections are computed in the widget from the members and competences it loads, bounded by the body's size (ADR-058).

### D4. Targets the body sets itself

`GovernanceBody.diversityTargets`: array of objects `dimension` (enum `gender`, `age-band`, `nationality`, `independence`), `value` (string), `minimumShare` (number between 0 and 1). For example `{ gender, female, 0.33 }`. The widget compares each against the current share.

## Declarative or imperative

- Declared in a new fragment `lib/Settings/register.d/NN-board-composition.json` (next free number at build time): the two schemas with `x-openregister-relations` to `governance-body` and `membership`, their authorization blocks, and `GovernanceBody.diversityTargets`. Every property written is declared, because OpenRegister discards undeclared properties on write (see the note at `decidesk_register.json:2592`).
- The matrix gaps and the figures are computed in the widget, not declared as `x-openregister-aggregations`. Each figure groups persons by a field reached through a membership, and each gap compares a count on one schema with a number on another; a declared aggregation groups one schema by its own fields. A server service for it would add a second reader of the same rows without a second consumer.
- The confirm guard is PHP under the ADR-031 exception for guards.

## Seed data

In `lib/Settings/profiles/corporate.json`, for body `rvc-waterschap-amstel` and its three memberships:

- `board-competence`: "Finance and audit" (`requiredHolders` 1), "Water management" (1), "Legal" (1), "IT and cybersecurity" (1);
- `member-competence`: Janneke de Bruin, Finance and audit, expert, confirmed; Jan de Vries, Legal, experienced, confirmed; Mark van den Berg, Water management, expert, unconfirmed;
- `diversityTargets` on the body: `{ gender, female, 0.33 }`.

With those seeds the matrix shows a gap for "IT and cybersecurity", and one for "Water management" until Mark's level is confirmed; the figures show one woman of three, which meets the target.

## Risks

- Gender is free text on `Person`. The figures group by the stored value, so "F" and "female" count apart. The widget lists values as stored, and cleaning the vocabulary is a data task for the body, not a rule this change imposes.
- Age bands shift over time. They are computed on the day, never stored.
