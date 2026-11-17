# Microsoft 365: Outlook and Teams

Members see decidiq's meetings in Outlook or on their phone. A digital or hybrid meeting can have a Microsoft Teams meeting, and members join it from decidiq.

## Your meetings in Outlook

Decidiq gives you a calendar address. Outlook, a phone and most calendar apps read it.

1. Open decidiq's personal settings and go to **Calendar subscription**.
2. Press **Subscribe in Outlook or on your phone**. An address ending in `.ics` appears.
3. Press **Copy address**.
4. In Outlook, choose **Add calendar**, then **Subscribe from web**, and paste the address.

The calendar shows every meeting you may read, with its title, start, end and location. A meeting without an end time is shown as two hours long.

### Moved and cancelled meetings

The calendar is made fresh each time Outlook reads it, so a moved or cancelled meeting is right the next time. Outlook decides how often it reads a subscribed calendar. That is often every few hours, so a change can take a while to show up.

### Stop a subscription

Each subscription shows the day you made it. Press **Stop this subscription** to end it. From then on the address answers nothing, and Outlook stops showing the meetings. Stop a subscription when you no longer use it, or when the address may have been shared by mistake.

Decidiq only lets the calendar read your meetings. It never writes into your Outlook calendar.

## A Teams meeting on a digital or hybrid meeting

The Teams join address is stored apart from the location, so a hybrid meeting keeps the room's address in **Location**.

### Create the Teams meeting

The chair or the secretary of the meeting does this.

1. An administrator adds a source in integriq from the `microsoft-365` template and links it to decidiq under the connection `microsoft-365`.
2. Open a digital or hybrid meeting. The **Microsoft Teams** widget shows **Create a Teams meeting**.
3. Press it. Decidiq asks Microsoft 365 for a Teams meeting with the meeting's title, start and end. The join address, your name and the time are saved on the meeting.

A meeting gets one Teams meeting. An in-person meeting gets none.

### Paste a join address

Without a linked Microsoft 365 source there is no create button. You can still make the Teams meeting in Teams yourself and paste its join address in the **Teams meeting** field under **Planning**. Only addresses that start with `https://teams.microsoft.com/` are accepted.

### Join

When a meeting has a Teams join address, members see **Join in Microsoft Teams** on the meeting page and on the live meeting page. It opens Teams in a new tab.

## Signing in with a Microsoft account

Signing in with a Microsoft account (Entra ID) and syncing users is a Nextcloud setting, not a decidiq one. Install and set up Nextcloud's **OpenID Connect user backend** (`user_oidc`) app with your Entra ID tenant. It works for every app on the Nextcloud, decidiq included, and decidiq needs no extra setup.

## What is not included

- OneDrive and SharePoint documents.
- A decidiq app inside Teams, or an Office add-in.
- Writing meetings into members' calendars. The calendar address is read-only.
