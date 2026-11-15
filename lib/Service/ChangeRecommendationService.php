<?php

/**
 * Decidiq ChangeRecommendationService
 *
 * Accepts or rejects a recommended text change to a motion without a vote.
 * Accepting works the recommendation's passage into the motion text the same
 * way an adopted amendment is worked in (AmendmentTextMerger), then marks the
 * recommendation accepted with who decided and when. Rejecting only records
 * the decision. Both refuse a recommendation that is already decided and a
 * motion that is past deliberation; accepting also refuses a passage that no
 * longer occurs in the text, and then writes nothing.
 *
 * The caller (ChangeRecommendationController) checks the chair or secretary
 * role of the motion's meeting before calling either method.
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
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\Decidiq\Exception\ChangeRecommendationRefusedException;
use OCA\Decidiq\Kernel\Contracts\ChangeRecommendationServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;

/**
 * Accept or reject a change recommendation.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 */
class ChangeRecommendationService implements ChangeRecommendationServiceInterface {
	/**
	 * The register every object here lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The recommendation schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'change-recommendation';

	/**
	 * The motion lifecycle states in which its text may still change.
	 *
	 * @var list<string>
	 */
	public const OPEN_MOTION_STATES = ['draft', 'proposed', 'deliberating'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param AmendmentTextMerger    $merger        Works a passage into the motion text
	 * @param ITimeFactory           $time          The clock
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AmendmentTextMerger $merger,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The motion a recommendation belongs to, or null when it does not exist.
	 *
	 * @param string $recommendationId The recommendation uuid
	 *
	 * @return string|null The motion uuid.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
	 */
	public function motionIdOf(string $recommendationId): ?string {
		$recommendation = $this->data(id: $recommendationId, schema: self::SCHEMA);
		if ($recommendation === null) {
			return null;
		}

		$motionId = trim((string) ($recommendation['motion'] ?? ''));
		if ($motionId === '') {
			return null;
		}

		return $motionId;
	}//end motionIdOf()

	/**
	 * Work a pending recommendation into its motion's text and mark it accepted.
	 *
	 * The motion is written first. When the passage no longer occurs, the merge
	 * throws before anything is written, so both objects stay as they were.
	 *
	 * @param string $recommendationId The recommendation uuid
	 * @param string $deciderUid       The chair or secretary accepting it
	 *
	 * @return array{recommendation: array<string, mixed>, motion: array<string, mixed>}
	 *
	 * @throws ChangeRecommendationRefusedException When it may not be accepted.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-005-a-recommendation-whose-passage-has-changed-cannot-be-accepted
	 */
	public function accept(string $recommendationId, string $deciderUid): array {
		[$recommendation, $motionId, $motion] = $this->decidable(recommendationId: $recommendationId);
		if (trim((string) ($recommendation['targetPassage'] ?? '')) === '') {
			throw new ChangeRecommendationRefusedException(message: 'This recommendation names no passage to replace', status: 422);
		}

		try {
			$merged = $this->merger->merge(
				motionData: $motion,
				amendmentData: [
					'targetPassage' => (string) $recommendation['targetPassage'],
					'proposedText'  => (string) ($recommendation['proposedText'] ?? ''),
				],
				amendmentId: $recommendationId,
				kind: AmendmentTextMerger::KIND_RECOMMENDATION
			);
		} catch (RuntimeException $e) {
			throw new ChangeRecommendationRefusedException(message: 'The passage has changed since this recommendation was made');
		}

		if ($merged === null) {
			throw new ChangeRecommendationRefusedException(message: 'This recommendation is already in the motion text');
		}

		$savedMotion = $this->objectService->saveObject(
			object: array_merge($motion, $merged),
			register: self::REGISTER,
			schema: 'decision',
			uuid: $motionId
		);

		return [
			'recommendation' => $this->decide(
				recommendationId: $recommendationId,
				recommendation: $recommendation,
				status: 'accepted',
				deciderUid: $deciderUid
			),
			'motion'         => $savedMotion->getObject(),
		];
	}//end accept()

	/**
	 * Mark a pending recommendation rejected; the motion is not touched.
	 *
	 * @param string $recommendationId The recommendation uuid
	 * @param string $deciderUid       The chair or secretary rejecting it
	 *
	 * @return array<string, mixed> The rejected recommendation.
	 *
	 * @throws ChangeRecommendationRefusedException When it may not be rejected.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-004-a-rejected-recommendation-changes-nothing
	 */
	public function reject(string $recommendationId, string $deciderUid): array {
		[$recommendation] = $this->decidable(recommendationId: $recommendationId);

		return $this->decide(recommendationId: $recommendationId, recommendation: $recommendation, status: 'rejected', deciderUid: $deciderUid);
	}//end reject()

	/**
	 * The recommendation, its motion id and the motion, when it may still be decided.
	 *
	 * @param string $recommendationId The recommendation uuid
	 *
	 * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}
	 *
	 * @throws ChangeRecommendationRefusedException When it is missing, decided, or its motion is past deliberation.
	 */
	private function decidable(string $recommendationId): array {
		$recommendation = $this->data(id: $recommendationId, schema: self::SCHEMA);
		if ($recommendation === null) {
			throw new ChangeRecommendationRefusedException(message: 'Recommendation not found', status: 404);
		}

		if ((string) ($recommendation['status'] ?? 'pending') !== 'pending') {
			throw new ChangeRecommendationRefusedException(message: 'This recommendation has already been decided');
		}

		$motionId = trim((string) ($recommendation['motion'] ?? ''));
		$motion = null;
		if ($motionId !== '') {
			$motion = $this->data(id: $motionId, schema: 'decision');
		}

		if ($motion === null || ($motion['decisionType'] ?? null) !== 'motion') {
			throw new ChangeRecommendationRefusedException(message: 'Motion not found', status: 404);
		}

		if (in_array((string) ($motion['lifecycle'] ?? ''), self::OPEN_MOTION_STATES, true) === false) {
			throw new ChangeRecommendationRefusedException(message: 'The motion text can no longer be changed');
		}

		return [$recommendation, $motionId, $motion];
	}//end decidable()

	/**
	 * Write the decision onto the recommendation.
	 *
	 * @param string               $recommendationId The recommendation uuid
	 * @param array<string, mixed> $recommendation   Its current data
	 * @param string               $status           accepted or rejected
	 * @param string               $deciderUid       Who decided
	 *
	 * @return array<string, mixed> The saved recommendation.
	 */
	private function decide(string $recommendationId, array $recommendation, string $status, string $deciderUid): array {
		$recommendation['status'] = $status;
		$recommendation['decidedBy'] = $deciderUid;
		$recommendation['decidedAt'] = $this->time->getDateTime()->format(DateTimeInterface::ATOM);

		return $this->objectService->saveObject(
			object: $recommendation,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $recommendationId
		)->getObject();
	}//end decide()

	/**
	 * One object's data, or null when it does not exist or cannot be read.
	 *
	 * @param string $id     The object uuid
	 * @param string $schema The schema slug
	 *
	 * @return array<string, mixed>|null
	 */
	private function data(string $id, string $schema): ?array {
		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->getObject();
	}//end data()
}//end class
