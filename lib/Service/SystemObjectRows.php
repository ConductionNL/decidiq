<?php

/**
 * Reads decidiq objects matching a filter as plain arrays, in system context.
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
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-lapses-when-the-body-does-not-ratify-it-at-the-next-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The objects of the decidiq register that match a filter, as arrays; none when
 * they cannot be read, with a warning naming the filter.
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-lapses-when-the-body-does-not-ratify-it-at-the-next-meeting
 */
final class SystemObjectRows {

	/**
	 * The register the objects live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The objects matching a filter, read without RBAC or multitenancy.
	 *
	 * @param ObjectServiceInterface $objectService The object service.
	 * @param LoggerInterface        $logger        Where a failed read is reported.
	 * @param array<string, string>  $filters       The filters, schema included.
	 * @param string                 $warning       The warning logged when the read fails.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-lapses-when-the-body-does-not-ratify-it-at-the-next-meeting
	 */
	public static function read(ObjectServiceInterface $objectService, LoggerInterface $logger, array $filters, string $warning): array {
		try {
			$found = $objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER] + $filters],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$logger->warning($warning, ['filters' => $filters, 'error' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end read()
}//end class
