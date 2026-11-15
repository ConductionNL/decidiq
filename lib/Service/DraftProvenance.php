<?php

/**
 * Decidiq Draft Provenance
 *
 * Who generated a minutes draft and when: the AI provider id and the
 * generation time, stamped on every draft section.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The provenance stamped on a generated minutes draft.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
final class DraftProvenance {
	/**
	 * Constructor.
	 *
	 * @param string $providerId  AI provider id
	 * @param string $generatedAt Generation timestamp
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		private readonly string $providerId,
		private readonly string $generatedAt,
	) {
	}//end __construct()

	/**
	 * The provenance of a draft generated now by the given provider.
	 *
	 * @param string $providerId AI provider id
	 *
	 * @return self
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public static function now(string $providerId): self {
		return new self(providerId: $providerId, generatedAt: (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
	}//end now()

	/**
	 * The provenance as stored on a section or a transcript.
	 *
	 * @return array{aiGenerated: true, providerId: string, generatedAt: string}
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function toArray(): array {
		return [
			'aiGenerated' => true,
			'providerId' => $this->providerId,
			'generatedAt' => $this->generatedAt,
		];
	}//end toArray()
}//end class
