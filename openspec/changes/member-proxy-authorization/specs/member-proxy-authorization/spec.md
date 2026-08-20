# member-proxy-authorization Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- member-proxy-authorization

## Purpose

Layers a signed authorization instrument and per-body statutory caps on top of decidesk's existing proxy voting. A `ProxyAuthorization` object carries the generated machtiging document, signed by the grantor through the existing eIDAS/docudesk signing seam (decidesk-contract-decision-hub REQ-DCDH-005 — reused, never re-implemented), with signature status visible wherever the proxy is used and revocation recorded as a signed, timestamped act. The body's process/voting configuration gains `maxProxiesPerHolder` (superseding the global `decidesk`/`max_proxies_per_holder` app config, which remains the fail-closed fallback) and `requireSignedProxy` (default off — current behaviour preserved; on — the round refuses unsigned proxy ballots). A per-meeting proxy register (who held whose vote, with signature status) is attachable to the minutes. Ballot mechanics (proxy-voting REQ-PRX-001/002/004), the create/revoke authorization guard (board-proxy-vote-authorization-guard REQ-BPV-001/002), and revocation-effect semantics (REQ-PRX-003) are referenced and unchanged.

**Standards**: eIDAS (via the signing provider), BW 2:38 (ALV proxy per statuten), BW 2:227 (shareholder proxy), Schema.org (`AuthorizeAction` for the instrument, `DigitalDocument` for the machtiging)
**Feature tier**: V1
**Legal reference**: BW 2:38 lid 4, BW 2:227, VvE splitsingsakte/modelreglement volmacht clauses

## ADDED Requirements

### Requirement: REQ-MPA-001 ProxyAuthorization instrument schema

The system MUST provide a `ProxyAuthorization` OpenRegister schema in the decidesk register, shipped as fragment `lib/Settings/register.d/63-member-proxy-authorization.json` (ADR-037 — the base `decidesk_register.json` is never edited for new schemas), carrying: `grantor` (required UUID reference to the delegating Participant), `holder` (required UUID reference to the receiving Participant), `meeting` (required UUID reference to the Meeting) and `votingRound` (optional UUID reference when the volmacht is round-specific), `proxyVote` (optional UUID reference to the proxy row on the `vote` schema once registered), `document` (optional reference to the generated machtiging document), `signatureStatus` (required enum: `ongetekend`, `getekend`, `geweigerd`; default `ongetekend`), `signedAt` (optional datetime), `signingReference` (optional provider reference, REQ-MPA-003), `countersignStatus` (optional enum: `ongetekend`, `getekend`, `geweigerd`) with `countersignedAt`, and revocation fields `revokedAt` (optional datetime), `revocationSignatureStatus` (optional, same enum), `revocationSigningReference` (optional). Every property MUST carry a `title`; the manifest and all widget/filter sources MUST reference the schema by its slug `proxyAuthorization`. The existing `vote` schema MUST gain one nullable property `authorizationRef` (UUID reference to the ProxyAuthorization) via a base-file edit, so the proxy row resolves its instrument without a reverse query.

#### Scenario: Instrument created when a proxy is granted

- GIVEN Participant A grants a proxy to Participant B for a meeting (proxy-voting REQ-PRX-001 flow, unchanged)
- WHEN the delegation is registered
- THEN a `proxyAuthorization` object MUST be created with `grantor` = A, `holder` = B, the meeting reference, and `signatureStatus = ongetekend`
- AND the proxy row on the `vote` schema MUST carry `authorizationRef` pointing at that instrument

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the ProxyAuthorization schema is registered from fragment `63-member-proxy-authorization.json`
- AND the only base-file change is the nullable `authorizationRef` property on the existing `vote` schema; no existing schema content is removed or restructured

---

### Requirement: REQ-MPA-002 Machtiging document generation with honest fallback

The system MUST generate a machtiging document for a ProxyAuthorization from a configurable template (grantor, holder, body, meeting, scope, date), delegating rendering to Docudesk via the ADR-019 integration registry when available. When Docudesk is not installed, the system MUST still persist a plain markdown machtiging and state honestly that PDF rendering was unavailable; it MUST NOT fail or silently pretend a PDF was produced (same contract as the resolution-minutes document-generation requirement). The document MUST be linked from the instrument's `document` property and stored in the meeting's Files folder.

