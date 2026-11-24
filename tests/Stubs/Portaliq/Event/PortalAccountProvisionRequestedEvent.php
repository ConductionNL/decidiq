<?php

/**
 * Test stub of portaliq's PortalAccountProvisionRequestedEvent
 *
 * Mirrors the real class's constructor, result slot and refusal, so decidiq's
 * reader flow is tested without portaliq. Loaded only when portaliq is absent.
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
 * An app asks portaliq to provision a pending portal account (stub).
 */
class PortalAccountProvisionRequestedEvent extends Event {

	/** @var string */
	private string $subjectRef = '';

	/** @var string */
	private string $status = '';

	/** @var string */
	private string $refusal = '';

	/**
	 * Constructor.
	 *
	 * @param string $appId        The dispatching app.
	 * @param string $audience     The audience.
	 * @param string $organisation The tenant slug.
	 * @param string $identityType The identity type.
	 * @param string $identityRef  The identity reference.
	 * @param string $email        A contact address.
	 * @param bool   $verifiedEmail Whether the address was verified.
	 * @param string $displayName  The display name.
	 * @param string $nextcloudUid A Nextcloud user id, or ''.
	 * @param string $portal       The portal slug, or ''.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		private readonly string $appId,
		private readonly string $audience,
		private readonly string $organisation,
		private readonly string $identityType = '',
		private readonly string $identityRef = '',
		private readonly string $email = '',
		private readonly bool $verifiedEmail = false,
		private readonly string $displayName = '',
		private readonly string $nextcloudUid = '',
		private readonly string $portal = '',
	) {
		parent::__construct();
	}//end __construct()

	/** @return string */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/** @return string */
	public function getAudience(): string {
		return $this->audience;
	}//end getAudience()

	/** @return string */
	public function getOrganisation(): string {
		return $this->organisation;
	}//end getOrganisation()

	/** @return string */
	public function getIdentityType(): string {
		return $this->identityType;
	}//end getIdentityType()

	/** @return string */
	public function getIdentityRef(): string {
		return $this->identityRef;
	}//end getIdentityRef()

	/** @return string */
	public function getDisplayName(): string {
		return $this->displayName;
	}//end getDisplayName()

	/**
	 * Portaliq's answer.
	 *
	 * @param string $subjectRef The account's subject reference.
	 * @param string $status     The account's status.
	 *
	 * @return void
	 */
	public function answer(string $subjectRef, string $status): void {
		$this->subjectRef = $subjectRef;
		$this->status = $status;
		$this->refusal = '';
	}//end answer()

	/**
	 * Portaliq's refusal.
	 *
	 * @param string $reason The reason.
	 *
	 * @return void
	 */
	public function refuse(string $reason): void {
		$this->subjectRef = '';
		$this->status = '';
		$this->refusal = $reason;
	}//end refuse()

	/** @return string */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	/** @return string */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/** @return string */
	public function getRefusal(): string {
		return $this->refusal;
	}//end getRefusal()
}//end class
