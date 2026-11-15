/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for sending minutes, decision lists and motions for signature in
 * a chosen order (min-17).
 *
 * The signers live on the record as `signers`, each with a participant and an
 * `order`. The Signers widget orders them, moves them and sends the record;
 * the same widget sits on the minutes, meeting (decision list) and motion
 * pages. This repo's vitest runs without @vitejs/plugin-vue, so a `.vue` file
 * cannot be mounted; the logic lives in src/utils and the wiring is asserted
 * against the sources.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	addSigner,
	moveSigner,
	orderedSigners,
	signingUrl,
} from '../../src/utils/signingRound.js'

function read(path) {
	return readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
}

describe('the signing order', () => {
	it('sorts the signers on their order, first signer first', () => {
		expect(
			orderedSigners([
				{ participant: 'pieter', order: 2 },
				{ participant: 'anna', order: 1 },
			]).map((s) => s.participant),
		).toEqual(['anna', 'pieter'])
	})

	it('keeps signers without an order in the place they were added, after the ordered ones', () => {
		expect(
			orderedSigners([
				'kees',
				{ participant: 'anna', order: 1 },
				{ participant: 'els' },
			]).map((s) => [s.participant, s.order]),
		).toEqual([
			['anna', 1],
			['kees', 2],
			['els', 3],
		])
	})

	it('keeps when someone signed', () => {
		expect(
			orderedSigners([
				{ participant: 'anna', order: 1, signedAt: '2026-10-15T10:00:00Z' },
			])[0].signedAt,
		).toBe('2026-10-15T10:00:00Z')
	})

	it('adds a new signer last', () => {
		const next = addSigner([{ participant: 'anna', order: 1 }], 'pieter')
		expect(next).toEqual([
			{ participant: 'anna', order: 1 },
			{ participant: 'pieter', order: 2 },
		])
	})

	it('moves a signer up and renumbers', () => {
		const next = moveSigner(
			[
				{ participant: 'anna', order: 1 },
				{ participant: 'pieter', order: 2 },
			],
			'pieter',
			-1,
		)
		expect(next).toEqual([
			{ participant: 'pieter', order: 1 },
			{ participant: 'anna', order: 2 },
		])
	})

	it('does not move the first signer further up', () => {
		const signers = [
			{ participant: 'anna', order: 1 },
			{ participant: 'pieter', order: 2 },
		]
		expect(moveSigner(signers, 'anna', -1)).toEqual(signers)
	})
})

describe('send for signature', () => {
	it('posts to the signing endpoint of the record type', () => {
		expect(signingUrl('motion', 'dec-1', 'send')).toBe(
			'/apps/decidiq/api/signing/motion/dec-1/send',
		)
		expect(signingUrl('decision-list', 'meet-1', 'collect')).toBe(
			'/apps/decidiq/api/signing/decision-list/meet-1/collect',
		)
	})

	it('is a button on the Signers widget that posts there', () => {
		const source = read('../../src/components/tabs/MinutesSignersTab.vue')
		expect(source).toContain("t('decidiq', 'Send for signature')")
		expect(source).toContain(
			'signingUrl(this.subjectType, this.objectId, action)',
		)
		expect(source).toContain("this.postSigning('send')")
		expect(source).toContain('moveSigner(')
	})

	it('sits on the motion and meeting pages as well as the minutes page', () => {
		const manifest = JSON.parse(read('../../src/manifest.json'))
		const page = (id) => manifest.pages.find((p) => p.id === id)
		const components = (id) => page(id).config.widgets.map((w) => w.component)
		expect(components('MinutesDetail')).toContain('MinutesSignersTab')
		expect(components('MotionDetail')).toContain('MotionSignersTab')
		expect(components('MeetingDetail')).toContain('DecisionListSignersTab')
		for (const id of ['MotionDetail', 'MeetingDetail']) {
			const widgetIds = page(id).config.widgets.map((w) => w.id)
			const placed = page(id).config.layout.map((l) => l.widgetId)
			for (const widgetId of widgetIds) expect(placed).toContain(widgetId)
		}

		const motion = read('../../src/components/tabs/MotionSignersTab.vue')
		expect(motion).toContain('subjectType="motion"')
		expect(motion).toContain('schema="decision"')
		const list = read('../../src/components/tabs/DecisionListSignersTab.vue')
		expect(list).toContain('subjectType="decision-list"')
		expect(list).toContain('schema="meeting"')

		const registry = read('../../src/registry.js')
		expect(registry).toContain('MotionSignersTab: page(MotionSignersTab)')
		expect(registry).toContain(
			'DecisionListSignersTab: page(DecisionListSignersTab)',
		)
	})
})
