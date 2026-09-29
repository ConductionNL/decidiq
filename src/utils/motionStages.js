// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The stage buttons on the motion page and the theme choices on the motion
 * form (motions-stages-and-themes).
 *
 * The server says which steps the caller may take (GET .../transitions): the
 * chair or secretary every step of the motion lifecycle, the member who
 * submitted the motion Withdraw. A step is applied through the guarded
 * POST .../transition. This module holds the paths and reads the answers, so
 * the widget cannot show a step the server did not offer.
 *
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */

/** Every step the motion lifecycle knows, in the order the buttons show. */
export const MOTION_STEPS = [
	'proposed',
	'deliberating',
	'voting',
	'decided-adopted',
	'decided-rejected',
	'enacted',
	'archived',
	'withdrawn',
]

/**
 * @param {string} motionId Motion uuid
 * @return {string} The path of the steps the caller may take
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */
export function transitionsPath(motionId) {
	return `/apps/decidiq/api/motions/${motionId}/transitions`
}

/**
 * @param {string} motionId Motion uuid
 * @return {string} The path that applies a step
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */
export function transitionPath(motionId) {
	return `/apps/decidiq/api/motions/${motionId}/transition`
}

/**
 * One key per step: the target stage, with the result for Decided.
 *
 * @param {{to: string, outcome?: string}} action The step
 * @return {string}
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */
export function actionKey(action) {
	return action?.outcome ? `${action.to}-${action.outcome}` : String(action?.to ?? '')
}

/**
 * Read the server's answer. Only known steps are kept, in lifecycle order; a
 * missing or malformed answer offers no step.
 *
 * @param {object|null} answer The GET .../transitions body
 * @return {{lifecycle: string, outcome: string, actions: Array<{to: string, outcome?: string}>}}
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */
export function readMotionStageAnswer(answer) {
	const offered = Array.isArray(answer?.actions) ? answer.actions : []
	const byKey = new Map(offered.map((action) => [actionKey(action), action]))
	return {
		lifecycle: typeof answer?.lifecycle === 'string' ? answer.lifecycle : '',
		outcome: typeof answer?.outcome === 'string' ? answer.outcome : '',
		actions: MOTION_STEPS.filter((key) => byKey.has(key)).map((key) => {
			const { to, outcome } = byKey.get(key)
			return outcome ? { to, outcome } : { to }
		}),
	}
}

/**
 * The configured theme names, sorted, without blanks.
 *
 * @param {Array<object>|null} themes Theme objects
 * @return {string[]}
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-002-tag-motions-by-theme-and-filter
 */
export function themeNames(themes) {
	if (!Array.isArray(themes)) return []
	return [...new Set(
		themes
			.map((theme) => (typeof theme?.name === 'string' ? theme.name.trim() : ''))
			.filter((name) => name !== ''),
	)].sort((a, b) => a.localeCompare(b))
}

/**
 * The form schema with the configured themes offered as the choices of
 * `themes`. Without configured themes the schema is returned untouched, so
 * the field stays free text rather than an empty picker.
 *
 * @param {object} schema The form schema
 * @param {string[]} names The configured theme names
 * @return {object}
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-002-tag-motions-by-theme-and-filter
 */
export function withThemeVocabulary(schema, names) {
	const themes = schema?.properties?.themes
	if (!themes || !Array.isArray(names) || names.length === 0) return schema
	return {
		...schema,
		properties: {
			...schema.properties,
			themes: {
				...themes,
				items: { ...(themes.items || {}), type: 'string', enum: names },
			},
		},
	}
}
