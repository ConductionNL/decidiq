<?php

/**
 * Moves a governing-document version to `replaced` once its successor holds.
 *
 * Nothing else writes a version's state on after a later version of its
 * document is put in effect, so the register showed two versions in effect at
 * once. Run once a day with the release of planned documents: per document,
 * every version in effect that a later sealed version has taken over from
 * (its effective date reached) is patched to `replaced`. A successor with a
 * future effective date replaces nothing yet. Only the state is patched; the
 * frozen fields of a sealed version are never sent.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\GoverningDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\GoverningDocument;

use DateTimeImmutable;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\Decidiq\Service\SystemObjectRows;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Patches superseded governing-document versions to `replaced`.
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 */
class GoverningDocumentSuccession {

	/**
	 * The state a version holds while it is in effect.
	 */
	private const IN_EFFECT = 'in-effect';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads and patches the versions.
	 * @param LoggerInterface        $logger        Logs a patch that failed.
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One run: patch every superseded version in effect to `replaced`.
	 *
	 * @param DateTimeImmutable $today The day of the run.
	 *
	 * @return array{replaced: int, failed: int} How many moved, and how many patches were refused.
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemObjectRows::read is the shared stateless system read the other sweeps use.
	 */
	public function settle(DateTimeImmutable $today): array {
		$versions = SystemObjectRows::read(
			objectService: $this->objectService,
			logger: $this->logger,
			filters: ['schema' => GoverningDocumentConsolidationService::VERSION_SCHEMA, 'status' => self::IN_EFFECT],
			warning: 'Decidiq: governing-document versions in effect could not be read for the succession'
		);

		$result = ['replaced' => 0, 'failed' => 0];
		foreach (self::superseded(versions: $versions, today: $today->format('Y-m-d')) as $id) {
			$result[$this->markReplaced(id: $id)]++;
		}

		return $result;
	}//end settle()

	/**
	 * The ids of the versions in effect that a later version of the same document has taken over from on a day.
	 *
	 * Per document, the version in effect with the latest effective date on
	 * or before the day holds; every version in effect with an earlier
	 * effective date is superseded. A version without an id, a document or an
	 * effective date is left alone.
	 *
	 * @param array<int, array<string, mixed>> $versions The versions read.
	 * @param string                           $today    The day, Y-m-d.
	 *
	 * @return list<string> The superseded version ids.
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public static function superseded(array $versions, string $today): array {
		$dated = self::datedByDocument(versions: $versions);

		$out = [];
		foreach ($dated as $byId) {
			$started = array_filter($byId, static fn (string $effective): bool => $effective <= $today);
			if ($started === []) {
				continue;
			}

			$holding = max($started);
			foreach ($byId as $id => $effective) {
				if ($effective < $holding) {
					$out[] = (string)$id;
				}
			}
		}

		return $out;
	}//end superseded()

	/**
	 * The effective date of every usable version in effect, by document then version id.
	 *
	 * @param array<int, array<string, mixed>> $versions The versions read.
	 *
	 * @return array<string, array<string, string>> Document id to version id to Y-m-d.
	 */
	private static function datedByDocument(array $versions): array {
		$dated = [];
		foreach ($versions as $version) {
			$id        = self::idOf(value: $version);
			$document  = self::idOf(value: ($version['document'] ?? null));
			$effective = substr((string)($version['effectiveDate'] ?? ''), 0, 10);
			$usable    = ($version['status'] ?? null) === self::IN_EFFECT && $id !== '' && $document !== '' && strlen($effective) === 10;
			if ($usable === true) {
				$dated[$document][$id] = $effective;
			}
		}

		return $dated;
	}//end datedByDocument()

	/**
	 * An id: a plain string, or the id an object carries.
	 *
	 * @param mixed $value The id, a reference object or a row.
	 *
	 * @return string The id, or '' when there is none.
	 */
	private static function idOf(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ($value['@self']['id'] ?? null)));
		}

		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end idOf()

	/**
	 * Patch one version's state to `replaced`, as the system.
	 *
	 * @param string $id The version id.
	 *
	 * @return string `replaced`, or `failed` when the patch was refused (logged).
	 */
	private function markReplaced(string $id): string {
		try {
			$this->objectService->patchObject(
				new ObjectPatch(
					objectId: $id,
					data: ['status' => 'replaced'],
					target: new ObjectFindTarget(register: 'decidiq', schema: GoverningDocumentConsolidationService::VERSION_SCHEMA)
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: a superseded governing-document version could not be moved to replaced',
				['version' => $id, 'reason' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'replaced';
	}//end markReplaced()
}//end class
