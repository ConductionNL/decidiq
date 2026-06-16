# Tasks: Decidesk web-push notification rules (title-vs-body model)

## 1. Meeting starting-soon rule

- [x] 1.1 Add a `meetingStartingSoon` rule to the Meeting `x-openregister-notifications` block in
  `lib/Settings/decidesk_register.json`: trigger `scheduled` with `intervalSec: 900` and
  `filter {lifecycle: "scheduled"}`; `originApp: "decidesk"`; `channels: ["nc-notification",
  "web-push"]`; recipients `[{kind:"object-acl", permission:"read"}]`; `subject`
  `{nl:"Vergadering begint binnenkort: {{title}}", en:"Meeting starts soon: {{title}}"}`; `message`
  `{nl:"{{title}} begint in Decidesk. Openen?", en:"{{title}} is starting in Decidesk. Open it?"}`;
  one primary `action` `{label:{nl:"Vergadering openen", en:"Open meeting"}, target:{kind:"object-detail"}}`.
  Preserve `meetingScheduled` and `meetingReminder` unchanged. (REQ-DPN-001)

## 2. Action item assigned-to-you rule

- [x] 2.1 Add an `actionItemAssignedToYou` rule to the ActionItem `x-openregister-notifications` block:
  trigger `created`; `originApp: "decidesk"`; `channels: ["nc-notification", "web-push"]`; recipients
  `[{kind:"field", field:"assignee"}]`; `subject` `{nl:"Aan jou toegewezen: {{title}}",
  en:"Assigned to you: {{title}}"}`; `message` `{nl:"Dit actiepunt staat in Decidesk. Openen?",
  en:"This action item is in Decidesk. Open it?"}`; one primary `action`
  `{label:{nl:"Actiepunt openen", en:"Open action item"}, target:{kind:"object-detail"}}`. Preserve
  `actionAssigned` and `actionOverdue` unchanged. Note in the proposal that `assignee` is free-text
  today. (REQ-DPN-002)

## 3. Decision vote-requested rule

- [x] 3.1 Add a `voteRequested` rule to the Decision `x-openregister-notifications` block: trigger
  `updated` with `condition {field:"lifecycle", operator:"equals", value:"voting"}`; `originApp:
  "decidesk"`; `channels: ["nc-notification", "web-push"]`; recipients `[{kind:"object-acl",
  permission:"read"}]`; `subject` `{nl:"Jouw stem is gevraagd: {{title}}", en:"Your vote is requested:
  {{title}}"}`; `message` `{nl:"Dit besluit staat in Decidesk. Openen?", en:"This decision is in
  Decidesk. Open it?"}`; one primary `action` `{label:{nl:"Besluit openen", en:"Open decision"},
  target:{kind:"object-detail"}}`. Preserve the five existing Decision rules unchanged. (REQ-DPN-003)

## 4. Validate

- [x] 4.1 `php -r` JSON-parse `lib/Settings/decidesk_register.json` and confirm all three schemas
  retain their pre-existing rules plus the new one.
- [x] 4.2 `openspec validate "decidesk-push-notifications" --type change --strict`; fix any errors.
