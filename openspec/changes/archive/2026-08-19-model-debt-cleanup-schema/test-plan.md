# Test Plan: model-debt-cleanup-schema

All test cases are schema-level (no UI). Type: **API** (`/test-api`) against
OpenRegister's schema/object endpoints, plus one **Regression** case
confirming the existing PHPUnit suite is unaffected.

## Coverage

### schemas-and-data-model

| Scenario | Test Case | Type |
|---|---|---|
| Decision created from a meeting tab carries a validated meeting reference | TC-1: POST a `decision` object with `meeting: <uuid>` via OpenRegister's object API; assert 2xx and the stored object echoes `meeting` | API |
| Decision created from an agenda item carries a validated agendaItem reference | TC-2: POST a `decision` object with `agendaItem: <uuid>`; assert 2xx and facet-filter `decision` by `agendaItem` returns it | API |
| New conflict-of-interest declaration references a Membership | TC-3: POST a `conflict-of-interest` object with `boardMember: <membershipUuid>`; assert 2xx. POST the same with a `Participant` UUID; assert OpenRegister's `$ref` type validation rejects it | API |
| New proxy authorization references Person and carries an approval state | TC-4: POST a `proxyAuthorization` object with `grantor`/`holder` as Person UUIDs and no `proxyStatus`; assert stored `proxyStatus` defaults to `pending-approval` | API |
| BoardProxy is inactive but not deleted | TC-5: GET the OpenRegister schema list for the decidesk register; assert `board-proxy` is present with `x-openregister.active: false` | API |
| GoverningDocument gains the property with no value on existing rows | TC-6: GET an existing `governing-document` seed object created before this change; assert `currentEffectiveDate` is `null`/absent, not an error | API |
| Advice-request schema resolves under its new kebab-case slug | TC-7: GET the decidesk register's schema list; assert `advice-request` is present and `adviceRequest` is absent | API |
| Proxy-authorization schema resolves under its new kebab-case slug | TC-8: GET the decidesk register's schema list; assert `proxy-authorization` is present and `proxyAuthorization` is absent | API |
| The unrelated WOR consultation-request enum value is untouched | TC-9: GET the `consultation-request` schema's `type` enum; assert it still contains the literal `adviceRequest`; POST a `consultation-request` with `type: "adviceRequest"`; assert 2xx (proves the rename did not collaterally block this unrelated enum value) | API |

### participant-crud

| Scenario | Test Case | Type |
|---|---|---|
| Participant's description names its exact remaining consumers | TC-10: GET the `Participant` schema definition; assert its description text names `Vote.participant`, `EngagementRecord.participant`, quorum aggregation, and `resolveParticipantUuid()`, and does not claim `ConflictOfInterest`/`ProxyAuthorization` as consumers | API |
| Participant shim still resolvable | TC-11: GET an existing `Vote` object with a `participant` reference; assert it resolves without error (regression on the untouched refs) | API |

### Regression

| Scenario | Test Case | Type |
|---|---|---|
| `tests/Unit/RegisterJsonTest.php` unaffected | TC-12: run `composer test -- --filter RegisterJsonTest`; assert it passes unmodified (confirms the direct edits to `decidesk_register.json`'s `components.registers` key, which this test never reads, caused no fallout) | Regression |

## Coverage Summary

All 9 `schemas-and-data-model` scenarios and both `participant-crud` scenarios
covered (TC-1 through TC-11). TC-12 is a regression guard on the one file this
design explicitly reasoned about (RegisterJsonTest.php reads `components.schemas`
from the base file only, never `components.registers`).

Deliberately untested here: any live-data behaviour (existing `board-proxy` rows,
existing `Participant`-typed `ConflictOfInterest`/`ProxyAuthorization` rows) —
those are `model-debt-cleanup-code`'s test-plan.md, since this change writes no
data migration.
