---
kind: code
depends_on: [agenda-change-notices-reach-members]
---

# Proposal: publication-subscriptions-and-daily-digest

## Summary

Council members, journalists and residents want to know when a new agenda, a changed agenda or new papers come out for the bodies they follow, without checking every day. This change lets anyone subscribe per governance body to agendas, papers, decisions and minutes, and receive the news straight away, as a daily digest, or as a weekly one. Members subscribe in decidiq and get it by email and in the bell. Residents subscribe on the portal and get it in their portal inbox, which portaliq mails on.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### pub-16, subscribe to new and changed agendas and documents and get the changes by email, also as a daily digest

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "Nobody can subscribe to new or changed agendas or documents, and there is no daily digest. The nearest thing is a declared 'new meeting scheduled' Nextcloud notification that OpenRegister has to send, and email goes out only for a few events such as a vote opening."

Demand: origin `tender`, https://www.tenderned.nl/aankondigingen/overzicht/408309

Competitor cells rated yes:

- notubiz: "https://www.kennisbank.notubiz.nl/handleidingen/attenderingen-instellen states alert profiles on new or changed items by email, daily, weekly or monthly; https://www.kennisbank.notubiz.nl/kennisclips/openbaar-publiek-account-instellen states residents can set them too"
- ibabs: "https://support.ibabs.com/docs/geavanceerde-mail-notificaties.md states each iBabs user chooses per agendatype and overview between a direct mail on publication or a daily bulk mail"

## Why

The tender behind this row (TenderNed 408309) asks for subscriptions to new and changed agendas and documents by email, also as a daily digest. NotuBiz alert profiles and iBabs' direct or daily bulk mail do exactly that. decidiq has the pieces that notice a change (`AgendaService::notifyAgendaChanged()` records every change to a published agenda; `PublicationService::publish()` publishes decisions, agendas and minutes; `agenda-office-files-to-pdf` observes papers arriving) and a per-person delivery channel (`NotificationPreferenceService::dispatch()`, repaired by `agenda-change-notices-reach-members`). What is missing is the subscription and the digest.

For residents, ADR-046 makes portaliq the only place that knows who they are and how to reach them. decidiq writes the digest into the resident's portal inbox (the `citizenNotifications` collection decidiq already contributes), and portaliq sends its existing "new message in your portal" mail. decidiq never holds a resident's email address.

## What changes

1. A new `PublicationSubscription`: who subscribes (a Nextcloud user, or a portal subject), which bodies, which kinds (agendas, papers, decisions, minutes), and how often (immediately, daily, weekly).
2. Each time an agenda is published or changed, a paper is added to a published agenda, or a decision or minutes are published, decidiq records one `PublicationEvent`.
3. A background job sends each subscriber the events that match since their last message: immediately (at most every 15 minutes), daily at 07:00, or weekly on Monday.
4. Members manage subscriptions on their user settings page and receive email or bell notices per their delivery choice. Residents subscribe through a portal action and receive a message in their portal inbox.
5. Residents only ever receive events about published items; members also receive events about agendas and papers they may read before publication.

## Out of scope

- RSS feeds, which `ori-adoption` specifies (row pub-10).
- Alerts on search terms ("tell me when 'windmolen' appears"). Subscriptions are per body and kind.
- Push notifications to phones.

## Risks

- A subscriber could learn of a confidential paper through its title. An event is delivered only to a subscriber who may read its object at sending time; for residents only published objects count.
- A busy evening makes many events. The daily and weekly digests group them per body and meeting in one message; immediate delivery waits at least 15 minutes to batch an editing session.
