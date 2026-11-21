<?php

/**
 * Declaration stub of OpenRegister's Db\FlowRun (an Entity with magic accessors in production).
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

namespace OCA\OpenRegister\Db;

/**
 * A flow run.
 */
class FlowRun {

	public const STATUS_SUSPENDED = 'suspended';

	/**
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * @var string|null
	 */
	private ?string $status = null;

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $context = null;

	/**
	 * @param string|null $uuid The run uuid.
	 *
	 * @return void
	 */
	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}

	/**
	 * @return string|null The run uuid.
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}

	/**
	 * @param string|null $status The run status.
	 *
	 * @return void
	 */
	public function setStatus(?string $status): void {
		$this->status = $status;
	}

	/**
	 * @return string|null The run status.
	 */
	public function getStatus(): ?string {
		return $this->status;
	}

	/**
	 * @param array<string, mixed>|null $context The run context.
	 *
	 * @return void
	 */
	public function setContext(?array $context): void {
		$this->context = $context;
	}

	/**
	 * @return array<string, mixed>|null The run context.
	 */
	public function getContext(): ?array {
		return $this->context;
	}
}//end class
