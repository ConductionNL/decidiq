# Tasks: decision-outcome-reaches-the-subscriber

## Implementation Tasks

### Task 1: Make the outcome rule fire
- **spec_ref**: `openspec/changes/decision-outcome-reaches-the-subscriber/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-010-the-outcome-reaches-the-subscribers-callback`
- **files**: `lib/Settings/decidesk_register.json` (`outcomeEmitted` split into `outcomeDecided`, `outcomeEnacted`, `outcomeWithdrawn`, each `operator: equals` on `lifecycle`), `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN no notification rule on an updated trigger uses `in`
  - GIVEN a decision moving to `decided` WHEN saved THEN exactly one of the three rules matches
- [ ] Implement
- [ ] Test

### Task 2: A webhook URL taken from the object (OpenRegister)
- **spec_ref**: `openspec/changes/decision-outcome-reaches-the-subscriber/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-010-the-outcome-reaches-the-subscribers-callback`
- **files**: ConductionNL/openregister `lib/Service/Notification/AnnotationNotificationDispatcher.php` (`emitWebhook()` accepts `webhook.urlField` and reads the URL from that property of the object, validated with `FILTER_VALIDATE_URL`), its unit test, and an openregister OpenSpec change for the dialect addition
- **acceptance_criteria**:
  - GIVEN a rule with `webhook.urlField: outcomeCallbackUrl` WHEN it fires on an object with that field THEN the POST goes to the object's URL
  - GIVEN the field is empty or malformed WHEN it fires THEN nothing is sent and the reason is logged
- [ ] Implement
- [ ] Test

### Task 3: Deliver to the subscriber
- **spec_ref**: `openspec/changes/decision-outcome-reaches-the-subscriber/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-010-the-outcome-reaches-the-subscribers-callback`
- **files**: `lib/Settings/decidesk_register.json` (the three rules gain `channels: [nc-notification, webhook]` and `webhook.urlField: outcomeCallbackUrl`), `lib/Service/DecisionIntegrationService.php` (`registerOutcomeCallback()` docblock names the rules), `tests/Unit/Service/DecisionIntegrationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN Task 2 merged in OpenRegister WHEN a subscribed decision concludes THEN the outcome envelope reaches its callback URL once
  - GIVEN a decision without a callback URL WHEN it concludes THEN no outbound call is made
- **depends_on**: Task 2
- [ ] Implement
- [ ] Test
