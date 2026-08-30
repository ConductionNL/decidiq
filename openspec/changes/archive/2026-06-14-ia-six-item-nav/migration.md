# Migration: ia-six-item-nav

> This change has **no database migration**. Decidesk is a thin client and owns
> no DB tables (data lives in OpenRegister). The "migration" here is the menu
> restructure (config) and the introduction of the `organisatie_modus` app-config
> default. No `lib/Migration/Version*.php` class is added.

## Current State

- `src/manifest.json` `menu` has **eight** main working items: Dashboard,
  Minutes, Decisions, Action items, Motions, Meetings, Workspaces, Engagement —
  plus Documentation (footer), Settings (settings section), Features-roadmap
  (footer).
- `GovernanceBodies` is a page (route `/governance-bodies`) but is NOT in the menu.
- `App.vue::translateForApp(key)` is a thin `ncT('decidesk', key)` wrapper; no
  mode-aware label resolution exists.
- `SettingsService::CONFIG_KEYS` has no `organisatie_modus` key; there is no
  tenant-mode setting.

## Target State

- `src/manifest.json` `menu` has the six canonical working items (Meetings,
  Decisions, Action items, Motions, Bodies, Beheer) plus a Dashboard landing item,
  plus the existing footer/settings entries. Minutes, Workspaces, Engagement are
  removed from the menu (routes retained).
- `GovernanceBodies` is surfaced as the **Bodies** menu item.
- `App.vue::translateForApp` resolves the canonical label through
  `src/config/modeLabels.js` for the active `organisatie_modus` before calling
  `t()`; the Bodies item relabels per mode.
- `SettingsService::CONFIG_KEYS` includes `organisatie_modus`; `getSettings()`
  returns it defaulting to `gov`; `updateSettings()` persists it; the admin UI
  offers a mode selector.

## Migration Class

```
None. No lib/Migration/VersionXXXXXXXXXX.php is added.
Key operations are config + frontend only:
- Rewrite src/manifest.json "menu"
- Add src/config/modeLabels.js
- Update src/App.vue translateForApp
- Add "organisatie_modus" to SettingsService::CONFIG_KEYS (+ gov default in getSettings())
```

## Migration Steps

1. Rewrite the `menu` array in `src/manifest.json` to the six working items +
   Dashboard landing (+ existing footer/settings entries); promote
   GovernanceBodies as the Bodies item; remove Minutes / Workspaces / Engagement
   menu rows.
2. Add `src/config/modeLabels.js` with the mode-keyed label map and `DEFAULT_MODE`.
3. Update `src/App.vue::translateForApp` to resolve via the label map using the
   active mode read from the settings store.
4. Add `organisatie_modus` to `SettingsService::CONFIG_KEYS` and return it with a
   `gov` default from `getSettings()`.
5. Surface the mode selector in the admin settings UI and expose
   `organisatie_modus` on the settings store.
6. Rebuild the frontend bundle.

## Data Impact

No records are transformed or deleted. The `organisatie_modus` app-config key is
read with a `gov` default, so existing installs need no backfill and see the same
governance labels until an admin opts into another mode. Demoted pages keep their
objects and routes — only menu rows are removed.

## Rollback Procedure

Revert the commit: restore the eight-item `menu` in `src/manifest.json`, restore
the thin `translateForApp`, delete `src/config/modeLabels.js`, remove
`organisatie_modus` from `SettingsService::CONFIG_KEYS`, and rebuild. Any
`organisatie_modus` value already written to app-config is inert once the code no
longer reads it (optional cleanup:
`occ config:app:delete decidesk organisatie_modus`).

## Validation

- `python3 -c "import json; json.load(open('src/manifest.json'))"` parses clean and
  the `menu` main section lists exactly the six working items + Dashboard.
- Frontend builds without error; the nav renders six working items + Dashboard,
  no Minutes/Workspaces/Engagement rows, and a Bodies item.
- `occ config:app:get decidesk organisatie_modus` (after no admin action) is empty
  while `getSettings()` reports `gov`; after setting `corp` via the UI it reports
  `corp` and the Bodies label renders "Board".
- Deep links `/minutes`, `/workspaces`, `/engagement` still resolve to their pages.
