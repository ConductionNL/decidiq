<?php

/**
 * Decidiq IntegriqConnection
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Finds the integriq source an administrator linked to one of decidiq's
 * declared connections, and reads the HTTP status of an integriq call answer.
 *
 * Connection rows and sources are admin configuration: they are read in system
 * context, as integriq itself does, so a user without rights on the integriq
 * register still reaches the linked service.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */
class IntegriqConnection {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (integriq's connection rows and sources).
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether a source is linked to the connection; false when the lookup fails.
	 *
	 * @param string $connectionKey The connection key decidiq declares.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return bool
	 */
	public function isLinked(string $connectionKey): bool {
		try {
			return $this->source(connectionKey: $connectionKey) !== null;
		} catch (Throwable $e) {
			return false;
		}
	}//end isLinked()

	/**
	 * The integriq source linked to the connection, or null.
	 *
	 * @param string $connectionKey The connection key decidiq declares.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return object|null
	 */
	public function source(string $connectionKey): ?object {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'integriq', 'schema' => 'app_connection', 'app' => 'decidiq', 'key' => $connectionKey]],
			_rbac: false,
			_multitenancy: false
		);

		foreach (($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$data = $row->getObject();
			}

			$sourceId = (string)(((array)$data)['source'] ?? '');
			if ($sourceId === '') {
				continue;
			}

			return $this->objectService->find(
				id: $sourceId,
				options: new ObjectFindOptions(register: 'integriq', schema: 'source'),
				_rbac: false,
				_multitenancy: false
			);
		}

		return null;
	}//end source()

	/**
	 * The HTTP status of an integriq call answer, 200 when it names none.
	 *
	 * @param mixed $response The call answer.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return int
	 */
	public function statusOf(mixed $response): int {
		$raw = [];
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$raw = (array)$response->getResponse();
		}

		if ($raw === [] && is_object($response) === true && method_exists($response, 'getObject') === true) {
			$raw = (array)(((array)$response->getObject())['response'] ?? []);
		}

		return (int)($raw['statusCode'] ?? 200);
	}//end statusOf()
}//end class
