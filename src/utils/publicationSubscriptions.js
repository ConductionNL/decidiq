// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Publication subscriptions on the member's settings page
// (publication-subscriptions-and-daily-digest, matrix rows pub-10 and pub-16).
// Pure helpers, so the section stays thin and vitest can cover the rules.

import { generateUrl } from '@nextcloud/router'

/** What a subscriber can follow (mirrors the schema enum). */
export const KINDS = ['agenda', 'paper', 'decision', 'minutes']

/** How often a subscriber hears of it (mirrors the schema enum). */
export const FREQUENCIES = ['immediate', 'daily', 'weekly']

const OBJECTS = '/apps/openregister/api/objects/decidiq'

/**
 * The OpenRegister list URL of one member's subscriptions.
 *
 * @param {string} uid The member's account.
 * @return {string} The URL.
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function listUrl(uid) {
	return generateUrl(
		`${OBJECTS}/publication-subscription?subscriberUserId=${encodeURIComponent(uid)}&_limit=100`,
	)
}

/**
 * The OpenRegister URL of one subscription, or of the collection to create in.
 *
 * @param {string} [id] The subscription.
 * @return {string} The URL.
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function subscriptionUrl(id = '') {
	const tail = id ? `/${encodeURIComponent(id)}` : ''
	return generateUrl(`${OBJECTS}/publication-subscription${tail}`)
}

/**
 * The OpenRegister list URL of the governance bodies to choose from.
 *
 * @return {string} The URL.
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function bodiesUrl() {
	return generateUrl(`${OBJECTS}/governance-body?_limit=200`)
}

/**
 * Why a subscription cannot be saved yet, or '' when it can.
 *
 * @param {object} form The form: kinds and frequency.
 * @return {string} The reason, in English (translated by the caller).
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function validateSubscription(form) {
	if (!Array.isArray(form?.kinds) || form.kinds.length === 0) {
		return 'Choose at least one thing to follow.'
	}
	if (!FREQUENCIES.includes(form?.frequency ?? 'daily')) {
		return 'Choose how often you want to hear of it.'
	}
	return ''
}

/**
 * The object the section writes for a member's new subscription.
 *
 * @param {string} uid The member's account.
 * @param {object} form The form: bodies (options with an id), kinds and frequency.
 * @return {object} The PublicationSubscription.
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function buildSubscription(uid, form) {
	return {
		subscriberUserId: uid,
		governanceBodies: (form.bodies ?? []).map((body) => body.id),
		kinds: KINDS.filter((kind) => (form.kinds ?? []).includes(kind)),
		frequency: form.frequency ?? 'daily',
		active: true,
	}
}

/**
 * The member's own active subscriptions, whatever the list returned.
 *
 * @param {Array<object>} rows Subscriptions from the list call.
 * @param {string} uid The member's account.
 * @return {Array<object>} His active ones.
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function ownSubscriptions(rows, uid) {
	return (rows ?? []).filter(
		(row) => row.subscriberUserId === uid && row.active !== false,
	)
}

/**
 * One line naming what a subscription follows, from which bodies, how often.
 *
 * @param {object} subscription The subscription.
 * @param {object} bodyNames Body names by id.
 * @param {Function} t The translate function.
 * @return {string} The line.
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 */
export function describeSubscription(subscription, bodyNames, t) {
	const kindLabels = {
		agenda: t('decidiq', 'agendas'),
		paper: t('decidiq', 'papers'),
		decision: t('decidiq', 'decisions'),
		minutes: t('decidiq', 'minutes'),
	}
	const frequencyLabels = {
		immediate: t('decidiq', 'immediately'),
		daily: t('decidiq', 'daily'),
		weekly: t('decidiq', 'weekly'),
	}
	const kinds = (subscription.kinds ?? []).map((kind) => kindLabels[kind] ?? kind).join(', ')
	const bodies = (subscription.governanceBodies ?? []).length
		? subscription.governanceBodies.map((id) => bodyNames[id] ?? id).join(', ')
		: t('decidiq', 'every body')
	const line = t('decidiq', '{kinds} from {bodies}, {frequency}', {
		kinds,
		bodies,
		frequency: frequencyLabels[subscription.frequency] ?? subscription.frequency,
	})
	return line.charAt(0).toUpperCase() + line.slice(1)
}
