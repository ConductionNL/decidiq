---
kind: code
---

## Why

`ConflictOfInterestController` (board-meeting-resolutions, task-4.3) exposes three
`#[NoAdminRequired]` endpoints with **no per-object or per-actor authorization check anywhere in
the call chain** — a clear violation of ADR-005 Rule 3 (per-object authorization / IDOR
prevention), the same shape as `board-proxy-vote-authorization-guard` fixed for `ProxyVoteController`.

- `POST /api/conflicts` (`declare()`) accepts `membershipId` and `agendaItemId` as arbitrary
  request parameters. It never checks that the calling user IS that Membership, nor that the
  caller is a chair/secretary/admin authorized to record a declaration on someone else's behalf.
- `GET /api/members/{id}/conflicts` (`forMember()`) never checks that the caller IS the member
  whose declarations are being read, nor chair/secretary/admin. Any authenticated user can read
  any member's conflict-of-interest declarations by guessing or enumerating Membership UUIDs.
- `PUT /api/conflicts/{id}/action` (`recordAction()`) never checks that the caller holds any
  authority over the declaration before recording the mitigating action taken (e.g. recusal).
- `ConflictOfInterestService` (`lib/Service/ConflictOfInterestService.php`) has zero caller
  scoping — `grep -cE 'getUID\(\)|currentUser|requireUser'` returns 0 — and the `ConflictOfInterest`
  schema declared no `authorization` block (decidesk_register.json), so there was no platform-level
  guard either: an absent block leaves OpenRegister access open at the register-baseline cascade
  (read/list to `public` + `authenticated`).

Each of the three endpoints only calls `requireUserOr401()`, which answers "is anyone logged in",
not "may this caller act on this object". Contrast with the correct, already-shipped pattern in
this same codebase: `ProxyVoteService::isAuthorizedToRegister()` /
`isAuthorizedForTransition()` resolve the caller's identity (Nextcloud UID -> Participant ->
Person/Membership crosswalk) and compare it against the object before allowing a write, with a
`null` `$callerUid` signalling the admin bypass.

## What Changes

- **`ConflictOfInterestService::declare()`** and **`forMember()`'s new
  `isAuthorizedForMember()`** gain the rule: allowed when the caller IS the declaring Membership
  (resolved via the same Participant -> Person/Membership crosswalk `ProxyVoteService` uses,
  since `ConflictOfInterest.boardMember` now references `Membership` per REQ-SDM-023), OR the
  caller is chair/secretary of the relevant meeting's GovernanceBody (resolved from the
  declaration's `agendaItem` -> `Meeting` -> `GovernanceBody`), OR an admin (`$callerUid = null`).
- **`ConflictOfInterestService::recordAction()`** gains a narrower rule: chair/secretary of the
  relevant GovernanceBody only (plus admin) — recording the mitigating action taken is a
  presiding-officer act, not something the declarant authorizes for themselves.
- **`ConflictOfInterestController`** resolves `$callerUid` (nullable for admin bypass, mirroring
  `ProxyVoteController`'s pattern) via `IUserSession` + `IGroupManager`, and forwards it into
  `declare()`/`recordAction()`; `forMember()` calls the new `isAuthorizedForMember()` guard
  directly before reading.
- **`ConflictOfInterest` schema** (`lib/Settings/decidesk_register.json`) gains its own
  `authorization` block narrowing `read`/`list` to `authenticated` (declarations are sensitive
  personal data, not a public governance record — the previous register-baseline fallback also
  granted `public` read). `create`/`update` are intentionally omitted: the service's own guard
  already enforces the finer per-object rule and passes `_rbac: false`, the same convention
  `ProxyVoteService` uses for `proxy-authorization`.
- Refusals return `403 Forbidden` with a static message, never `404` — these ids are not secret
  and the endpoints already `404` on genuinely-missing rows via `respondFromResult()`.

This is **not** marked BREAKING: it tightens an authorization gap that should never have been
open; no legitimate caller was relying on reading or recording actions on conflict-of-interest
declarations they have no relation to.
