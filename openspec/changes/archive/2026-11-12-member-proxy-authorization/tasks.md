# Tasks: member-proxy-authorization

## Implementation Tasks

### Task 1: Register fragment 63 — proxyAuthorization schema + signature-status lifecycle; base-file vote.authorizationRef
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-001-proxyauthorization-instrument-schema`
- **files**: `lib/Settings/register.d/63-member-proxy-authorization.json`, `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a clean instance WHEN the register is imported THEN schema `proxyAuthorization` exists with grantor/holder/meeting (required), votingRound/proxyVote/document/signing fields, `signatureStatus` enum (`ongetekend`/`getekend`/`geweigerd`) under `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys; `ongetekend → getekend | geweigerd`), countersign and revocation fields; every property carries a `title`
  - GIVEN a `getekend` instrument WHEN a transition back to `ongetekend` is attempted THEN OR rejects it (undeclared transition)
  - GIVEN the base-file diff WHEN reviewed THEN the only `decidesk_register.json` change is the nullable `authorizationRef` property on the existing `vote` schema
- [ ] Implement
- [ ] Test

### Task 2: Seed data — signed+countersigned, ongetekend, and revoked instruments + duplicated per-body template
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-001-proxyauthorization-instrument-schema`
- **files**: `lib/Settings/register.d/63-member-proxy-authorization.json` (x-openregister seed block)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seed data is planted THEN the 3 objects from the design Seed Data table exist: a `getekend`+countersigned instrument linked to the seed proxy vote `stem-proxy-vandam-begroting`, an `ongetekend` instrument, and a revoked instrument with `revokedAt` and `revocationSignatureStatus = getekend`
  - GIVEN the seeds WHEN imported THEN the duplicated process template "ALV met statutaire volmachtgrens" exists with `votingRule.maxProxiesPerHolder = 1` and `votingRule.requireSignedProxy = true`, and no built-in template carries either key (behaviour-identical upgrade, ADR-016 demoability)
- [ ] Implement
- [ ] Test

### Task 3: Fragment 43 additive votingRule keys + effective-cap resolution and dual enforcement
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-006-per-body-maxproxiesperholder-supersedes-the-global-cap`
- **files**: `lib/Settings/register.d/43-process-config-v1.json`, `lib/Service/ProxyVoteService.php`, `lib/Service/VotingService.php`
- **acceptance_criteria**:
  - GIVEN a body template with `maxProxiesPerHolder = 1` and global config 2 WHEN a second proxy to the same holder is registered THEN it is rejected citing the body maximum; GIVEN no body value THEN the existing global-cap scenario (voting-system) passes verbatim (compatibility regression test)
  - GIVEN a holder over the effective cap (cap lowered after registration) WHEN the chair opens a round THEN the open is refused naming holder and excess, and succeeds after revoking/suspending the excess proxy
  - GIVEN the template, config, or proxy rows cannot be read WHEN registration or round open runs THEN it is rejected (fail closed, never null-swallow); REQ-PRX-002's one-per-round rule still holds independently
- [ ] Implement
- [ ] Test

### Task 4: ProxyAuthorizationService — machtiging document + eIDAS signing seam reuse + countersign
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-003-grantor-signs-the-machtiging-via-the-existing-eidas-signing-seam`
- **files**: `lib/Service/ProxyAuthorizationService.php`, `lib/Controller/ProxyVoteController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a granted proxy WHEN the instrument is created THEN a machtiging document is generated (Docudesk via the ADR-019 registry; without Docudesk a markdown fallback is persisted with an honest notice — REQ-MPA-002, mirroring MinutesDocumentService) and linked from `document`
  - GIVEN an available provider WHEN the grantor completes signing THEN `signatureStatus = getekend` + `signedAt` + `signingReference`; refusal yields `geweigerd`; the holder can optionally countersign via the same seam
  - GIVEN no signing provider WHEN a signature is requested THEN the instrument stays `ongetekend` (fail closed — nothing is ever marked signed app-side); sign is grantor-only and countersign holder-only with per-object guards under REQ-BPV authorization (explicit auth attributes)
- [ ] Implement
- [ ] Test

### Task 5: requireSignedProxy admissibility gate at cast + round-open surfacing
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-005-per-body-requiresignedproxy-policy-gates-unsigned-proxy-ballots`
- **files**: `lib/Service/VotingService.php`, `lib/Service/ProxyVoteService.php`
- **acceptance_criteria**:
  - GIVEN the policy off/absent WHEN a holder with an `ongetekend` proxy casts THEN both ballots record exactly as today (regression test against the existing proxy-voting scenarios)
  - GIVEN the policy on WHEN the holder casts THEN the own ballot proceeds and the unsigned proxy ballot is rejected ("De volmacht van [A] is niet ondertekend"); the chair's round-open view flags unusable proxies
  - GIVEN the policy on and an unresolvable instrument/status WHEN admissibility is evaluated THEN the proxy ballot is refused (fail closed)
