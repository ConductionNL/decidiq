# Meeting broadcast

You stream a public meeting live, pause it for a closed session and stop it at the end. Residents watch without an account. Afterwards you release subtitles made from the meeting's transcript.

The Broadcast widget sits on the meeting page. Only the chair and the secretary of the meeting see its buttons. Everyone else sees one line saying who runs the broadcast.

## Connect a streaming service

Decidiq does not stream video itself. A streaming service does, linked through integriq.

1. Open integriq and add a source from the `streaming` template.
2. Link it to decidiq under the connection `streaming`.
3. Open a meeting. The widget now shows its buttons.

Without a linked source the widget says "No streaming service is connected" and shows no buttons.

## Run a test

Press "Run a test broadcast" before the meeting. The service sends a staff preview address. Only staff see it; residents see nothing yet.

Watch the preview, then record what you saw. Choose "Picture and sound were fine" or "There were problems", add a note and press "Save the test result". The widget keeps the result, your name and the time.

## Go live

Press "Go live". This works only for a meeting marked public. The service sends a public player address and the broadcast appears on the portal.

Decidiq also asks the service for live captions. When the service cannot make them, the widget says so. The broadcast goes live either way.

## Pause for a closed session

Press "Pause for a closed session" when the meeting goes behind closed doors. The public window closes at that moment. Press "Resume" to open a new one.

Decidiq records each public window in seconds from the meeting's opening. Those windows decide what the subtitles may hold.

## Stop

Press "Stop the broadcast" at the end. The service sends the recording address. A stopped broadcast cannot start again.

## Release subtitles

Subtitles come from the meeting's transcript. Align the transcript with the agenda first.

1. Press "Make subtitles" once the broadcast has ended.
2. Decidiq writes `captions-nl.vtt` to the meeting's `Broadcast` folder.
3. Open the file and check it.
4. Press "Release subtitles".

The file holds only what was said in a public window. Speech from a closed session never reaches it, and speaker labels are left out. Each line is timed to the recording, with the paused time left out.

Release works only for a public meeting whose broadcast has ended. Decidiq creates a read-only public link to the file and records who released it and when. It also asks the streaming service to attach the subtitles to the recording.

The transcript itself stays confidential. Releasing subtitles does not make the transcript or the recording publishable. The retention job deletes the recording and the transcript as before and leaves released subtitles in place.

## What residents see

The portal's "Live and recent meetings" list shows each broadcast once its publication date has passed. A row shows the title, the body, the date, the state, the player, the recording and released subtitles. The staff preview, the test result and the public windows never reach the portal.
