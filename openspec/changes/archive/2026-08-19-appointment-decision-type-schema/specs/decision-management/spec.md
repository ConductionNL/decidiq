# decision-management Specification

## ADDED Requirements

### Requirement: Appointment decision type carries folded nomination fields

`Decision` MUST expose a set of appointment-specific fields, revealed via
progressive disclosure (ADR-004 Rule 2) only when `decisionType = appointment`:
`targetBody` (reference to the `GovernanceBody` the appointment is for),
`targetPosts` (zero or more references to `Post`), `targetRole` (the Membership
role being appointed to), `candidates` (one or more structured candidates, each
carrying either a `person` reference or a free-text `externalName` for a
not-yet-registered candidate), and `nominatingParty` (the fractie/orgaan/persoon
that made the nomination). These fields replace the retired `Voordracht` schema's
`body`/`post`/`targetRole`/`kandidaten`/`nominatingParty` fields one-for-one
(ADR-005, ADR-006 — one schema per concept, discriminator over parallel entity).

#### Scenario: Appointment fields appear only for an appointment decision

- GIVEN the decision form
- WHEN `decisionType = appointment` is selected
- THEN `targetBody`, `targetPosts`, `targetRole`, `candidates`, and
  `nominatingParty` are revealed
- AND motion/amendment/resolution-specific fields stay hidden

#### Scenario: A non-appointment decision does not require appointment fields

- GIVEN a decision with `decisionType = motion`
- WHEN it is created without `targetBody` or `candidates`
- THEN it is accepted — these fields are appointment-only and are enforced at
  the form/spec layer, not in the JSON-schema `required[]`, matching the
  established per-type required-field pattern (motion/resolution)

#### Scenario: At least one candidate is expected before submitting an appointment

- GIVEN a `decisionType = appointment` decision being edited in `lifecycle = draft`
- WHEN `candidates` is empty
- THEN the form marks `candidates` as required before allowing the `propose`
  action — matching the established form-only enforcement for other per-type
  required fields (e.g. motion's `proposer`, resolution's `resolutionNumber`);
  no server-side JSON-schema or service-layer guard exists for this, exactly
  as none exists for the sibling per-type required fields

### Requirement: Appointment decisions reuse the Decision lifecycle, not a bespoke one

An appointment decision MUST use `Decision`'s existing declarative 7-state
lifecycle (`draft → proposed → deliberating → voting → decided → enacted →
archived`, plus terminal `withdrawn`) rather than the retired `Voordracht`
schema's bespoke 5-state lifecycle (`submitted → handled → appointed |
not-appointed`, `withdrawn`). This follows the same reuse decision the archived
`unify-decision-supertype` change made for motion/amendment/resolution (design
D2): the proven, already-declarative `x-openregister-lifecycle` block on
`Decision` is not duplicated per type.

#### Scenario: An appointment decision progresses through the shared lifecycle

- GIVEN a `decisionType = appointment` decision in `lifecycle = draft`
- WHEN it is proposed, deliberated, and voted on
- THEN it moves through `proposed → deliberating → voting` exactly as any other
  decision type does, using the single `x-openregister-lifecycle` block declared
  once on `Decision`

#### Scenario: Adoption is expressed the same way as any other decision type

- GIVEN a `decisionType = appointment` decision reaching `lifecycle = decided`
- WHEN the vote outcome is recorded
- THEN `outcome = adopted` or `outcome = rejected` is set exactly as for any
  other `decisionType`, subject to the existing terminal-completeness rule
  (`x-decidesk-terminal-completeness`)

### Requirement: Adopted appointments record their materialized Memberships

`Decision` MUST expose a nullable, server-set `appointedMemberships` field
(array of references to `Membership`) on the `decisionType = appointment`
folded field set. This field is declared here so the schema is complete in one
place; it is populated by the imperative Membership-materialization service
shipped in the dependent change `appointment-decision-type-membership`
(`depends_on` this change) — no service code ships in this change.

#### Scenario: The field exists and accepts no client writes before materialization ships

- GIVEN a freshly-adopted `decisionType = appointment` decision, before the
  dependent change ships
- WHEN the decision is inspected
- THEN `appointedMemberships` is present on the schema and empty/absent — no
  error, no orphaned reference

### Requirement: The Voordracht schema is retired in favor of decisionType=appointment

The standalone `Voordracht` schema (`lib/Settings/register.d/61-appointments-and-terms.json`)
MUST be removed. Its 3 demo seed objects MUST be re-authored as `Decision`
seeds with `decisionType = appointment`, mapping the retired lifecycle onto the
shared `Decision` lifecycle (`submitted→proposed`, `handled→deliberating`,
`appointed→decided` with `outcome=adopted`, `not-appointed→decided` with
`outcome=rejected`, `withdrawn→withdrawn`). `TermijnRegeling`,
`RoosterVanAftreden`, and `RoosterRegel` in the same register fragment are
**not** part of this requirement — they reference `Membership`, never
`Voordracht`, and are unaffected.

#### Scenario: Voordracht is absent from the register after this change

- GIVEN the decidesk register
- WHEN `components.schemas` in `register.d/61-appointments-and-terms.json` is
  inspected
- THEN `Voordracht` is absent and `TermijnRegeling`, `RoosterVanAftreden`,
  `RoosterRegel` are present, unchanged

#### Scenario: Every retired voordracht seed has a re-authored decision seed

- GIVEN a freshly installed register
- WHEN the `Decision` seed objects are inspected
- THEN 3 `decisionType = appointment` seeds exist, one per retired `voordracht`
  seed (`voordracht-auditcommissie-lid`, `voordracht-rvc-vanduin`,
  `voordracht-auditcommissie-vz`), each carrying the equivalent
  `targetBody`/`targetRole`/`candidates`/`nominatingParty` data and the mapped
  lifecycle/outcome

#### Scenario: The Voordrachten nav pages are removed, Rooster/Termijnregeling pages are untouched

- GIVEN `src/manifest.d/appointments-and-terms.json`
- WHEN the `menu` and `pages` arrays are inspected
- THEN the `Voordrachten` menu entry and the `Voordrachten`/`VoordrachtDetail`
  pages are absent
- AND `Roosters`, `RoosterDetail`, `Roosterregels`, `RoosterregelDetail`,
  `Termijnregelingen`, `TermijnRegelingDetail` are present, unchanged
