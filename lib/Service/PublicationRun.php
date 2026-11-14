<?php

/**
 * Decidiq PublicationRun
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
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * One publication of a source object, filled in step by step: the eligible
 * source and its body, the persisted public payload, and where it was
 * catalogued. Publish and rectify each build one and never let it leave
 * PublicationService.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
final class PublicationRun {

	/**
	 * Nextcloud UID of the publishing staff member.
	 *
	 * @var string
	 */
	public string $actorId = '';

	/**
	 * The payload version this run publishes.
	 *
	 * @var int
	 */
	public int $version = 1;

	/**
	 * The eligible source object's data.
	 *
	 * @var array<string, mixed>
	 */
	public array $source = [];

	/**
	 * The source's governance body, when it names one.
	 *
	 * @var string|null
	 */
	public ?string $bodyId = null;

	/**
	 * The public payload as persisted.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload = [];

	/**
	 * The papers the payload made public.
	 *
	 * @var array<int, mixed>
	 */
	public array $papers = [];

	/**
	 * The accessibility override the publication went ahead with, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $accessibilityOverride = null;

	/**
	 * The persisted payload's id.
	 *
	 * @var string
	 */
	public string $payloadId = '';

	/**
	 * When the payload became public, as an ISO-8601 instant.
	 *
	 * @var string
	 */
	public string $publishedAt = '';

	/**
	 * The OpenCatalogi catalog the payload is routed to, or ''.
	 *
	 * @var string
	 */
	public string $targetCatalog = '';

	/**
	 * The catalog publication, or '' when it was not catalogued.
	 *
	 * @var string
	 */
	public string $catalogRef = '';

	/**
	 * What went wrong without stopping the publication.
	 *
	 * @var list<string>
	 */
	public array $warnings = [];

	/**
	 * Start a run for one source object.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId   UUID of the source object.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $sourceType,
		public readonly string $sourceId,
	) {
	}//end __construct()
}//end class
