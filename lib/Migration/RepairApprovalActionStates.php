<?php

/**
 * Decidiq repair step — backfill the state of recorded approval actions.
 *
 * The route engine now writes a terminal `state` onto every action it records,
 * and refuses to close a step on which an open approval still waits on
 * somebody else (REQ-DWP-001). Actions stored before that write carry the
 * schema default `open`, so on a task list they would read as approvals still
 * awaited. The engine never blocks on them (a row carrying a verb is the
 * record of an act, never a work item), but they would still look like work.
 *
 * The backfill itself lives in
 * {@see \OCA\Decidiq\Service\ApprovalActionStateRepair}, which takes the state
 * from the same verb mapping the engine writes with, so the two cannot drift.
 *
 * Idempotent: a repaired action carries a terminal state and is skipped on a
 * re-run. Registered under <post-migration> only: a fresh install has no
 * actions yet.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Kernel\Contracts\ApprovalActionStateRepairInterface;
use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfills the terminal state onto stored approval actions that record an act. Idempotent.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */
class RepairApprovalActionStates implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * The repair service is resolved LAZILY through the container, never
	 * constructor-injected, for the reason RepairDecisionStageLabels gives: its
	 * store takes OpenRegister's facade by type, so injecting it here would make
	 * `occ upgrade` fatal on an instance without openregister.
	 *
	 * @param SettingsServiceInterface $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface       $container       Resolves the repair service and OR's ObjectService.
	 * @param LoggerInterface          $logger          Records a skipped backfill.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function getName(): string {
		return 'Backfill the state of Decidiq approval actions recorded before the engine wrote it';
	}//end getName()

	/**
	 * Run the backfill as the system, so every stored action is reached.
	 *
	 * @param IOutput $output Where progress is reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — no approval actions to repair.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$repairService = $this->container->get(ApprovalActionStateRepairInterface::class);
		} catch (Throwable $e) {
			$output->warning('Could not resolve the approval-action state repair: ' . $e->getMessage());
			return;
		}

		try {
			$repaired = $objectService->runAsSystem(
				static fn (): int => (int)$repairService->repair()
			);
			$output->info(sprintf('Backfilled the state of %d approval action(s).', (int)$repaired));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: approval-action state backfill skipped',
				['exception' => $e->getMessage()]
			);
			$output->warning('Approval-action state backfill skipped: ' . $e->getMessage());
		}
	}//end run()
}//end class
