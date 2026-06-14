# app-foundation Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- ia-six-item-nav

## Purpose

Updates the foundation-level description of the app navigation menu to match
ADR-004's six-item information architecture (C7). The left-side navigation no
longer enumerates the legacy item set; it lists the six canonical working items
plus a Dashboard landing item, with Bodies promoted into the menu and
Minutes/Workspaces/Engagement demoted.

## MODIFIED Requirements

### Requirement: App navigation via MainMenu
The app SHALL provide a left-side navigation menu following ADR-004's six-item
information architecture: a Dashboard landing item plus the six canonical working
items — Meetings, Decisions, Action items, Motions, Bodies (the GovernanceBodies
surface), and Beheer (the settings/admin door). The menu SHALL NOT include
Minutes, Workspaces, or Engagement as top-level items; those surfaces are demoted
(Minutes lives as a tab in MeetingDetail, Workspaces under Bodies, Engagement
under Beheer) while their routes remain reachable.

#### Scenario: Navigation renders the six-item IA
- WHEN the app is fully loaded
- THEN the MainMenu shows Dashboard (`/`), Meetings (`/meetings`), Decisions (`/decisions`), Action items (`/action-items`), Motions (`/motions`), and Bodies (`/governance-bodies`)
- AND Beheer (Settings) appears in the settings section
- AND Minutes, Workspaces, and Engagement are NOT shown as top-level menu items

#### Scenario: Active route is highlighted
- WHEN the user is on the Meetings list page
- THEN the Meetings navigation item is styled as active
