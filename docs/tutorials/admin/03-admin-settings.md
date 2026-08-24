---
sidebar_position: 3
title: Manage Decidiq settings
description: Open the Decidiq settings, import the register and schemas, check the version, and configure the ORI endpoint and email voting.
---

# Manage Decidiq settings

Decidiq's settings page does three jobs: it tells you the installed version, it maps the app's object types onto an OpenRegister register and schemas (this is the import that makes everything else work), and it holds the advanced options — the ORI endpoint for publishing voting results, and the email-reply voting toggle.

## Goal

By the end you will have confirmed the Decidiq version, run (or re-run) the register import so all 24 object types are configured, and set the ORI endpoint and email-voting option to match your deployment.

## Prerequisites

- Admin on the Nextcloud instance (or a Decidiq admin), since this changes how the whole app is wired.
- The **OpenRegister** app installed and enabled — the register import has nothing to import into otherwise.
- For ORI publication: the URL of your ORI (Open Raadsinformatie / decision-publication) endpoint.

## Steps

1. Open **Settings** from the Decidiq navigation. The page has three sections — **Version**, **Registers**, **Advanced**.

   ![Decidiq settings page](/screenshots/tutorials/admin/03-admin-settings-01.png)

2. **Version** — confirms the installed Decidiq version and shows an "Up to date" indicator. Nothing to change here; it's the at-a-glance check that the app installed cleanly.

   ![Version section of settings](/screenshots/tutorials/admin/03-admin-settings-02.png)

3. **Registers** — the *Register Configuration* widget shows how many of Decidiq's 24 object types are mapped (e.g. *0/24 configured* on a broken or fresh install, *24/24* once imported). Pick the target register, then click **Re-import configuration** to (re)create the register, all schemas, and the mappings.

   ![Register configuration widget](/screenshots/tutorials/admin/03-admin-settings-03.png)

4. After the import, the count should read *24/24 configured* and the Decidiq lists (Meetings, Motions, …) and their **Add Item** forms work. The same import also runs automatically on app install/upgrade — the button is for fixing a partial import.

   ![Register configuration after import](/screenshots/tutorials/admin/03-admin-settings-04.png)

5. **Advanced** — set the **ORI endpoint** (the URL Decidiq pushes published voting results to) and toggle **email voting** on if you want absent members to be able to vote by replying to a ballot email. Save.

   ![Advanced settings — ORI endpoint and email voting](/screenshots/tutorials/admin/03-admin-settings-05.png)

## Verification

The **Version** section shows the installed version with "Up to date", the **Registers** widget reads *24/24 configured*, a list view's **Add Item** opens a dialog with real form fields (not an empty modal), and the **Advanced** values you saved persist on reload.

## Common issues

| Symptom | Fix |
|---|---|
| Register widget stuck at *0/24 configured* even after clicking Re-import | The import is failing server-side — check the Nextcloud log for the Decidiq configuration error; a stale OpenRegister / Decidiq version pair can mismatch the import API. Re-run after both apps are on compatible versions. |
| **Add Item** dialogs are empty across the app | Same root cause — the schemas aren't mapped; fix the register import first, everything else follows. |
| ORI publication does nothing | The **ORI endpoint** field is empty or wrong — publishing a voting result only pushes to ORI when a valid endpoint is set. |
| Email votes never count | **Email voting** must be enabled here *and* the member must reply from their registered address within the voting round's window. |
| Settings page itself shows an OpenRegister error | OpenRegister isn't installed/enabled — install it, then reload Decidiq. |

## Reference

- [Open Decidiq for the first time](../user/01-first-launch.md) — the user-facing check that the import worked.
- [Configure a governance workflow](01-configure-workflow.md) — the first thing to set up once the register is imported.
- [Run a vote](../user/05-run-vote.md) — where the ORI endpoint and email-voting settings are used.
