---
status: done
---

# manifest-version-bump Specification

## Purpose
Bumps the decidiq app manifest version in src/manifest.json from 0.3.0 to 0.4.0 per the content-versioning rules, changing only the top-level version field and leaving all other manifest fields untouched.

## Requirements

### Requirement: REQ-MVB-1 — manifest.version reflects 0.4.0

The decidiq app manifest at `src/manifest.json` MUST declare `version: "0.4.0"` (incremented from `0.3.0`) per ADR-024 §7 content-versioning rules. No other manifest fields change in this spec.

#### Scenario: Manifest version is bumped
- **GIVEN** the post-change `src/manifest.json`
- **WHEN** parsing the JSON document
- **THEN** the top-level `version` field MUST equal `"0.4.0"`

#### Scenario: No unrelated manifest fields change
- **GIVEN** a diff between pre-change and post-change `src/manifest.json`
- **WHEN** inspecting changed keys
- **THEN** only the `version` field MUST be modified
