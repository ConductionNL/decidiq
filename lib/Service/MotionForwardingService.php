<?php

/**
 * Decidesk Motion Forwarding Service
 *
 * Forwards a motion to another governance body: copies it into the target
 * body, cross-links both copies with an audit note, and notifies when the
 * instance requires approval for forwarding.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The forward-a-motion path, extracted from MotionService.
 *
 * MotionService::forwardMotion() was a 110-line method that read the
 * forwarding configuration, authorised the actor, copied the motion, wrote
 * two audit notes and conditionally notified — five concerns in one body.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
 */
class MotionForwardingService {

	/**
	 * ObjectEntityInterface -> array normalisation for the save result.
	 *
	 * @var SavedObjectNormaliser
	 */
	private readonly SavedObjectNormaliser $normaliser;

	/**
	 * Constructor for the MotionForwardingService.
	 *
	 * @param ContainerInterface $container The DI container (for IAppConfig / MotionNotifier)
	 * @param IUserManager $userManager Nextcloud user manager for UID lookup
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service (ADR-084)
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserManager $userManager,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->normaliser = new SavedObjectNormaliser();

	}//end __construct()

	/**
	 * Forward a motion to another governance body.
	 *
	 * @param string $motionId The motion UUID to forward
	 * @param string $targetBodyId The target governance body UUID
	 * @param string $actorId The Nextcloud user ID of the person forwarding
	 * @param string $justification The reason for forwarding
	 *
	 * @return array<string,mixed> The created forwarded Motion object
	 *
	 * @throws RuntimeException When the actor or the motion is not found
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
	 */
	public function forward(string $motionId, string $targetBodyId, string $actorId, string $justification): array {
		$appConfig = $this->container->get(IAppConfig::class);

		// Simple check: the actor must exist (enforced in the backend only, never
		// frontend-only). A full implementation would query governance body
		// membership against motion_forwarding_roles.
		if ($this->userManager->get($actorId) === null) {
			throw new RuntimeException("Actor {$actorId} not found");
		}


		// Fetch the source motion. ADR-005: motions are `decision` objects
		// discriminated by decisionType=motion.
		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('decision');
		$sourceMotionObject = $this->objectService->find($motionId);
		$sourceMotionData = [];
		if ($sourceMotionObject !== null) {
			$sourceMotionData = $sourceMotionObject->getObject();
		}

		if ($sourceMotionObject === null
			|| ($sourceMotionData['decisionType'] ?? null) !== 'motion'
		) {
			throw new RuntimeException("Motion $motionId not found");
		}

		$forwardedMotion = $this->buildForwardedMotion(
			sourceMotionData: $sourceMotionData,
			motionId: $motionId,
			targetBodyId: $targetBodyId,
			actorId: $actorId,
			justification: $justification
		);

		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('decision');
		// ADR-084: saveObject() returns ObjectEntityInterface, not an array.
		$created = $this->normaliser->toArray(
			saved: $this->objectService->saveObject(
				object: $forwardedMotion,
				register: 'decidesk',
				schema: 'decision',
			),
			fallback: $forwardedMotion
		);

		$sourceMotionData = $this->noteForwarding(
			objectService: $this->objectService,
			sourceMotionData: $sourceMotionData,
			motionId: $motionId,
			targetBodyId: $targetBodyId,
			forwardedMotionId: ($created['id'] ?? $created['uuid'] ?? null)
		);

		// Send notification if approval is required.
		if ($appConfig->getValueBool('decidesk', 'motion_forwarding_requires_approval', false) === true) {
			$this->notifyApprovalRequired(
				actorId: $actorId,
				forwardedMotionId: (string)($created['id'] ?? $created['uuid'] ?? ''),
				targetBodyId: $targetBodyId,
				title: (string)($sourceMotionData['title'] ?? '')
			);
		}

		return $created;
	}//end forward()

