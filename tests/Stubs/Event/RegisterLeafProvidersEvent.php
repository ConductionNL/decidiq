<?php

/**
 * Test stub for OpenRegister RegisterLeafProvidersEvent.
 *
 * Stands in for OCA\OpenRegister\Event\RegisterLeafProvidersEvent when
 * OpenRegister is not installed (standalone CI). Mirrors the real collect-event's
 * `registerLeaf()` / `getLeaves()` API — including the `['descriptor' =>, 'provider' =>]`
 * tuple shape `LeafRegistry` reads — so RegisterDecisionsLeafListener is
 * unit-tested against the same surface it meets in production. The real event
 * ships with OpenRegister (lib/Event/RegisterLeafProvidersEvent.php, ADR-066).
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;

/**
 * Minimal RegisterLeafProvidersEvent stub for standalone unit runs.
 */
class RegisterLeafProvidersEvent extends Event {

	/**
	 * Leaves contributed during this dispatch.
	 *
	 * @var array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}>
	 */
	private array $leaves = [];

	/**
	 * Contribute a leaf.
	 *
	 * @param LeafDescriptor $descriptor The leaf declaration.
	 * @param IntegrationProvider|null $provider The data provider, or null for a render-only leaf.
	 *
	 * @return void
	 */
	public function registerLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider = null): void {
		$this->leaves[] = [
			'descriptor' => $descriptor,
			'provider' => $provider,
		];
	}//end registerLeaf()

	/**
	 * Every leaf contributed during this dispatch.
	 *
	 * @return array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}> The leaves.
	 */
	public function getLeaves(): array {
		return $this->leaves;
	}//end getLeaves()
}//end class
