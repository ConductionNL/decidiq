# Design: member-proxy-authorization

## Context

Proxy voting exists and works: member-initiated "Volmacht verlenen" with pre-open revocation (proxy-voting REQ-PRX-001/003), one proxy per delegate per round (REQ-PRX-002), proxy rows persisted on the unified `vote` schema with statuses `pending-approval/active/suspended/revoked` (`ProxyVoteService`, `SCHEMA = 'vote'`), a who-may-create/revoke guard (board-proxy-vote-authorization-guard REQ-BPV-001/002 on `ProxyVoteController`), and a single global cap `decidesk`/`max_proxies_per_holder` (default 2, fail closed — `ProxyVoteService::maxProxiesPerHolder()`). Two gaps: the volmacht has no signed instrument (nothing proves the absent member authorized it), and the cap cannot differ per body although statuten do. Constraints: thin client (ADR-022), register fragments (ADR-037, fragment number **63** assigned to this change; 40–62/64–65 belong to siblings), declarative-first (ADR-031), and mandatory reuse of the eIDAS/docudesk signing seam (REQ-DCDH-005, `EIDASSignatureService`/`IEIDASSignatureService` already in `lib/Service/`) and the Docudesk-with-honest-markdown-fallback pattern (`MinutesDocumentService`).

## Goals / Non-Goals

**Goals**: signed machtiging instrument attached to the proxy record; signature status visible wherever the proxy is used; per-body `requireSignedProxy` gate (default off = behaviour-identical upgrade); per-body `maxProxiesPerHolder` superseding the global config (global stays the fail-closed fallback); revocation as a signed timestamped act with REQ-PRX-003 effect semantics untouched; per-meeting proxy register attachable to minutes.

**Non-Goals**: identity assurance levels (DigiD/eHerkenning), wet-specific volmacht texts, any change to ballot mechanics or to who may register/revoke proxies, a decidesk-owned signature engine.

## Architecture Overview

One new OpenRegister schema — `ProxyAuthorization` — ships as `lib/Settings/register.d/63-member-proxy-authorization.json` (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load). The instrument is a sibling object of the proxy row, not a replacement: the proxy row on the `vote` schema stays the thing the voting machinery reads (REQ-PRX-001/002, REQ-BPV guard, cap counting), and gains one nullable `authorizationRef` property (base-file edit) pointing at its instrument. The per-body policy values live on `ProcessTemplate.votingRule` (fragment `43-process-config-v1.json`, edited additively — `voteThreshold`/`abstentionHandling`/`tieBreakRule` already live there and are already resolved per body via the meeting→governanceBody→template link).

Imperative code is confined to `ProxyAuthorizationService` (document + signing seam + revocation act + register report) and small extensions of the existing `ProxyVoteService`/`VotingService` enforcement points. No new CRUD controllers for the instrument — the frontend reads it via the OR object stores; the only new routes are the sign/countersign/report actions on the existing `ProxyVoteController` surface, under the existing REQ-BPV guard.

## Decisions

### D1: ProxyAuthorization is a first-class schema, not extra fields on the proxy row

The proxy row (unified `vote` schema) is ballot machinery: it is created/suspended/revoked by `ProxyVoteService` and counted by the cap check. The instrument is an evidentiary document with its own lifecycle (generated → signed/refused, later a signed revocation act) that must survive and be auditable independently of the row's ballot status, and adding ~10 signature properties to the shared `vote` schema would pollute every ordinary ballot object with proxy-only fields. A separate `proxyAuthorization` schema in fragment 63 keeps the `vote` schema clean (one nullable `authorizationRef` back-reference, the established one-nullable-property base-edit pattern from delegatie-mandaatregister's `bevoegdheidsgrondslag`) and gives the instrument its own declarative lifecycle.

**Alternative considered:** signature fields directly on the `vote` schema — rejected: base-file schema growth for all votes, no independent lifecycle, and the instrument must exist before/without a round-specific proxy row (a volmacht can cover the whole meeting).

### D2: Reuse the REQ-DCDH-005 signing seam verbatim — provider via registry, fail closed

Signing composes a docudesk `signingRequest` from the machtiging document with the grantor as signatory, selected via the ADR-019 integration registry, with openconnector's `e-sign` Source as fallback provider; the returned reference is stored as `signingReference`, and provider completion/refusal is resolved onto the instrument (`getekend`+`signedAt` / `geweigerd`) through the same resolution path the `EIDASSignatureService` seam already implements for signature stages. No provider → the instrument stays `ongetekend` with an honest notice — the app never marks signed on its own authority (the anti-signature-theatre contract, identical to REQ-DCDH-005's fail-closed clause). Countersign by the holder is the same flow with the holder as signatory, writing `countersignStatus`/`countersignedAt`.