	/**
	 * Build the copy of the motion that lands in the target body.
	 *
	 * @param array<string,mixed> $sourceMotionData The source motion object
	 * @param string $motionId The source motion UUID
	 * @param string $targetBodyId The target governance body UUID
	 * @param string $actorId The Nextcloud user ID of the person forwarding
	 * @param string $justification The reason for forwarding
	 *
	 * @return array<string,mixed> The forwarded motion payload.
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
	 */
	private function buildForwardedMotion(
		array $sourceMotionData,
		string $motionId,
		string $targetBodyId,
		string $actorId,
		string $justification,
	): array {
		return [
			// ADR-005: the copy is a `decision`; `decisionType` carries the
			// motion identity the retired schema used to carry. It is `required`
			// on the Decision schema and defaults to `meeting-outcome`, so
			// omitting it would silently mistype every forwarded motion.
			'decisionType' => 'motion',
			'title' => $sourceMotionData['title'] ?? '',
			'text' => $sourceMotionData['text'] ?? '',
			'motionType' => $sourceMotionData['motionType'] ?? 'motion',
			'proposer' => $sourceMotionData['proposer'] ?? '',
			'coSigners' => $sourceMotionData['coSigners'] ?? [],
			// ADR-005: `submitted` is the retired Motion vocabulary and is
			// outside the Decision.lifecycle enum, so this write was refused
			// outright. The forwarded copy HAS been submitted to the receiving
			// body, which is exactly what this schema calls `proposed`.
			'lifecycle' => 'proposed',
			'submittedAt' => $this->nowIso(),
			'relations' => [
				['register' => 'decidesk', 'schema' => 'governance-body', 'id' => $targetBodyId],
				['register' => 'decidesk', 'schema' => 'decision', 'id' => $motionId],
			],
			'notes' => [
				[
					'title' => 'Doorgestuurd van',
					'body' => json_encode(
						[
							'sourceMotionId' => $motionId,
							'targetBodyId' => $targetBodyId,
							'forwardedBy' => $actorId,
							'justification' => $justification,
							'forwardedAt' => $this->nowIso(),
						]
					),
				],
			],
		];

	}//end buildForwardedMotion()

	/**
	 * Append the "forwarded to" audit note to the source motion and persist it.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array<string,mixed> $sourceMotionData The source motion object
	 * @param string $motionId The source motion UUID
	 * @param string $targetBodyId The target governance body UUID
	 * @param mixed $forwardedMotionId The UUID of the created copy
	 *
	 * @return array<string,mixed> The source motion with the note appended.
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
	 */
	private function noteForwarding(
		object $objectService,
		array $sourceMotionData,
		string $motionId,
		string $targetBodyId,
		mixed $forwardedMotionId,
	): array {
		$sourceMotionData['notes'] = ($sourceMotionData['notes'] ?? []);
		$sourceMotionData['notes'][] = [
			'title' => 'Doorgestuurd naar',
			'body' => json_encode(
				[
					'targetBodyId' => $targetBodyId,
					'forwardedMotionId' => $forwardedMotionId,
					'forwardedAt' => $this->nowIso(),
				]
			),
		];

		$objectService->setRegister('decidesk');
		$objectService->setSchema('decision');
		$objectService->saveObject(
			object: $sourceMotionData,
			register: 'decidesk',
			schema: 'decision',
			uuid: $motionId,
		);

		return $sourceMotionData;
	}//end noteForwarding()

	/**
	 * Notify the actor that the forwarded motion awaits approval.
	 *
	 * @param string $actorId The Nextcloud user ID of the person forwarding
	 * @param string $forwardedMotionId The UUID of the created copy
	 * @param string $targetBodyId The target governance body UUID
	 * @param string $title The motion title
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
	 */
	private function notifyApprovalRequired(
		string $actorId,
		string $forwardedMotionId,
		string $targetBodyId,
		string $title,
	): void {
		$this->container->get(MotionNotifier::class)->notify(
			userId: $actorId,
			motionId: $forwardedMotionId,
			subject: 'motion_forwarded_approval',
			parameters: [
				'title' => $title,
				'body' => $targetBodyId,
			],
			failureLog: 'Decidesk: notification send failed: '
		);

	}//end notifyApprovalRequired()

	/**
	 * Current timestamp in ISO 8601 (ATOM) form.
	 *
	 * @return string The formatted timestamp.
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end nowIso()
}//end class
