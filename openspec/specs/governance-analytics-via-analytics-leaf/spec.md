---
status: done
---

# governance-analytics-via-analytics-leaf Specification

## Purpose
Renders engagement, action-item, and voting-behaviour dashboards through the Nextcloud Analytics integration leaf over OpenRegister object data, surfaced as a registry tab and widget instead of app-local chart components. Generic aggregations (counts, sums, completion and overdue rates, group-bys) are computed by the leaf or schema-declarative aggregations, while governance-specific metrics such as the engagement-score formula and quorum-weighted voting statistics stay as in-app calculations fed to the leaf as values. The dashboard tab degrades gracefully when the Analytics app is absent, and underlying metric capture is unchanged.

## Requirements

### Requirement: REQ-AN-LEAF-001 Dashboards are rendered by the Analytics leaf
The system SHALL render engagement, action-item, and voting-behaviour dashboards through the ADR-019 analytics integration leaf over OpenRegister object data, surfaced as a registry tab + widget. The system SHALL NOT render these dashboards through app-local chart components that duplicate the analytics leaf.

#### Scenario: Engagement dashboard via the analytics leaf
- **GIVEN** a meeting with recorded engagement records (speeches, questions, topics)
- **AND** the Nextcloud Analytics app is installed and the analytics leaf is registered
- **WHEN** a participant opens the engagement dashboard tab
- **THEN** the registry-driven analytics leaf renders the charts over the OR engagement data
- **THEN** no app-local chart component is used

#### Scenario: Analytics app not installed degrades gracefully
- **GIVEN** the Nextcloud Analytics app is not installed
- **WHEN** a participant opens a page that would show an analytics dashboard
- **THEN** the dashboard tab is hidden and the page renders normally
- **THEN** the underlying engagement/vote/action-item data remains intact in OpenRegister

### Requirement: REQ-AN-LEAF-002 Generic aggregations move; governance-specific calculations stay in-app
The system SHALL compute generic aggregations (counts, sums, completion rate, overdue counts, group-bys) via the analytics leaf or schema-declarative aggregations (ADR-031), and SHALL retain governance-specific derived metrics that the leaf cannot compute (engagement-score formula, quorum/weight-aware voting-behaviour statistics) as in-app calculations exposed to the leaf as values.

#### Scenario: Completion rate computed by the leaf
- **WHEN** the action-item completion rate is needed
- **THEN** it is computed by the analytics leaf or a schema aggregation over the VTODO/ActionItem data
- **THEN** no in-app service renders a chart for it

#### Scenario: Engagement score retained in-app and fed to the leaf
- **GIVEN** the engagement score is a governance-specific formula the generic leaf cannot derive from raw data
- **WHEN** the engagement dashboard is rendered
- **THEN** the score is computed in-app and exposed to the analytics leaf as a value/field
- **THEN** the leaf charts the value without re-implementing the formula

### Requirement: REQ-AN-LEAF-003 Metric capture is unaffected
The system SHALL continue to capture engagement records, action-item status, and votes exactly as before; this change SHALL only relocate dashboard rendering and generic aggregation.

#### Scenario: Capture continues unchanged
- **WHEN** a participant's speech is recorded during a meeting
- **THEN** the engagement record is created in OpenRegister as before
- **THEN** the only behavioural change is that the dashboard over that data is now the analytics leaf
