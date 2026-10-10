# agenda-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-incoming-documents-list](../../) (this delta)

## Purpose

Incoming letters are agenda items of an incoming document type since the documents-as-agenda-items change, but the meeting's routed documents widget still reads the retired schemas and shows nothing, and there is no list of incoming documents waiting for a meeting. Moves decidiq matrix rows age-13 toward built.

## ADDED Requirements

### Requirement: REQ-AIDL-001 Incoming documents reach the agenda

The griffier SHALL see incoming documents that wait for a meeting and put one on a meeting agenda; the meeting page SHALL show the incoming documents on its agenda.

#### Scenario: putting a letter on the agenda
- GIVEN an incoming letter from a resident with no meeting
- WHEN griffier Anna presses Put on agenda and picks the council meeting of 14 October
- THEN the letter leaves the waiting list and the meeting shows it under incoming documents
