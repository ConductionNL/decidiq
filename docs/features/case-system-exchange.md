# The case system

A raadsvoorstel starts as a case in the organisation's case system, and after
the meeting the decision and the minutes belong back in that case. This page
shows how to connect the case system, link an agenda item to its case, fetch
the case's documents, and send the meeting file back after the minutes are
approved.

## Connect the case system

decidiq reaches the case system through integriq, never directly. integriq
speaks the ZGW APIs (Zaken API and Documenten API) or StUF-ZKN, and maps
decidiq's kinds of document onto your organisation's document and case types.

1. Open integriq and go to **Connections**.
2. Find the decidiq connection **Case system** and link a source to it. The
   template `zgw-zaken` is offered first.
3. In integriq, map the six kinds of document decidiq sends (agenda, item
   document, decision, decision list, minutes, proof package) and the case
   type for a meeting.

Until a source is linked, decidiq shows no case actions. The agenda item says
"No case system is connected" and every case endpoint answers 409.

## Link an agenda item to its case

1. Open the agenda item, for example "Vaststelling omgevingsvisie".
2. In the **Case** widget, enter the case number, for example Z-2026-00412,
   or the case's address, and choose **Link case**.

decidiq reads the case once. When it exists, the item shows its title and
number, "Omgevingsvisie 2040 (Z-2026-00412)". When it does not, the link is
refused with "Case Z-2026-99999 was not found in the case system".

## Fetch documents from the case

1. On the linked agenda item choose **Fetch documents from the case**.
2. Tick the documents you want and choose **Fetch**.

The documents land in the item's **Documents** widget. A document fetched
before is marked "Fetched" and is never fetched a second time.

## Send the meeting file

Once the minutes are approved, the chair or the secretary opens the minutes,
goes to **Documents** and chooses **Send the meeting file to the case system**.
Before approval the button stays off and the request is refused with "Approve
the minutes before sending the meeting file".

The meeting file holds the published agenda, every item's documents, each
decision, the decision list, the minutes and the proof package. Each item's
decisions go to that item's own case. The whole file goes to one new case for
the meeting.

A document under a confidentiality restriction, on the document, its item or
its decision, is sent marked confidential with its ground, for example
"Gemeentewet artikel 25". integriq maps that to the case system's level of
confidentiality.

An administrator can have the file sent on approval: in **Administration
settings > decidiq**, switch on "Send the meeting file to the case system when
the minutes are approved".

## The decision list

When the minutes reach approved, and again when they are signed, decidiq
writes `Besluitenlijst.pdf` into the meeting folder: every decision with its
outcome and its vote totals, headed with the approval date and the signers.
Without filinq it is saved as HTML, with a note.

## When a document fails

The **Case system** widget on the meeting page lists every fetch and send,
each document with its status: waiting, sent or failed, and the case system's
reason for a failure. **Send again** sends only the failed documents, to the
case created the first time. decidiq never retries a failed document on its
own, so a missing mapping does not flood the case system.
