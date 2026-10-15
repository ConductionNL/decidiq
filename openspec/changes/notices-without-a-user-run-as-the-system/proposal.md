# Proposal: notices-without-a-user-run-as-the-system

## Summary

The Zuiddrecht rehearsal logged "Decidiq: agenda change notice failed" 40 times while `occ openregister:example-set:install` wrote agenda items, and "Decidiq: meeting reminder skipped" from every cron run, both with "User 'Anonymous' does not have permission to 'update' objects in schema 'Meeting'". Both notices stamp the meeting (the agenda version and who was told, or the reminder sent), and with nobody signed in OpenRegister's RBAC refuses that update, so the notice is lost. From cron the meeting reminder could never be sent at all.

No decidiq spec says an example-set install must stay silent, so the notices are kept and made to work.

## What changes

- `SystemWhenNobodySignedIn` runs an operation through `ObjectServiceInterface::runAsSystem()` when no user is signed in, and as the signed-in user otherwise (the pattern of dossiq #3965).
- `AgendaItemChangeListener` sends the agenda change notice through it.
- `MeetingReminderService::run()` runs its pass through it.

A request with a signed-in user is unchanged: the notice keeps that user's rights.
