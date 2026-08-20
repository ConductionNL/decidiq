# citizen-participation Specification (delta)

## ADDED Requirements

### Requirement: Consultation family discriminator boundary (ADR-006)

`PublicConsultation` SHALL remain the sole ADR-006-discriminated concept for the public/market-consultation family (`consultationType`: `citizen-participation | market-consultation | tender | idea-box | participatory-budget`). The system SHALL NOT introduce a new `consultationType` value, or any other mechanism, that folds `MemberConsultation` (`member-consultation` / `member-consultation-response`, the internal non-binding achterbanraadpleging) or `ConsultationRequest` (`consultation-request`, the formal WOR art. 25/27 traject) into the `PublicConsultation` schema, UNLESS a future evaluation re-measures field overlap and the authorization/schema.org/structural signals documented in `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md` (addendum) and finds the earlier evidence no longer holds. `MemberConsultation` and `ConsultationRequest` are each independently exempted from ADR-006's "one schema per concept" rule as genuinely distinct concepts, evidenced by: field-name overlap with `PublicConsultation` of 13% and 10% of their own properties respectively (no pairing exceeding that in any direction), the absence of any public-group `authorization.read` block on either schema (vs. `PublicConsultation`'s WOO/DIWOO public-publication rule), distinct `x-schema-org` types (`AskAction` and `Action` vs. `PublicConsultation`'s `Event`), and — for `ConsultationRequest` specifically — a live structural reference (`constituencyConsultation`) to `MemberConsultation` as a composed step, which presupposes the two remain separate objects.

#### Scenario: Spec review cites the addendum instead of re-deriving the boundary

- **GIVEN** a future change proposal suggests folding `MemberConsultation` or `ConsultationRequest` into `PublicConsultation` on the basis of shared "consultation" naming
- **WHEN** the proposal is reviewed against this requirement
- **THEN** the reviewer points to the ADR-006 addendum's evidence table and requires the proposal to either accept the existing exemption or present new measured evidence that overturns it — a naming-similarity argument alone is insufficient

#### Scenario: PublicConsultation absorbs a genuinely public consultation variant

- **GIVEN** a future consultation variant that DOES share `PublicConsultation`'s public-group authorization model and core "ask citizens, collect reactions, publish results" shape
- **WHEN** it is proposed as a new capability
- **THEN** it is added as a new `consultationType` enum value with progressive-disclosure fields on the existing `PublicConsultation` schema, per ADR-006 — never as a new parallel schema

#### Scenario: MemberConsultation and ConsultationRequest keep independent authorization postures

- **GIVEN** `MemberConsultation` and `ConsultationRequest` remain separate schemas per this requirement
- **WHEN** their OpenRegister `authorization` blocks are inspected
- **THEN** neither declares a `public` group read rule — both remain internal/staff-only by the standard OR RBAC default, unlike `PublicConsultation`
