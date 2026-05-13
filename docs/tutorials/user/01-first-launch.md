---
sidebar_position: 1
title: Open Decidesk for the first time
description: Open Decidesk, find your way around the navigation, and confirm the OpenRegister back end is connected.
---

# Open Decidesk for the first time

A first look at Decidesk — where the app lives, what the navigation gives you, and how to tell it is wired up to OpenRegister.

## Goal

By the end you will have opened the Decidesk app, recognised the dashboard and the left-hand navigation, and confirmed that the OpenRegister-backed lists (Meetings, Motions, Decisions, …) load.

## Prerequisites

- A Nextcloud account on an instance where the **Decidesk** app is installed and enabled.
- The **OpenRegister** app installed and enabled — Decidesk stores everything (meetings, motions, votes, minutes) in OpenRegister, so it is a hard dependency.
- The Decidesk register and its schemas imported. An admin runs this once from **Settings → Registers → Re-import configuration** (see [Manage Decidesk settings](../admin/03-admin-settings.md)).

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Decidesk**. You land on the dashboard.

   ![Decidesk dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. Read the dashboard tiles — *Minutes awaiting approval*, *Published decisions*, *Open action items*. On a fresh install they read `0`; they fill in as work moves through the app.

   ![Dashboard stat tiles](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. The entries map one-to-one onto the things Decidesk tracks: **Meetings**, **Motions**, **Decisions**, **Action items**, **Minutes**, **Tasks**, **Workspaces**, **Comments**, **Email links**, **Engagement**. Below the divider sit **Settings** and **Features & roadmap**.

   ![Decidesk navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Meetings**. The list view opens with a *Cards / Table* toggle, an **Add Item** button, and a search sidebar. An empty install shows *No items found* — expected until someone schedules the first meeting.

   ![Meetings list, empty state](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the Decidesk dashboard renders without an error banner, the left navigation lists the entries above, and clicking through to **Meetings** (or any other list) shows either rows or a clean *No items found* state — not a load error.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload Decidesk. |
| Lists load but **Add Item** opens a modal with no form fields | The Decidesk register import is incomplete — an admin re-runs **Settings → Registers → Re-import configuration**. |
| Decidesk is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it (and check it is not restricted to a group you are not in). |

## Reference

- [MCP Tools (AI Chat Companion integration)](../../features/mcp-tools.md) — how the AI companion reaches Decidesk's data.
- [Manage Decidesk settings](../admin/03-admin-settings.md) — register import, ORI endpoint, email voting.
