# Design: fix-inert-seeds

## Context
21 seed declarations across two register files used `x-openregister-seeds`, an out-of-vocabulary key.
OpenRegister's `Schema::setConfiguration()` keeps only keys in `ANNOTATION_VOCABULARY` and drops the
rest (Schema.php:1940), so the declarations never reached any engine.

## Seed Data
This change is entirely about seed data, so this section is the design.

### The mechanism OpenRegister honours today
Seeds are **not** a schema annotation. `ANNOTATION_VOCABULARY` (Schema.php:2094) lists lifecycle,
aggregations, calculations, references, aggregate-refs, notifications, widgets, relations,
processing-activity, processing, archival, object-source, quality, dedup, flows, survivorship, merge,
handoff, mcp, approval-chains — and no seed key in either spelling. Anything else is dropped.

Since OR's R07 change the drop is at least *observable*: `Schema` collects unknown keys and
`SchemaMapper` logs them via `consumeDroppedAnnotationKeys()` (SchemaMapper.php:694 even suggests
"Typo? See Schema::ANNOTATION_VOCABULARY"). That signal is a warning in the log, not a failure, which
is why 21 dropped keys survived review.

Seeds live in `ImportHandler` instead, in one of two shapes:

| Location | Shape | Read at |
|---|---|---|
| `x-openregister.seedData.objects` | map: schema **slug** → array of plain objects | ImportHandler.php:3812 |
| `components.objects` (or top-level `objects`) | flat list; each object carries `@self` | ImportHandler.php:2017 |

### Choice
`x-openregister.seedData.objects`. It is keyed by schema slug, which maps 1:1 onto the 21 per-schema
blocks we already had, so the relocation is mechanical and reviewable. The `components.objects` shape
would require authoring a correct `@self` (register + schema + slug) per object — 121 objects of
hand-written identity metadata, with de-duplication depending on getting it right.

### Slug discipline
`importSeedData()` looks schemas up **by slug** (`$this->schemasMap[$schemaSlug]`, else database).
Keys are therefore `process-template`, `governance-body`, `contact-detail` — not the PascalCase
schema keys used under `components.schemas`. A PascalCase key here would resolve to no schema and the
seed would be skipped: the same phantom in a new costume.

### Ordering / dependencies
Seeds are planted after registers and schemas are persisted, which is why `importSeedData()` runs at
the end of `import()`. Relations between seed objects are left as-is; this change relocates
declarations only and changes no object payload.

## ADR-031
ADR-031 governs the declarative `x-openregister-*` dialects: an app declares intent in its register
and OpenRegister's engines consume it — apps do not hand-roll imperative equivalents. This change is a
direct application: the seeds stay fully declarative and move to the location the engine reads, rather
than decidesk growing a bespoke "seed on boot" service to compensate. The lesson ADR-031 encodes —
that a declarative dialect is only real if some engine consumes that exact key — is precisely what
failed here, and the failure was invisible because the drop is silent by design.

## The version gate (why relocation alone is insufficient)
`importFromJson()` (ImportHandler.php:1573-1613) computes a definition hash and early-returns when
`version_compare($version, $storedVersion, '<=')` **and** the stored hash matches. That return is
above `importSeedData()` (line 2318), so on an existing install a corrected-but-same-version config is
never seeded.

decidesk's effective version is `info.version` + a fragment signature
(`SettingsService::loadConfiguration()`), e.g. `0.5.1+frag.02c67089`. Relying on the fragment hash
alone to defeat the gate is unsound: `version_compare()` on two `0.5.1+frag.<md5>` strings compares
the opaque hash segments, so whether the "new" config counts as newer depends on the md5 sorting —
which is a coin flip, not a guarantee. Bumping `info.version` to `0.6.0` makes the comparison
unambiguous.

## Alternatives considered
- **Add `x-openregister-seeds` to OR's vocabulary.** Rejected: it would make OR carry a third seed
  location for one app's typo, and the annotation still has no engine behind it — the key would be
  accepted and stored while remaining just as inert. That is the phantom class, not a fix for it.
- **Remove the 21 declarations as vestigial.** Rejected on evidence: they are wanted data (process
  templates, governance bodies, evaluation templates), and they demonstrably plant once relocated.
  Removal would have been the honest call only if nothing consumed them.
- **A decidesk-side seeding service.** Rejected: duplicates an engine OR already has, and violates
  ADR-031.
