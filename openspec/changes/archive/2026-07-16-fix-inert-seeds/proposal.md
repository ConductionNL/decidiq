---
kind: code
---

# Proposal: fix-inert-seeds

## Summary
Decidesk declared seed data with `x-openregister-seeds` (**plural**) — a key that does not exist in
OpenRegister's annotation vocabulary. `Schema::setConfiguration()` silently drops unknown
`x-openregister-*` keys, so all **21** seed declarations were inert: they were authored, reviewed and
shipped, but no seed object has ever planted. This change moves all 21 to the seed location
OpenRegister actually reads today, and bumps `info.version` so the corrected config is not skipped by
the import version gate.

## Motivation
This is the "declared ≠ consumed" phantom class: a declaration that looks authoritative in review but
addresses a key no engine reads. Nothing failed loudly — no exception, no red test — because
`setConfiguration()` drops the key by design.

Verified against OpenRegister `origin/development` (read-only) and against the **deployed** OR in the
dev container (they agree):

- `Schema::ANNOTATION_VOCABULARY` (lib/Db/Schema.php:2094) contains **neither** `x-openregister-seeds`
  **nor** the singular `x-openregister-seed`. Seeds are **not a schema-level annotation at all** —
  the singular form some apps still carry is equally inert.
- OpenRegister honours exactly two seed locations, both in `ImportHandler`:
  1. **`x-openregister.seedData.objects`** at the top level of the config document, keyed by **schema
     slug** — read by `importSeedData()` (ImportHandler.php:3812), which iterates
     `foreach ($seedData['objects'] as $schemaSlug => $objects)`.
  2. `components.objects` / top-level `objects` — a flat list where each object carries its own
     `@self` block (ImportHandler.php:2017).
- The importer is genuinely **wired, not orphaned**: `importSeedData()` is called from `import()` at
  ImportHandler.php:2318 with `configData: $data` (the full merged config).

We adopt location 1: it is keyed by schema slug, mapping 1:1 onto the 21 per-schema blocks decidesk
already had. Location 2 would require hand-authoring an `@self` block per seed object.

## Affected Projects
- [x] Project: `decidesk` — register configuration only. No PHP, no Vue, no schema shape changes.

## Scope
- `lib/Settings/decidesk_register.json` — 20 blocks relocated; `info.version` 0.5.1 → 0.6.0.
- `lib/Settings/register.d/43-process-config-v1.json` — 1 block relocated (5 process templates).

## Why the version bump is part of the fix, not housekeeping
`ImportHandler::importFromJson()` early-returns (line ~1601) when the computed version is `<=` the
stored `imported_config_decidesk_version` **and** the content hash is unchanged — and that return
happens **before** `importSeedData()` is ever reached. Relocating the seeds without bumping
`info.version` would therefore have produced a second inert fix: correct JSON that the importer never
reads on any existing install. Bumping to `0.6.0` makes `version_compare()` pass so the import runs.
This was not deduced — it was observed live (see Verification).

## Verification (live, not asserted)
On the dev instance, `process-template` (magic table `oc_openregister_table_18_1200`) held **0**
objects. After deploying this change and running the ordinary `occ app:enable decidesk` upgrade path —
no manual key deletion, no forced import — it holds **5**:

| _slug | name | context |
|---|---|---|
| association-alv | Association ALV | association |
| association-board | Association Board | association |
| corporate-board | Corporate Board (BV) | corporate |
| municipal-council | Municipal Council | legislative |
| operational-team | Operational Team | operations |

The log shows `[ImportHandler] SeedData will be imported into register` and
`[ImportHandler] Importing seed data objects`, with no `Seed-data import failed` line. These 5 came
from the **fragment** file, which additionally proves `SettingsService::deepMergeConfig()` carries a
fragment's `x-openregister.seedData` into the merged config — a merge that only unioned `components`
would have silently re-created the phantom.

## Upstream defects found while proving this (filed, not worked around here)
Two OpenRegister bugs were found by live-verifying rather than trusting the code read. Neither is
decidesk's to fix; both are filed upstream:
1. `importSeedData()` resolves its target register as `$configuration->getRegisters()[0]` and calls
   `registerMapper->find()` **unguarded**. A stale id in that list throws, the outer catch swallows it
   as `Seed-data import failed`, and **every** seed silently fails — even when a valid register sits
   later in the same list.
2. `importFromJson()`'s early-return skips `importSeedData()` entirely, contradicting the comment at
   ImportHandler.php:3029 that promises the version-equal path still "checks seedData".

## Out of Scope
- Fixing the two OpenRegister defects above (upstream).
- The other `x-openregister-*` annotations, which were checked and are all in-vocabulary.
