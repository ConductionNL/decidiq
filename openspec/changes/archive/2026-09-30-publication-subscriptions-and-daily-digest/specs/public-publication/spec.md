# public-publication Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [publication-subscriptions-and-daily-digest](../../) (this delta)

## Purpose

Lets members and residents subscribe to new and changed agendas, papers, decisions and minutes of the bodies they follow, and receive them straight away or as a daily or weekly digest. Closes decidiq matrix row pub-16.

**Standards**: ADR-046 (portal contribution contract), Schema.org `Message`.

## ADDED Requirements

### Requirement: REQ-PSD-001 Anyone can subscribe per body and kind, and choose how often

A member SHALL be able to subscribe on the user settings page, and a resident through the portal, to one or more governance bodies (or all), to the kinds agenda, paper, decision and minutes, with the frequency immediately, daily or weekly. A subscriber SHALL see and remove only their own subscriptions.

#### Scenario: A journalist follows the council
- GIVEN a resident signed in on the portal
- WHEN she subscribes to the municipal council's agendas and decisions, daily
- THEN her subscription is listed under her subscriptions on the portal

#### Scenario: A member follows two committees
- GIVEN council member Pieter
- WHEN he subscribes on his settings page to the committees Ruimte and Bestuur, agendas and papers, immediately
- THEN the subscription is saved and he can remove it there

### Requirement: REQ-PSD-002 Agendas, papers, decisions and minutes are recorded as events

The app SHALL record an event when an agenda is published or changed, when a paper is added to a meeting whose agenda is published, and when a decision or minutes are published, with the body, the meeting, a one-line summary and whether residents may hear of it.

#### Scenario: A changed agenda
- GIVEN the published agenda of the council meeting of 14 October
- WHEN the griffier adds item 4, Motie vreemd aan de orde
- THEN an event "Agenda changed: item 4 added" is recorded for that meeting and body

### Requirement: REQ-PSD-003 Subscribers receive matching events immediately, daily or weekly

The app SHALL send each subscriber the events matching their bodies and kinds since their last message: immediate subscriptions after a 15 minute wait that groups an editing session, daily at 07:00, weekly on Monday at 07:00. One message SHALL group the events per body and meeting with links. A member SHALL receive it through his own delivery choice, and only events about objects he may read at sending time.

#### Scenario: Pieter's morning digest
- GIVEN Pieter subscribes daily and five matching events happened since yesterday's digest
- WHEN the 07:00 run happens
- THEN he receives one email listing the five events under their meetings

#### Scenario: A paper Pieter may not read
- GIVEN one of the events is about a paper under a confidentiality restriction Pieter is outside
- WHEN his digest is sent
- THEN that event is not in it

### Requirement: REQ-PSD-004 Residents subscribe on the portal and receive published news only

For a resident, the app SHALL deliver the digest as a message in the resident's portal inbox, which portaliq mails on, and SHALL include only events about published items. decidiq SHALL not store a resident's email address.

#### Scenario: The journalist's digest
- GIVEN the journalist's daily subscription, one event about an unpublished agenda and two about published decisions
- WHEN the daily run happens
- THEN her portal inbox receives one message listing the two decisions
