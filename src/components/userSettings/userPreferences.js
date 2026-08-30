/**
 * User-settings logic module (user-settings spec).
 *
 * Shared by the four personal-settings section components and their three
 * mounts (Nextcloud personal settings panel, SPA settings page, in-app
 * dialog). Pure logic lives here so vitest can exercise it without a DOM:
 *   - fetch/save of the per-user NotificationPreference REST resource
 *   - fetch/save of IConfig-backed display preferences
 *   - the two-toggle ↔ deliveryMethod enum mapping
 *   - delegation + e-mail validation
 *   - the date-format preference formatter
 *
 * Every endpoint consumed here is scoped server-side to the session user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

/** Notification event types (key = REST field). Labels are translated in the components. */
export const EVENT_TYPES = [
	'meetingCreated',
	'votingOpened',
	'decisionPublished',
	'taskAssigned',
	'commentMention',
	'meetingReminder',
]

/** Valid meeting-reminder timing tokens (mirrors the schema enum). */
export const REMINDER_TIME_OPTIONS = ['1h', '4h', '24h', '48h', '1w']

/** Spec default: 24 hours and 1 hour before the meeting. */
export const DEFAULT_REMINDER_TIMES = ['24h', '1h']

/** Valid governance communication languages (mirrors the schema enum). */
export const COMMUNICATION_LANGUAGES = ['nl', 'en', 'de', 'fr', 'es', 'it']

/** Display preference defaults ('locale' = follow the Nextcloud locale). */
export const DISPLAY_DEFAULTS = {
	'default-view': 'dashboard',
	'items-per-page': '25',
	'date-format': 'locale',
}

/** Supported default landing views (route names per src/manifest.json). */
export const DEFAULT_VIEW_OPTIONS = [
	{ id: 'dashboard', route: '/' },
	{ id: 'meetings', route: '/meetings' },
	{ id: 'decisions', route: '/decisions' },
]

/** Supported date formats ('locale' = follow the Nextcloud locale). */
export const DATE_FORMAT_OPTIONS = [
	'locale',
	'DD-MM-YYYY',
	'YYYY-MM-DD',
	'MM/DD/YYYY',
]

