/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Member provisioning admin setting: the groups a governance body may map
 * for member onboarding and offboarding. OnboardingProvisioningService reads
 * it as a comma-separated list and never provisions the admin group.
 */

/**
 * The group ids as the admin section shows them.
 *
 * @param {object} settings The GET /api/settings answer.
 * @return {string[]} The allowed group ids, trimmed, without duplicates.
 */
export function provisioningGroups(settings) {
	const raw = String(settings?.member_provisioning_groups ?? '')
	return [...new Set(raw.split(',').map((name) => name.trim()).filter((name) => name !== ''))]
}

/**
 * The PUT /api/settings body for the allowed groups.
 *
 * @param {string[]} groups The allowed group ids.
 * @return {object} The payload.
 */
export function provisioningGroupsPayload(groups) {
	return { member_provisioning_groups: provisioningGroups({ member_provisioning_groups: (groups || []).join(',') }).join(',') }
}
