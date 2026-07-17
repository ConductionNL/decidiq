---
kind: code
---

# Proposal: member-proxy-authorization

## Summary

Upgrade decidesk's proxy voting (volmacht) with the two things the existing capability verifiably lacks: (A) the volmacht as a **signed instrument** — a generated machtiging document that the absent member digitally signs through the existing eIDAS/docudesk signing seam, attached to the proxy record as a `ProxyAuthorization` object with a visible signature status (`ongetekend`/`getekend`/`geweigerd`), optional countersign by the holder, and revocation recorded as a signed, timestamped act; and (B) **statutory proxy caps per body** — a `maxProxiesPerHolder` value on the body's process/voting configuration (ALV per statuten, BV per BW 2:227, council, VvE per splitsingsakte may all differ) that supersedes the single global `decidesk`/`max_proxies_per_holder` app config, which remains the fail-closed fallback. A per-body `requireSignedProxy` policy (default off, preserving current behaviour) lets bodies whose statuten demand a written volmacht refuse unsigned proxy ballots. A proxy register per meeting (who held whose vote, with signature status) becomes attachable to the minutes.

## Motivation

Novelty verification (2026-07-17) shows the delta is narrow but real. What exists: member-initiated "Volmacht verlenen" with revocation before round open (proxy-voting REQ-PRX-001/003), one-proxy-per-delegate-per-round (REQ-PRX-002), a who-may-create/revoke authorization guard including chair-registered proxies (board-proxy-vote-authorization-guard REQ-BPV-001/002), and a single **global** cap via app config `decidesk`/`max_proxies_per_holder` (voting-system Proxy Voting requirement, default 2, fail closed, BW 2:38/2:227 references). What is missing: (1) the volmacht exists only as a data row — there is no signed machtiging instrument, so a chair cannot demonstrate at the meeting (or an auditor afterwards) that the absent member actually authorized the delegation, which is exactly what BW 2:38 statuten clauses and VvE splitsingsaktes require in writing; and (2) the cap is one number for the whole instance, while decidesk explicitly serves five governance domains whose statutory caps differ per body — an ALV capped at 2 by its statuten and a BV with no statutory cap cannot coexist on one instance today. Both gaps sit on building blocks decidesk already owns: the eIDAS/docudesk signing seam (decidesk-contract-decision-hub REQ-DCDH-005, `EIDASSignatureService`), the Docudesk document generation with honest markdown fallback (resolution-minutes), and the per-body process template as the home of procedural voting rules (process-configuration).

## Affected Projects

- [ ] Project: `decidesk` — new OR schema `proxyAuthorization` in register fragment `lib/Settings/register.d/63-member-proxy-authorization.json` (declarative signature-status lifecycle, seed data); one nullable `authorizationRef` property on the existing `vote` schema (base-file edit); two optional keys (`maxProxiesPerHolder`, `requireSignedProxy`) on `ProcessTemplate.votingRule` in fragment `43-process-config-v1.json`; a `ProxyAuthorizationService` (machtiging document generation + signing-seam integration + revocation act); cap/signed-policy resolution and enforcement in `ProxyVoteService`/`VotingService`; manifest UI (signature status badges, per-meeting proxy register report); tests, docs.
- [ ] Project: `docudesk` — consumed only via the ADR-019 integration registry (document rendering + e-signature), with the established honest fallbacks when absent. No docudesk changes.
- [ ] Project: `openregister` — consumed only: ObjectService storage, declarative lifecycle, schema validation. No OR changes.

## Scope

### In Scope

1. **ProxyAuthorization instrument**: a generated machtiging document (Docudesk rendering with honest markdown fallback, mirroring resolution-minutes), signed by the grantor via the **existing** eIDAS signing seam (REQ-DCDH-005 / `EIDASSignatureService` precedent — reuse, never re-implement), optional countersign by the holder; signature status (`ongetekend`/`getekend`/`geweigerd`) stored on the instrument and visible wherever the proxy is used (proxy lists, voting panel, result breakdown, audit trail).
2. **Per-body signed-proxy policy**: `requireSignedProxy` toggle on the body's process/voting configuration; default **off** → current behaviour fully preserved (unsigned proxies remain usable); **on** → the round refuses unsigned proxy ballots, fail closed.
3. **Per-body statutory cap**: `maxProxiesPerHolder` on the body's process/voting configuration, superseding the global app config; validated at proxy registration AND at round open; the global `decidesk`/`max_proxies_per_holder` config remains the fallback so all existing voting-system cap scenarios stay true unchanged when no body value is set; fail-closed semantics kept.
4. **Revocation as a signed act**: revocation recorded on the instrument as a signed, timestamped act; effect stays exactly REQ-PRX-003 (immediate for unopened rounds) and is never gated on the revocation signature completing.
5. **Proxy register per meeting**: an audit report of who held whose vote with signature status, generated as a document (Docudesk with markdown fallback) and attachable to the minutes.

### Out of Scope

- **DigiD/eHerkenning identity assurance levels** — which eIDAS assurance level a signature carries is the signing provider's concern; decidesk records the outcome only.
- **Wet-specific volmacht texts** — the machtiging template content is configurable; no per-wet legal text engineering in this change.
- **Changing ballot mechanics** — how proxy votes are cast, tallied, and displayed (proxy-voting REQ-PRX-001/004, voting-system) is untouched; this change only gates *whether* a proxy ballot is admissible and *how many* proxies one holder may carry.
- **Changing who may register/revoke a proxy** — board-proxy-vote-authorization-guard REQ-BPV-001/002 stays authoritative and unchanged.

