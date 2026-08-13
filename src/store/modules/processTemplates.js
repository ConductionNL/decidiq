// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Process-template admin store + pure client-side state-machine graph
// validation. The graph validation mirrors the authoritative server-side
// checks in ProcessTemplateService::validateStateMachine() for fast editor
// feedback; the server is always the authority on save.
//
// @spec openspec/specs/process-configuration/spec.md

import { defineStore } from 'pinia'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

// Re-export the pure graph validation so callers can keep importing it from the
// store; the implementation lives dependency-free in services/ for vitest.
export {
	KNOWN_GUARDS,
	validateStateMachineGraph,
} from '../../services/processTemplateGraph.js'

/**
 * Admin store for process-template CRUD.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
export const useProcessTemplatesStore = defineStore('decidesk-process-templates', {
	state: () => ({
		templates: [],
		loading: false,
		error: '',
	}),

	getters: {
		builtInTemplates: (state) =>
			state.templates.filter((t) => t.builtIn === true),
		customTemplates: (state) =>
			state.templates.filter((t) => t.builtIn !== true),
	},

	actions: {
		/** @spec openspec/specs/process-configuration/spec.md */
		async fetchTemplates() {
			this.loading = true
			this.error = ''
			try {
				const response = await fetch(
					generateUrl('/apps/decidesk/api/process-templates'),
					{
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.templates = Array.isArray(data?.results) ? data.results : []
					return this.templates
				}
				this.error = `Failed to load templates (${response.status})`
			} catch (e) {
				this.error = String(e?.message || e)
			} finally {
				this.loading = false
			}
			return []
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		async createTemplate(template) {
			return this.write(
				generateUrl('/apps/decidesk/api/process-templates'),
				'POST',
				template,
			)
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		async updateTemplate(id, template) {
			return this.write(
				generateUrl(
					'/apps/decidesk/api/process-templates/' + encodeURIComponent(id),
				),
				'PUT',
				template,
			)
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		async duplicateTemplate(id, name) {
			return this.write(
				generateUrl(
					'/apps/decidesk/api/process-templates/'
						+ encodeURIComponent(id)
						+ '/duplicate',
				),
				'POST',
				{ name },
			)
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		async deleteTemplate(id) {
			this.error = ''
			try {
				const response = await fetch(
					generateUrl(
						'/apps/decidesk/api/process-templates/'
							+ encodeURIComponent(id),
					),
					{
						method: 'DELETE',
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (response.ok) {
					await this.fetchTemplates()
					return true
				}
				const body = await response.json().catch(() => ({}))
				this.error = body?.message || `Delete failed (${response.status})`
			} catch (e) {
				this.error = String(e?.message || e)
			}
			return false
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		async write(url, method, payload) {
			this.error = ''
			try {
				const response = await fetch(url, {
					method,
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify(payload),
				})
				const data = await response.json().catch(() => ({}))
				if (response.ok) {
					await this.fetchTemplates()
					return data
				}
				this.error = data?.message || `Request failed (${response.status})`
			} catch (e) {
				this.error = String(e?.message || e)
			}
			return null
		},
	},
})
