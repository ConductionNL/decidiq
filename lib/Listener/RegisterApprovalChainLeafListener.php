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
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Contributes the `decidiq-approval-chain` render leaf to OpenRegister's
 * catalogue.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
 */
class RegisterApprovalChainLeafListener implements IEventListener {

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
	 * Constructor.
	 *
	 * @param IL10N $l10n Localisation for the human-readable label.
	 * @param LoggerInterface $logger PSR-3 logger (a throwing listener costs only its own leaf).
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Contribute the `decidiq-approval-chain` leaf descriptor.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		try {
			$optional = [];
			if ($this->descriptorSupportsLoadStrategy() === true) {
				// This app loads its own leaf bundle (decidiq#1345): the
				// registration ships in `decidiq-integration-init.js`, added on
				// every page by `Util::addInitScript` in Application::boot. There
				// is no `decidiq-leaves.js` and the absence of one is not evidence
				// that this surface is dark.
				$optional['loadStrategy'] = LeafDescriptor::LOADS_VIA_OWN_SCRIPT;
			}

			// The optional half is UNPACKED FIRST and every agreed field stays a
			// written-out named argument, for two reasons that pull the same way.
			// PHP refuses unpacking after a named argument, and
			// `scripts/check-integration-parity.js` reads this call as SOURCE: it
			// correlates the two halves of the leaf by matching `name: value`
			// arguments here against the JS registration. Building the whole
			// argument list as an array leaves the gate nothing to read: measured,
			// it dropped from 8 field assertions to 0 and reported the leaf as
			// having no id at all.
			$descriptor = new LeafDescriptor(
				...$optional,
				id: self::LEAF_ID,
				label: $this->l10n->t(self::LABEL_SOURCE),
				icon: self::ICON,
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				requiredApp: Application::APP_ID,
				group: self::GROUP,
				surfaces: self::SURFACES,
				referenceType: self::REFERENCE_TYPE,
				// The JS half renders through a `mount`/`unmount` DOM hand-off, so
				// this MUST declare the same mode under the shared id or the
				// surface blanks.
				renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
			);

			// Render-only leaf: no IntegrationProvider (null). The tab and widget
			// read stages and actions through OpenRegister's own object API and
			// write through decidiq's own controller, so there is no app-local
			// store to serve behind this leaf.
			$event->registerLeaf($descriptor, null);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log and skip our own leaf only.
			$this->logger->warning(
				'Decidiq could not register the decidiq-approval-chain leaf: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * Whether the OpenRegister beside us understands the `loadStrategy` argument.
	 *
	 * 🔴 A DECLARATION ABOUT HOW A LEAF LOADS MUST NEVER BE WHY IT DOES NOT LOAD.
	 *
	 * `loadStrategy` and the `LOADS_*` constants arrived together in
	 * openregister#3956. Decidiq does not choose which OpenRegister an admin runs
	 * it beside, and reading a constant that version does not declare is an
	 * `Error`. The catch in `handle()` then swallows it, the leaf is simply
	 * absent, and a warning in nextcloud.log is the only trace. hermiq measured
	 * exactly that on a live instance: seven occurrences in the log and the leaf
	 * never registered at all.
	 *
	 * Both halves are checked rather than one standing in for the other: the
	 * constant is what this listener reads, the parameter is what it passes, and
	 * a stub or a partial backport can carry one without the other.
	 *
	 * `protected` so a test can drive the negative branch. Two versions of one
	 * class cannot both be loaded to be compared directly, so the seam is the
	 * only way to assert what happens beside the older one.
	 *
	 * @return bool Whether the descriptor accepts a load strategy.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	protected function descriptorSupportsLoadStrategy(): bool {
		if (defined(LeafDescriptor::class . '::LOADS_VIA_OWN_SCRIPT') === false) {
			return false;
		}

		$constructor = (new ReflectionClass(LeafDescriptor::class))->getConstructor();
		if ($constructor === null) {
			return false;
		}

		foreach ($constructor->getParameters() as $parameter) {
			if ($parameter->getName() === 'loadStrategy') {
				return true;
			}
		}

		return false;

	}//end descriptorSupportsLoadStrategy()
}//end class