## Approach

Thin-client extension (ADR-022/ADR-037). One new schema in an additive `register.d` fragment (63) with a declarative signature-status lifecycle; two optional additive keys on the existing `ProcessTemplate.votingRule` (edited in place in fragment 43, where that schema lives); one nullable property on the base-file `vote` schema (the established one-nullable-property edit pattern). Imperative code is confined to the seams dialects cannot express: composing the machtiging document and its docudesk `signingRequest` via the ADR-019 registry (reusing the REQ-DCDH-005 provider-selection and fail-closed shape verbatim), resolving the signature outcome onto the instrument, resolving the effective cap/policy (body template → global config → default), and enforcing both at registration/round-open/cast in the existing `ProxyVoteService`/`VotingService` paths. No new signature engine, no new document renderer, no new authorization model. Details in design.md.

## New Dependencies

None. Docudesk and the openconnector e-sign Source are existing optional integrations consumed through the existing registry with existing fallbacks.

## Impact

- `lib/Settings/register.d/63-member-proxy-authorization.json` — new: `proxyAuthorization` schema, signature-status lifecycle, relations (participant grantor/holder, votingRound/meeting, vote proxy row, document), seed data.
- `lib/Settings/register.d/43-process-config-v1.json` — edit: two optional keys on `ProcessTemplate.votingRule` (`maxProxiesPerHolder`, `requireSignedProxy`); no existing key changed.
- `lib/Settings/decidesk_register.json` — edit: one nullable `authorizationRef` property on the existing `vote` schema (same pattern as delegatie-mandaatregister's `bevoegdheidsgrondslag` edit).
- `lib/Service/ProxyAuthorizationService.php` — new: machtiging generation, signing-seam integration, countersign, revocation act, per-meeting proxy register report.
- `lib/Service/ProxyVoteService.php` / `lib/Service/VotingService.php` — edit: effective-cap resolution (body → global → default) at registration and round open; `requireSignedProxy` refusal of unsigned proxy ballots.
- `lib/Controller/ProxyVoteController.php` + `appinfo/routes.php` — edit: sign/countersign/report endpoints under the existing REQ-BPV authorization guard.
- `src/manifest.d/` — new fragment: signature status display, sign/countersign actions, per-meeting proxy register view/report action.
- Specs: one new capability spec (`member-proxy-authorization`) with ADDED requirements only; existing proxy-voting / voting-system / board-proxy-vote-authorization-guard requirements are referenced, not modified — compatibility is stated as explicit requirements in this capability's own spec.

## Cross-Project Dependencies

None requiring changes elsewhere. Consumes docudesk (rendering + e-signature) and openconnector (e-sign fallback Source) through the ADR-019 integration registry exactly as decidesk-contract-decision-hub and resolution-minutes already do; behaves honestly when both are absent.

## Risks

### Risk 1: Signed-proxy policy accidentally breaks existing proxy flows
**Severity:** High — **Mitigation:** `requireSignedProxy` defaults off and the effective cap falls back to the exact existing global config, so a zero-configuration upgrade is behaviour-identical; regression tests assert the existing proxy-voting and voting-system cap scenarios verbatim against the new code path.

### Risk 2: Signature theatre — instrument marked signed without a provider actually signing
**Severity:** High — **Mitigation:** reuse the REQ-DCDH-005 fail-closed contract verbatim: no signing provider → the instrument stays `ongetekend`; the app never marks `getekend` except from a provider completion callback; the declarative lifecycle rejects illegal status transitions; PHPUnit covers the no-provider path.

### Risk 3: Cap lowered after proxies were registered (template edit) leaves an over-cap holder
**Severity:** Medium — **Mitigation:** the cap is validated at round open as well as at registration; an over-cap holder blocks the round from opening with a message naming holder and excess, so the chair resolves it explicitly (revoke/suspend) — fail closed, never silent trimming.

### Risk 4: Per-body value confused with the per-round one-proxy limit (REQ-PRX-002)
**Severity:** Low — **Mitigation:** the spec states the relationship explicitly: REQ-PRX-002's one-proxy-per-round rule and the per-meeting holder cap are independent constraints, both enforced; UI copy distinguishes "volmacht per stemronde" from "volmachten per lid per vergadering".

## Rollback Strategy

Revert the PR: fragment 63 disappears (schema unregisters on next import), the two `votingRule` keys and the `vote.authorizationRef` property edits revert (stored values remain as harmless extra data under OR's additive handling), services/routes/manifest fragment are removed, and cap resolution falls back to the global app config — the pre-change behaviour. Already-generated machtiging documents remain as ordinary files in the meeting Files folders. No data migration in either direction.

## Open Questions

- Whether the holder countersign should be required (not just optional) when `requireSignedProxy` is on — provisionally optional everywhere; bodies whose statuten require acceptance can be served by a follow-up toggle.
- Machtiging template text per governance domain (ALV vs BV vs VvE wording) — provisionally one configurable neutral template; per-domain presets deferred.
