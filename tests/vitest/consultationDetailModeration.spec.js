/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The consultation detail page carries its own moderation surface.
 *
 * p3-citizen-participation requires staff to approve or reject a consultation's
 * pending reactions from the Reactions tab on that consultation's detail page.
 * ConsultationReactionsTab does exactly that when it receives an `objectId`,
 * and it used to be mounted as a sidebar tab. A layout rework turned the
 * sidebar tab into a read-only table and dropped the component, so the only
 * place left to moderate was the hub-wide queue. Nothing reported it: the
 * component stayed registered and the queue page still used it.
 *
 * This pins the three links that have to hold for the tab to render, scoped:
 * a widget of type custom naming the component, a layout item that places it,
 * and the `widget-<id>` slot CnDetailPage renders it through (that slot is what
 * binds `objectId`, which scopes the list to this consultation).
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const FRAGMENT = path.join(ROOT, 'src', 'manifest.d', 'citizen-participation.json')

/** The ConsultationDetail page as the fragment declares it. */
function consultationDetail() {
	const parsed = JSON.parse(fs.readFileSync(FRAGMENT, 'utf8'))
	return (parsed.pages ?? []).find((page) => page.id === 'ConsultationDetail')
}

describe('ConsultationDetail moderation', () => {
	it('declares a custom widget that mounts ConsultationReactionsTab', () => {
		const page = consultationDetail()
		expect(page).toBeTruthy()
		const widgets = page.config.widgets.filter(
			(w) => w.component === 'ConsultationReactionsTab',
		)
		expect(widgets).toHaveLength(1)
		expect(widgets[0].type).toBe('custom')
	})

	it('places that widget in the layout', () => {
		const page = consultationDetail()
		const widget = page.config.widgets.find(
			(w) => w.component === 'ConsultationReactionsTab',
		)
		const placed = page.config.layout.filter(
			(item) => item.widgetId === widget.id,
		)
		expect(placed).toHaveLength(1)
	})

	it('renders it through the widget slot, which binds the consultation id', () => {
		const page = consultationDetail()
		const widget = page.config.widgets.find(
			(w) => w.component === 'ConsultationReactionsTab',
		)
		expect(page.slots?.[`widget-${widget.id}`]).toBe('ConsultationReactionsTab')
	})

	it('names a component the registry exports', () => {
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)
		expect(registry).toMatch(
			/^\s*ConsultationReactionsTab: page\(ConsultationReactionsTab\),$/m,
		)
	})
})