/**
 * Map the two independent channel toggles onto the storage enum.
 *
 * Returns null when both channels are off — the enum has no `none` value;
 * per-event toggles already express "no notifications", so the UI must keep
 * at least one channel enabled.
 *
 * @param {object} channels Channel toggles.
 * @param {boolean} channels.inApp Nextcloud notification channel.
 * @param {boolean} channels.email Email channel.
 * @return {string|null} 'in-app' | 'email' | 'both' | null (invalid).
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export function channelsToDeliveryMethod({ inApp, email }) {
	if (inApp && email) {
		return 'both'
	}
	if (inApp) {
		return 'in-app'
	}
	if (email) {
		return 'email'
	}
	return null
}

/**
 * Map the storage enum back onto the two channel toggles.
 *
 * @param {string} method 'in-app' | 'email' | 'both'.
 * @return {{inApp: boolean, email: boolean}} Channel toggles.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export function deliveryMethodToChannels(method) {
	return {
		inApp: method === 'in-app' || method === 'both',
		email: method === 'email' || method === 'both',
	}
}

/**
 * Basic e-mail shape validation (server revalidates with FILTER_VALIDATE_EMAIL).
 *
 * @param {string} value The candidate address.
 * @return {boolean} True when the value looks like an e-mail address.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export function isValidEmail(value) {
	return typeof value === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

/**
 * Validate a delegation form state client-side.
 *
 * Mirrors the server rules: a delegate requires an expiry date (delegations
 * expire automatically per the spec) and the period must not be inverted.
 *
 * @param {object} delegation Delegation form state.
 * @param {string} delegation.delegate Delegate NC user id ('' = no delegation).
 * @param {string} delegation.delegationFrom First day (YYYY-MM-DD) or ''.
 * @param {string} delegation.delegationUntil Last day (YYYY-MM-DD) or ''.
 * @return {string|null} An error code ('expiry-required' | 'inverted-period'), or null when valid.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export function validateDelegation({ delegate, delegationFrom, delegationUntil }) {
	if (!delegate) {
		return null
	}
	if (!delegationUntil) {
		return 'expiry-required'
	}
	if (delegationFrom && delegationUntil < delegationFrom) {
		return 'inverted-period'
	}
	return null
}

/**
 * Format a date per the user's date-format display preference.
 *
 * 'locale' (the default) follows the Nextcloud/browser locale, satisfying
 * "the default MUST follow the Nextcloud locale setting".
 *
 * @param {string|Date} value The date (ISO string or Date).
 * @param {string} format One of DATE_FORMAT_OPTIONS.
 * @return {string} The formatted date ('' for empty/invalid input).
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export function formatDate(value, format = 'locale') {
	if (!value) {
		return ''
	}
	const date = value instanceof Date ? value : new Date(value)
	if (isNaN(date.getTime())) {
		return ''
	}
	const dd = String(date.getDate()).padStart(2, '0')
	const mm = String(date.getMonth() + 1).padStart(2, '0')
	const yyyy = String(date.getFullYear())
	switch (format) {
		case 'DD-MM-YYYY':
			return `${dd}-${mm}-${yyyy}`
		case 'YYYY-MM-DD':
			return `${yyyy}-${mm}-${dd}`
		case 'MM/DD/YYYY':
			return `${mm}/${dd}/${yyyy}`
		default:
			return date.toLocaleDateString()
	}
}

/**
 * Fetch the session user's notification/delegation/communication preferences.
 *
 * @return {Promise<object>} The defaults-merged preference object (incl. accountEmail).
 * @throws {Error} When the request fails.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export async function fetchNotificationPreference() {
	const response = await fetch(
		generateUrl('/apps/decidiq/api/notification-preference'),
		{
			headers: { requesttoken: getRequestToken() },
		},
	)
	if (!response.ok) {
		throw new Error(`Failed to load preferences (HTTP ${response.status})`)
	}
	return await response.json()
}

/**
 * Save (partial) notification/delegation/communication preferences for the session user.
 *
 * @param {object} changes Validated field changes.
 * @return {Promise<object>} The persisted preference object.
 * @throws {Error} When the server rejects the payload (message from the 422 envelope when present).
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export async function saveNotificationPreference(changes) {
	const response = await fetch(
		generateUrl('/apps/decidiq/api/notification-preference'),
		{
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify(changes),
		},
	)
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(
			data?.message || `Failed to save preferences (HTTP ${response.status})`,
		)
	}
	return data
}

/**
 * Read one IConfig-backed display preference for the session user.
 *
 * @param {string} key Preference key (e.g. 'default-view').
 * @return {Promise<string>} The stored value, or the DISPLAY_DEFAULTS fallback.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export async function fetchDisplayPreference(key) {
	const response = await fetch(
		generateUrl(`/apps/decidiq/api/preferences/${key}`),
		{
			headers: { requesttoken: getRequestToken() },
		},
	)
	if (!response.ok) {
		return DISPLAY_DEFAULTS[key] ?? ''
	}
	const data = await response.json().catch(() => ({}))
	return data?.value ?? DISPLAY_DEFAULTS[key] ?? ''
}

/**
 * Persist one IConfig-backed display preference for the session user.
 *
 * @param {string} key Preference key (e.g. 'date-format').
 * @param {string} value The value to store ('' clears it back to the default).
 * @return {Promise<void>} Resolves when stored.
 * @throws {Error} When the request fails.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export async function saveDisplayPreference(key, value) {
	const response = await fetch(
		generateUrl(`/apps/decidiq/api/preferences/${key}`),
		{
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ value }),
		},
	)
	if (!response.ok) {
		throw new Error(`Failed to save preference ${key} (HTTP ${response.status})`)
	}
}

/**
 * Search Nextcloud users for the delegate picker via the sharees OCS endpoint
 * (available to non-admin users, unlike the provisioning API).
 *
 * @param {string} search The search term.
 * @return {Promise<Array<{id: string, label: string}>>} User options.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
export async function searchDelegateUsers(search) {
	const url =
		generateOcsUrl('apps/files_sharing/api/v1/sharees')
		+ `?search=${encodeURIComponent(search)}&itemType=file&shareType=0&perPage=20&format=json`
	const response = await fetch(url, {
		headers: {
			requesttoken: getRequestToken(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	if (!response.ok) {
		return []
	}
	const data = await response.json().catch(() => ({}))
	const users = [
		...(data?.ocs?.data?.exact?.users ?? []),
		...(data?.ocs?.data?.users ?? []),
	]
	return users
		.map((u) => ({
			id: u?.value?.shareWith ?? '',
			label: u?.label ?? u?.value?.shareWith ?? '',
		}))
		.filter((u) => u.id !== '')
}
