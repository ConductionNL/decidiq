<?php

/**
 * Accepts a parent toedeling only when it exists and permits ondermandaat, and
 * only when walking the parents never revisits a toedeling. Fails closed: a
 * parent that cannot be read refuses the save.
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
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\OndermandaatRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * The save-path guard on a bevoegdheidstoedeling's parentAllocation.
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 */
class OndermandaatGuardService {

	/**
	 * The toedeling schema. It was renamed from `bevoegdheidstoedeling`
	 * (MigrateTheLastTwoDutchNames moves the rows), so parents are read here.
	 *
	 * @var string
	 */
	public const SCHEMA = 'authority-delegation';

	/**
	 * The schemas a toedeling is saved on: the current one, and the old Dutch
	 * slug, which stays guarded while an install still writes to it.
	 *
	 * @var list<string>
	 */
	public const SCHEMAS = [self::SCHEMA, 'bevoegdheidstoedeling'];

	/**
	 * The deepest chain walked; a longer one is refused as if it looped.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 32;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the parent toedelingen.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Refuse a toedeling whose parent forbids ondermandaat, or whose chain loops.
	 *
	 * @param array<string, mixed> $toedeling The toedeling about to be saved.
	 *
	 * @return void
	 *
	 * @throws OndermandaatRefusedException When the parent is missing, unreadable or forbids ondermandaat, or the chain loops.
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
	 */
	public function assertAllowed(array $toedeling): void {
		$parentId = self::reference(value: ($toedeling['parentAllocation'] ?? null));
		if ($parentId === '') {
			return;
		}

		$ownId   = self::reference(value: ($toedeling['id'] ?? ($toedeling['uuid'] ?? ($toedeling['@self']['id'] ?? null))));
		$visited = [];
		if ($ownId !== '') {
			$visited[$ownId] = true;
		}

		$current = $parentId;
		for ($depth = 0; $current !== ''; $depth++) {
			if (isset($visited[$current]) === true || $depth >= self::MAX_DEPTH) {
				throw new OndermandaatRefusedException(
					message: 'The toedeling was not saved: its parent chain leads back to itself. An ondermandaat chain may not loop.'
				);
			}

			$visited[$current] = true;
			$parent = $this->parent(parentId: $current);
			if ($depth === 0 && ($parent['subMandatePermitted'] ?? false) !== true) {
				throw new OndermandaatRefusedException(
					message: 'The toedeling was not saved: its parent does not permit ondermandaat.'
				);
			}

			$current = self::reference(value: ($parent['parentAllocation'] ?? null));
		}
	}//end assertAllowed()

	/**
	 * A parent toedeling.
	 *
	 * @param string $parentId The parent's uuid.
	 *
	 * @return array<string, mixed> The parent.
	 *
	 * @throws OndermandaatRefusedException When it does not exist or cannot be read.
	 */
	private function parent(string $parentId): array {
		try {
			$entity = $this->objectService->find(id: $parentId, register: 'decidiq', schema: self::SCHEMA, _rbac: false);
		} catch (DoesNotExistException) {
			$entity = null;
		} catch (Throwable $e) {
			throw new OndermandaatRefusedException(
				message: 'The toedeling was not saved: its parent could not be read to check the ondermandaat chain.',
				code: 0,
				previous: $e
			);
		}

		if ($entity === null) {
			throw new OndermandaatRefusedException(message: 'The toedeling was not saved: its parent toedeling does not exist.');
		}

		return (array)$entity->jsonSerialize();
	}//end parent()

	/**
	 * A reference as a uuid string: a plain id, or an object carrying one.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The id, or '' when there is none.
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end reference()
}//end class
