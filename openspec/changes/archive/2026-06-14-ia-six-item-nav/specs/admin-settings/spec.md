# Admin Settings Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- ia-six-item-nav

## Purpose

Adds the `organisatie-modus` tenant-mode setting (C7) that drives ADR-004 Rule 1 /
ADR-006 label adaptation. The mode selects which label map applies to the
navigation (and, in follow-up work, to in-page nouns), so the same six-item
structure relabels per audience (gov / corp / assoc / ops / citizen) without
branching the nav or duplicating entities.

## ADDED Requirements

### Requirement: REQ-ADM-MODE-001 Organisatie-modus tenant setting
The system MUST expose an `organisatie_modus` setting whose value is one of
`gov`, `corp`, `assoc`, `ops`, or `citizen`, defaulting to `gov`. The setting MUST
be persisted via `IAppConfig` through `SettingsService` (added to
`SettingsService::CONFIG_KEYS`), returned by `getSettings()` with the `gov`
default when unset, and writable via `updateSettings()`. The setting MUST be
selectable in the Decidesk admin settings UI. The value MUST drive the
navigation label map (per the app-navigation capability) and MUST NOT alter the
entity/schema set or the navigation structure (ADR-006: mode adaptation, never
parallel entities).

#### Scenario: Default mode is gov

@e2e openspec/specs/admin-settings/spec.md#default-mode-is-gov

- GIVEN a fresh install where `organisatie_modus` has never been set
- WHEN `getSettings()` is called
- THEN it returns `organisatie_modus = "gov"`

#### Scenario: Admin selects a tenant mode

@e2e openspec/specs/admin-settings/spec.md#admin-selects-a-tenant-mode

- GIVEN an administrator in the Decidesk admin settings
- WHEN they set the organisation mode to "corp"
- THEN `updateSettings()` persists `organisatie_modus = "corp"` via `IAppConfig`
- AND `getSettings()` subsequently returns `"corp"`
- AND the navigation Bodies item relabels to "Board" on next render

#### Scenario: Mode does not create parallel entities

@e2e openspec/specs/admin-settings/spec.md#mode-does-not-create-parallel-entities

- GIVEN any `organisatie_modus` value
- WHEN the app boots with that mode
- THEN the register schema set is unchanged and the navigation structure stays the six-item IA
- AND only displayed labels differ

## Non-Functional Requirements

- **Performance:** Reading `organisatie_modus` MUST reuse the existing
  `getSettings()` payload already fetched on app init; it MUST NOT add a request.
- **Accessibility:** The mode selector in the admin UI MUST use an
  `NcSelect`/`NcSelectField` with an `inputLabel` (WCAG 2.1 AA, ADR-004 hard rule).
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the
  setting label and the mode option names.

## Acceptance Criteria

- [ ] `organisatie_modus` is in `SettingsService::CONFIG_KEYS`
- [ ] `getSettings()` returns `organisatie_modus` defaulting to `gov`
- [ ] `updateSettings()` persists `organisatie_modus`
- [ ] The admin settings UI offers a mode selector (gov/corp/assoc/ops/citizen)
- [ ] Changing the mode relabels the Bodies navigation item

## Notes

- ADR-004 Rule 1 (one shell, role/mode-aware labels); ADR-006 mechanism 1 (label
  adaptation). The setting is a UI-hint / cosmetic selector — it carries no secret
  and drives no authorization decision.
- DEFERRED: C7 wires relabeling for the Bodies item; broader per-item relabeling
  is scaffolded in `src/config/modeLabels.js` for a follow-up.
