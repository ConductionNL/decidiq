---
kind: code
---

# Proposal: decision-outcome-reaches-the-subscriber

## Summary

Another system can subscribe to a decision (`POST /api/v1/decisions/{id}/subscriptions`), and decidiq stores its callback URL on the decision. Two things stop the outcome from ever reaching that URL. The `outcomeEmitted` notification rule uses the `in` operator, which OpenRegister does not support on updated triggers, so the rule has never fired. And its only channel is a Nextcloud notification, while OpenRegister's webhook channel takes one fixed URL per rule rather than the URL stored on each decision. This change makes the rule fire and delivers the outcome to the subscriber.

## Motivation

REQ-DCDH-004 promises push delivery of the outcome envelope. Read from code on 30 October 2026: `lib/Settings/decidesk_register.json` declares `outcomeEmitted` with `operator: in` on `lifecycle` (its own `_note` says it has never fired), and `channels: [nc-notification]`. OpenRegister's `AnnotationNotificationDispatcher::fieldChangeConditionMatches()` handles only `changed` and `equals`, and `emitWebhook()` reads a static `webhook.url`. Co-installed apps still get the in-process `DecisionConcludedEvent`; external systems get nothing.

## Scope

### In scope

1. Split `outcomeEmitted` into one `equals` rule per terminal lifecycle value (`decided`, `enacted`, `withdrawn`), so it fires today.
2. Deliver the outcome envelope to the decision's own `outcomeCallbackUrl` through OpenRegister's webhook channel, which needs that channel to take its URL from an object field. That part is an OpenRegister change.
3. Tests on both sides.

### Out of scope

- Retries, signing and dead-letter handling beyond what OpenRegister's webhook pipeline already gives a persistent webhook.

## Matrix rows

- plt-18: Let another system subscribe to changes in a decision.
