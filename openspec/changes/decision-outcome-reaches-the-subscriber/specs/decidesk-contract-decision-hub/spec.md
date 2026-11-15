## ADDED Requirements

### Requirement: REQ-DCDH-010 The outcome reaches the subscriber's callback

When a decision with a stored `outcomeCallbackUrl` reaches `decided`, `enacted` or `withdrawn`, the outcome envelope SHALL be posted to that URL once per transition, through OpenRegister's notification webhook channel. The notification rule SHALL use only conditions the OpenRegister dispatcher supports on updated triggers. A decision without a callback URL SHALL send nothing outbound.

#### Scenario: A subscribed system receives the outcome

- GIVEN shillinq subscribed to a contract decision with its registry callback URL
- WHEN the decision moves to `decided`
- THEN one POST with the outcome envelope reaches shillinq's callback URL
- AND moving the same decision to `enacted` later sends a second POST

#### Scenario: A decision nobody subscribed to

- GIVEN a decision without `outcomeCallbackUrl`
- WHEN it moves to `decided`
- THEN no outbound call is made
