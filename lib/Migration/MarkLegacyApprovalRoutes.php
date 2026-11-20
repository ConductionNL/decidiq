<?php

/**
 * Repair step: decisions whose route is under way on the old engine finish there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks every decision with an open stage on the old engine as `approvalEngine: legacy`.
 *
 * The send path does not depend on the mark: a decision that has stage rows is
 * never started as a flow. The mark makes the split visible on the decision.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */
class MarkLegacyApprovalRoutes implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Says whether OpenRegister is there.
	 * @param ContainerInterface       $container       Resolves the finder once it is.
	 * @param LoggerInterface          $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
	 */
	public function getName(): string {
		return 'Mark Decidiq decisions whose approval route finishes on the old engine';
	}//end getName()

	/**
	 * Mark the decisions.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — no approval routes to mark.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$legacy        = $this->container->get('OCA\Decidiq\Service\LegacyApprovalRoutes');
			$marked        = $objectService->runAsSystem(static fn (): int => (int)$legacy->mark());
			$output->info(sprintf('Marked %d decision(s) whose approval route finishes on the old engine.', (int)$marked));
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: marking legacy approval routes skipped', ['exception' => $e->getMessage()]);
			$output->warning('Marking legacy approval routes skipped: ' . $e->getMessage());
		}
	}//end run()
}//end class
