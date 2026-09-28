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
