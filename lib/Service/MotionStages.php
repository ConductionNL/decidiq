<?php

/**
 * Decidiq Motion Stages
 *
 * The stage steps a caller may take on a motion from its page (mot-08): the
 * chair or secretary of the motion's meeting walks it through the motion
 * lifecycle, and the member who submitted it may withdraw it.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Which motion stage steps the caller may take.
 *
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */
class MotionStages {

	/**
	 * Constructor for MotionStages.
	 *
	 * @param ObjectServiceInterface $objectService       The OpenRegister object service
	 * @param MotionService          $motionService       Resolves the motion's meeting
	 * @param ParticipantResolver    $participantResolver Per-meeting roles
	 * @param IGroupManager          $groupManager        Admin and chair group membership
	 * @param IAppConfig             $appConfig           The chair_group setting
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly MotionService $motionService,
		private readonly ParticipantResolver $participantResolver,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The motion's stage and the steps the caller may take, or null when
	 * there is no such motion.
	 *
	 * @param string $motionId The motion (Decision) UUID
	 * @param string $uid      The caller's Nextcloud UID
	 *
	 * @return array{lifecycle: string, outcome: string|null, actions: array<int, array{to: string, outcome?: string}>}|null
	 *
	 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
	 */
	public function forCaller(string $motionId, string $uid): ?array {
		$motion = $this->load(motionId: $motionId);
		if ($motion === null) {
			return null;
		}

		$lifecycle = (string)($motion['object']['lifecycle'] ?? 'draft');
		$canManage = $this->canManage(motionId: $motionId, uid: $uid);
		$isSubmitter = ($uid !== '' && $motion['owner'] === $uid);

		return [
			'lifecycle' => $lifecycle,
			'outcome' => $this->stringOrNull(value: ($motion['object']['outcome'] ?? null)),
			'actions' => self::actionsFor(lifecycle: $lifecycle, canManage: $canManage, isSubmitter: $isSubmitter),
		];
	}//end forCaller()

	/**
	 * Whether the caller may withdraw the motion: its submitter, or the
	 * chair or secretary, while withdrawal is still a step from its stage.
	 *
	 * @param string $motionId The motion (Decision) UUID
	 * @param string $uid      The caller's Nextcloud UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
	 */
	public function mayWithdraw(string $motionId, string $uid): bool {
		$stages = $this->forCaller(motionId: $motionId, uid: $uid);
		if ($stages === null) {
			return false;
		}

		return in_array('withdrawn', array_column($stages['actions'], 'to'), true);
	}//end mayWithdraw()

	/**
	 * The steps from a stage. The chair or secretary gets every step the
	 * motion lifecycle allows, with Decided split into adopted and rejected;
	 * the submitter gets Withdraw only.
	 *
	 * @param string $lifecycle   The motion's stage
	 * @param bool   $canManage   Whether the caller chairs or keeps the minutes
	 * @param bool   $isSubmitter Whether the caller submitted the motion
	 *
	 * @return array<int, array{to: string, outcome?: string}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Two independent facts about the caller, not a mode switch.
	 *
	 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
	 */
	public static function actionsFor(string $lifecycle, bool $canManage, bool $isSubmitter): array {
		$next = (MotionLifecycleTransitioner::MOTION_TRANSITIONS[$lifecycle] ?? []);
		if ($canManage === false) {
			if ($isSubmitter === true && in_array('withdrawn', $next, true) === true) {
				return [['to' => 'withdrawn']];
			}

			return [];
		}

		$actions = [];
		foreach ($next as $state) {
			if ($state === 'decided') {
				$actions[] = ['to' => 'decided', 'outcome' => 'adopted'];
				$actions[] = ['to' => 'decided', 'outcome' => 'rejected'];
				continue;
			}

			$actions[] = ['to' => $state];
		}

		return $actions;
	}//end actionsFor()

	/**
	 * Load the motion and its owner (the user who submitted it).
	 *
	 * @param string $motionId The motion UUID
	 *
	 * @return array{object: array<string, mixed>, owner: string}|null
	 */
	private function load(string $motionId): ?array {
		$entity = $this->objectService->find(id: $motionId, register: 'decidiq', schema: 'decision');
		if ($entity === null) {
			return null;
		}

		$object = $entity->getObject();
		if (($object['decisionType'] ?? null) !== 'motion') {
			return null;
		}

		return ['object' => $object, 'owner' => (string)($entity->getOwner() ?? '')];
	}//end load()

	/**
	 * Whether the caller is chair or secretary of the motion's meeting; with
	 * no meeting, a member of the chair group (or an admin when none is set).
	 *
	 * @param string $motionId The motion UUID
	 * @param string $uid      The caller's Nextcloud UID
	 *
	 * @return bool
	 */
	private function canManage(string $motionId, string $uid): bool {
		if ($uid === '') {
			return false;
		}

		$meetingId = $this->motionService->resolveMeetingId(motionId: $motionId);
		if ($meetingId !== null) {
			return $this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $uid, roles: ['chair', 'secretary']);
		}

		$chairGroup = $this->appConfig->getValueString(Application::APP_ID, 'chair_group', '');
		if ($chairGroup === '') {
			return $this->groupManager->isAdmin($uid);
		}

		return $this->groupManager->isInGroup($uid, $chairGroup);
	}//end canManage()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()
}//end class
