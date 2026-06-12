# Design — admin-settings-v1

## 1. Members-tab root cause and fix

`GovernanceBodyMembersTab.vue` queries
`GET /apps/openregister/api/objects/decidesk/participant?governanceBody=<bodyId>`.
OpenRegister filters on **materialised schema properties**; `governanceBody` is only
declared under `x-openregister-relations` (an annotation block OR stores but does not
turn into a property — see `openregister/lib/Db/Schema.php`), so the field does not
exist on any participant object and the filter yields zero rows — the tab is
permanently empty.

Fix (additive, ADR-037): a register fragment `lib/Settings/register.d/42-admin-settings-v1.json`
overlays:

```json
components.schemas.Participant.properties.governanceBody  (string, uuid of the body)
components.schemas.Meeting.properties.governanceBody      (same latent defect; the
   Meeting aggregations totalParticipantCount/presentParticipantCount filter on
   @self.governanceBody and were equally dead)
components.schemas.GovernanceBody.properties.processTemplate      (string)
components.schemas.GovernanceBody.properties.additionalTemplates  (array<string>)
```

`SettingsService::loadConfiguration()` already folds a fragment signature into the
import version, so OpenRegister's version-gated `importFromApp` re-imports
automatically — no manual migration step. The `x-openregister-relations` blocks stay
(they document cardinality and feed the notification dispatcher's typed-relation
resolution).

## 2. Members & roles UI

`GovernanceBodyMembersTab.vue` keeps its add-existing/remove posture and gains:

- a **Change role** row action → `src/modals/MemberRoleDialog.vue` — `NcSelect`
  (`inputLabel`) over the schema role enum (chair, vice-chair, secretary, member,
  observer, guest); saves via the shared object store's `saveObject('participant', …)`
  (OpenRegister enforces per-object RBAC server-side; there is no app endpoint to
  guard — ADR-022 forbids a pass-through controller here).
- the inline `NcDialog` add-member picker is **extracted** to
  `src/modals/MemberAddDialog.vue` (pre-existing modal-isolation violation in the
  touched file).
- two import entry points (NC group / CSV) opening the import dialogs below.

## 3. Member import

### From a Nextcloud group

Listing NC groups and their members requires server support. New
`MemberImportController` (+ `MemberImportService`):

| Route | Method | Purpose |
|---|---|---|
| `/api/member-import/groups` | GET | groups (`gid`, `displayName`, `userCount`) |
| `/api/member-import/groups/{groupId}/members` | GET | members (`uid`, `displayName`, `email`) |
| `/api/member-import/match` | POST | email[] → `{uid, displayName}` map |

All three carry `#[AuthorizedAdminSetting(AdminSettings::class)]` — admin-only (the
import surface is an administrator workflow per the spec), satisfying route-auth and
semantic-auth gates. **No** `#[NoAdminRequired]` anywhere on this controller.

`MemberGroupImportDialog.vue`: pick group (`NcSelect` + `inputLabel`) → preview table
(name, email, duplicate flag) → batch `saveObject('participant', …)` creating one
participant per non-duplicate member with `governanceBody = <bodyId>`,
`nextcloudUserId = uid`, default role `member`. Duplicates are detected client-side
against the body's current member list by `nextcloudUserId` (fallback email) and
skipped.

### From CSV

Client-side parse (no new dependency — `src/utils/memberImport.js` implements a small
RFC-4180-ish parser: quoted fields, escaped quotes, CRLF). Expected header
`name,email,role`. Validation per row: non-empty name, well-formed email, role in the
schema enum (empty role defaults to `member`); duplicate detection against existing
members **and** within the file (by email). Preview table shows per-row status
(ok / error reason / duplicate-skip) before anything is written.

NC account linking: the dialog calls `POST /api/member-import/match` with the
candidate emails; the server (admin-gated) validates each email shape, **caps the
batch at 500 rows** (HTTP 413 beyond), and resolves accounts via
`IUserManager::getByEmail()`. Matched rows get `nextcloudUserId`; unmatched rows are
flagged `unmatched` in the preview (manual linking later) — satisfying the spec's
"flagged for manual linking" clause. Import then batch-creates via the OR object API.
The client mirrors the 500-row cap.

## 4. Organization configuration

`SettingsService::CONFIG_KEYS` grows five keys (IAppConfig-backed):
`organisation_name`, `organisation_logo` (image URL), `organisation_timezone`,
`organisation_locale` (nl/en), `organisation_currency`. The existing
`SettingsController::create` (`#[AuthorizedAdminSetting]`) persists them — no new
routes, admin gating untouched. `Settings.vue` gains an "Organization" section:
name + logo URL text inputs, timezone/locale/currency `NcSelect`s (all with
`inputLabel`).

## 5. Process-template assignment

Management of templates is explicitly out of scope (process-configuration spec, V1).
Assignment only:

- `GovernanceBody.processTemplate` — default template id; `additionalTemplates` —
  specialized template ids (statute amendment, board election, …).
- Built-in catalogue (`src/components/tabs/processTemplates.js`): `standard-decision`,
  `statute-amendment`, `board-election`, `urgent-decision` — ids stable, labels i18n.
  When the process-configuration spec ships real template objects the catalogue is
  replaced by an OR query; the link fields stay as-is.
- `GovernanceBodyTemplateTab.vue` on the body detail sidebar (manifest `sidebarTabs`,
  order 30): current assignment, default selector (`NcSelect`, single), specialized
  multi-select, Save via `saveObject('governance-body', …)` (OR per-object RBAC).

## 6. Test strategy

- **PHPUnit** (`tests/unit/Service/MemberImportServiceTest.php`,
  `tests/unit/Service/SettingsServiceOrganisationTest.php`): group/member mapping,
  email-match validation + 500-row cap + malformed-email handling, org-config
  round-trip through a stubbed IAppConfig.
- **vitest** (`tests/vitest/memberImport.spec.js`): CSV parser (quotes, CRLF, BOM),
  row validation matrix, duplicate handling (file-internal + against existing),
  role defaulting.
- **Playwright** (`tests/e2e/spec-coverage/admin-settings.spec.ts`): members tab
  add/role flows, template tab, org section in admin settings, import dialogs —
  defensive skips when the live deploy lacks the surface.
- **Newman** (`tests/integration/decidesk-admin-settings.postman_collection.json`,
  wired into `tests/newman/run-all.sh`): admin 200s on the three import endpoints,
  401 unauthenticated and non-admin 403 posture, match-endpoint validation (422/413).

## 7. Decisions

- **Fragment over monolith edit** — three sibling PRs merge concurrently;
  ADR-037 exists precisely so register changes don't conflict.
- **No participant CRUD controller** — OR object API + per-object RBAC; a wrapper
  would be a gate-17 redundant controller.
- **Admin-only import endpoints** — group membership enumeration is directory
  disclosure; `AuthorizedAdminSetting` matches the existing settings mutation posture.
- **Seeds untouched** — seed-time slug→uuid resolution for relation fields is not a
  verified OR import behaviour; demo linkage is not worth a misleading fixture.
