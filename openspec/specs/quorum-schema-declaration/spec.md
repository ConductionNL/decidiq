---
status: done
---

# quorum-schema-declaration Specification

## Purpose
Declares the OpenRegister aggregations and calculations on the Meeting schema that compute meeting quorum directly from related Participant objects. It defines totalParticipantCount and presentParticipantCount aggregations and derives a guarded quorumPercentage and a quorumMet boolean, so quorum status is computed by the data model rather than ad-hoc application code.

## Requirements

### Requirement: REQ-QSC-1 — Meeting schema declares totalParticipantCount aggregation

The Meeting schema in `lib/Settings/decidesk_register.json` MUST declare an `x-openregister-aggregations.totalParticipantCount` entry that counts Participant objects related to the Meeting via `governanceBody == @self.governanceBody`.

#### Scenario: totalParticipantCount aggregation present
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-aggregations.totalParticipantCount`
- **THEN** the entry MUST exist with `metric: "count"`, `schema: "Participant"`, and a filter referencing `@self.governanceBody`

### Requirement: REQ-QSC-2 — Meeting schema declares presentParticipantCount aggregation

The Meeting schema MUST declare an `x-openregister-aggregations.presentParticipantCount` entry that counts Participant objects related to the Meeting where `attendanceStatus == "present"`.

#### Scenario: presentParticipantCount aggregation present
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-aggregations.presentParticipantCount`
- **THEN** the entry MUST exist with `metric: "count"`, `schema: "Participant"`, and a filter combining `@self.governanceBody` and `attendanceStatus == "present"`

### Requirement: REQ-QSC-3 — Meeting schema declares quorumPercentage calculation

The Meeting schema MUST declare an `x-openregister-calculations.quorumPercentage` entry of type number that evaluates to `(presentParticipantCount / totalParticipantCount) * 100` when `totalParticipantCount > 0`, and `0` otherwise.

#### Scenario: quorumPercentage calculation present and guarded
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-calculations.quorumPercentage`
- **THEN** the calculation MUST declare `type: "number"` and guard against zero-division by emitting `0` when `totalParticipantCount` is `0`

### Requirement: REQ-QSC-4 — Meeting schema declares quorumMet calculation

The Meeting schema MUST declare an `x-openregister-calculations.quorumMet` entry of type boolean that evaluates to `true` when `quorumRequired === null OR presentParticipantCount >= quorumRequired`, and `false` otherwise.

#### Scenario: quorumMet boolean present
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-calculations.quorumMet`
- **THEN** the calculation MUST declare `type: "boolean"` and reflect the documented expression
