<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The register reads and writes behind the member checklist walk, and the two
 * steps decidiq runs on its own: an installation creates the membership and an
 * end step end-dates it.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 * @spec     openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Register reads and writes of OnboardingChecklistService, and its membership effects.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
 */
class OnboardingStepEffects {

	/**
	 * The register.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Run the membership effect of an installation or end step; other steps have none.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $effect The record's kind and the step's type, as `<kind>:<stepType>`.
	 *
	 * @return array<string, mixed> The record, with the membership it now refers to.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
	 */
	public function apply(array $record, string $effect): array {
		if ($effect === 'onboarding:installation') {
			return $this->createMembership(record: $record);
		}

		if ($effect === 'offboarding:end-membership') {
			$this->endMembership(record: $record);
		}

		return $record;
	}//end apply()

	/**
	 * Create the membership an installation starts, once.
	 *
	 * @param array<string, mixed> $record The onboarding.
	 *
	 * @return array<string, mixed> The onboarding, referring to the membership.
	 *
	 * @throws OnboardingStepRefusedException When the installation is not recorded yet.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
	 */
	private function createMembership(array $record): array {
		self::assertInstallationRecorded(record: $record);

		if (is_string($record['membership'] ?? null) === true && $record['membership'] !== '') {
			return $record;
		}

		$membership = [
			'person' => $record['person'],
			'governanceBody' => $record['targetBody'],
			'role' => $record['targetRole'],
			'startDate' => $record['installedOn'],
		];
		$saved = $this->persist(object: $membership, schema: 'membership');
		$record['membership'] = (string)$saved->getUuid();

		return $record;
	}//end createMembership()

	/**
	 * Refuse an installation whose date, type or meeting is not recorded.
	 *
	 * @param array<string, mixed> $record The onboarding record.
	 *
	 * @return void
	 *
	 * @throws OnboardingStepRefusedException When one of them is missing.
	 */
	private static function assertInstallationRecorded(array $record): void {
		foreach (['installedOn', 'installationType', 'installationMeeting'] as $field) {
			if (is_string($record[$field] ?? null) === false || trim($record[$field]) === '') {
				throw new OnboardingStepRefusedException(message: 'Record the installation date, type and meeting before completing the installation.');
			}
		}
	}//end assertInstallationRecorded()

	/**
	 * Set the membership's end date to the offboarding's.
	 *
	 * @param array<string, mixed> $record The offboarding.
	 *
	 * @return void
	 *
	 * @throws OnboardingStepRefusedException When the end date or the membership is missing.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
	 */
	private function endMembership(array $record): void {
		$endDate = ($record['endDate'] ?? null);
		if (is_string($endDate) === false || $endDate === '') {
			throw new OnboardingStepRefusedException(message: 'Record the end date before ending the membership.');
		}

		$membershipId = (string)($record['membership'] ?? '');
		$data = $this->membershipData(membershipId: $membershipId);

		$data['endDate'] = $endDate;
		$this->persist(object: $data, schema: 'membership', uuid: $membershipId);
	}//end endMembership()

	/**
	 * The stored membership an offboarding ends.
	 *
	 * @param string $membershipId The membership's id.
	 *
	 * @return array<string, mixed> Its data.
	 *
	 * @throws OnboardingStepRefusedException When there is no such membership.
	 */
	private function membershipData(string $membershipId): array {
		$data = $this->findData(id: $membershipId, schema: 'membership');
		if ($membershipId === '' || $data === null) {
			throw new OnboardingStepRefusedException(message: 'The membership to end does not exist.');
		}

		return $data;
	}//end membershipData()

	/**
	 * The stored data of an object in the decidiq register, or null when it cannot be read.
	 *
	 * @param string $id     The object's uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public function findData(string $id, string $schema): ?array {
		try {
			$data = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false)?->getObject();
		} catch (\Throwable) {
			return null;
		}

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end findData()

	/**
	 * Save an object in the decidiq register.
	 *
	 * @param array<string, mixed> $object The object's data.
	 * @param string               $schema The schema slug.
	 * @param string|null          $uuid   The object's uuid, or null to create one.
	 *
	 * @return ObjectEntityInterface The saved object.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public function persist(array $object, string $schema, ?string $uuid=null): ObjectEntityInterface {
		return $this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema), uuid: $uuid),
			_rbac: false,
			_multitenancy: false
		);
	}//end persist()
}//end class
