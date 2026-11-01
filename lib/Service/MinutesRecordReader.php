<?php

/**
 * Decidiq Minutes Record Reader
 *
 * Loads a Minutes object under the caller's OpenRegister ACL and resolves the
 * Meeting it links to.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads a Minutes object and its linked Meeting.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesRecordReader {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch a Minutes object, or null when the caller cannot read it.
	 *
	 * The register and schema are set on the object service first, so
	 * OpenRegister's session-based ACL applies to the find (OWASP A01): a
	 * caller without read access gets null.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return array<string,mixed>|null The Minutes data, or null
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function find(string $minutesId): ?array {
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema('minutes');
		$minutesEntity = $this->objectService->find($minutesId);

		if ($minutesEntity === null) {
			return null;
		}

		return $minutesEntity->getObject();
	}//end find()

	/**
	 * Fetch a Minutes object or fail.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @throws MissingObjectException When the caller cannot read the Minutes object
	 *
	 * @return array<string,mixed> The Minutes data
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function require(string $minutesId): array {
		$minutes = $this->find(minutesId: $minutesId);
		if ($minutes === null) {
			throw new MissingObjectException(
				message: sprintf('Minutes object "%s" not found.', $minutesId)
			);
		}

		return $minutes;
	}//end require()

	/**
	 * Resolve the Meeting linked to a Minutes object.
	 *
	 * The link is either an embedded meeting carrying an id, returned as is, or
	 * a meeting UUID, fetched from the register.
	 *
	 * @param array<string,mixed> $minutes The Minutes object data
	 * @param string $failureLog The warning logged when the meeting fetch fails
	 *
	 * @throws RuntimeException With code 503 when OpenRegister fails on the fetch
	 *
	 * @return array<string,mixed>|null The Meeting data, or null when none is linked or found
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function linkedMeeting(array $minutes, string $failureLog): ?array {
		$meetingRelation = $minutes['relations']['meeting'] ?? $minutes['meeting'] ?? null;

		if (is_array($meetingRelation) === true) {
			if (isset($meetingRelation['id']) === true && $meetingRelation['id'] !== '') {
				return $meetingRelation;
			}

			return null;
		}

		// A null relation casts to '' and is rejected here.
		$meetingId = (string)$meetingRelation;
		if ($meetingId === '') {
			return null;
		}

		try {
			$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
			if ($meetingEntity === null) {
				return null;
			}

			return $meetingEntity->getObject();
		} catch (Throwable $e) {
			$this->logger->warning($failureLog, ['exception' => $e->getMessage(), 'meetingId' => $meetingId]);
			// A 503 lets the caller tell a transient OpenRegister outage from a
			// missing relation (null above).
			throw new RuntimeException(
				'OpenRegister service is temporarily unavailable. Please try again later.',
				503,
				$e
			);
		}//end try

	}//end linkedMeeting()
}//end class
