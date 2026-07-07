---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — ADR-046 v2.2 provider class contributing the `citizen` read + inbox surface with field projection and default pseudonymous-token (subjectRef) scoping (kind: code)

## Purpose

Decidesk contributes a `citizen` section to **portaliq**, the shared external
portal for people without a Nextcloud account (hydra ADR-046, contribution
contract v2.2). The contribution is one plain, dependency-free provider class
(`OCA\Decidesk\Portal\PortalContributionProvider`, duck-typed by FQCN — inert
without portaliq) that returns a pure-data manifest for the `citizen` audience:
the citizen reads their own consultation reactions, advisory votes and
participatory-budget proposals, and their own notification inbox. Every read is
scoped by the subject's own pseudonymous token (`scopeField == subjectRef`, no
claim — Decidesk already stores that token in each record's scope field) and
projected server-side to subject-safe fields only. The citizen edge is
portaliq's ordinary password login at trust `low`; DigiD/eHerkenning and any
credential broker are deferred.

## Requirements

Detailed requirements (REQ-DKPORT-001 … REQ-DKPORT-006) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Decidesk ships the ADR-046 portal contribution (REQ-DKPORT-000)

The app MUST serve its entire portal contribution through the single artefact
this capability owns: the plain, dependency-free
`OCA\Decidesk\Portal\PortalContributionProvider` class (duck-typed by FQCN, inert
without portaliq). It declares the `citizen` read + inbox manifest — default
pseudonymous-token scoping, server-side field projection, `minTrust: low` gating
and the notification inbox — and no other portal *contribution* logic, UI,
dependency, create-action or endpoint-action may ship outside it in this wave.

#### Scenario: The provider is the sole portal-contribution artefact

- GIVEN Decidesk installed with portaliq present
- WHEN portaliq resolves `OCA\Decidesk\Portal\PortalContributionProvider` by FQCN and calls `getAudiences()` / `getContribution()`
- THEN the `citizen` read + inbox manifest is served from that class alone
- AND no route, controller, service, frontend or `info.xml` dependency is added for the contribution
- @e2e exclude backend-only contribution class rendered inside portaliq, not Decidesk — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)
