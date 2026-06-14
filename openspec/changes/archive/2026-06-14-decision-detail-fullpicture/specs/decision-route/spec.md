# Spec delta: Decision Route — route timeline view

This file contains delta specifications for the `decision-detail-fullpicture` change against the `decision-route` capability (introduced by `decision-route-and-stages` (C4) and extended by `decision-methods` (C5)). It adds the read-only timeline view of a Decision's route on the detail page. It does NOT re-model the route; it renders existing C4/C5 fields.

---

## ADDED Requirements

### Requirement: Route timeline view on the decision detail

The decision detail SHALL provide a route tab that renders the Decision's `route` (its ordered `DecisionStage` objects, read via the `route` relation) as a timeline. For each stage the view SHALL show, ordered by `sequence`: the sequence number, `label`, the decision-maker name (resolved from `assignedBody` or `assignedPerson` per `decisionMakerType`), `stageType`, `method`, `status`, `outcome`, and `decidedAt`. The stage whose id equals the Decision's `currentStage` SHALL be visually highlighted, and route progress SHALL be shown as `decidedStageCount` of `stageCount` (from the C4 declarative fields). When the Decision has no configured route (`stageCount` = 0) the view SHALL render an empty state and SHALL NOT error. The view SHALL be read-only — resolving a stage is owned by `decision-methods`, not this view. The view SHALL surface what is still to be done by indicating the current stage and the count of open action items.

#### Scenario: Routed decision shows its timeline with the current stage highlighted

- **GIVEN** a decision with a three-stage route (College decided, Auditcommissie decided, Gemeenteraad active) where `currentStage` is the Gemeenteraad stage
- **WHEN** the user opens the route tab
- **THEN** the three stages are listed in sequence with their decision-maker, status, and outcome, the Gemeenteraad stage is highlighted as current, and route progress reads "2 of 3 stages decided"

#### Scenario: Stageless decision shows an empty state

- **GIVEN** a decision with no configured route (`stageCount` = 0)
- **WHEN** the user opens the route tab
- **THEN** an empty state ("No staged route configured") is shown and no error occurs

#### Scenario: Effective-status banner above the timeline

- **GIVEN** a decision whose `effectiveStatus` is `superseded`
- **WHEN** the user opens the route tab
- **THEN** a banner naming the superseding decision is shown above the timeline with navigation to it, while the lifecycle badge remains visible
