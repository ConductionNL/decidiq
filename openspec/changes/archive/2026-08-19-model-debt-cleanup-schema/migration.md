# Migration: model-debt-cleanup-schema

This change ships no `lib/Migration/VersionXXXX` class and no
`lib/Repair/` step. decidesk's OpenRegister schema register does not live in
Nextcloud's own SQL tables — it propagates through OpenRegister's own
version-gated importer. This document describes that propagation mechanism
and explicitly hands off the live-data side to `model-debt-cleanup-code`.

## Current State

- `Decision` has no `meeting`/`agendaItem` properties (undeclared writes accepted silently).
- `ConflictOfInterest.boardMember` → `$ref: Participant`.
- `ProxyAuthorization.grantor`/`holder` → `$ref: Participant`; no `proxyStatus` property.
- `BoardProxy` is `x-openregister.active: true`, the live schema `ProxyVoteService` writes.
- `GoverningDocument` has no current-in-force convenience property.
- Schema slugs `adviceRequest`, `proxyAuthorization` (camelCase) in `components.registers.decidesk.schemas`.

## Target State

- `Decision.meeting`/`.agendaItem` declared, optional, `facetable: true`.
- `ConflictOfInterest.boardMember` → `$ref: Membership`.
- `ProxyAuthorization.grantor`/`holder` → `$ref: Person`; `proxyStatus` added.
- `BoardProxy` → `x-openregister.active: false`, description points at `ProxyAuthorization`.
- `GoverningDocument.currentEffectiveDate` declared, nullable, `facetable: true`.
- Schema slugs `advice-request`, `proxy-authorization` (kebab-case) everywhere they're referenced.

## Propagation Mechanism (not a migration class)

1. `SettingsService::mergeRegisterFragments()` glob-reads `lib/Settings/register.d/*.json` in filename order and deep-merges each onto the base config loaded from `decidesk_register.json` (`lib/Service/SettingsService.php:351-380`).
2. The merged fragment signature (`md5` of each fragment's content, concatenated) is folded into the imported config's version string (`importRegisterConfig()`, same file, line ~397-410).
3. `OCA\OpenRegister\Service\ConfigurationService::importFromApp()` re-imports the register whenever that composite version string changes — i.e. whenever any fragment file (including the new `67-model-debt-cleanup.json`) or a directly-edited fragment/base file changes.
4. This runs at settings load (admin settings page visit) and — per the existing `<repair-steps><install>`/`<post-migration>` entries in `appinfo/info.xml` — via `OCA\Decidesk\Repair\InitializeSettings`, which is registered to run on every app upgrade.

No new registration is needed in `appinfo/info.xml` for the schema change itself — `InitializeSettings` already re-triggers the fragment merge + import on every upgrade. The two direct edits to `decidesk_register.json` (slug renames in `components.registers.decidesk.schemas`) are picked up the same way, since the importer reads the whole merged config, not just the fragment delta.

## Migration Steps

1. Land `lib/Settings/register.d/67-model-debt-cleanup.json` (new fragment: Decision joins, ConflictOfInterest retarget, Participant description narrowing, BoardProxy retirement, seed objects).
2. Directly edit `60-advisory-opinion-workflow.json` (slug rename + seed object key), `63-member-proxy-authorization.json` (slug rename, seed object key, grantor/holder retarget, proxyStatus addition), `55-governing-documents-register.json` (currentEffectiveDate addition).
3. Directly edit `decidesk_register.json`'s `components.registers.decidesk.schemas` list (two string renames only).
4. Update `src/manifest.json` and the two affected `src/manifest.d/*.json` files (slug string updates only, no structural change).
5. On next app upgrade / settings load, `InitializeSettings` → `mergeRegisterFragments()` → `importFromApp()` picks up all of the above in one version-gated import.

## Data Impact

Zero rows are rewritten by this change. Existing `decision`, `conflict-of-interest`,
`proxyAuthorization`, `board-proxy`, and `governing-document` rows are untouched —
the schema DECLARATION changes; stored data does not. This is the same "declare
first, backfill separately" pattern already used by `RenameDutchDecideskValues`
(see that repair step's own comment: "The schema edit changes the DECLARATION;
every row already written still holds the [old] string"). The live-data side —
resolving stale `Participant` UUIDs on `ConflictOfInterest`/`ProxyAuthorization`
rows and migrating `board-proxy` rows into `proxyAuthorization` — is
`model-debt-cleanup-code`'s migration.md, not this document's concern.

## Rollback Procedure

Revert the fragment file and the direct edits; the next settings load re-imports
the reverted (shorter) merged config under a different version-gated signature.
No SQL rollback needed — OpenRegister's schema import is itself idempotent and
version-gated, matching the rollback strategy already stated in proposal.md.

## Validation

- `openspec validate` passes for this change.
- Manually trigger a settings-load (visit decidesk admin settings) in a dev
  environment and confirm via OpenRegister's schema browser that: `decision`
  shows `meeting`/`agendaItem`; `conflict-of-interest.boardMember` `$ref`s
  `Membership`; `proxyAuthorization.grantor`/`holder` `$ref` `Person` and
  `proxyStatus` is present; `board-proxy`'s `x-openregister.active` is `false`;
  `governing-document.currentEffectiveDate` is present; `components.registers.decidesk.schemas`
  contains `advice-request`/`proxy-authorization` and not the camelCase forms.
- `composer test` (`tests/Unit/RegisterJsonTest.php`) passes unmodified — confirmed
  in design.md Decision 1 that this test never reads `components.registers` or
  register.d fragments, so it is unaffected by this change.