**Alternative considered:** a decidesk-local "click to confirm" pseudo-signature — rejected outright: REQ-DCDH-005 exists precisely so decidesk never grows its own signature engine, and a checkbox is not a machtiging under BW 2:38-style statuten clauses.

### D3: Per-body values live on `ProcessTemplate.votingRule`, not on GovernanceBody

`maxProxiesPerHolder` (integer ≥1, optional) and `requireSignedProxy` (boolean, optional/default false) are added to `ProcessTemplate.votingRule` in fragment 43 — additive keys next to `voteThreshold`/`abstentionHandling`/`tieBreakRule`, because process-configuration is the established home of per-body procedural voting rules and the meeting→governanceBody→template resolution chain already exists (process-configuration guard/round-open wiring). A body whose statuten differ from a shared built-in template duplicates the template (built-ins are read-only-but-duplicable) — exactly how a body gets its own `voteThreshold` today. Built-in templates ship **unset** for both keys, so the global config remains authoritative everywhere until an admin sets a body-level value: a zero-configuration upgrade is behaviour-identical.

**Alternative considered:** properties on the GovernanceBody schema — rejected: splits voting rules across two homes, bypasses the existing template resolution/validation machinery, and process-configuration's fail-closed malformed-template handling would not cover them.

### D4: Effective-cap resolution order and dual enforcement points

Effective cap = body's assigned template `votingRule.maxProxiesPerHolder` (when set and ≥1) → global app config `decidesk`/`max_proxies_per_holder` → default 2. Resolution lives in one method used by both enforcement points: **registration** (existing `ProxyVoteService` cap check, which today reads only the global config) and **round open** (new `VotingService` check: any holder whose ACTIVE proxy count in the meeting exceeds the effective cap blocks the open, naming holder and excess — covers caps lowered after registration and template reassignment). Fail closed on every branch: unreadable template, config, or proxy rows → reject, mirroring the existing `maxProxiesPerHolder()` fail-closed contract. The voting-system global-cap scenarios stay true verbatim whenever no body value is set — stated as an explicit compatibility scenario (REQ-MPA-006), not assumed.

**Alternative considered:** silent trimming of excess proxies at round open — rejected: destroys member intent without a decision-maker in the loop; the chair must resolve explicitly.

### D5: `requireSignedProxy` gates admissibility at cast, surfaces at round open

When on, the enforcement point is `VotingService::castVote()`'s proxy branch: the proxy ballot is refused unless the row's instrument resolves to `signatureStatus = getekend`; the holder's own ballot always proceeds. Round open does not hard-block on unsigned proxies (unlike the cap — an unsigned proxy may still get signed between open and cast); instead the chair's round-open view flags unusable proxies. Fail closed: unresolvable instrument/status while the policy is on → refuse the proxy ballot. When off/absent (the default, and the value on every existing template), the branch is never taken — current behaviour is preserved bit-for-bit.

**Alternative considered:** blocking registration of unsigned proxies when the policy is on — rejected: the instrument is created `ongetekend` by construction (signature follows generation), so registration-time blocking would deadlock the flow.

### D6: Revocation effect is never gated on the revocation signature

Revocation keeps REQ-PRX-003/REQ-BPV-002 semantics exactly: authorized actor revokes → immediate effect for unopened rounds, impossible after open. The signed act is documentation layered on top: `revokedAt` stamps at effect time; a revocation `signingRequest` is issued through the D2 seam and its completion writes `revocationSignatureStatus` asynchronously with no further effect. A missing provider therefore never traps a member in a proxy they want back — the legally riskier direction is an unrevocable volmacht, not an unsigned revocation record.

### Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Instrument signature-status machine (`ongetekend → getekend \| geweigerd`) | `x-openregister-lifecycle` on `signatureStatus` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys — never the silently-ignored `initialState`/`default` drift dialect) | Guarded state machine; illegal transitions (e.g. `getekend → ongetekend`) rejected by OR, zero app code |
| Required fields, enums, reference shapes | OR schema validation (`required`, `enum`, `format`) | Structural validation is the schema's job |
| Per-body policy storage | Additive keys on `ProcessTemplate.votingRule` (fragment 43) | Data, not code; validated by the existing template validation path |
| Signature request/resolution (docudesk `signingRequest`, e-sign fallback, completion → status write) | **Imperative** — `ProxyAuthorizationService` reusing the REQ-DCDH-005 seam | Cross-app provider orchestration is not expressible as a dialect; the seam already exists and must be reused, not duplicated |
| Machtiging + proxy-register document generation | **Imperative** — Docudesk via registry with markdown fallback (the `MinutesDocumentService` pattern) | Established imperative surface, mirrored not re-invented |
| Effective-cap resolution + enforcement at registration/round-open; signed-ballot admissibility at cast | **Imperative** — `ProxyVoteService`/`VotingService` extensions | Cross-object counting against a resolved per-body value is beyond schema constraints; the enforcement points already exist imperatively (fail-closed cap check) |
| Proxy-register meeting view | Manifest list over `proxyAuthorization` filtered by meeting | Pure query; document export is the imperative branch above |

## Nextcloud Integration

- Controllers: sign/countersign/generate-document/generate-register endpoints on the existing `ProxyVoteController` (+ `appinfo/routes.php`), each with explicit auth attributes and per-object guards under the REQ-BPV-001/002 authorization model (no-admin-idor, semantic-auth gates); a callback/resolution endpoint only if the existing seam's resolution path does not already deliver completions.
- Services: new `ProxyAuthorizationService` (instrument CRUD via `ObjectService` — PUT-semantic `saveObject()` carrying ALL fields forward on partial updates; document generation; signing seam; revocation act; register report). Edits to `ProxyVoteService` (effective-cap resolution, instrument creation on grant) and `VotingService` (round-open cap check, cast-time signed-admissibility check).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: reuse the seam's completion resolution; proxy grant/revoke notifications (REQ-PRX-001/003) unchanged.
- Frontend: manifest fragment `src/manifest.d/member-proxy-authorization.json` — signature status badges on proxy lists/voting panel/result breakdown, sign/countersign actions, meeting proxy-register view + export action. Schema references use the slug `proxyAuthorization`, never PascalCase.

## Security Considerations

- **No signature theatre:** `getekend` is writable only from provider completion (D2); the declarative lifecycle rejects backwards transitions; no-provider path is honest and fail-closed (never the `catch (\Throwable) { return null; }` fail-open shape — unsafe-auth-resolver gate).
- **Authorization unchanged and reused:** all new mutations ride the REQ-BPV guard (grantor/holder/chair-clerk/admin); sign is grantor-only, countersign holder-only, per-object checks in the method body, not just route annotations.
- **Fail-closed enforcement:** cap resolution errors reject registration/round-open; signed-policy resolution errors refuse the proxy ballot; both directions covered by PHPUnit fault-injection tests.
- **No new public surface:** all routes authenticated; the proxy register document lands in the meeting's Files folder under existing file ACLs; no writeOnly/secret fields on the new schema.
- **Privacy:** the instrument carries governance participants already present in the register; the machtiging document contains no data beyond what the proxy row already exposes to the same audience.

## File Structure

```
lib/Settings/register.d/63-member-proxy-authorization.json  (new — proxyAuthorization schema + lifecycle + seed)
lib/Settings/register.d/43-process-config-v1.json           (edit — votingRule.maxProxiesPerHolder + requireSignedProxy, additive)
lib/Settings/decidesk_register.json                         (edit — vote.authorizationRef nullable property only)
lib/Service/ProxyAuthorizationService.php                   (new — document, signing seam, revocation act, register report)
lib/Service/ProxyVoteService.php                            (edit — effective-cap resolution, instrument creation on grant)
lib/Service/VotingService.php                               (edit — round-open cap check, cast-time signed admissibility)
lib/Controller/ProxyVoteController.php + appinfo/routes.php (edit — sign/countersign/document/register endpoints)
src/manifest.d/member-proxy-authorization.json              (new — status badges, actions, meeting proxy-register view)
tests/Unit/Service/ProxyAuthorizationServiceTest.php        (new)
tests/Unit/Service/ProxyVoteServiceTest.php                 (edit — body-cap + compatibility + fail-closed cases)
tests/e2e/...                                               (new — scenario coverage per gate-19)
docs/features/member-proxy-authorization.md                 (new)
```

## Seed Data

Realistic Dutch examples (ADR-016); references use existing decidesk seed objects (participants, meetings, the seed proxy row `stem-proxy-vandam-begroting` on the `vote` schema) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. All objects carry the `@self` envelope (`register: decidesk`, `schema: proxyAuthorization`, slug as below).

