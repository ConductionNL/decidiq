---
kind: code
---

# Proposal: Admin Settings v1 — Members & Roles, Group/CSV Import, Org Config, Template Assignment

## Problem

The seeded spec `openspec/specs/admin-settings/spec.md` (status: partial, 1/4
requirements built) covers governing-body management only. Three requirements have no
surface at all, and the one member-facing surface that does exist is broken:

- **Members tab renders empty (real bug).** `GovernanceBodyMembersTab.vue` filters
  participants with `?governanceBody=<bodyId>`, but the `Participant` schema in
  `decidesk_register.json` declares `governanceBody` only in the
  `x-openregister-relations` extension block — it is **absent from `properties`**, so
  OpenRegister never materialises the field, no create surface ever writes it, and the
  filter matches nothing. Every live participant object lacks the field entirely.
  (The `Meeting` schema has the identical latent defect, which also breaks the
  `totalParticipantCount` / `presentParticipantCount` aggregations that filter on
  `@self.governanceBody`.)
- **No role-assignment UI** — roles can only be set at participant creation; there is
  no way to change a member's role from the body detail.
- **No member import** — neither from a Nextcloud group nor from CSV.
- **No organization configuration UI** — the admin panel only exposes the version
  widget and register-mapping form; org name/logo/timezone/locale/currency are not
  configurable.
- **No process-template assignment** — the body create dialog has a free-text
  `workflowTemplate` field but no template selector and no link field.

## What Changes

- **NEW** `lib/Settings/register.d/42-admin-settings-v1.json` (ADR-037 fragment) —
  adds `governanceBody` as a real property on `Participant` and `Meeting` (fixing the
  members-tab root cause additively), and adds `processTemplate` +
  `additionalTemplates` link fields on `GovernanceBody`.
- **MODIFIED** `src/components/tabs/GovernanceBodyMembersTab.vue` — role display +
  change-role action, import entry points; the inline add dialog is extracted to
  `src/modals/` (fixes a pre-existing modal-isolation violation).
- **NEW** `src/modals/MemberAddDialog.vue`, `MemberRoleDialog.vue`,
  `MemberGroupImportDialog.vue`, `MemberCsvImportDialog.vue`.
- **NEW** `src/utils/memberImport.js` — client-side CSV parse + row validation +
  duplicate detection (vitest-covered).
- **NEW** `lib/Service/MemberImportService.php` + `lib/Controller/MemberImportController.php`
  + routes — admin-gated (`#[AuthorizedAdminSetting(AdminSettings::class)]`) endpoints
  to list NC groups, list group members, and match CSV emails to NC accounts
  (validated + row-capped server-side).
- **MODIFIED** `lib/Service/SettingsService.php` — organization config keys
  (`organisation_name`, `organisation_logo`, `organisation_timezone`,
  `organisation_locale`, `organisation_currency`) via IAppConfig.
- **MODIFIED** `src/views/settings/Settings.vue` — Organization defaults section in
  the existing admin settings panel (admin gating untouched).
- **NEW** `src/components/tabs/GovernanceBodyTemplateTab.vue` + built-in template
  catalogue — assigns a default process template (and optional specialized templates)
  to a body. Template *management* stays out of scope (process-configuration spec).
- **MODIFIED** `src/manifest.json` + `src/registry.js` — register the template tab on
  the governance-body detail sidebar.
- Tests: PHPUnit (import service, org-config round-trip), vitest (CSV parse/preview),
  Playwright `tests/e2e/spec-coverage/admin-settings.spec.ts`, Newman
  `tests/integration/decidesk-admin-settings.postman_collection.json` wired into
  `tests/newman/run-all.sh`.
- i18n: English-source keys, `l10n/en.json` + `en_US.json` + `nl.json`.

## Capabilities

### Modified Capabilities

- `admin-settings`: role assignment on body members, member import (NC group + CSV),
  organization configuration, process-template assignment. Closes the remaining 3/4
  requirements and the broken members surface of the first.

## Out of Scope

- Process-template **management** (create/edit/duplicate templates, state-machine
  editor) — `process-configuration` spec, V1 tier.
- Import from Nextcloud **Contacts** (spec's requirement is satisfiable via Groups or
  CSV; Contacts import is follow-up).
- Rendering the organization name/logo on generated resolutions and minutes (document
  generation pipeline concern).

## Impact

- Schema: additive only (new properties via ADR-037 fragment; nothing removed or
  retyped). Existing participant objects simply lack `governanceBody` until linked.
- Security: all new server endpoints are admin-only via
  `#[AuthorizedAdminSetting(AdminSettings::class)]`; member CRUD itself stays on the
  OpenRegister object API (per-object RBAC enforced by OR).
- Dependencies: none new (OCP\IGroupManager / IUserManager / IAppConfig are platform).
