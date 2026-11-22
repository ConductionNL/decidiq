# Tasks: agenda-item-confidential-papers

## Implementation tasks

### Task 1: Fields and read rules
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public`, `#requirement-req-acp-002-only-authorised-readers-can-read-a-non-public-item-and-its-papers`
- **files**: `lib/Settings/register.d/129-agenda-item-confidential-papers.json` (new: `public`, `authorisedReaders`, `authorization.read` on AgendaItem and DigitalDocument), register version move and lock-file test (decision 70)
- **acceptance_criteria**:
  - the read rules are evaluated by OpenRegister's real RBAC evaluator in the test: body member yes, other body no, named reader yes
  - if the dialect cannot express "user in `authorisedReaders`", stop and file an OpenRegister ask; do not filter in decidiq PHP
- [ ] Implement
- [ ] Test (red first): register fragment test with the real evaluator
- Note: the fields (`public`, `authorisedReaders`) are in the fragment. The read rules are not yet: the body rule needs the governance body on AgendaItem (it only has `meeting`) for a `$memberOf` rule on participant `governanceBody` matched by `nextcloudUserId`; the named-reader rule is expressible as `{"authorisedReaders":{"$contains":"$userId"}}`. Both need a test through OpenRegister's evaluator, which decidiq's unit suite does not load.

### Task 2: Item page and Stukken badges (board DcAgendapunt)
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public`
- **files**: `src/manifest.json` (AgendaItemDetail Kerngegevens field, Stukken badge, Lezers toevoegen for the secretariat), `l10n/` ("Openbaar", "Besloten", "Besloten punt", "Lezers toevoegen")
- **acceptance_criteria**:
  - matches the board's Kerngegevens and Stukken
- [ ] Implement
- [ ] Test (red first): vitest on the page config
- Note: the Stukken badge is built: `DocumentMetadataTab` shows "Openbaar" or "Besloten" per paper from its record (`src/utils/paperPublicity.js`, `tests/vitest/paperPublicity.spec.js`). Kerngegevens shows `public` through the page's generic data widget, which renders every schema field. Left: the "Alleen voor leden van {orgaan}" line with "Lezers toevoegen" for the secretariat, and "Besloten punt" with only the number for readers who may not read the item (that one depends on the read rules of task 1).
- Progress (22 Nov): the "Alleen voor leden van {orgaan} en wie de griffie toevoegt" line is built. On a non-public item the readers widget (`AgendaItemReadersTab`, which carries "Lezers toevoegen" for the secretariat) reads the item's meeting and its governance body and says "Only for members of {body} and the people the clerk adds."; when the body cannot be read it says the same without the name. Rules in `src/utils/confidentialReaders.js` (`closedItemAudience`, `relationId`), tested in `tests/vitest/confidentialReaders.spec.js` and `tests/vitest/agendaItemReadersAudience.spec.js`; two strings in all 38 catalogues. Left: "Besloten punt" with only the number for readers who may not read the item, which needs task 1's read rules.

### Task 3: Inheritance and restriction listener
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public`, `#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item`
- **files**: `lib/Listener/AgendaItemConfidentialityListener.php` (new), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - the test constructs the real OpenRegister object events, not fakes
- [x] Implement
- [x] Test (red first): `tests/Unit/Listener/AgendaItemConfidentialityListenerTest.php`
- Note: before-save on `digital-document` (SaveGuardSubscriptions) closes a paper saved onto a non-public item; after-save on `agenda-item` closes the item's public papers; after-save on `confidentiality-restriction` closes the item when scope `item` reaches `imposed` (lifting changes nothing). Writes run with `_rbac: false`: closing is an invariant, not the saver's right.

### Task 4: Pack and publication leave the item out
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site`
- **files**: `lib/Service/MeetingPackageService.php`, `lib/Service/PublicationPayloadService.php`
- [x] Implement
- [x] Test (red first): `tests/Unit/Service/MeetingPackageServiceTest.php`, `tests/Unit/Service/PublicationClosedItemTest.php`

### Task 5: View log
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged`
- **files**: the paper download path (`lib/Controller/` that serves meeting papers), `lib/Service/AuditLogService.php`
- [x] Implement
- [x] Test (red first): `tests/Unit/Service/ClosedPaperReadsTest.php`, two more in `tests/Unit/Controller/PaperControllerTest.php`
- Built (21 Nov): agenda-paper-watermark gave decidiq its own paper read path, `GET /api/papers/{schema}/{objectId}/{fileId}/view`, which the Papers widget links members through. `PaperDelivery` hands every read of an agenda item's paper to `ClosedPaperReads::record()` before the file is read: when the item's `public` is false, one `material-access` entry (kind `closed-paper-read`: reader, item, file id, paper name) is appended through `AuditLogService` with the item as its only object, so it lands on the item's own audit trail, which its History tab renders. A read that cannot be recorded is refused with 503. Still outside it: a paper read straight through OpenRegister's file API (the Documents widget), and the History rendering is confirmed in the live pass.
- Note (earlier): decidiq has no paper download path to hook: the Documents widget and DocumentMetadataTab read papers through OpenRegister's object file endpoints and Nextcloud Files, so a read never passes decidiq PHP. Logging each read of a closed paper belongs where the file is served (an OpenRegister read audit on object files), then shown on the item's History tab, which already renders OpenRegister's audit trail.

## Verification

- Each task has a unit or vitest test that is red before the code and green after.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- Playwright `tests/e2e/confidential-agenda-item.spec.ts` tagged with each scenario.
  - Written for the scenarios that are built (REQ-ACP-001 closing, REQ-ACP-003, REQ-ACP-004, REQ-ACP-005 pack). The REQ-ACP-002 read-rule scenarios and the REQ-ACP-006 view log get theirs with tasks 1 and 5.