- [ ] Implement
- [ ] Test

### Task 6: Revocation as signed act — effect timing unchanged
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-007-revocation-is-recorded-as-a-signed-timestamped-act`
- **files**: `lib/Service/ProxyAuthorizationService.php`, `lib/Service/ProxyVoteService.php`
- **acceptance_criteria**:
  - GIVEN an unopened round WHEN an authorized actor revokes THEN the proxy effect is immediate per REQ-PRX-003 (unchanged, incl. holder notification) and `revokedAt` is stamped; the revocation signature completes asynchronously into `revocationSignatureStatus` with no further voting effect
  - GIVEN no signing provider WHEN revoking THEN the revocation still takes immediate effect with an honest ongetekend revocation record; GIVEN an opened round THEN revocation stays impossible (existing behaviour)
  - GIVEN an unauthorized user WHEN revoking THEN REQ-BPV-002 rejects with 403 (guard unchanged, regression test)
- [ ] Implement
- [ ] Test

### Task 7: UI — signature status everywhere + per-meeting proxy register attachable to minutes
- **spec_ref**: `openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-008-per-meeting-proxy-register-attachable-to-the-minutes`
- **files**: `src/manifest.d/member-proxy-authorization.json`, `lib/Service/ProxyAuthorizationService.php`
- **acceptance_criteria**:
  - GIVEN seeded instruments WHEN viewing proxy lists, the chair's round-open view, and the non-secret result breakdown THEN each proxy shows its signature status (REQ-MPA-004; status not conveyed by colour alone, WCAG 2.1 AA); manifest refs use the slug `proxyAuthorization`, never PascalCase
  - GIVEN a meeting with a signed and a revoked proxy WHEN the secretary generates the proxy register document THEN it lists grantor, holder, signature status, and revocation timestamp, renders via Docudesk with honest markdown fallback, and lands in the meeting's Files folder alongside the minutes
  - GIVEN the meeting proxy-register view WHEN opened THEN statuses show live from a single list query (no per-proxy N+1)
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria on Postgres (8080) instance
- [ ] Code review against spec requirements (REQ-MPA-001…008)

## Quality checklist

- Tests (ADR-009): PHPUnit unit tests for all new/changed business logic (`tests/Unit/` — seam fail-closed paths, cap resolution matrix, admissibility gate, revocation timing); Newman/Postman tests for new/changed endpoints (sign/countersign/document/register incl. 403 guard cases); Playwright browser tests for UI changes (status badges, sign action, register view/export); all tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Documentation (ADR-010): feature documentation in `docs/features/member-proxy-authorization.md` with screenshot in `docs/images/`
- i18n (ADR-005): Dutch (`nl_NL`) and English (`en_US`) strings for all new user-facing strings; `ongetekend`/`getekend`/`geweigerd` stay stored enum values with translated display labels; Dutch legal terms (volmacht, machtiging) stay untranslated domain vocabulary
- `openspec validate` passes
