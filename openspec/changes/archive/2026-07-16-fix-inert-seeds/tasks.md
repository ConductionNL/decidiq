# Tasks: fix-inert-seeds

- [x] 1. Verify against OpenRegister `origin/development` (read-only) which seed mechanism is honoured today
- [x] 2. Confirm `x-openregister-seeds` AND `x-openregister-seed` are both absent from `ANNOTATION_VOCABULARY`
- [x] 3. Confirm `importSeedData()` is actually called (ImportHandler.php:2318) — not itself an orphan
- [x] 4. Confirm the deployed OR in the dev container matches origin/development on the seed path
- [x] 5. Relocate 20 seed blocks in `decidesk_register.json` to `x-openregister.seedData.objects`
- [x] 6. Relocate 1 seed block in `register.d/43-process-config-v1.json`
- [x] 7. Verify all seedData keys are schema **slugs**, not PascalCase component keys
- [x] 8. Verify `deepMergeConfig()` carries a fragment's `x-openregister.seedData` into the merged config
- [x] 9. Bump `info.version` 0.5.1 → 0.6.0 so the import version gate does not skip the corrected config
- [x] 10. Live-verify: `process-template` 0 → 5 objects via the normal `occ app:enable` upgrade path
- [x] 11. Confirm the log shows `Importing seed data objects` and no `Seed-data import failed`
- [x] 12. File the two OpenRegister defects found while proving this (unguarded register find; early-return skips seedData)
- [x] 13. Validate both register files parse as JSON after the edit
