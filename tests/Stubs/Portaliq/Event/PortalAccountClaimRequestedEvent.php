<?php

/**
 * Test stub of portaliq's PortalAccountClaimRequestedEvent
 *
 * Mirrors the real class's constructor and result slot. Loaded only when
 * portaliq is absent.
 *
 * @category Tests
 * @package  OCA\Portaliq\Event
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app asks portaliq to write a claim on a portal account (stub).
 */
class PortalAccountClaimRequestedEvent extends Event {

	/** @var string */
	private string $result = '';

	/**
	 * Constructor.
	 *
	 * @param string $appId      The dispatching app.
	 * @param string $subjectRef The account.
	 * @param string $claimName  The claim.
	 * @param string $value      The claim's value.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly string $subjectRef,
		private readonly string $claimName,
		private readonly string $value,
	) {
		parent::__construct();
	}//end __construct()

	/** @return string */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/** @return string */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	/** @return string */
	public function getClaimName(): string {
		return $this->claimName;
	}//end getClaimName()

	/** @return string */
	public function getValue(): string {
		return $this->value;
	}//end getValue()

	/**
	 * Portaliq's answer.
	 *
	 * @param string $result 'ok' or 'refused'.
	 *
	 * @return void
	 */
	public function answer(string $result): void {
		$this->result = $result;
	}//end answer()

	/** @return string */
	public function getResult(): string {
		return $this->result;
	}//end getResult()
}//end class
