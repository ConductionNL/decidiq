# governance-bodies Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-term-of-office-and-step-down](../../) (this delta)

## Purpose

A body's page shows whose term ends when and whether they can stand again, computed from memberships and position holds. Covers matrix row bod-07. Screen: board DcOrgaan on the Zuiddrecht design canvas.

## ADDED Requirements

### Requirement: REQ-TOS-001 A member's term is entered when the member is added

The Leden add and edit form on a body's page SHALL ask "Termijn begint" and "Termijn eindigt" and store them on the membership's `startDate` and `endDate`. "Termijn begint" MUST default to the start of the body's current term.

#### Scenario: adding a council member with a term

- GIVEN the Gemeenteraad with term 30 March 2026 to 29 March 2030
- WHEN the griffier adds Rachid Yilmaz through Lid toevoegen
- THEN the form shows Termijn begint filled with 30 March 2026
- AND after saving, Rachid's membership carries that start date and the end date the griffier entered

### Requirement: REQ-TOS-002 The step-down schedule lists every term that ends, soonest first

The body's page SHALL offer "Rooster van aftreden" under Meer > Functies en integriteit. The list MUST union the body's memberships and position holds whose end date falls in the chosen window, sorted by end date ascending, with name, position or "Lid", term number, end date and Herbenoembaar. The list MUST be computed on read; the system MUST NOT store a copy of it.

#### Scenario: a position term ends before a membership term

- GIVEN Lars de Koning's membership ends on 29 March 2030
- AND his hold of Plaatsvervanger ends on 1 January 2027
- WHEN the griffier opens Rooster van aftreden with the window "alles"
- THEN the Plaatsvervanger line for 1 January 2027 is listed before the membership line for 29 March 2030

#### Scenario: nothing is stored

- GIVEN the griffier opened the schedule
- WHEN the register is searched for a schedule object
- THEN none exists

### Requirement: REQ-TOS-003 Herbenoembaar follows the position's rules

For a position hold, Herbenoembaar MUST read "nee" when the position type is not reappointable, "laatste termijn" when the term number equals the position type's maximum consecutive terms, and "ja" otherwise.

#### Scenario: a treasurer in the last allowed term

- GIVEN position type Penningmeester allows 2 consecutive terms
- AND Joost Hendriks holds it in term 2
- WHEN the schedule is shown
- THEN Joost's line reads "laatste termijn"

### Requirement: REQ-TOS-004 The secretary is reminded before a term ends

The register SHALL declare a notification to the body's secretary group a number of days before a membership or position hold ends. The number of days MUST come from the body's `termReminderDays`, default 90.

#### Scenario: a reminder 90 days ahead

- GIVEN a body without its own reminder setting
- AND a position hold ending on 1 January 2027
- WHEN the notification sweep runs on 3 October 2026
- THEN the body's secretary group gets one notice naming the holder, the position and the end date

### Requirement: REQ-TOS-005 Ended memberships show as former members without a write

A membership whose end date has passed MUST be listed under "Oud-leden" on the body's page and MUST NOT be listed under the current members. The system MUST NOT change the membership record when its term ends.

#### Scenario: a term passes

- GIVEN Sanne Mulder's membership ended yesterday
- WHEN the griffier opens the body's page
- THEN Sanne is listed under Oud-leden
- AND her membership record is unchanged
