<?php

/**
 * Test stub of filinq's SigningConcludedEvent (filinq development, lib/Event/SigningConcludedEvent.php),
 * reduced to what decidiq reads and writes. decidiq reaches filinq by string
 * class name through FleetAppId, so it stays installable without filinq;
 * this stub loads only when filinq's real class is absent.
 *
 * @category Test
 * @package  OCA\Filinq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Filinq\Event;

use OCP\EventDispatcher\Event;

/**
 * filinq reports a concluded delegated signing request.
 */
class SigningConcludedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string            $signingRequestId  The request id
	 * @param string            $status            signed|declined|expired|cancelled
	 * @param string|null       $signedDocumentRef The signed document, when signed
	 * @param array<int, mixed> $signers           The signers
	 * @param string|null       $signedAt          When it concluded
	 * @param SigningProvenance $provenance        Who asked, about which object
	 */
	public function __construct(
		private readonly string $signingRequestId,
		private readonly string $status,
		private readonly ?string $signedDocumentRef,
		private readonly array $signers,
		private readonly ?string $signedAt,
		private readonly SigningProvenance $provenance,
	) {
		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function getSigningRequestId(): string {
		return $this->signingRequestId;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * @return string|null
	 */
	public function getSignedDocumentRef(): ?string {
		return $this->signedDocumentRef;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getSigners(): array {
		return $this->signers;
	}

	/**
	 * @return string|null
	 */
	public function getSignedAt(): ?string {
		return $this->signedAt;
	}

	/**
	 * @return string
	 */
	public function getSourceApp(): string {
		return $this->provenance->getSourceApp();
	}

	/**
	 * @return string|null
	 */
	public function getSubjectSchema(): ?string {
		return $this->provenance->getSubjectSchema();
	}

	/**
	 * @return string|null
	 */
	public function getSubjectId(): ?string {
		return $this->provenance->getSubjectId();
	}
}
