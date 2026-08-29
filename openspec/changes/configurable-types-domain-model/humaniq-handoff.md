# Handoff: employee-lifecycle concepts move from decidiq to humaniq

**From:** decidiq (`ConductionNL/decidiq`, repo dir `decidesk`)
**To:** humaniq (`ConductionNL/humaniq`, repo dir `hrmq`) — *for humaniq's own backlog*
**Status:** proposal. **Nothing in `hrmq` is edited by this change.**
**Date:** 2026-08-27

## Why

decidiq is a decision-making platform. Onboarding a member, offboarding them,
and tracking when their term expires are **people-lifecycle** concerns. They
landed in decidiq because the council-installation use case arrived here first,
not because they belong here. Keeping them means decidiq maintains a second,
diverging HR model.

## What decidiq holds today

| decidiq schema | File | Verdict |
|---|---|---|
| `OnboardingTraject` | `lib/Settings/register.d/59-member-onboarding.json` | **adopt** — humaniq already ships the concept |
| `OffboardingTraject` | `lib/Settings/register.d/59-member-onboarding.json` | **adopt** — humaniq already ships the concept |
| `RoosterVanAftreden` | `lib/Settings/register.d/61-appointments-and-terms.json` | **do not move — delete.** It is a derived view (see below) |
| `RoosterRegel` | `lib/Settings/register.d/61-appointments-and-terms.json` | **do not move — delete.** Same |
| `TermijnRegeling` | `lib/Settings/register.d/61-appointments-and-terms.json` | **move** — humaniq has no term-of-office concept at all |

## What humaniq already has (verified, not assumed)

humaniq declares **54 schemas**, and the bodies live in 32 fragments under
`hrmq/lib/Settings/register.d/` — the main `humaniq_register.json` has
`components.schemas: {}`, which is why a sweep reading only the main register
would wrongly conclude humaniq models none of this.

- **`Onboarding`** (`register.d/hr-onboarding.json`, slug `Onboarding`) — shipped,
  with a declarative `x-openregister-lifecycle`
  (`aangenomen → contract_getekend → gegevens_gevalideerd →
  gereed_eerste_werkdag → proeftijd_lopend → afgerond`), rules in
  `lib/Standards/Checks/NlOnboardingChecks.php`, and a full UI
  (`OnboardingDetail` + `Onboardings` index).
- **`Offboarding`** (same file) — shipped, lifecycle
  `aangekondigd → afronding_gepland → eindafrekening_gereed → afgerond`, with a
  `reason` enum that already contains `pensioen`.
- **Term of office / rooster van aftreden / reappointment — nothing.** Zero hits
  across `lib/Settings/`, `src/` and every spec. The nearest shapes
  (`Employee.endDate`, `EmploymentContract.endDate`, `OrgAssignment.endDate`)
  are effective dates, not governed terms.
- **Position/function — partial.** `Normfunctie` is a read-only HR21 job-grading
  catalogue; `OrgAssignment.role` is a **free-text string**. There is no
  first-class held post.
- **Identity:** humaniq's canonical person is `Employee`, with a nullable
  `nextcloudUserId` bridge. decidiq's is a `Person` + `Membership`. **This is the
  main impedance mismatch** and the part that needs a decision before any code
  moves.

## Proposed disposition

### 1. Onboarding / offboarding — adopt, do not port

humaniq's `Onboarding` / `Offboarding` are strictly more developed than
decidiq's trajecten. decidiq should **delete** `OnboardingTraject` and
`OffboardingTraject` and link out to humaniq where an app is installed alongside.

Two adaptations are needed on humaniq's side, and both are small:

- decidiq's trajecten target a **body + role** (`targetBody`, `targetRole`) — a
  council seat, not an employment. humaniq's `Onboarding` targets an `employeeId`
  only. It needs an optional *governance* target, or the concept splits.
- decidiq's `trigger` includes `council-turnover-batch` (a whole council installed
  at once). humaniq has no batch concept.

**Write in humaniq's dialect, not decidiq's.** humaniq's house style is
explicit: a flat schema with `configuration.x-openregister-lifecycle` and
boolean/date checklist fields *on the object*. Its onboarding design note
records that it deliberately rejected `OnboardingStep` side entities. decidiq's
`steps: array` would be pushed back on; it should be flattened.

### 2. Members are not employees — decide this first

A council member is not on the payroll. humaniq already has the precedent for
this: **`Stagiair`** is a deliberately non-`Employee` person type with its own
lifecycle, explicitly never payroll input.

Recommended: a `Bestuurder` / office-holder person type on the same precedent,
rather than forcing council members through `Employee` (which would put them in
payroll runs, loonaangifte and WKR reporting they do not belong in). This is the
single biggest open question in the handoff and humaniq should own the call.

### 3. Term of office — a genuine move, and it should be built on decidiq's shape

humaniq has no term concept. What it should adopt is **not** decidiq's
`TermijnRegeling` + `RoosterVanAftreden` + `RoosterRegel` triple, because
decidiq is itself deleting two of the three. It should adopt the *replacement*
shape from `configurable-types-domain-model`:

- term configuration (`termDurationMonths`, `maxConsecutiveTerms`,
  `reappointable`) lives **on the position**, not in a separate rules object;
- a **retirement schedule is a query**, not an entity — a union of membership
  end dates and position-hold end dates, filtered to a window;
- **a person can have two independent end dates** (member until A, chair until
  B), which `RoosterRegel`'s single `endTermDate` column could never express.

The equivalent in humaniq's model is `OrgAssignment.endDate` plus a held-post end
date — which requires promoting `OrgAssignment.role` from a free string to a
reference, and that is the same "position as a first-class object" gap noted
above. The two land together or not at all.

### 4. What decidiq does on its side

- Delete `OnboardingTraject`, `OffboardingTraject` (−2 schemas).
- Delete `RoosterVanAftreden`, `RoosterRegel`; replace with a derived view.
- Delete `TermijnRegeling`; absorbed into `PositionType`.
- Remove the `Onboarding`, `Offboarding` and `Retirement schedules` menu leaves,
  keeping the routes resolvable during the deprecation window.
- Where humaniq is installed, link out to it rather than re-implementing.

## Sequencing

humaniq's active change `humaniq-rule-compliance-enforcement` owns write-time
guard wiring for compliance-checked schemas, and both `Onboarding` and
`Offboarding` already defer their gate enforcement to it. Any handed-off traject
inherits that posture, so this handoff should land **after** that change, not
beside it.

## Open questions for humaniq

1. Are office-holders `Employee`s, or a new non-payroll person type on the
   `Stagiair` precedent?
2. Does `Onboarding` gain an optional governance target, or does a governance
   installation become a distinct schema?
3. Is `OrgAssignment.role` promoted to a reference? Term tracking depends on it.
4. Who owns the batch (`council-turnover-batch`) concept — is there an HR
   analogue (a TUPE-style bulk transfer) that would share it?