#### Scenario: Machtiging rendered via Docudesk

- GIVEN Docudesk is installed and Participant A grants a proxy to Participant B
- WHEN the machtiging document is generated
- THEN a rendered document containing grantor, holder, body, meeting, and date MUST be stored in the meeting's Files folder and linked from `ProxyAuthorization.document`

#### Scenario: Machtiging generated without Docudesk installed

@e2e exclude graceful-degradation branch is environment-dependent (Docudesk present or absent on the test instance); the fallback contract is locked by PHPUnit, mirroring the resolution-minutes fallback tests

- GIVEN an instance without the Docudesk app
- WHEN the machtiging document is generated
- THEN a markdown machtiging MUST be persisted and linked, and the response MUST state that Docudesk was unavailable and a markdown fallback was produced

---

### Requirement: REQ-MPA-003 Grantor signs the machtiging via the existing eIDAS signing seam

The system MUST let the grantor digitally sign the machtiging through the **existing** signing seam established by decidesk-contract-decision-hub REQ-DCDH-005: compose a docudesk `signingRequest` (openconnector `e-sign` Source as fallback provider, selected via the ADR-019 registry) from the machtiging document with the grantor as signatory, store the returned `signingReference` on the instrument, and on provider completion set `signatureStatus = getekend` with `signedAt`; on provider refusal set `signatureStatus = geweigerd`. The holder MAY optionally countersign through the same seam (`countersignStatus`/`countersignedAt`). The system MUST fail closed: when no signing provider is available the instrument stays `ongetekend` — it MUST NOT be marked signed by any path other than a provider completion. decidesk SHALL NOT implement its own e-signature engine (reuse of the REQ-DCDH-005 machinery is mandatory).

#### Scenario: Grantor signs the machtiging

- GIVEN a ProxyAuthorization with a generated machtiging and an available signing provider
- WHEN grantor A completes the signing flow
- THEN the instrument MUST carry `signatureStatus = getekend`, `signedAt`, and the provider `signingReference`

#### Scenario: Grantor refuses to sign

- GIVEN a signing request offered to grantor A
- WHEN the provider reports refusal
- THEN the instrument MUST carry `signatureStatus = geweigerd` and the proxy MUST be treated as unsigned for REQ-MPA-005 purposes

#### Scenario: No signing provider fails closed

@e2e exclude backend integration contract — the no-provider path is covered by PHPUnit, not a UI flow

- GIVEN neither docudesk nor the openconnector e-sign Source is available
- WHEN a signature is requested for a machtiging
- THEN the instrument stays `ongetekend` and the response states that no signing provider is available; nothing is marked signed

---

### Requirement: REQ-MPA-004 Signature status is visible wherever the proxy is used

The system MUST display the instrument's signature status (`ongetekend`/`getekend`/`geweigerd`) wherever the proxy appears: the proxy list of a meeting, the grantor's and holder's own proxy views, the chair's round-open view, the non-secret result breakdown (alongside the existing "(volmacht van [A])" marker, proxy-voting REQ-PRX-004 unchanged), and the audit trail.

#### Scenario: Chair sees signature status before opening a round

- GIVEN a meeting with a signed proxy (A→B, `getekend`) and an unsigned proxy (C→D, `ongetekend`)
- WHEN the chair views the proxies for the round
- THEN each proxy MUST show its signature status, with the unsigned one clearly distinguishable

#### Scenario: Result breakdown carries the signature status

- GIVEN a closed non-secret VotingRound containing a proxy vote whose instrument is `getekend`
- WHEN the chair views the detailed result breakdown
- THEN the proxy vote MUST show the existing "(volmacht van [A])" marker together with the signature status

---

### Requirement: REQ-MPA-005 Per-body requireSignedProxy policy gates unsigned proxy ballots

