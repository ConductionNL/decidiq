---
sidebar_position: 8
title: Ask the AI companion about a meeting
description: Use the Nextcloud AI Chat Companion to query Decidiq — meetings, action items, decisions — in plain language.
---

# Ask the AI companion about a meeting

Decidiq exposes its governance data to the Nextcloud AI Chat Companion, so you can ask "what action items are due this week?" or "summarise the last council meeting" instead of clicking through lists.

## Goal

By the end you will know how to open the AI companion, ask it a Decidiq question, and read the answer — including the source objects it cites.

## Prerequisites

- The Nextcloud **AI Chat Companion** available on your instance (hydra ADR-034), with a model configured.
- The **OpenRegister** app at a version that publishes the `IMcpToolProvider` interface — Decidiq registers its tools automatically against it, no admin step.
- Some Decidiq data to ask about (meetings, decisions, action items).

## Steps

1. Open the AI Chat Companion (the chat panel in the Nextcloud sidebar, or the companion app). It greets you with a chat box.

   ![AI companion chat panel](/screenshots/tutorials/user/08-ai-companion-01.png)

2. Ask a Decidiq question in plain language — for example *"What action items are open and due this week?"* The companion calls Decidiq's `action-items` tool behind the scenes.

   ![Asking the companion about action items](/screenshots/tutorials/user/08-ai-companion-02.png)

3. Read the answer. It lists the items with assignees and due dates, and — because every Decidiq tool returns a `sources[]` array — it cites which objects it used, so you can open them directly.

   ![Companion answer with cited sources](/screenshots/tutorials/user/08-ai-companion-03.png)

4. Follow up — *"summarise the last council meeting"*, *"which motions are still admissible but not voted?"*, *"start the next board meeting"*. Each call is argument-validated and authorisation-checked against the objects before it runs, so the companion only ever shows you what you're allowed to see.

   ![Follow-up question to the companion](/screenshots/tutorials/user/08-ai-companion-04.png)

## Verification

The companion returns a relevant answer (not "I don't have access to that"), the answer cites Decidiq objects you can click through to, and an action that changes state (e.g. "start the meeting") only succeeds if you have the right role.

## Common issues

| Symptom | Fix |
|---|---|
| Companion says it can't reach Decidiq | OpenRegister must be at the release that publishes `IMcpToolProvider`; if it isn't, the tools are simply unavailable and the rest of Decidiq still works. |
| Companion answer omits sources | Re-ask — every Decidiq tool returns sources; an answer without them usually means the model didn't actually call the tool. Be specific ("list the open action items"). |
| "Not authorised" on an action | The authorisation check runs before any business logic — you don't have the role that action requires for that body. |
| `/api/chat/health` 404 in the browser console | Harmless — that's the companion probing whether the chat back end is wired up; Decidiq's own pages don't depend on it. |

## Reference

- [MCP Tools (AI Chat Companion integration)](../../features/mcp-tools.md) — the five tools Decidiq exposes and how each call is validated and authorised.
- [Track decisions and action items](07-track-decisions.md) — the data the companion answers from.
