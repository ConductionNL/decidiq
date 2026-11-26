<?php

/**
 * Test stub of filinq's DocumentSigningRequestedEvent (filinq development, lib/Event/DocumentSigningRequestedEvent.php),
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
 * A consumer app asks filinq to raise a signing request.
 */
class DocumentSigningRequestedEvent extends Event {
	/**
	 * @var string|null
	 */
	private ?string $signingRequestId = null;

	/**
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param SigningProvenance $provenance        Who asked, about which object
	 * @param string            $subjectLabel      Display label
	 * @param string            $documentReference The file id of the PDF
	 * @param array<int, mixed> $signers           Ordered signers
	 * @param string            $signatureLevel    SES|AdES|QES
	 * @param string            $signingMode       sequential|parallel
	 */
	public function __construct(
		private readonly SigningProvenance $provenance,
		private readonly string $subjectLabel = '',
		private readonly string $documentReference = '',
		private readonly array $signers = [],
		private readonly string $signatureLevel = 'SES',
		private readonly string $signingMode = 'sequential',
	) {
		parent::__construct();
	}

	/**
	 * @return SigningProvenance
	 */
	public function getProvenance(): SigningProvenance {
		return $this->provenance;
	}

	/**
	 * @return string
	 */
	public function getSubjectLabel(): string {
		return $this->subjectLabel;
	}

	/**
	 * @return string
	 */
	public function getDocumentReference(): string {
		return $this->documentReference;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getSigners(): array {
		return $this->signers;
	}

	/**
	 * @return string
	 */
	public function getSignatureLevel(): string {
		return $this->signatureLevel;
	}

	/**
	 * @return string
	 */
	public function getSigningMode(): string {
		return $this->signingMode;
	}

	/**
	 * @return string|null
	 */
	public function getSigningRequestId(): ?string {
		return $this->signingRequestId;
	}

	/**
	 * @param string $signingRequestId The created request id
	 *
	 * @return void
	 */
	public function setSigningRequestId(string $signingRequestId): void {
		$this->signingRequestId = $signingRequestId;
	}

	/**
	 * @return bool
	 */
	public function isHandled(): bool {
		return $this->handled;
	}

	/**
	 * @param bool $handled Whether filinq took the request
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}

	/**
	 * @return string|null
	 */
	public function getRefusal(): ?string {
		return $this->refusal;
	}

	/**
	 * @param string $refusal Why filinq refused
	 *
	 * @return void
	 */
	public function setRefusal(string $refusal): void {
		$this->refusal = $refusal;
	}
}
