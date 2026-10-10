# Design: platform-role-rights-per-record-type

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Register rules | `lib/Settings/decidesk_register.json:61` baseline plus schema authorization blocks |
| Guards | `lib/Service/MeetingRoleGate.php`, `GovernanceScopeGuard.php`, `PublicationStaffGuard.php` |

## Approach

1. Read the authorization blocks from the loaded register; render them; role-to-group mapping in IAppConfig consumed where the register names decidiq groups.

## Declarative or imperative

The rules stay declarative in the register; only the role-to-group mapping is configuration.

## Tests

- PHPUnit: the rights endpoint lists every schema with its rules (red before).
- vitest: the page renders a row per schema.

## Design corrections (30 Sep, build)

- **Rewrite on import (Ruben, DECISIONS row 22).** OpenRegister evaluates the
  authorization rules with literal group names, so a mapping kept in app
  config alone would change nothing. `RoleGroupMapping::rewrite()` runs on the
  merged register in `SettingsService` before `importFromApp()`: wherever a
  rule names a role's group, the mapped groups are added beside it, a
  conditional rule (`{group, match}`) is copied with its condition. The
  mapping's signature is added to the import version (`+roles.<hash>`), so a
  changed mapping re-imports instead of being skipped as a known version.
  Saving on the page re-imports at once.
- **Both levels are rewritten.** A register-level authorization block is the
  baseline of every schema without rules of its own. Minutes are such a
  schema: who may change minutes comes from the register baseline. Rewriting
  only the schema blocks would have left them on the old groups; the page
  marks those rows "(the app's general rules)".
- **The roles are the groups the rules name.** The register names three
  decidiq groups: `decidiq-administrators` (with its pre-rename twin
  `decidesk-administrators`), `decidiq-secretariat` and
  `decidiq-publication-flow`. Those are the mappable roles (record
  administrators, secretariat, publication flow). There is no separate
  "griffie" or "members" group in the rules: the scenario's griffie editing
  minutes is the Griffie group added to the record administrators role.
  `authenticated` and `public` are not roles and cannot be mapped.
- **Mapping only widens.** The role's own group stays in every rule, so an
  administrator cannot lock the app's administrators out by mapping.
