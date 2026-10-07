# Tasks: portal-contribution

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation

- [x] Ship the plain, dependency-free provider class `lib/Portal/PortalContributionProvider.php`
  - Namespace `OCA\Decidiq\Portal`; no `use` of any portaliq symbol; no `implements`; no constructor; no info.xml dependency; repo-standard EUPL-1.2/SPDX docblock + `@spec` tags.
  - Not registered in `lib/AppInfo/Application.php` — discovery is pull-based by FQCN from portaliq.

- [x] Implement the v2 + v1 audience contract
  - `getAudiences()` returns `['citizen']`; `getAudience()` returns `'citizen'`; `getContribution()` returns `null` for any other/absent audience (fail-closed).

- [x] Declare the `citizen` read manifest over register `decidiq`
  - `citizenReactions` (`consultation-reaction`, `scopeField: submitterId`), `citizenVotes` (`citizen-vote`, `scopeField: voterId`), `citizenBudgetProposals` (`budget-proposal`, `scopeField: submitter`) — all default subjectRef scope (no `scopeClaim`), `minTrust: low`, listable, each with its documented `fields` whitelist.

- [x] Declare the `citizenNotifications` inbox collection
  - `notification`, `scopeField: recipientId`, `kind: 'inbox'`, `minTrust: low`, listable, projected to `type`/`subject`/`content`/`channel`/`status`/`sentAt`/`readAt`.

- [x] Keep this wave read-only and document the deferrals
  - `actions` and manifest-level `notifications` empty; both creates deferred (parent-relation link has no writable scalar property); public consultation/results lists deferred (not per-subject) — all recorded on Conduction/decidiq#113 in design.md.

- [x] Unit-test the full provider contract (`tests/Unit/Portal/PortalContributionProviderTest.php`)
  - Direct construction (no mocks/container); pins audiences, fail-closed null, the four collections, default subjectRef scoping (no `scopeClaim`/`via`), the inbox `kind`, field whitelists and forbidden exclusions.
  - Register-drift pin: every scopeField and projected field exists on the shipped schema in `lib/Settings/decidesk_register.json`.

- [x] Register the capability spec `openspec/specs/portal-contribution/spec.md`
  - Exists with status `in-progress`, pointing at this change.

- [x] Pass the quality gates
  - `php -l`, `composer phpcs`, `psalm`, `phpstan` and the unit suite pass on the new files (php:8.3-cli container) with zero new violations; `openspec validate portal-contribution --strict` exits 0.

## Acceptance criteria

- `new PortalContributionProvider()` constructs with no dependencies; no `implements`; source references no portaliq symbol.
- The `citizen` manifest ships exactly the four documented collections, each scoped by the pseudonymous subjectRef (default), gated `low`, read-only.
- Every scopeField and projected field is verified to exist on its schema at HEAD; no staff/moderation or other-citizen column appears in any whitelist.
- Manifest labels ship in English source (i18n policy); portaliq owns portal-side translation.
- No register JSON change; no route, controller, service, frontend or `info.xml` change.
- No new API endpoints (no Newman collection); no UI change (portal renders in portaliq, no Playwright).
- `openspec validate portal-contribution --strict` passes.
