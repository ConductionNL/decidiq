---
kind: code
---

# Proposal: adopt-connection-registry

## Summary

Admins get one page in decidiq that lists its outside connections and says whether each one really works. The rows come from integriq's connection registry (hydra `openspec/changes/connection-registry`, hydra#667, amended in hydra#673).

## Motivation

Decidiq talks to three systems outside Nextcloud, and none of them says so on screen:

- **ORI publication** posts voting results to an Open Raadsinformatie (ORI) API endpoint. An admin sees a URL field and nothing else.
- **eIDAS signatures** fall back to `LogEIDASSignatureService` when integriq is missing. That service logs the request and signs nothing.
- **Translation** runs through `LogTranslationAdapter`, which returns the original text when no provider answers.

A fallback that answers looks exactly like a working connection. The registry gives decidiq the same page dossiq, pipelinq and shillinq already have.

## Affected projects

- [x] `decidiq`: a connection declaration, an Integrations page under the settings gear, a daily binding report, a refresh on ORI saves, section anchors on the admin page, tests.

Integriq owns the rows, the statuses and the Connections overview. Nothing in integriq changes here.

## Scope

### In scope

1. `lib/Settings/connections.json` with three connections: `ori`, `eidas` and `translation`.
2. An `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear, admin only.
3. A header action Add integration that opens `/apps/integriq/connections?app=decidiq&link=1`.
4. `ConnectionReportService` and a daily `ConnectionReportJob` that report which eIDAS service and translation adapter answer.
5. A refresh request when a settings save writes an ORI key, and a binding report on every settings save.
6. `id="section-ori"` and `id="section-email-voting"` on the admin page.

### Out of scope

- Replacing `MotionIntegrations.vue`. That page shows leaves beside one motion; this page shows connections.
- Email voting. It sends nothing through an outside mail server (see design D1).
- Fixing the eIDAS source lookup. `EIDASSignatureService` asks integriq for `Db\SourceMapper`, which integriq no longer ships. The report says so; the fix is its own change.

## Depends on

- hydra `connection-registry`, design D2, D4, D6, D8, D9 and D12.
- integriq shipping the amended declaration schema (`reportedOnly`). Integriq refuses a file with an unknown field whole, so the rows appear once that lands.

## Rollback

Revert the change. Decidiq writes no rows of its own. Integriq keeps its rows until its next sync finds no declaration and deletes the rows without a linked source.
