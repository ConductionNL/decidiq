<?php

/**
 * Decidiq GovernanceBodyStateRequestedEvent
 *
 * The READ half of the governance-body contract. `GovernanceBodyRequestedEvent`
 * lets a consumer app hold a committee in decidiq; this event lets the same
 * consumer read that committee back (its `active` flag, quorum, jurisdiction
 * and roster) without reading decidiq's register directly, which ADR-022/066
 * forbid.
 *
 * Dispatched through Nextcloud's IEventDispatcher and answered synchronously by
 * GovernanceBodyStateRequestedListener, so the consumer reads the result slots
 * straight after dispatchTyped(). A consumer without decidiq installed gets an
 * unhandled event back and falls back to its own copy.
 *
 * @category Event
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app read event: a consumer asks decidiq for a governance body it holds.
 *
 * Two answers, kept apart: `isHandled()` false means the seam could not answer
 * (decidiq absent, or the read failed) and the consumer should use its own copy;
 * handled but not `isFound()` means decidiq answered and holds no such body for
 * this consumer (or the acting user may not read it).
 *
 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
 */
class GovernanceBodyStateRequestedEvent extends Event {

	/**
	 * Whether decidiq's listener answered at all (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The body, when found (result slot). See GovernanceBodyQueryService::lookup().
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $governanceBody = null;

	/**
	 * Construct the read event.
	 *
	 * Look up by the id decidiq returned on GovernanceBodyRequestedEvent, or by
	 * the consumer's own reference. Either way only a body this consumer raised
	 * (its `sourceApp`) is returned.
	 *
	 * @param string $sourceApp         App id of the consumer (e.g. dossiq)
	 * @param string $externalReference The consumer's own id for the committee, or ''
	 * @param string $governanceBodyId  The governance body id decidiq returned, or ''
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference = '',
		private readonly string $governanceBodyId = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the consuming app id.
	 *
	 * @return string The app id
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;

	}//end getSourceApp()

	/**
	 * Get the consumer's own reference for the committee.
	 *
	 * @return string The external reference, or ''
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function getExternalReference(): string {
		return $this->externalReference;

	}//end getExternalReference()

	/**
	 * Get the governance body id the consumer holds.
	 *
	 * @return string The id, or ''
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function getGovernanceBodyId(): string {
		return $this->governanceBodyId;

	}//end getGovernanceBodyId()

	/**
	 * Whether decidiq answered this read.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function isHandled(): bool {
		return $this->handled;

	}//end isHandled()

	/**
	 * Record that decidiq answered this read.
	 *
	 * @param boolean $handled Whether the read was answered
	 *
	 * @return void
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;

	}//end setHandled()

	/**
	 * Whether a body was found.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function isFound(): bool {
		return $this->governanceBody !== null;

	}//end isFound()

	/**
	 * The body that was found, or null.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function getGovernanceBody(): ?array {
		return $this->governanceBody;

	}//end getGovernanceBody()

	/**
	 * Record the body that was found.
	 *
	 * @param array<string, mixed> $governanceBody The body
	 *
	 * @return void
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function setGovernanceBody(array $governanceBody): void {
		$this->governanceBody = $governanceBody;

	}//end setGovernanceBody()
}//end class