The system MUST support a boolean `requireSignedProxy` on the body's process/voting configuration (`ProcessTemplate.votingRule`, process-configuration territory), default **false**. When false or absent, behaviour MUST be identical to today: unsigned proxies remain fully usable (all existing proxy-voting scenarios hold unchanged). When true, the system MUST refuse proxy ballots whose instrument is not `getekend`: at round open the chair MUST see which registered proxies are unusable, and at vote casting the proxy ballot MUST be rejected with a clear error while the holder's own ballot proceeds normally. The check MUST fail closed: when the instrument or its status cannot be resolved while the policy is on, the proxy ballot MUST be refused.

#### Scenario: Policy off — unsigned proxy usable (current behaviour preserved)

- GIVEN a body whose configuration has no `requireSignedProxy` (or false)
- WHEN holder B casts a vote while holding an `ongetekend` proxy from A
- THEN both B's own vote and A's proxy vote MUST be recorded exactly as proxy-voting REQ-PRX-001 specifies today

#### Scenario: Policy on — unsigned proxy ballot refused

- GIVEN a body whose configuration sets `requireSignedProxy = true` and an `ongetekend` proxy from A to B
- WHEN B casts a vote in a round of that body
- THEN B's own vote MUST be recorded and A's proxy ballot MUST be rejected with an error naming the missing signature ("De volmacht van [A] is niet ondertekend")

#### Scenario: Policy on — status unresolvable fails closed

@e2e exclude fail-closed resolver branch is a server-side fault-injection case; covered by PHPUnit

- GIVEN `requireSignedProxy = true` and an instrument lookup that errors
- WHEN the proxy ballot admissibility is evaluated
- THEN the proxy ballot MUST be refused (never fail open)

---

### Requirement: REQ-MPA-006 Per-body maxProxiesPerHolder supersedes the global cap

The system MUST support an optional integer `maxProxiesPerHolder` (minimum 1) on the body's process/voting configuration (`ProcessTemplate.votingRule`). The effective cap for a meeting MUST resolve as: the body's assigned template value when set → otherwise the global app config `decidesk`/`max_proxies_per_holder` → otherwise the default 2 (the existing voting-system Proxy Voting requirement and its scenarios remain true verbatim whenever no body value is set — explicit compatibility). The effective cap MUST be enforced at proxy registration (existing `ProxyVoteService` cap check, now resolving the body value first) AND at round open: when any holder's ACTIVE proxies exceed the effective cap, the round MUST refuse to open, naming the holder and the excess, until the chair resolves it (revoke/suspend). Cap resolution and counting MUST fail closed: when the template, config, or existing proxies cannot be read, registration and round open MUST be rejected. This cap is independent of proxy-voting REQ-PRX-002's one-proxy-per-delegate-per-round rule; both MUST hold.

#### Scenario: Body value overrides the global config

- GIVEN the global config is 2 and the meeting's body has an assigned template with `votingRule.maxProxiesPerHolder = 1`
- WHEN member C attempts to register a proxy to member B who already holds 1 ACTIVE proxy in the meeting
- THEN the registration MUST be rejected citing the body's statutory maximum of 1

#### Scenario: No body value — global config remains authoritative (compatibility)

- GIVEN a body whose assigned template has no `maxProxiesPerHolder` and the global config is 2
- WHEN member B already holds 2 ACTIVE proxies and member C attempts to register a third to B
- THEN the registration MUST be rejected exactly as the existing voting-system cap scenario specifies

#### Scenario: Cap lowered after registration blocks round open

@e2e exclude requires seeded multi-proxy state plus a template edit between registration and round open; covered by PHPUnit and the Newman lifecycle suite

- GIVEN holder B legitimately registered 2 ACTIVE proxies and the body's template is then changed to `maxProxiesPerHolder = 1`
- WHEN the chair opens a VotingRound in that meeting
- THEN the round MUST refuse to open with a message naming B and the excess, and MUST open normally once one of B's proxies is revoked or suspended

---

### Requirement: REQ-MPA-007 Revocation is recorded as a signed, timestamped act

