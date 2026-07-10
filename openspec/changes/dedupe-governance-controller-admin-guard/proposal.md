---
kind: code
---

## Why

`GovernanceControllerTrait` (`lib/Controller/GovernanceControllerTrait.php`) already exists as the
shared home for helpers reused across "the retained governance controllers (audit-log,
conflict-of-interest, eIDAS-signature, proxy-vote, governance-report, regulator-export,
multilingual-reconciliation)" (its own docblock, line 27). It carries `requireUserOr401()`,
`bodyParams()`, and `respondFromResult()` — but **not** the admin-check helper, which four of
those seven controllers each re-implement as a byte-for-byte identical private method:

- `lib/Controller/AuditLogController.php:175-188`
- `lib/Controller/RegulatorExportController.php:200-213`
- `lib/Controller/GovernanceReportController.php:209-222`
- `lib/Controller/MultilingualReconciliationController.php:173-186`

All four `private function requireAdmin(): ?JSONResponse` bodies are identical (verified via
`md5sum` of the extracted method bodies — all four hash to `8f57cd42c87e5f3a6ea066206e84c399`):

```php
private function requireAdmin(): ?JSONResponse
{
    $user = $this->userSession->getUser();
    if ($user === null) {
        return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
    }

    if ($this->groupManager->isAdmin($user->getUID()) === false) {
        return new JSONResponse(['message' => 'Administrator role required.'], Http::STATUS_FORBIDDEN);
    }

    return null;
}
```

This is exactly the deduplication anti-pattern ADR-012 exists to prevent: one behavior, four
copy-pasted implementations. It has already drifted from the pattern the trait's own docblock
promises ("shared helpers... reused across the retained governance controllers") — the trait
lists 7 controllers but only actually shares 3 of the ~4 helpers those controllers need. A future
change to the admin-check semantics (e.g. adding a specific role instead of blanket
`isAdmin()`, or routing through `#[AuthorizedAdminSetting]` per ADR-005's stated preference) would
have to be applied identically in four places by hand, with no compiler or test forcing the
fourth edit to match the first three.

`ConflictOfInterestController` and `EIDASSignatureController` (also named in the trait's docblock)
were checked and do NOT define `requireAdmin()` — they use a different, `#[NoAdminRequired]` +
per-object-check pattern instead, so they are out of scope here; this change only touches the
four controllers that duplicate the identical admin-gate method.

## What Changes

- **Add `requireAdmin(IUserSession $session, IGroupManager $groupManager): ?JSONResponse`** to
  `GovernanceControllerTrait`, taking both collaborators as parameters (the trait has no
  constructor, so it cannot rely on promoted properties — this mirrors the existing
  `requireUserOr401(IUserSession $session)` signature style already in the trait).
- **Remove the four duplicated private `requireAdmin()` methods** from `AuditLogController`,
  `RegulatorExportController`, `GovernanceReportController`, and `MultilingualReconciliationController`,
  replacing each call site (`$this->requireAdmin()`) with
  `$this->requireAdmin($this->userSession, $this->groupManager)`.
- No behavior change: the 401/403 responses, status codes, and messages are byte-identical to
  today's — this is a pure dedup refactor.

Not marked BREAKING — no public contract changes; internal-only refactor.
