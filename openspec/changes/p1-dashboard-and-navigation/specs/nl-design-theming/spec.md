## ADDED Requirements

### Requirement: REQ-THM-001 All colours use Nextcloud CSS custom properties
Every component in Decidesk SHALL reference only Nextcloud CSS variables (e.g. `var(--color-primary)`, `var(--color-main-background)`, `var(--color-text-maxcontrast)`). No hexadecimal, RGB, or named colour values SHALL appear in component `<style>` blocks. No `--nldesign-*` tokens SHALL be referenced directly in components.

#### Scenario: Colour audit passes
- **WHEN** a CSS linting rule scans all `*.vue` and `*.css` files
- **THEN** no hardcoded colour values or `--nldesign-*` references are found outside `nl-design.css`

---

### Requirement: REQ-THM-002 NL Design System token mapping file
A single `src/assets/nl-design.css` file SHALL map government NL Design System token names to their Nextcloud CSS variable equivalents. This file is the ONLY place where `--nldesign-*` tokens may appear.

#### Scenario: Token file exists and maps primary colour
- **WHEN** `src/assets/nl-design.css` is loaded
- **THEN** it contains at minimum `--nldesign-color-brand-1` mapped to `var(--color-primary)`

---

### Requirement: REQ-THM-003 Theme survives Nextcloud dark mode toggle
All UI elements SHALL remain readable and correctly themed when the Nextcloud user switches between light and dark mode, because Nextcloud CSS variables automatically switch.

#### Scenario: Dark mode active
- **WHEN** a user enables dark mode in Nextcloud personal settings
- **THEN** all Decidesk pages adapt their background and text colours without custom JavaScript
