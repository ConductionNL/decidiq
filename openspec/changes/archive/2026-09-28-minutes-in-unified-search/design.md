# Design: minutes-in-unified-search

Read at decidiq development `4d7430ff`.

## What exists

| Piece | Where |
|---|---|
| Provider | `lib/Search/DecidiqSearchProvider.php:52` implements `OCP\Search\IProvider`; `:59-62` `SCHEMAS = ['decision' => 'decisions', 'meeting' => 'meetings']`; `:69` `LIMIT_PER_SCHEMA = 5`; `:157-200` `search()` calls OpenRegister `ObjectService::findAll(['register' => 'decidiq', 'schema' => ..., 'search' => $term, 'limit' => 5])` per schema; `:215-240` `buildEntry()` uses `title`, a status from `lifecycle`/`status`/`outcome`, and joins the subline with an em-dash; `:248` `schemaLabel()` |
| Registration | `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php:105` |
| Minutes schema | `lib/Settings/decidesk_register.json:2990` `Minutes`: `title`, `lifecycle`, `content`, `approvedAt`, `itemNotes`, `meeting` |
| Minutes page | `src/manifest.json:1022` `MinutesDetail` (`/minutes/:id`) |
| Test | `tests/Unit/Search/DecidiqSearchProviderTest.php` |

## Approach

1. Add `'minutes' => 'minutes'` to `SCHEMAS`.
2. `buildEntry()` takes the subline date per schema: `decisionDate` for a decision, `scheduledDate` for a meeting, `approvedAt` for minutes (left out when empty). The label map gains `'minutes' => $this->l10n->t('Minutes')`.
3. Replace the em-dash that `implode()` puts between subline parts with a middle dot (`' · '`).
4. Read rules: `findAll()` runs under the caller's session, so OpenRegister's `authorization.read` on `Minutes` decides what is returned; the provider adds no filtering of its own.

## Declarative or imperative

Imperative by necessity: `OCP\Search\IProvider` is Nextcloud's API. No schema change, no `x-openregister-*` extension involved.

## Seed data

None needed: every example set seeds minutes with content.

## Files

- `lib/Search/DecidiqSearchProvider.php`, `l10n/*` (the Minutes label), `tests/Unit/Search/DecidiqSearchProviderTest.php`, `tests/e2e/unified-search.spec.ts`
