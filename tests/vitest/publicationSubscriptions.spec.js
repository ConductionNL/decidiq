// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * publication-subscriptions-and-daily-digest (matrix rows pub-10, pub-16):
 * a member subscribes on his settings page per body and kind, chooses how
 * often, sees only his own subscriptions and removes them there. The payload
 * the section writes validates against the merged register schema.
 *
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	buildSubscription,
	describeSubscription,
	FREQUENCIES,
	KINDS,
	listUrl,
	ownSubscriptions,
	validateSubscription,
} from '../../src/utils/publicationSubscriptions.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const ruimte = '00000000-0000-4000-8000-00000000000a'
const bestuur = '00000000-0000-4000-8000-00000000000b'
/**
 * A stand-in for the translate function: fills the placeholders.
 *
 * @param {string} _app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholder values.
 * @return {string} The text with its placeholders filled.
 */
function t(_app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (_m, key) => vars[key] ?? `{${key}}`)
}

describe('publication subscriptions on the settings page', () => {
	it('offers the four kinds and the three frequencies of the schema', () => {
		expect(KINDS).toEqual(['agenda', 'paper', 'decision', 'minutes'])
		expect(FREQUENCIES).toEqual(['immediate', 'daily', 'weekly'])
	})

	it('Pieter follows two committees, agendas and papers, immediately', () => {
		const payload = buildSubscription('pieter', {
			bodies: [{ id: ruimte }, { id: bestuur }],
			kinds: ['agenda', 'paper'],
			frequency: 'immediate',
		})

		expect(payload).toEqual({
			subscriberUserId: 'pieter',
			governanceBodies: [ruimte, bestuur],
			kinds: ['agenda', 'paper'],
			frequency: 'immediate',
			active: true,
		})
		const validate = validatorFor('publication-subscription')
		expect(validate(payload), JSON.stringify(validate.errors)).toBe(true)
	})

	it('no bodies means every body, and daily is the default', () => {
		const payload = buildSubscription('pieter', { kinds: ['decision'] })

		expect(payload.governanceBodies).toEqual([])
		expect(payload.frequency).toBe('daily')
		const validate = validatorFor('publication-subscription')
		expect(validate(payload), JSON.stringify(validate.errors)).toBe(true)
	})

	it('refuses a subscription without a kind or with an unknown frequency', () => {
		expect(validateSubscription({ kinds: [], frequency: 'daily' })).toBe(
			'Choose at least one thing to follow.',
		)
		expect(
			validateSubscription({ kinds: ['agenda'], frequency: 'hourly' }),
		).toBe('Choose how often you want to hear of it.')
		expect(
			validateSubscription({ kinds: ['agenda'], frequency: 'weekly' }),
		).toBe('')
	})

	it("lists only the member's own active subscriptions", () => {
		const rows = [
			{ id: '1', subscriberUserId: 'pieter', kinds: ['agenda'], active: true },
			{ id: '2', subscriberUserId: 'anna', kinds: ['agenda'], active: true },
			{ id: '3', subscriberUserId: 'pieter', kinds: ['paper'], active: false },
			{
				id: '4',
				subscriberRef: 'example-resident',
				kinds: ['agenda'],
				active: true,
			},
		]

		expect(ownSubscriptions(rows, 'pieter').map((row) => row.id)).toEqual(['1'])
		expect(listUrl('pieter')).toContain(
			'/apps/openregister/api/objects/decidiq/publication-subscription?subscriberUserId=pieter',
		)
	})

	it('describes a subscription in one line', () => {
		const names = {
			[ruimte]: 'Commissie Ruimte',
			[bestuur]: 'Commissie Bestuur',
		}
		const line = describeSubscription(
			{
				governanceBodies: [ruimte, bestuur],
				kinds: ['agenda', 'paper'],
				frequency: 'immediate',
			},
			names,
			t,
		)
		expect(line).toBe(
			'Agendas, papers from Commissie Ruimte, Commissie Bestuur, immediately',
		)
		expect(
			describeSubscription(
				{ governanceBodies: [], kinds: ['minutes'], frequency: 'weekly' },
				names,
				t,
			),
		).toBe('Minutes from every body, weekly')
	})

	it('the section sits on both settings pages and labels its select', () => {
		const section = read('src/components/userSettings/SubscriptionsSection.vue')
		expect(section).toContain('inputLabel')
		expect(section).toContain('data-testid="subscriptions-section"')
		for (const page of [
			'src/views/settings/PersonalRoot.vue',
			'src/views/settings/UserSettingsPage.vue',
		]) {
			expect(read(page)).toContain('<SubscriptionsSection')
		}
	})
})
