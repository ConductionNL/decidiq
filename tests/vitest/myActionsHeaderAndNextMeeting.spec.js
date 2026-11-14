/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My actions (board DcMijnActies): a page header with "New action item", and
 * beside the work cards the next meeting with a link to its agenda, then the
 * meetings after that. "After that" skips the first meeting with the record
 * list's `offset` (nextcloud-vue feat/record-list-offset).
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

const simple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
)
const config = simple.pages.find((page) => page.id === 'MyActions').config
const widgets = new Map(config.widgets.map((widget) => [widget.id, widget]))
const placed = (id) => config.layout.find((item) => item.widgetId === id)
const dutch = readJson('l10n', 'nl.json').translations

describe('My actions', () => {
	it('offers New action item in the header, on the action item form', () => {
		const action = config.headerActions.find(
			(item) => item.id === 'my-actions-new',
		)
		expect(action).toMatchObject({
			type: 'open-form',
			register: 'decidiq',
			schema: 'action-item',
		})
		expect(dutch[action.label]).toBe('Nieuw actiepunt')
	})

	it('shows the next meeting beside the work, then the ones after it', () => {
		const next = widgets.get('my-next-meeting').content
		const after = widgets.get('my-meetings-after').content
		for (const list of [next, after]) {
			expect(list.schema).toBe('meeting')
			expect(list.filter).toEqual({ 'scheduledDate[gte]': '@now' })
			expect(list.sort).toEqual({ field: 'scheduledDate', dir: 'asc' })
		}
		expect(next.limit).toBe(1)
		expect(next.rowAction).toMatchObject({
			type: 'open-page',
			target: 'MeetingDetail',
		})
		expect(after.offset).toBe(1)
		for (const id of ['my-next-meeting', 'my-meetings-after']) {
			expect(placed(id).gridX, id).toBe(8)
			expect(placed(id).gridWidth, id).toBe(4)
		}
		expect(placed('my-meetings-after').gridY).toBeGreaterThan(
			placed('my-next-meeting').gridY,
		)
	})

	it('keeps the work cards in the main column', () => {
		for (const id of ['my-proposals', 'my-votes', 'my-action-items']) {
			expect(placed(id).gridX, id).toBe(0)
			expect(placed(id).gridWidth, id).toBe(8)
		}
	})

	it('reads in Dutch', () => {
		for (const widget of [
			widgets.get('my-next-meeting'),
			widgets.get('my-meetings-after'),
		]) {
			for (const text of [
				widget.title,
				widget.content.emptyText,
				widget.content.rowAction?.label,
			].filter(Boolean)) {
				expect(dutch[text], text).toBeTruthy()
			}
		}
		expect(dutch['After that']).toBe('Daarna')
	})
})
