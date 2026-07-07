# Tasks: info-licence-eupl

## Implementation Tasks

### Task 1: Correct the info.xml licence to EUPL-1.2
- **spec_ref**: `openspec/changes/info-licence-eupl/specs/app-foundation/spec.md#requirement-app-store-licence-metadata-matches-the-repository-licence`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` WHEN read THEN its `<licence>` element is `EUPL-1.2`
  - GIVEN the repository licence sources (`LICENSE`, `REUSE.toml`, `publiccode.yml`, SPDX headers) WHEN compared THEN all agree on EUPL-1.2 with no `agpl` remaining
- [ ] Change `<licence>agpl</licence>` to `<licence>EUPL-1.2</licence>` (mirroring pipelinq at `min-version="28"`); do not change `min-version`/`max-version`.
- [ ] Verify no other occurrence of `agpl` remains in `appinfo/info.xml`.

### Task 2: Bump the app version for the metadata correction
- **spec_ref**: `openspec/changes/info-licence-eupl/specs/app-foundation/spec.md#requirement-app-store-licence-metadata-matches-the-repository-licence`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the corrected metadata WHEN the app is packaged THEN the `<version>` is bumped so the new metadata is picked up (immutable-cache-bust rule)
- [ ] Bump `<version>` per the repository's versioning convention.
