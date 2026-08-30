---
sidebar_position: 1
title: Configure a governance workflow
description: Create a governance body and set the rules — quorum, majority, co-signature threshold, who may do what — that drive its meetings.
---

# Configure a governance workflow

A *governance body* in Decidiq is the thing that meets and decides — a board, a council, a general assembly, a working group. Its workflow is the set of rules Decidiq enforces for its meetings: quorum, majority, co-signature threshold, and which roles may schedule meetings, submit motions, and operate votes.

## Goal

By the end you will have a governance body in Decidiq with a type, a domain, term dates, and the workflow rules that its meetings, motions, and votes will follow.

## Prerequisites

- The **Decidiq** and **OpenRegister** apps installed and enabled, with the Decidesk register imported (see [Manage Decidiq settings](03-admin-settings.md)).
- Admin (or whoever your organisation appoints) — creating governance bodies and setting workflow rules is an administrative act.
- A clear picture of the body's actual rules of order (quorum, majority threshold, co-signature requirement, term length).

## Steps

1. Go to **Governance bodies** (under the Decidiq navigation) and click **Add Item**. The *Create Item* dialog opens.

   ![Create governance body dialog](/screenshots/tutorials/admin/01-configure-workflow-01.png)

2. Fill in the body — **name**, **body type** (board, council, ALV/general assembly, committee, …), **domain** (the area it governs), and **term start / term end**. Click **Create**.

   ![Governance body fields filled in](/screenshots/tutorials/admin/01-configure-workflow-02.png)

3. Open the body. Its sidebar has an **Overview**, a **Members** tab, and an **Audit trail**. The Overview is where the workflow rules live — **quorum**, **majority rule** (simple, absolute, two-thirds, …), and the **co-signature threshold** for motions.

   ![Governance body detail with workflow rules](/screenshots/tutorials/admin/01-configure-workflow-03.png)

4. Set the rules to match the body's rules of order. These feed straight into the app: the quorum is checked when a voting round opens, the majority rule decides whether a motion carries, and the co-signature threshold gates a motion's admissibility.

   ![Workflow rules set on the body](/screenshots/tutorials/admin/01-configure-workflow-04.png)

5. Add members on the **Members** tab and give each a role (see [Manage members and roles](02-manage-members.md)) — roles are what let someone schedule a meeting, submit a motion, or operate a vote for this body.

   ![Members tab on the governance body](/screenshots/tutorials/admin/01-configure-workflow-05.png)

## Verification

The body shows under **Governance bodies** with its type and domain, its Overview shows the quorum / majority / co-signature settings you entered, and a test meeting created against the body enforces them (e.g. opening a voting round flags quorum, a motion needs the threshold of co-signatures). The **Audit trail** records the body's creation and any rule changes.

## Common issues

| Symptom | Fix |
|---|---|
| **Add Item** opens an empty dialog | The `governance-body` schema isn't imported — re-run **Settings → Registers → Re-import configuration** (see [Manage Decidiq settings](03-admin-settings.md)). |
| Motions on this body never need co-signatures | The co-signature threshold is 0 — set it to the number the body's rules require. |
| Quorum warning never appears | Quorum is unset or 0 — set the body's quorum so the check has something to compare against. |
| A member can't schedule a meeting for the body | They don't have a role that grants meeting-scheduling rights — adjust their role on the **Members** tab. |

## Reference

- [Manage members and roles](02-manage-members.md) — assign chair / voting rights / secretary on this body.
- [Manage Decidiq settings](03-admin-settings.md) — the register import these schemas depend on.
- [Schedule a meeting and build the agenda](../user/02-schedule-meeting.md) — what a member does once the body exists.
