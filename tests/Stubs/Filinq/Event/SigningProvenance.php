<?php

/**
 * Test stub of filinq's SigningProvenance (filinq development, lib/Event/SigningProvenance.php),
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

/**
 * Who asked for a signature, and about which object.
 */
class SigningProvenance {
	/**
	 * Constructor.
	 *
	 * @param string      $sourceApp         The requesting app
	 * @param string|null $subjectRegister   The subject's register
	 * @param string|null $subjectSchema     The subject's schema
	 * @param string|null $subjectId         The subject's id
	 * @param string      $externalReference The consumer's reference
	 * @param string      $correlationId     The correlation id
	 */
	public function __construct(
		private readonly string $sourceApp = '',
		private readonly ?string $subjectRegister = null,
		private readonly ?string $subjectSchema = null,
		private readonly ?string $subjectId = null,
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {
	}

	/**
	 * @return string
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}

	/**
	 * @return string|null
	 */
	public function getSubjectRegister(): ?string {
		return $this->subjectRegister;
	}

	/**
	 * @return string|null
	 */
	public function getSubjectSchema(): ?string {
		return $this->subjectSchema;
	}

	/**
	 * @return string|null
	 */
	public function getSubjectId(): ?string {
		return $this->subjectId;
	}

	/**
	 * @return string
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}

	/**
	 * @return string
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}
}
