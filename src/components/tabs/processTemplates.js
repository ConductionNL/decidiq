// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Built-in process-template catalogue for governance-body template
// assignment (admin-settings spec: Process Template Assignment).
//
// Template MANAGEMENT (creating/editing state machines, voting rules)
// is the process-configuration capability (V1, not built). Until it
// ships real ProcessTemplate objects, assignment selects from this
// stable built-in catalogue; the GovernanceBody.processTemplate /
// additionalTemplates link fields stay unchanged when the catalogue is
// later replaced by an OpenRegister query.
// @spec openspec/specs/admin-settings/spec.md

import { translate as t } from '@nextcloud/l10n'

/**
 * The built-in process templates: stable ids + translated labels.
 *
 * @return {Array<{id: string, label: string, description: string}>}
 * @spec openspec/specs/admin-settings/spec.md
 */
export function getProcessTemplates() {
	return [
		{
			id: 'standard-decision',
			label: t('decidiq', 'Standard decision'),
			description: t(
				'decidiq',
				'Simple majority of votes cast, default quorum.',
			),
		},
		{
			id: 'statute-amendment',
			label: t('decidiq', 'Statute amendment'),
			description: t(
				'decidiq',
				'Qualified majority (2/3) with elevated quorum.',
			),
		},
		{
			id: 'board-election',
			label: t('decidiq', 'Board election'),
			description: t('decidiq', 'Secret ballot with candidate rounds.'),
		},
		{
			id: 'urgent-decision',
			label: t('decidiq', 'Urgent decision'),
			description: t('decidiq', 'Shortened debate and voting windows.'),
		},
	]
}
