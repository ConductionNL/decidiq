---
kind: code
---

## Why

`AuditLogService::append()` — the write path invoked for **every** governance action logged to
the tamper-evident hash chain (vote, conflict declaration, material access, signature, notice
send, proxy grant/revocation, per the class docblock) — reloads the **entire** audit-trail chain
from OpenRegister on every single write, just to read the hash of the last row.

- `lib/Service/AuditLogService.php:113` (`append()`) calls
  `$previousHash = $this->resolvePreviousHash(objectService: $objectService);`
- `lib/Service/AuditLogService.php:457-465` (`resolvePreviousHash()`) calls
  `$this->loadChain(objectService: $objectService)` and then does `end($chain)` — i.e. it fetches
  **every** row just to read the last one.
- `lib/Service/AuditLogService.php:478-490` (`loadChain()`) issues
  `$objectService->findAll(['register' => 'decidesk', 'schema' => 'audit-trail', 'order' =>
  ['timestamp' => 'ASC'], 'limit' => 10000])` — an OpenRegister query capped at 10,000 rows, fully
  rendered and hydrated into PHP arrays.
- `verify()` (`:210,214`) and `export()` (`:284,299`) also call `loadChain()` for their own
  (legitimate, whole-chain) purposes — those are read-only, user-triggered, low-frequency
  operations and are NOT in scope here.

This makes `append()` — the hot path — **O(n)** in the number of audit-log rows accumulated so
far, and the audit log for an active governance body only grows. A council with years of meetings,
votes, and conflict declarations will, at 10,000+ entries, either silently truncate
`resolvePreviousHash()`'s view of the chain (breaking hash-chain continuity — the truncated
`limit: 10000` means `end($chain)` is capped at row 10,000, not necessarily the true last row, once
past that threshold) or (below the cap) pay an ever-growing full-table fetch-and-hydrate cost on
every governance action, in every request that logs one. This is the single audit-write path
that is guaranteed to be called synchronously and repeatedly, unlike the on-demand `verify()`/
`export()` calls.

There is no existing spec/change in `openspec/` covering the write-path cost of the audit chain
(the archived `board-meeting-resolutions` change introduced hash chaining but only specified
integrity, not performance).

## What Changes

- Add an OpenRegister-backed "resolve last hash" path that does not require loading the whole
  chain: query only the single most-recent `audit-trail` row (`order: timestamp DESC`, `limit:
  1`) instead of loading up to 10,000 rows and taking the tail.
- `resolvePreviousHash()` uses this new bounded query instead of `loadChain()`.
- `loadChain()` (whole-chain load) remains unchanged and continues to back `verify()` and
  `export()`, which legitimately need the full ordered chain.
- Not BREAKING: `append()`'s external behavior (return shape, computed hash) is unchanged; this
  only changes the internal query used to resolve `previousHash`.
