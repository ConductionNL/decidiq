---
status: done
---

# accessibility-baseline Specification

## Purpose
Establishes a WCAG 2.1 accessibility baseline across every Decidesk page. Each page carries a single H1, a skip-navigation link, standard ARIA landmarks, and fully keyboard-operable interactive elements, while all user-visible strings are routed through translation wrappers.

## Requirements

### Requirement: REQ-ACC-001 Each page has a single H1 heading
Every page view (Dashboard, list views, detail views, settings) SHALL contain exactly one `<h1>` element that describes the page content. This satisfies WCAG 2.1 Success Criterion 1.3.1 (Info and Relationships) and the "Accessibility Optimization with H1 Structure" feature (demand: 224).

#### Scenario: Dashboard page H1
- **WHEN** the Dashboard page is rendered
- **THEN** exactly one `<h1>` element is present with content such as "Dashboard" or the app name

#### Scenario: List page H1
- **WHEN** a list view (e.g. Meetings) is rendered
- **THEN** exactly one `<h1>` element is present with the entity type name (e.g. "Vergaderingen")

---

### Requirement: REQ-ACC-002 Skip-navigation link before main content
Every page SHALL include a visually hidden "Sla navigatie over" skip link as the first focusable element. On focus, the link becomes visible. Activating it moves keyboard focus to the `<main>` content area.

#### Scenario: Skip link receives focus
- **WHEN** a keyboard user presses Tab as the first interaction on a page
- **THEN** the skip-navigation link becomes visible and receives focus

#### Scenario: Skip link activates
- **WHEN** the skip link is focused and the user presses Enter
- **THEN** focus moves to the main content area, bypassing the navigation menu

---

### Requirement: REQ-ACC-003 ARIA landmarks on all pages
Every page SHALL include the following ARIA landmark roles: `banner` (app header), `navigation` (MainMenu), `main` (NcAppContent), and `contentinfo` (footer if present). These SHALL be provided by the Nextcloud/conduction component wrappers and SHALL NOT require custom ARIA attributes in app components unless the wrappers are absent.

#### Scenario: Landmark roles present
- **WHEN** any Decidesk page is rendered
- **THEN** a screen reader can navigate by landmarks to `navigation`, `main`, and `banner`

---

### Requirement: REQ-ACC-004 Interactive elements are keyboard focusable and operable
All buttons, links, form fields, and navigation items SHALL be reachable by Tab and operable by keyboard (Enter/Space for buttons, Enter for links). Focus indicators SHALL be visible per WCAG 2.1 SC 2.4.7.

#### Scenario: Navigation items keyboard accessible
- **WHEN** a keyboard user tabs through the MainMenu
- **THEN** each navigation item receives a visible focus ring and can be activated with Enter

#### Scenario: Dashboard tiles keyboard accessible
- **WHEN** a keyboard user focuses a tile widget
- **THEN** pressing Enter navigates to the linked list view

---

### Requirement: REQ-ACC-005 All user-visible strings are translated
Every string displayed in the UI SHALL be wrapped in `t(appName, '...')`. No hardcoded Dutch or English strings SHALL appear in template markup.

#### Scenario: Translation wrapper present
- **WHEN** a code review or linting pass scans Vue templates
- **THEN** no bare string literals appear outside of `t()` calls in user-facing text nodes
