---
sidebar_position: 2
title: Manage members and roles
description: Add participants to a governance body, assign roles (chair, secretary, voting member), and handle proxies and party affiliations.
---

# Manage members and roles

Members are the people in a governance body; their **role** is what Decidiq checks before letting them act. This page covers adding participants, assigning roles, and the details that affect votes — voting rights, party affiliation, proxies.

## Goal

By the end you will have a governance body whose members are set up with the right roles, so meeting scheduling, motion submission, vote operation, and minutes signing all land on the right people.

## Prerequisites

- A governance body to add members to (see [Configure a governance workflow](01-configure-workflow.md)).
- Admin, or the chair of the body — both can manage that body's membership.
- The list of people, their roles, and (if relevant) their party affiliations and voting rights.

## Steps

1. Open the governance body and go to its **Members** tab. It lists the current members with their role; click **Add member**.

   ![Members tab on a governance body](/screenshots/tutorials/admin/02-manage-members-01.png)

2. Add a participant — link a Nextcloud account (or record an external participant with a **display name** and **email**), set the **role** (chair, vice-chair, secretary, voting member, observer, …), and the **party** affiliation if the body tracks one. Save.

   ![Add member dialog](/screenshots/tutorials/admin/02-manage-members-02.png)

3. Repeat for the rest of the body. The role each person holds is what the app enforces — only a chair opens and closes voting rounds, only a secretary drives the minutes lifecycle, observers see but don't vote.

   ![Members list with assigned roles](/screenshots/tutorials/admin/02-manage-members-03.png)

4. Manage participants more broadly under **Participants** in the navigation — a person can sit on more than one body, each with its own role. The participant detail page shows their roles and an **Audit trail** of membership changes.

   ![Participants list](/screenshots/tutorials/admin/02-manage-members-04.png)

5. For a meeting, the chair (or whoever has the right) confirms who is **present**; an absent voting member can have a **proxy** assigned for that meeting's votes, if the body allows proxies. Proxy limits and whether proxies are allowed at all come from the body's workflow.

   ![Meeting participants with a proxy assigned](/screenshots/tutorials/admin/02-manage-members-05.png)

## Verification

The body's **Members** tab lists everyone with the role you set, a chair can open a voting round (and a non-chair can't), a secretary can submit minutes for approval, and proxy assignments only stick where the body's workflow permits them. Membership changes show in the **Audit trail**.

## Common issues

| Symptom | Fix |
|---|---|
| A member can't do something you expected | Check their **role** on this body — rights are role-based and per-body, so a chair on one body is just a member on another. |
| Can't assign a proxy | The body must allow proxies, the proxy must be a present member of the meeting, and one member can hold only a limited number of proxies. |
| The same person appears twice | They're a member of two bodies — that's expected; each membership is separate, with its own role. |
| External participant has no account link | That's fine — Decidiq records external participants by display name and email; they just can't log in to act themselves. |

## Reference

- [Configure a governance workflow](01-configure-workflow.md) — quorum, majority, proxy rules that interact with roles.
- [Run a vote](../user/05-run-vote.md) — where presence, voting rights, and proxies come into play.
- [Take and publish the minutes](../user/06-take-minutes.md) — who must be a signer.