The system MUST record a proxy revocation on the instrument as a signed act: `revokedAt` (timestamp) set at the moment of revocation, and a revocation signature requested through the same signing seam as REQ-MPA-003 (`revocationSignatureStatus`, `revocationSigningReference`). The revocation's **effect** MUST remain exactly proxy-voting REQ-PRX-003: effective immediately for rounds that have not opened, impossible after the round opens — the effect MUST NOT be gated on the revocation signature completing (the signature documents the act; it never delays it). Who may revoke remains governed by board-proxy-vote-authorization-guard REQ-BPV-002, unchanged.

#### Scenario: Revocation takes effect immediately, signature follows

- GIVEN A's proxy to B with a `getekend` instrument and an unopened VotingRound
- WHEN A revokes the proxy
- THEN the proxy is removed with the existing REQ-PRX-003 behaviour (immediately, B notified) and the instrument carries `revokedAt`
- AND the revocation signature request is issued; its later completion sets `revocationSignatureStatus = getekend` without any further effect on voting

#### Scenario: Revocation without a signing provider still revokes

@e2e exclude provider-absence branch; covered by PHPUnit

- GIVEN no signing provider is available
- WHEN A revokes the proxy before the round opens
- THEN the revocation takes effect immediately and `revokedAt` is set, while `revocationSignatureStatus` stays `ongetekend` with an honest notice that no provider was available

---

### Requirement: REQ-MPA-008 Per-meeting proxy register attachable to the minutes

The system MUST provide a proxy register per meeting: every proxy of the meeting with grantor, holder, signature status, countersign status where present, and revocation timestamp where revoked. The register MUST be viewable in the meeting detail and MUST be exportable as a document (Docudesk rendering with the honest markdown fallback of REQ-MPA-002) stored in the meeting's Files folder so it can be attached to the minutes.

#### Scenario: Proxy register generated and attached

- GIVEN a meeting with a `getekend` proxy A→B and a revoked proxy C→D
- WHEN the secretary generates the proxy register document
- THEN the document MUST list both rows with their signature statuses and D's revocation timestamp, and MUST be stored in the meeting's Files folder alongside the minutes

#### Scenario: Proxy register reflects signature status live in the meeting view

- GIVEN the same meeting
- WHEN a participant with access opens the meeting's proxy register view
- THEN each proxy MUST show grantor, holder, and current signature status without requiring document generation

## Non-Functional Requirements

- **Performance:** effective-cap and signed-policy resolution add at most two object reads per registration/round-open/cast evaluation (template, instrument) — no per-proxy N+1 in the register view (single list query).
- **Accessibility:** signature status badges and refusal messages meet WCAG 2.1 AA (status never conveyed by colour alone; refusal errors announced to screen readers).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); `ongetekend`/`getekend`/`geweigerd` are stored enum values with translated display labels.

## Acceptance Criteria

- [ ] A granted proxy always has a ProxyAuthorization instrument with `signatureStatus = ongetekend` until a provider completion changes it
- [ ] Machtiging and proxy-register documents render via Docudesk and fall back honestly to markdown without Docudesk
- [ ] No signing provider → nothing is ever marked signed (fail closed)
- [ ] `requireSignedProxy` off/absent → all existing proxy-voting scenarios pass unchanged; on → unsigned proxy ballots refused at cast and flagged at round open
- [ ] Body `maxProxiesPerHolder` overrides the global config; absent → existing voting-system cap scenarios pass verbatim; enforced at registration and round open, fail closed
- [ ] Revocation effect timing identical to REQ-PRX-003 with or without a completed revocation signature

## Notes

- Reuses, never re-implements: eIDAS/docudesk signing seam (decidesk-contract-decision-hub REQ-DCDH-005, `EIDASSignatureService`), Docudesk-with-fallback document generation (resolution-minutes), create/revoke authorization guard (board-proxy-vote-authorization-guard), per-body procedural configuration home (process-configuration).
- Out of scope per proposal: DigiD/eHerkenning assurance levels, wet-specific volmacht texts, ballot mechanics.
- Related ADRs: ADR-019 (integration registry), ADR-022 (thin client on OR), ADR-031 (declarative dialects), ADR-037 (register fragments).
