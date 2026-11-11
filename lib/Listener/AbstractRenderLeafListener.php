<?php

/**
 * Decidiq AbstractRenderLeafListener.
 *
 * The shared half of decidiq's render-leaf listeners: it answers OpenRegister's
 * `RegisterLeafProvidersEvent`, adds the own-script load strategy when the
 * OpenRegister beside it understands one, and logs instead of throwing when the
 * registration fails. Each subclass constructs its own LeafDescriptor,
 * because `scripts/check-integration-parity.js` (gate-24) reads that call as
 * source in the file that declares the leaf.
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
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafRendering;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Contributes one render leaf to OpenRegister's catalogue.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */
abstract class AbstractRenderLeafListener implements IEventListener {

	/**
	 * The shared leaf id; each subclass declares its own.
	 *
	 * @var string
	 */
	public const LEAF_ID = '';

	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   Localisation for the human-readable label.
	 * @param LoggerInterface $logger PSR-3 logger (a throwing listener costs only its own leaf).
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the leaf descriptor.
	 *
	 * The optional arguments must be UNPACKED FIRST and every agreed field must
	 * stay a written-out named argument: PHP refuses unpacking after a named
	 * argument, and gate-24 correlates the two halves of a leaf by reading those
	 * `name: value` arguments as source.
	 *
	 * @param array<string, string> $optional LeafRendering arguments only some OpenRegister versions accept.
	 *
	 * @return LeafDescriptor The descriptor.
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	abstract protected function descriptor(array $optional): LeafDescriptor;

	/**
	 * Contribute the leaf descriptor, render-only (no IntegrationProvider).
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		try {
			$optional = [];
			if ($this->descriptorSupportsLoadStrategy() === true) {
				// Decidiq ships no `decidiq-leaves.js` and does not need one: it
				// loads `decidiq-integration-init.js` itself, on EVERY Nextcloud
				// page, via `Util::addInitScript` in `Application::boot()`, so its
				// leaves register wherever a host object renders. openregister#3954
				// read the missing file as proof the surface was dark; #3956
				// replaced that inference with this declaration.
				$optional['loadStrategy'] = LeafDescriptor::LOADS_VIA_OWN_SCRIPT;
			}

			$event->registerLeaf($this->descriptor(optional: $optional), null);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log and skip this leaf only.
			$this->logger->warning(
				'Decidiq could not register the ' . static::LEAF_ID . ' leaf: ' . $e->getMessage(),
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
	 * absent, and a warning in nextcloud.log is the only trace.
	 *
	 * Both halves are checked rather than one standing in for the other: the
	 * constant is what this listener reads, the `LeafRendering` parameter is
	 * what it passes, and
	 * a stub or a partial backport can carry one without the other.
	 *
	 * `protected` so a test can drive the negative branch. Two versions of one
	 * class cannot both be loaded to be compared directly, so the seam is the
	 * only way to assert what happens beside the older one.
	 *
	 * @return bool Whether the descriptor accepts a load strategy.
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	protected function descriptorSupportsLoadStrategy(): bool {
		if (defined(LeafDescriptor::class . '::LOADS_VIA_OWN_SCRIPT') === false) {
			return false;
		}

		$constructor = (new ReflectionClass(LeafRendering::class))->getConstructor();
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
