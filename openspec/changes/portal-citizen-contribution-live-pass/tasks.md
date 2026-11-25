# Tasks: portal-citizen-contribution-live-pass

## Implementation Tasks

### Task 1: The end-to-end run
- **spec_ref**: `openspec/changes/portal-citizen-contribution-live-pass/specs/portal-citizen-create-actions/spec.md#requirement-a-residents-contribution-reaches-the-griffie-and-comes-back-on-mijn-inspraak-req-dkpca-005`
- **files**: `tests/e2e/portal-citizen-contribution.spec.ts` (new; needs portaliq enabled on the test instance), `tests/e2e/global-setup.ts` (seed one open and one closed consultation)
- **acceptance_criteria**:
  - GIVEN both apps enabled WHEN a resident reacts on the portal THEN the reaction appears pending on the consultation's moderation list and on Mijn inspraak
  - GIVEN the griffie approves it WHEN the resident reloads Mijn inspraak THEN it shows as approved
  - GIVEN a closed consultation WHEN the resident reacts THEN nothing is created
- Written, not run (21 Nov): `tests/e2e/portal-citizen-contribution.spec.ts`, both scenarios tagged. The resident acts through portaliq's portal API (`/portal/api/collections/decidiq/consultation-reaction`) with a citizen session from portaliq's dev-login; the griffie lists and approves as the administrator; Mijn inspraak is read through the resident's own collection. The spec creates its open and closed consultation itself instead of `global-setup.ts`, so a run leaves nothing behind. Left: running it on an instance with portaliq and `debug: true`.
- Run live (25 Nov 2026, sandbox Nextcloud 34.0.6 + PostgreSQL 16, OpenRegister main, decidiq and portaliq development): the first scenario is green (the reaction reaches decidiq pending, Mijn inspraak lists it pending, the griffie approves, Mijn inspraak shows approved). The second: the reaction on a closed consultation is refused and nothing is created, but portaliq answers 502 `write_failed` for the open-parent guard's refusal, so the site cannot say that reacting is closed (Task 2). Two spec fixes the run needed: the consultation carries a past `publicationDate` (a resident only reacts on a published consultation, and the guard reads it as the public), and the instance setup is portaliq's own ci-seed (`dev_login_enabled = yes`, an OpenRegister organisation `dev-org`, the administrator working in it; a portal session reads and writes as its organisation only), not `debug: true`.
- [x] Implement
- [x] Test

### Task 2: Fix what the run finds and drop the e2e exclusion
- **spec_ref**: `openspec/changes/portal-citizen-contribution-live-pass/specs/portal-citizen-create-actions/spec.md#requirement-a-residents-contribution-reaches-the-griffie-and-comes-back-on-mijn-inspraak-req-dkpca-005`
- **files**: `lib/Portal/PortalContributionProvider.php`, `lib/Listener/PortalCreateOpenParentGuardListener.php`, `openspec/specs/portal-citizen-create-actions/spec.md` (replace the `@e2e exclude` on REQ-DKPCA-001 and REQ-DKPCA-002 with the new spec file), `openspec/parity/capabilities.json` (par-10 to built with the run as evidence)
- **acceptance_criteria**:
  - GIVEN the run WHEN green THEN par-10 reads built and cites the spec file
  - GIVEN a defect on the portaliq side WHEN found THEN it is filed as a portaliq change, not patched here
- [ ] Implement
- [ ] Test
