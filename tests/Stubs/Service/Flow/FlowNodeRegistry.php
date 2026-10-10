<?php

/**
 * Declaration stub of OpenRegister's Service\Flow\FlowNodeRegistry.
 *
 * Mirrors ONLY the members decidiq calls, with the real signatures taken from
 * ConductionNL/openregister@ecaba04a (development). OpenRegister is a sibling
 * app, not a composer dependency; the real class wins whenever it is loaded.
 * Behaviour lives in OpenRegister and is tested there; tests here double it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use UnexpectedValueException;

/**
 * The node catalogue. Registration and lookup only.
 */
class FlowNodeRegistry {

	/**
	 * @var array<string, IFlowNode>
	 */
	private array $nodes = [];

	/**
	 * @param IFlowNode $node The node type.
	 *
	 * @return void
	 */
	public function register(IFlowNode $node): void {
		$this->nodes[$node->getId()] = $node;
	}

	/**
	 * @param integer|null $scope The workflow scope.
	 *
	 * @return array<string, IFlowNode> The registered nodes by id.
	 */
	public function all(?int $scope = null): array {
		return $this->nodes;
	}

	/**
	 * @param string $type The node id.
	 *
	 * @return IFlowNode The node.
	 *
	 * @throws UnexpectedValueException When no node provides that id.
	 */
	public function get(string $type): IFlowNode {
		if (isset($this->nodes[$type]) === false) {
			throw new UnexpectedValueException(sprintf('No node provides "%s".', $type));
		}

		return $this->nodes[$type];
	}
}//end class
