/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the Broadcast widget on the meeting page (change
 * live-public-livestream, matrix rows liv-06 liv-10 liv-19 liv-22). The
 * guards, the refusals and the public windows are covered by
 * BroadcastControllerTest and MeetingBroadcastServiceTest; the buttons per
 * state by meetingBroadcast.spec.js.
 *
 * Needs the municipality example set: "Informatieavond windpark Noord" has a
 * planned broadcast whose test found a problem. Runs as the administrator,
 * who passes the chair-or-secretary guard. The tests that need a streaming
 * service skip with a named reason on an instance without one linked to the
 * integriq connection `streaming`, and the reverse.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

/**
 * Open a seeded meeting and wait for the Broadcast widget.
 *
 * @param page The page.
 * @param title The meeting title.
 * @return The widget.
 */
async function openMeeting(page, title: string) {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings`)
	await page.getByText(title).first().click()
	const widget = page.getByTestId('meeting-broadcast')
	await expect(widget).toBeVisible()
	return widget
}

/**
 * Whether a streaming service is linked, read from the widget's own route.
 *
 * @param page The page, on the meeting.
 * @return True when connected.
 */
async function isConnected(page): Promise<boolean> {
	const id = page.url().split('/meetings/')[1]?.split(/[/?#]/)[0] ?? ''
	const response = await page.request.get(
		`${BASE}/index.php/apps/decidiq/api/meetings/${id}/broadcast`,
		{ headers: { 'OCS-APIREQUEST': 'true', requesttoken: '' } },
	)
	if (!response.ok()) return false
	return (await response.json())?.connected === true
}

// @e2e meeting-broadcast::without-a-streaming-service-the-widget-says-so
test('without a streaming service the widget says so and shows no buttons', async ({
	page,
}) => {
	const widget = await openMeeting(page, 'Informatieavond windpark Noord')
	test.skip(
		await isConnected(page),
		'a streaming service is linked on this instance; the unconnected state cannot be shown here',
	)
	await expect(
		widget.getByTestId('meeting-broadcast-not-connected'),
	).toContainText('No streaming service is connected')
	await expect(widget.getByRole('button')).toHaveCount(0)
})

// @e2e meeting-broadcast::the-clerk-records-what-the-test-showed
test('the recorded test result shows its note and who recorded it', async ({
	page,
}) => {
	const widget = await openMeeting(page, 'Informatieavond windpark Noord')
	const result = widget.getByTestId('meeting-broadcast-test-result')
	await expect(result).toContainText('Test had problems, recorded by griffier1')
	await expect(result).toContainText('Microfoon 4 gaf geen geluid, vervangen.')
})

// @e2e meeting-broadcast::the-clerk-checks-picture-and-sound-before-the-meeting
test('the secretary runs a test broadcast and gets the staff preview', async ({
	page,
}) => {
	const widget = await openMeeting(page, 'Informatieavond windpark Noord')
	test.skip(
		!(await isConnected(page)),
		'no streaming service linked on this instance; BroadcastControllerTest covers the test run',
	)
	await widget.getByTestId('meeting-broadcast-test').click()
	await expect(widget.getByTestId('meeting-broadcast-state')).toContainText(
		'A test broadcast is running',
	)
	await expect(widget.getByText('Open the staff preview')).toBeVisible()
})

// @e2e meeting-broadcast::going-live-makes-the-stream-public
// @e2e meeting-broadcast::stopping-ends-the-broadcast
test('the chair goes live, pauses, resumes and stops', async ({ page }) => {
	const widget = await openMeeting(page, 'Informatieavond windpark Noord')
	test.skip(
		!(await isConnected(page)),
		'no streaming service linked on this instance; MeetingBroadcastServiceTest covers the moves',
	)
	await widget.getByTestId('meeting-broadcast-start').click()
	await expect(widget.getByTestId('meeting-broadcast-state')).toContainText(
		'The meeting is live.',
	)
	await expect(widget.getByText('Open the public player')).toBeVisible()
	await widget.getByTestId('meeting-broadcast-pause').click()
	await expect(widget.getByTestId('meeting-broadcast-state')).toContainText(
		'Paused for a closed session.',
	)
	await widget.getByTestId('meeting-broadcast-resume').click()
	await widget.getByTestId('meeting-broadcast-stop').click()
	await expect(widget.getByTestId('meeting-broadcast-state')).toContainText(
		'The broadcast has ended.',
	)
	await expect(widget.getByRole('button')).toHaveCount(0)
})

// @e2e meeting-broadcast::a-service-without-live-captions
test('a service without live captions is named in the widget', async ({ page }) => {
	const widget = await openMeeting(page, 'Informatieavond windpark Noord')
	test.skip(
		!(await isConnected(page)),
		'no streaming service linked on this instance; MeetingBroadcastServiceTest covers the captions answer',
	)
	await expect(widget).toBeVisible()
	const captions = widget.getByTestId('meeting-broadcast-captions')
	test.skip(
		(await captions.count()) === 0,
		'the linked service offers live captions',
	)
	await expect(captions).toContainText(
		'Live captions are not available from this streaming service',
	)
})
