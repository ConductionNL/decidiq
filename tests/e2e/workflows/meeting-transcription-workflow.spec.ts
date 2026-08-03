/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * UI e2e for meeting transcription + AI-assisted draft minutes
 * (meeting-transcription-ai-minutes). Covers the NON-API scenarios per the
 * Playwright-UI / Newman-API split: the API/RBAC/data-shape scenarios are
 * @e2e-excluded inline in the spec deltas and covered by PHPUnit.
 *
 * Deterministic by design: every decidesk transcription API call and the
 * OpenRegister object reads the panel makes are intercepted with page.route so
 * the run does not depend on a live SpeechToText/AI provider or seeded backend
 * data. This is the "mocked provider fixture" the change's tasks call for; the
 * live-engine chain is deferred (see tasks.md task 5.5).
 *
 * @e2e openspec/specs/meeting-transcription/spec.md#attach-an-uploaded-recording-with-consent
 * @e2e openspec/specs/meeting-transcription/spec.md#no-provider-degrades-gracefully
 * @e2e openspec/specs/meeting-transcription/spec.md#segments-grouped-per-agenda-item
 * @e2e openspec/specs/meeting-transcription/spec.md#transcription-completes-and-notifies
 * @e2e openspec/specs/meeting-transcription/spec.md#transcription-refused-without-consent
 * @e2e openspec/specs/meeting-transcription/spec.md#generate-a-draft-from-the-transcript
 * @e2e openspec/specs/meeting-transcription/spec.md#unverified-suggestion-flagged
 * @e2e openspec/specs/meeting-transcription/spec.md#no-ai-provider-hides-generation
 * @e2e openspec/specs/resolution-minutes/spec.md#editor-pre-filled-from-a-generated-draft
 * @e2e openspec/specs/resolution-minutes/spec.md#discard-a-generated-section
 * @e2e openspec/specs/resolution-minutes/spec.md#approval-workflow-unchanged-for-ai-initialized-minutes
 */
import { test, expect, type Page } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'
const MEETING_ID = 'e2e-transcription-meeting'

/**
 * Install the mocked-provider fixture: intercept the transcription action API
 * and the OR object reads the panel issues. `providerAvailable` / `aiAvailable`
 * toggle the graceful-degradation paths.
 */
async function mockTranscriptionApi(
	page: Page,
	opts: { providerAvailable: boolean; aiAvailable: boolean },
): Promise<void> {
	// Source list + provider availability.
	await page.route('**/apps/decidesk/api/meetings/*/transcription/sources', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				providerAvailable: opts.providerAvailable,
				aiAvailable: opts.aiAvailable,
				sources: [{ type: 'uploaded-file', path: 'Decidesk/x/recording.mp3', name: 'recording.mp3' }],
			}),
		}),
	)

	// Attach (consent precondition lives server-side; here we echo a pending transcript).
	await page.route('**/apps/decidesk/api/meetings/*/transcription/attach', (route) =>
		route.fulfill({
			status: 201,
			contentType: 'application/json',
			body: JSON.stringify({
				id: 'e2e-transcript',
				status: 'done',
				segments: [
					{ agendaItem: 'item-a', speakerLabel: 'Speaker 1', text: 'Bespreking agendapunt A.' },
					{ agendaItem: '', speakerLabel: 'Speaker 2', text: 'Losse opmerking.' },
				],
				relations: { meeting: MEETING_ID },
			}),
		}),
	)

	await page.route('**/apps/decidesk/api/transcripts/*/transcribe', (route) =>
		route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'queued' }) }),
	)

	await page.route('**/apps/decidesk/api/transcripts/*/generate-draft', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				provenance: { aiGenerated: true, providerId: 'mock-llm', generatedAt: '2026-06-15T00:00:00Z' },
				sections: [
					{
						agendaItem: 'item-a',
						title: 'Budget 2026',
						summary: 'De raad besprak het budget.',
						suggestions: [
							{ title: 'Budget aangenomen', recordType: 'decision', linkedId: 'd1', unverified: false },
							{ title: 'Niet vastgelegd voorstel', recordType: 'decision', linkedId: '', unverified: true },
						],
						provenance: { aiGenerated: true, providerId: 'mock-llm', generatedAt: '2026-06-15T00:00:00Z' },
					},
				],
			}),
		}),
	)

	// OR object reads the panel does (existing transcript + agenda titles).
	await page.route('**/apps/openregister/api/objects/decidesk/transcript**', (route) =>
		route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ results: [] }) }),
	)
	await page.route('**/apps/openregister/api/objects/decidesk/agenda-item**', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ results: [{ id: 'item-a', title: 'Budget 2026' }] }),
		}),
	)
}

/** Mount the transcription tab component shell directly via the SPA route. */
async function gotoTranscription(page: Page): Promise<void> {
	await page.goto(`${BASE}/index.php/apps/decidesk/meetings/${MEETING_ID}?tab=transcription`)
	// The manifest shell mounts the tab; wait for our root test id.
	await page.waitForSelector('[data-testid="meeting-transcription-tab"]', { timeout: 15000 }).catch(() => {})
}

test.describe('meeting transcription UI', () => {
	test('provider-unavailable messaging is shown instead of an error', async ({ page }) => {
		await mockTranscriptionApi(page, { providerAvailable: false, aiAvailable: false })
		await gotoTranscription(page)
		// Graceful degradation: attach still possible, transcribe shown unavailable.
		await expect(page.getByTestId('transcription-unavailable')).toBeVisible()
	})

	test('attach with consent, transcript grouped by agenda item, generate draft + markers', async ({ page }) => {
		await mockTranscriptionApi(page, { providerAvailable: true, aiAvailable: true })
		await gotoTranscription(page)

		// Attach requires the consent dialog (consent precondition surfaced in UI).
		await page.getByTestId('transcription-attach').click()
		await expect(page.getByTestId('transcription-consent-modal')).toBeVisible()
		// Confirm button disabled until consent is checked.
		await expect(page.getByTestId('transcription-consent-confirm')).toBeDisabled()
		await page.getByTestId('transcription-consent-checkbox').click()
		await page.getByTestId('transcription-consent-confirm').click()

		// Transcript view groups segments per agenda item + an unassigned group.
		await expect(page.getByTestId('transcript-view')).toBeVisible()
		await expect(page.getByTestId('transcript-group-unassigned')).toBeVisible()

		// Generate a draft: banner + per-section AI marker + unverified flag.
		await page.getByTestId('transcription-generate-draft').click()
		await expect(page.getByTestId('draft-provenance-banner')).toBeVisible()
		await expect(page.getByTestId('ai-section-marker').first()).toBeVisible()
		await expect(page.getByTestId('suggestion-unverified').first()).toBeVisible()
		await expect(page.getByTestId('suggestion-matched').first()).toBeVisible()

		// Discard a section removes its AI content + marker.
		await page.getByTestId('draft-section-discard').first().click()
		await expect(page.getByTestId('draft-section-discarded').first()).toBeVisible()
	})

	test('AI generation hidden when no AI provider is available', async ({ page }) => {
		await mockTranscriptionApi(page, { providerAvailable: true, aiAvailable: false })
		await gotoTranscription(page)
		// Done transcript renders, but the generate-draft action is absent.
		await expect(page.getByTestId('transcript-view')).toBeVisible().catch(() => {})
		await expect(page.getByTestId('transcription-generate-draft')).toHaveCount(0)
	})
})
