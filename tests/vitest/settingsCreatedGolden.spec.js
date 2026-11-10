/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the admin settings page's `created()` hook: the form fields
 * it seeds from the settings store, over full, empty and missing settings.
 * Pinned before the seeding moved into named steps.
 *
 * @spec exclude golden test of the settings form seeding, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(__dirname, '../../src/views/settings/Settings.vue')

/**
 * Run created() against a settings store holding the given settings.
 *
 * @param {object|undefined} settings The stored settings.
 *
 * @return {object} The seeded form.
 */
function seededForm(settings) {
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': {
			CnRadioCardsWidget: {},
			CnSettingsFormWidget: {},
			CnSettingsSection: {},
			CnSwitchRowsWidget: {},
		},
		'@nextcloud/vue': { NcButton: {}, NcSelect: {} },
		'../../components/processTemplates/ProcessTemplates.vue': { default: {} },
		'../../store/modules/settings.js': {
			useSettingsStore: () => ({ settings }),
		},
	})
	const vm = instanceOf(component, {})
	for (const [name, fn] of Object.entries(component.computed || {})) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	component.created.call(vm)
	return vm.form
}

describe('Settings created()', () => {
	it('seeds every field from the stored settings', () => {
		expect(
			seededForm({
				ori_endpoint: 'https://ori.example',
				organisation_name: 'Gemeente',
				organisation_logo: 'logo.png',
				organisation_timezone: 'Europe/Amsterdam',
				organisation_locale: 'en',
				organisation_currency: 'EUR',
				organisation_retention_days: 30,
				participation_default_moderation_policy: 'post-moderation',
				participation_catalog: 'cat',
				participation_anon_rate_limit: 5,
			}),
		).toMatchSnapshot()
	})

	it('falls back to empty fields, no locale and the first moderation policy', () => {
		expect(seededForm(undefined)).toMatchSnapshot()
		expect(
			seededForm({
				organisation_locale: 'fr',
				participation_default_moderation_policy: 'unknown',
				organisation_retention_days: 0,
			}),
		).toEqual(seededForm({}))
	})
})
