# leaves-linked-card Specification

## Purpose
The decisions and approval-chain leaves report a summary (count, noun, rows, actions) to the linked card another app draws, instead of drawing their own card there. Created by the change leaves-linked-card (archived 2026-10-30).

## Requirements

### Requirement: The decision leaves report a summary to the linked card

Given `onSummary`, the decisions and approval-chain leaves SHALL draw nothing and SHALL report `{ count, noun, rows,
actions }` once read, and nothing while loading or after a failed read.

#### Scenario: a case with two decisions

- **GIVEN** a case with two linked decisions
- **THEN** the decisions leaf reports count 2 and a row per decision linking to it in decidiq
