## 1. Backend — MeetingService

- [x] 1.1 Create `lib/Service/MeetingService.php` — stateless service with `transition(string $meetingId, string $action): array`. Defines the state-machine transition table (`TRANSITIONS` constant). Validates the action name and current lifecycle state, then patches the lifecycle via `ObjectService::updateFromArray($id, ['lifecycle' => $newState], updateVersion: true, patch: true)`. Returns `['success' => bool, 'meeting' => array|null, 'message' => string]`.
- [x] 1.2 Add `@spec openspec/changes/p2-meeting-management/tasks.md#task-1.1` to every class and public method in `MeetingService.php`.

## 2. Backend — MeetingController

- [x] 2.1 Create `lib/Controller/MeetingController.php` — thin controller with one method: `lifecycle(string $id): JSONResponse`. Reads `action` from request body, calls `MeetingService::transition($id, $action)`, returns the result. Returns HTTP 422 if transition invalid, 200 on success. Annotate with `@NoAdminRequired`.
- [x] 2.2 Add `@spec openspec/changes/p2-meeting-management/tasks.md#task-2.1` to class and method.
- [x] 2.3 Register route in `appinfo/routes.php`: `POST /api/meetings/{id}/lifecycle` → `meeting#lifecycle`.

## 3. Tests — PHPUnit

- [x] 3.1 Create `tests/Unit/Service/MeetingServiceTest.php` with ≥3 test methods covering:
  - Valid transition (e.g. scheduled → opened via `open`) returns success
  - Invalid transition (e.g. trying to `pause` a draft) returns failure with message
  - Unknown action name returns failure with message
- [x] 3.2 Create `tests/unit/Controller/MeetingControllerTest.php` with ≥3 test methods covering:
  - Valid action returns HTTP 200 with meeting data
  - Invalid transition returns HTTP 422
  - Missing action returns HTTP 422

## 4. Frontend — MeetingLifecycle Component

- [x] 4.1 Create `src/components/MeetingLifecycle.vue` — receives `meeting` object as prop. Computes the list of valid actions for the current `lifecycle` value using the same transition table. Renders one `NcButton` per valid action. On click, sends `POST /api/meetings/{id}/lifecycle` and emits `lifecycle-updated` with the new meeting object.
- [x] 4.2 Add `@spec openspec/changes/p2-meeting-management/tasks.md#task-4.1` JSDoc comment to the component.

## 5. Frontend — MeetingDetail update

- [x] 5.1 Import and render `MeetingLifecycle` inside `MeetingDetail.vue` in the `#properties` slot below the `CnDetailCard`. Pass `object` as the `meeting` prop. On `lifecycle-updated` event, call `objectStore.fetchObject('meeting', id)` to refresh the view.

## 6. Verification

- [x] 6.1 Run `composer check:strict` — all PHP quality checks pass
- [x] 6.2 Run `npm run lint` — ESLint passes
- [x] 6.3 Verify lifecycle transition: schedule a draft meeting via UI, open it, pause it, resume it, close it — all state changes persist
- [x] 6.4 Verify invalid transition (e.g. closing an already-closed meeting) returns 422 and shows no UI button