### Schema: `proxyAuthorization` (fragment 63 seeds)

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | machtiging-vandam-begroting | machtiging-alv-jansen-ongetekend | machtiging-devries-ingetrokken |
| grantor | (seed Participant Van Dam ref) | (seed Participant Jansen ref, nil-UUID placeholder) | (seed Participant De Vries ref, nil-UUID placeholder) |
| holder | (seed Participant ref matching the seed proxy row) | (seed Participant ref) | (seed Participant ref) |
| meeting | (seed Meeting ref) | (seed Meeting ref) | (seed Meeting ref) |
| proxyVote | (seed vote `stem-proxy-vandam-begroting` ref) | — | — |
| document | (markdown machtiging file ref, placeholder) | — | (markdown machtiging file ref, placeholder) |
| signatureStatus | getekend | ongetekend | getekend |
| signedAt | 2026-06-02T09:15:00Z | — | 2026-05-01T10:00:00Z |
| countersignStatus | getekend | — | — |
| countersignedAt | 2026-06-02T11:40:00Z | — | — |
| revokedAt | — | — | 2026-05-20T14:30:00Z |
| revocationSignatureStatus | — | — | getekend |

Object 1 completes the existing seed proxy vote with a fully signed + countersigned instrument (happy path demonstrable on a fresh install); object 2 stays `ongetekend` so the sign action and the `requireSignedProxy` refusal are demoable; object 3 demonstrates the signed revocation act. Additionally, one **duplicated** process template seed "ALV met statutaire volmachtgrens" (`@self.schema: processTemplate`, cloned from the association-alv built-in) carries `votingRule.maxProxiesPerHolder: 1` and `votingRule.requireSignedProxy: true` so the per-body override path is demonstrable without editing read-only built-ins; the built-in templates themselves ship with both keys **unset** (global fallback, behaviour-identical upgrade).

**Related items per object:**
- Files: the markdown machtiging documents for objects 1 and 3 in the seed meeting's Files folder (honest fallback form, since Docudesk may be absent on a fresh install).
- Notes/Tasks/Contacts: none.

## Migration Plan

1. Land fragment 63, the fragment-43 additive keys, the base-file `vote.authorizationRef` property, services, routes, manifest fragment, seeds, tests, docs in one decidesk PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks them up on upgrade).
2. Zero-configuration upgrade is behaviour-identical: no template carries the new keys, so the global cap stays authoritative and `requireSignedProxy` is off everywhere; existing proxy rows simply have no instrument (`authorizationRef` null) — instruments are created for proxies granted after the upgrade, and the register report labels pre-existing proxies honestly as "geen machtiging geregistreerd".
3. Ordering: fragment 63 is disjoint from sibling fragments 40–62/64–65; the fragment-43 edit is additive keys only (no existing key touched), so concurrent sibling merges union cleanly — verified against the merge base at PR time.
4. Rollback: revert the PR (see proposal Rollback Strategy) — no data migration in either direction.

## Risks / Trade-offs

- [Upgrade regression on existing proxy flows] → default-off policy + unset built-in keys + explicit compatibility scenarios (REQ-MPA-005/006) asserted by regression tests against the existing proxy-voting/voting-system scenarios.
- [Signature theatre] → provider-completion-only `getekend`, declarative lifecycle rejecting backwards transitions, no-provider PHPUnit path (D2).
- [Fragment-43 edit collides with a sibling touching the same file] → additive keys under `votingRule.properties` only; union-merge review against the merge base (never hand-pick), per the union-merge discipline.
- [Cap check races between registration and round open] → round open re-validates against current ACTIVE rows (D4); the round-open check is the authoritative last gate.
- [Instrument orphaned when proxy registration fails midway] → instrument creation and proxy-row registration are sequenced instrument-last-linked; an unlinked instrument is inert (no capability reads it) and the register report shows only meeting-linked instruments.
- [Multi-word schema slug breakage] → manifest refs use the slug `proxyAuthorization` verbatim; gates 28/30/51/52 run on register+manifest changes.

## Open Questions

- Whether the seam's completion resolution needs a decidesk-side callback route or the existing `EIDASSignatureService` resolution path covers instrument targets too — decided at apply time against the seam's actual extension surface.
- Whether countersign should become mandatory when `requireSignedProxy` is on (proposal Open Question) — provisionally optional.
