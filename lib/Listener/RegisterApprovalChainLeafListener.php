<?php

/**
 * Decidiq approval-chain leaf registration.
 *
 * The SERVER half of the `decidiq-approval-chain` render leaf. Every constant
 * below is a value the JS half also declares, and
 * `tests/Unit/Listener/ApprovalChainLeafParityTest.php` compares the two
 * directly. They are constants rather than inlined literals so that comparison
 * has something to read on this side.
 *
 * A leaf declared on one half only is a leaf that renders in one place and is
 * invisible in another, and nothing says so.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\AppInfo\Application;
use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRendering;

/**
 * Contributes the `decidiq-approval-chain` render leaf to OpenRegister's
 * catalogue.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
 */
class RegisterApprovalChainLeafListener extends AbstractRenderLeafListener {

	/**
	 * The shared leaf id, equal to the JS `APPROVAL_CHAIN_INTEGRATION_ID`.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'decidiq-approval-chain';

	/**
	 * The l10n SOURCE string for the leaf label, equal to the string the JS half
	 * passes to `t('decidiq', …)`.
	 *
	 * The catalogue is keyed on Dutch source strings, as the decisions leaf
	 * beside this one already is, so the server half has to use the SAME key or
	 * the two halves render different labels on the same leaf while both are
	 * "translated".
	 *
	 * @var string
	 */
	public const LABEL_SOURCE = 'Parafering';

	/**
	 * Material Design Icons name, equal to the JS half's `icon`.
	 *
	 * @var string
	 */
	public const ICON = 'Signature';

	/**
	 * Admin-UI grouping, equal to the JS half's `group`.
	 *
	 * @var string
	 */
	public const GROUP = 'workflow';

	/**
	 * ADR-019 AD-18 marker: a schema property carrying this `referenceType`
	 * renders the leaf's single-entity surface. Equal to the JS half's value.
	 *
	 * @var string
	 */
	public const REFERENCE_TYPE = 'approval-route';

	/**
	 * The render surfaces this leaf targets, the SAME set in the same order as
	 * `src/integrations/registerApprovalChainLeaf.js` declares.
	 *
	 * All four, because the JS half's `componentForSurface()` roots the widget on
	 * the three dashboard-and-detail surfaces and the timeline everywhere else.
	 *
	 * @var array<int, string>
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public const SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

	/**
	 * Build the `decidiq-approval-chain` leaf descriptor.
	 *
	 * @param array<string, string> $optional LeafRendering arguments only some OpenRegister versions accept.
	 *
	 * @return LeafDescriptor The descriptor.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	protected function descriptor(array $optional): LeafDescriptor {
		// The optional half is UNPACKED FIRST into the rendering value object,
		// and every agreed field stays a written-out named argument, for two
		// reasons that pull the same way.
		// PHP refuses unpacking after a named argument, and
		// `scripts/check-integration-parity.js` reads this call as SOURCE: it
		// correlates the two halves of the leaf by matching `name: value`
		// arguments here against the JS registration. Building the whole
		// argument list as an array leaves the gate nothing to read: measured,
		// it dropped from 8 field assertions to 0 and reported the leaf as
		// having no id at all.
		return new LeafDescriptor(
			identity: new LeafIdentity(
				id: self::LEAF_ID,
				label: $this->l10n->t(self::LABEL_SOURCE),
				icon: self::ICON,
				group: self::GROUP,
			),
			kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
			availability: new LeafAvailability(requiredApp: Application::APP_ID),
			rendering: new LeafRendering(
				...$optional,
				surfaces: self::SURFACES,
				referenceType: self::REFERENCE_TYPE,
				// The JS half renders through a `mount`/`unmount` DOM hand-off, so
				// this MUST declare the same mode under the shared id or the
				// surface blanks.
				renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
			),
		);

	}//end descriptor()
}//end class
