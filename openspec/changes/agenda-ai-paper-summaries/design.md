# Design: agenda-ai-paper-summaries

Read at decidiq development `4d7430ff` and openregister development `555af72`.

## What exists

| Piece | Where |
|---|---|
| TaskProcessing use | `lib/Service/MinutesDraftService.php:69-90` hides the action when no provider exists; `:288-290` builds `new Task(TextToText::ID, ['input' => $prompt], 'decidesk', null)` and calls `runTask()` synchronously |
| Papers of an agenda item | OpenRegister `FileService::getFiles($objectId)`, used by `lib/Service/MeetingPackageService.php:195` and `:511` |
| Text of a file | OpenRegister `lib/Service/TextExtractionService.php:196` `extractFile(int $fileId)` writes chunks; `lib/Db/ChunkMapper.php:93` `findBySource('file', $fileId)` reads them |
| Agenda item page | `src/manifest.json:821` `AgendaItemDetail`, widgets `agenda-data`, `agenda-files` (files integration), `agenda-type-fields`, `agenda-motions` |
| Confidentiality | `lib/Settings/register.d/83-confidentiality-in-plain-words.json` `ConfidentialityRestriction` with `targetAgendaItem`, `targetDocument`, `lifecycle` |
| Groups | `decidiq-secretariat` (clerks), `decidesk-members`, `decidiq-administrators` (used across `lib/Settings/register.d/*.json`) |

## Approach

### Schema

A new fragment `lib/Settings/register.d/92-paper-summaries.json` declares `PaperSummary` (slug `paper-summary`, Schema.org `CreativeWork` with `isBasedOn`):

| Property | Type | Notes |
|---|---|---|
| `agendaItem` | uuid, `$ref` AgendaItem | required |
| `paperFileId` | integer | the Nextcloud file id of the paper |
| `paperTitle` | string | file name at generation time |
| `kind` | enum `summary`, `comparison` | required |
| `comparedFileId`, `comparedTitle` | integer, string | set for a comparison |
| `text` | string (markdown) | the AI output, then the clerk's edit |
| `provider` | string | TaskProcessing provider id, for provenance |
| `taskId` | integer | the TaskProcessing task |
| `chunked` | boolean | true when the paper was summarised in parts |
| `status` | enum `requested`, `draft`, `shown`, `hidden`, `failed` | lifecycle |
| `reviewedBy`, `reviewedAt` | string, datetime | the clerk who showed or hid it |

`x-openregister-lifecycle` on `status`: `requested` initial; `requested` to `draft` or `failed`; `draft` to `shown` or `hidden`; `shown` to `hidden`; `hidden` to `shown`. OpenRegister rejects anything else.

`authorization.read`: `decidiq-secretariat` and `decidiq-administrators` read all; `{ "group": "decidesk-members", "match": { "status": "shown" } }` reads shown ones only. Create and update: `decidiq-secretariat`, `decidiq-administrators`.

### Service and task flow

`lib/Service/PaperSummaryService.php` (new):

1. `request(agendaItemId, fileId, kind, comparedFileId?)` checks the caller is secretariat or admin, checks the file belongs to the agenda item through `FileService::getFiles()`, and refuses when an active `ConfidentialityRestriction` targets the agenda item or the document and the caller is outside its circle.
2. It reads the text through `TextExtractionService::extractFile()` then `ChunkMapper::findBySource('file', $fileId)`, both resolved lazily from the container as `MeetingPackageService` does with `FileService`.
3. It schedules, not runs, a TaskProcessing task: `TextToTextSummary` for a summary, `TextToText` with a fixed comparison prompt for a comparison, app id `decidiq`, custom id `paper-summary:<uuid>`. It saves the `PaperSummary` as `requested`. A paper longer than the provider's input is split on OpenRegister's chunk boundaries, each part summarised, and the parts summarised again; `chunked` records it.
4. `lib/Listener/PaperSummaryTaskListener.php` listens to `OCP\TaskProcessing\Events\TaskSuccessfulEvent` and `TaskFailedEvent`, matches the custom id prefix, and moves the object to `draft` with the text or to `failed`.

Controller `lib/Controller/PaperSummaryController.php`, `#[NoAdminRequired]` with the secretariat check inside (ADR-005 rule 3): `POST /api/agenda-items/{id}/paper-summaries` and `GET /api/paper-summaries/availability` (provider present or not). Review (edit, show, hide) is an ordinary OpenRegister update guarded by the lifecycle and the update rule, so no extra endpoint.

### Screen

A custom widget `agenda-paper-summaries` on `AgendaItemDetail` (`src/components/tabs/AgendaPaperSummariesTab.vue`):

- A clerk sees each paper with Summarise and Compare with actions, and every summary in any status with Edit, Show to members and Hide.
- A member sees shown summaries only, each labelled "AI-generated summary, checked by <name> on <date>".
- With no provider installed, the actions do not render and the widget says so in one line.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Review states | Declarative `x-openregister-lifecycle` on `PaperSummary.status` | A guarded state map |
| Who reads what | Declarative `authorization` with `match` on `status` | OpenRegister enforces it on every read |
| Generating the text | Imperative `PaperSummaryService` plus a TaskProcessing listener | ADR-031 exception: external integration (AI provider) and document text extraction |
| Confidentiality check | Imperative, in the service | A cross-object rule on the restriction's circle; no dialect reads another object's membership |

## Seed data

Two objects in the municipality example set (`lib/Settings/profiles/municipality.json`):

1. A shown summary of "Programmabegroting 2027" on the agenda item Begroting 2027 of the council meeting, kind `summary`, reviewed by the griffier, text of four short paragraphs.
2. A draft comparison of "Programmabegroting 2027" with "Programmabegroting 2026", kind `comparison`, not yet reviewed, so a clerk sees the review step on a fresh install.

## Files

- `lib/Settings/register.d/92-paper-summaries.json`, `lib/Settings/profiles/municipality.json`
- `lib/Service/PaperSummaryService.php`, `lib/Listener/PaperSummaryTaskListener.php`, `lib/AppInfo/Registrar/*` (listener registration), `lib/Controller/PaperSummaryController.php`, `appinfo/routes.php`
- `src/components/tabs/AgendaPaperSummariesTab.vue`, `src/registry.js`, `src/manifest.json` (`AgendaItemDetail` widget and layout row)
- `tests/Unit/Service/PaperSummaryServiceTest.php`, `tests/Unit/Listener/PaperSummaryTaskListenerTest.php` (constructs the real `TaskSuccessfulEvent`), `tests/e2e/paper-summaries.spec.ts`
