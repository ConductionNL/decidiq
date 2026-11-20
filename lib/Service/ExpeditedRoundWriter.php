<?php

/**
 * Decidiq Expedited Round Writer
 *
 * Saves the expedited written round for ExpeditedRoundService: a
 * for-against-abstain voting round opened now with its deadline the given
 * number of hours later, and the decisive stage switched to a vote on it.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Saves an expedited round and links it to its stage.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
 */
class ExpeditedRoundWriter {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $timeFactory The opening time.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
	 */
	public function __construct(
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Save the round on the stage and switch the stage to a vote on it.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $stage         The open decisive stage.
	 * @param int                  $hours         The response deadline in hours.
	 *
	 * @return array<string, mixed> The saved round.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) UrgentRatificationService::idOf is the shared stateless reader of a row's id.
	 */
	public function write(object $objectService, array $stage, int $hours): array {
		$opened  = new DateTimeImmutable($this->timeFactory->getDateTime()->format(DATE_ATOM));
		$stageId = UrgentRatificationService::idOf(row: $stage);
		$round   = (array)$objectService->saveObject(object: [
			'votingMethod'   => 'for-against-abstain',
			'isSecret'       => false,
			'openedAt'       => $opened->format(DATE_ATOM),
			'votingDeadline' => $opened->add(new DateInterval('PT' . $hours . 'H'))->format(DATE_ATOM),
			'decisionStage'  => $stageId,
		], options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'voting-round')))->jsonSerialize();

		$linked = $stage;
		unset($linked['@self']);
		$linked['method']      = 'vote';
		$linked['votingRound'] = UrgentRatificationService::idOf(row: $round);
		$objectService->saveObject(
			object: $linked,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'decision-stage'), uuid: $stageId)
		);

		return $round;
	}//end write()
}//end class
