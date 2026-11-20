<?php

/**
 * Creates the member onboardings and offboardings of a confirmed roster turnover.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Pathway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Pathway;

use OCA\Decidiq\Service\OnboardingProvisioningService;
use OCA\Decidiq\Service\RosterTurnoverService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Each created pathway starts with its checklist steps open and carries the
 * batch label of the confirmation. The only other write is a Person record
 * for a joining person who has none yet.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
final class TurnoverPathwayWriter {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The steps a new onboarding starts with.
	 *
	 * @var list<array{stepType: string, title: string, mandatory: bool}>
	 */
	private const ONBOARDING_STEPS = [
		['stepType' => 'installation', 'title' => 'Installation', 'mandatory' => true],
		['stepType' => 'account-linking', 'title' => 'Link the account', 'mandatory' => true],
		['stepType' => 'assign-groups', 'title' => 'Assign groups', 'mandatory' => true],
		['stepType' => 'induction-pack', 'title' => 'Induction pack', 'mandatory' => false],
		['stepType' => 'ancillary-positions-intake', 'title' => 'Ancillary positions', 'mandatory' => false],
	];

	/**
	 * The steps a new offboarding starts with.
	 *
	 * @var list<array{stepType: string, title: string, mandatory: bool}>
	 */
	private const OFFBOARDING_STEPS = [
		['stepType' => 'end-membership', 'title' => 'End the membership', 'mandatory' => true],
		['stepType' => 'revoke-groups', 'title' => 'Revoke groups', 'mandatory' => true],
		['stepType' => 'personal-data-note', 'title' => 'Personal data note', 'mandatory' => true],
		['stepType' => 'exit-confirmation', 'title' => 'Confirm the exit', 'mandatory' => true],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create one onboarding, and the Person when there is none yet.
	 *
	 * @param string              $bodyId     The governance body uuid.
	 * @param array<string,mixed> $suggestion The joining suggestion, with its batch label.
	 *
	 * @return string The new onboarding uuid.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function createOnboarding(string $bodyId, array $suggestion): string {
		$personId = (string) $suggestion['personId'];
		if ($personId === '') {
			$person = array_filter(
				[
					'name'            => $suggestion['name'],
					'email'           => $suggestion['email'],
					'nextcloudUserId' => $suggestion['nextcloudUserId'],
				],
				static fn (string $value): bool => $value !== ''
			);
			$personId = $this->idOf(saved: $this->objectService->saveObject(object: $person, options: $this->saveTo(schema: 'person')));
		}

		$record = [
			'person'     => $personId,
			'targetBody' => $bodyId,
			'targetRole' => $suggestion['role'],
			'trigger'    => RosterTurnoverService::TRIGGER,
			'batch'      => $suggestion['batch'],
			'lifecycle'  => 'started',
			'steps'      => $this->openSteps(steps: self::ONBOARDING_STEPS),
		];
		if ($suggestion['nextcloudUserId'] !== '') {
			$record['ncAccount'] = $suggestion['nextcloudUserId'];
		}

		return $this->idOf(
			saved: $this->objectService->saveObject(object: $record, options: $this->saveTo(schema: OnboardingProvisioningService::ONBOARDING))
		);
	}//end createOnboarding()

	/**
	 * Create one offboarding. The membership itself is left as it is.
	 *
	 * @param string              $bodyId     The governance body uuid.
	 * @param array<string,mixed> $suggestion The leaving suggestion, with its batch label, end date and end reason.
	 *
	 * @return string The new offboarding uuid.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function createOffboarding(string $bodyId, array $suggestion): string {
		$record = [
			'person'     => $suggestion['personId'],
			'body'       => $bodyId,
			'membership' => $suggestion['membershipId'],
			'trigger'    => RosterTurnoverService::TRIGGER,
			'batch'      => $suggestion['batch'],
			'endDate'    => $suggestion['endDate'],
			'endReason'  => $suggestion['endReason'],
			'lifecycle'  => 'started',
			'steps'      => $this->openSteps(steps: self::OFFBOARDING_STEPS),
		];

		return $this->idOf(
			saved: $this->objectService->saveObject(object: $record, options: $this->saveTo(schema: OnboardingProvisioningService::OFFBOARDING))
		);
	}//end createOffboarding()

	/**
	 * The default steps, all open.
	 *
	 * @param list<array{stepType: string, title: string, mandatory: bool}> $steps The step templates.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function openSteps(array $steps): array {
		return array_map(static fn (array $step): array => $step + ['status' => 'open'], $steps);
	}//end openSteps()

	/**
	 * The uuid of a saved object.
	 *
	 * @param mixed $saved What saveObject returned.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function idOf(mixed $saved): string {
		if ($saved instanceof ObjectEntityInterface === true && (string) $saved->getUuid() !== '') {
			return (string) $saved->getUuid();
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return (string) ($saved['id'] ?? ($saved['uuid'] ?? ''));
		}

		return '';
	}//end idOf()

	/**
	 * The save options that write an object of this register's schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectSaveOptions The save options.
	 */
	private function saveTo(string $schema): ObjectSaveOptions {
		return new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema));

	}//end saveTo()

}//end class
