---
kind: code
---

## Why

`ProxyVoteController` (board-meeting-resolutions, task-5.1) exposes proxy-registration endpoints
with `#[NoAdminRequired]` but **no per-object or per-actor authorization check anywhere in the
call chain** — a clear violation of ADR-005 Rule 3 (per-object authorization / IDOR prevention).

- `lib/Controller/ProxyVoteController.php:68-97` (`register()`) accepts `grantorId` and `holderId`
  as arbitrary request parameters. It never checks that the calling user (from `IUserSession`) IS
  the grantor, nor that the caller is a chair/clerk/admin authorized to register a proxy on
  someone else's behalf. Any authenticated non-admin Nextcloud user can register a proxy vote
  linking any two board members for any meeting.
- `lib/Controller/ProxyVoteController.php:152-166` (`suspend()`) and `:179-193` (`revoke()`) derive
  `$actor` correctly from the session (`$this->userSession->getUser()?->getUID()`), but pass it to
  `ProxyVoteService::transition()` purely as an audit-log label — never as an authorization
  subject.
- `lib/Service/ProxyVoteService.php:308-344` (`transition()`) loads the proxy object via
  `ObjectService::find()` and mutates its status without ever comparing `$actor` against the
  proxy's `grantorKoppeling`/`holderKoppeling` or checking chair/admin authority. Any authenticated
  user who knows (or enumerates) a proxy UUID can suspend or revoke **any other board member's**
  proxy delegation.

This directly affects the integrity of board resolutions and votes: a proxy vote's validity is a
legal fact under Dutch corporate-governance rules (Boek 2 BW), and an unauthenticated-in-effect
"anyone can grant/revoke anyone's proxy" surface undermines that. Contrast with the correct,
already-shipped pattern in this same codebase: `MotionCoauthorController::addCoauthor()`
(`lib/Controller/MotionCoauthorController.php:78-110`) resolves `$callerUid`, lets admins bypass,
and passes a non-null `callerUid` into the service so `MotionCoauthorService` can reject
non-owners. `ProxyVoteController` has no equivalent guard at all — it is the outlier, not the
house style.

No archived board-meeting-resolutions spec file documents an authorization rule for this endpoint
(`openspec/changes/archive/2026-06-12-board-meeting-resolutions/` has no `proxy-voting`
capability spec), so this is captured as a new capability spec here rather than a spec
correction.

## What Changes

- **`ProxyVoteService::register()`** gains a `$callerUid` parameter. Registration is rejected
  (`403`) unless the caller IS the `grantorId` (delegating their own vote) OR is a chair/clerk of
  the meeting's GovernanceBody OR a Nextcloud admin. Admins/chairs bypass the self-grantor check
  the same way `MotionCoauthorService` already does for admins.
- **`ProxyVoteService::transition()`** (and its `suspend()`/`revoke()` wrappers) gain a
  `$callerUid` used for authorization, not just an audit label. A transition is rejected (`403`)
  unless the caller is the proxy's `grantorKoppeling`, the proxy's `holderKoppeling`, a chair/clerk
  of the governing body, or an admin.
- **`ProxyVoteController`** resolves `$callerUid` (nullable for admin bypass, mirroring
  `MotionCoauthorController`'s pattern) and forwards it into `register()`/`suspend()`/`revoke()`.
- **New capability spec** `board-proxy-voting` documents the authorization rule so it is
  spec-traceable (`@spec`) going forward and covered by gate-16 (spec-coverage).
- No change to `index()`/`forMeeting()` (read-only, already scoped to an explicit `meetingId`
  param and returns no cross-tenant data beyond what any board member may already see via the
  meeting detail).

This is **not** marked BREAKING: it tightens an authorization gap that should never have been
open; no legitimate caller was relying on registering/suspending/revoking proxies they have no
relation to.
