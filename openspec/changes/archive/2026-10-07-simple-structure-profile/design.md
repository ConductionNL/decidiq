# Design: simple-structure-profile

## How it works

1. The manifest (`src/manifest.json` and `src/manifest.d/*.json`) stays the single source of pages and menu entries.
2. Two layout files sit next to it. `src/menu-layout.json` is the full structure and is not touched. `src/menu-layout.simple.json` is the simple one.
3. The app setting `menu_structure` holds `simple` or `full`. Unset, empty or mistyped reads as `simple`.
4. `DashboardController::renderIndex()` provides it as initial state. `src/main.js` reads it with `loadState` before it builds a route.
5. `src/utils/structureProfile.js#buildProfiledManifest` hands the chosen file to the library's `buildManifest`.

The mechanism is the one the dossiq pilot built (ConductionNL/dossiq#3265). `structureProfile.js` is the same file, apart from how it reports a skipped overlay.

## What a profile file holds

Standard keys, passed to `buildManifest` unchanged: `removals`, `settingsSection`. The simple file has no `relocations` key at all. The library's relocation step ends by dropping every entry with no route, href, action or children, and a caption is such an entry.

Profile keys, applied by `structureProfile.js`:

- `menu`: entries merged before the manifest's own menu. `buildManifest` merges by id and the first definition of a key wins. `{ "id": "Meetings", "order": 24 }` keeps the manifest's label, icon and route. An id the manifest does not know (the three captions, `Proposals`) is added as written.
- `pages`: overlays by page id. This change uses `configAppend` only, to add header links. An overlay never adds or removes a page.

## Decisions

### D1. Voorstellen opens the Motions page

Two menu entries on the same route both show as active, because the navigation decides that by route name. So Voorstellen cannot be a filter on the Decisions page while Besluiten opens that same page. The Motions page exists, has its own route (`/motions`) and lists decisions that are proposals by type. It is the nearest real page. The full structure reaches it through a quick filter on Decisions and keeps doing so.

### D2. Registers gets a page

The manifest's `Registers` entry has a label and no route. In the full structure it holds four children. In a flat menu it needs something to open. `RegistersHub` is a dashboard page in a new fragment (`src/manifest.d/registers-hub.json`) with eleven `stat` tiles, each a count of one schema and a link to that register's list. The page exists in both structures, because a profile never adds a page. The full menu does not link to it.

The eleven tiles also carry the entries the full structure nests under Organisation (gifts, other positions, proxy authorizations, onboarding, offboarding), under Meetings (audit statements) and under Tasks & Commitments (goals). The design moves the first group under Registers by name. The other two had no place in the design and are registers by nature.

### D3. Links are header actions on the list pages

An index page takes `headerActions` with `handler: "navigate"` and a `route`. Voorstellen, Vergaderingen and Organen en leden gain theirs through `configAppend`, so the full structure's pages are unchanged.

### D4. Labels and the organisation mode

`src/App.vue` rewords a label per organisation mode before translating it (`src/config/modeLabels.js`). Three labels are chosen around that:

- the caption reads `Your organisation` (Dutch: Organisatie), because `Organisation` is the key the mode map turns into Bodies, Board or Teams;
- `Bodies and members` has rows of its own in the mode map: Board and members for a company, Teams and members for a team;
- the first caption reads `Home` (Dutch: Start), because `Start` already translates as the verb on a button.

### D5. The setting

`lib/Service/Settings/MenuStructure.php` holds the key, the two words and `normalise()`. The key is on `SettingsService::CONFIG_KEYS`, or the settings write would answer success and store nothing. `saveMenuStructure()` reads the stored value back from the response for the same reason. The admin page shows two radios in a section named Menu structure.

### D6. Tests

- `tests/vitest/structureProfile.spec.js` builds both structures with the library's real `buildManifest`: the full one equals what it was, the simple menu is the eight entries in order, nothing is lost, the page lists are equal, the built simple manifest passes the library's validator.
- `tests/Unit/Service/Settings/MenuStructureTest.php`: the default, the words, the write, and that PHP and JavaScript spell them the same.
- `tests/e2e/simple-structure-menu.spec.ts`: the menu in a browser. The CI instance is seeded on `full`.
