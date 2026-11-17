# Design: bodies-term-of-office-and-step-down

Read at decidiq development `a13c5382`.

## Screen

Board **DcOrgaan** on the Zuiddrecht design canvas (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a, page dcb81aee8d83). What the board has and where this change puts its parts:

| Board element | This change |
|---|---|
| Header subline "Zittingstermijn 30 maart 2026 tot 29 maart 2030 · 31 leden" | unchanged; the body's own term |
| Gegevens orgaan: "Termijn begint", "Termijn eindigt" | the same two labels are used on the Leden add and edit form for one member |
| Leden card: "Alle 31 bekijken", "Oud-leden", "Contactgegevens", "Leden importeren", "Lid toevoegen" | "Lid toevoegen" asks Termijn begint and Termijn eindigt; Oud-leden lists memberships whose end date has passed |
| Meer > "Functies en integriteit": "Functievervullers", "Functies", "Nevenfuncties", "Geschenken" | a new entry "Rooster van aftreden" in this group, between Functies and Nevenfuncties |
| Functiehouders card: Voorzitter, Plaatsvervanger, Griffier | unchanged |

The Rooster van aftreden list has the columns Naam, Functie or "Lid", Termijn, Termijn eindigt, Herbenoembaar. A window filter offers "komende 12 maanden" (default), "deze zittingsperiode" and "alles".

## What exists

| Piece | Where |
|---|---|
| Position terms | `lib/Settings/register.d/70-configurable-types.json` PositionType (`termDurationMonths`, `maxConsecutiveTerms`, `reappointable`), PositionHold (`startDate`, `endDate`, `termNumber`) |
| Membership | `lib/Settings/decidesk_register.json` Membership (`startDate`, `endDate`) |
| Derived schedule rule | `configurable-types-domain-model` REQ-CTM-007, REQ-CTM-008 |
| Body page | `src/manifest.json` GovernanceBodyDetail, widget `body-position-holders` sorted by endDate |

## Approach

- **Form.** The Leden add dialog (`src/modals/MembershipFormDialog.vue` or the generic create dialog the Leden widget uses) shows `startDate` and `endDate` with the board's labels. `startDate` defaults to the body's term start.
- **List.** A widget `body-step-down-schedule` (custom, `src/components/widgets/StepDownScheduleWidget.vue`) fetches the body's memberships and position holds with an `endDate` in the window through the object store, unions and sorts them in the browser. It writes nothing. A custom widget is needed because one object-list cannot union two schemas; the widget carries a reason-bearing ratchet exclude.
- **Herbenoembaar.** Computed in the widget: a position hold is "nee" when `PositionType.reappointable` is false, "laatste termijn" when `termNumber` equals `maxConsecutiveTerms`, otherwise "ja". A plain membership shows "ja" unless the body sets a maximum.
- **Reminder.** An `x-openregister-notifications` rule on Membership and on PositionHold: `endDate` minus the body's `termReminderDays` (new optional integer on GovernanceBody, default 90), to the body's secretary group. Declared in a new fragment `lib/Settings/register.d/123-term-of-office-reminders.json`.

## Declarative or imperative

The reminder and the new body field are declarative. The list is a read-only widget. No service, no job.

## Tests

- vitest `StepDownScheduleWidget.spec.js`: a membership ending in March and a position hold ending in January sort January first; a hold at its last term shows "laatste termijn".
- Register test: the fragment validates against the real register loader and the notification rule names an existing property.
- Playwright `tests/e2e/step-down-schedule.spec.ts`.
