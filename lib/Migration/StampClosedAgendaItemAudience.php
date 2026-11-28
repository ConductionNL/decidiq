<?php

/**
 * Repair step: non-public agenda items saved before their read rules carry their meeting's body.
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
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
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
 * Re-saves each non-public agenda item without a governance body, so the confidentiality
 * listener stamps it from the meeting and hands body and readers to its papers. Runs after
 * InitializeSettings has imported the register, so the field and the read rules exist.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
 */
class StampClosedAgendaItemAudience implements IRepairStep {
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
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	#[\Override]
	public function getName(): string {
		return 'Give Decidiq non-public agenda items and their papers their governance body';
	}//end getName()

	/**
	 * Stamp the items.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	#[\Override]
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — no agenda items to stamp.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$restamp       = $this->container->get('OCA\Decidiq\Service\ClosedAgendaItemRestamp');
			$saved         = $objectService->runAsSystem(static fn (): int => (int)$restamp->restamp());
			$output->info(sprintf('Gave %d non-public agenda item(s) their governance body.', (int)$saved));
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: stamping non-public agenda items skipped', ['exception' => $e->getMessage()]);
			$output->warning('Stamping non-public agenda items skipped: ' . $e->getMessage());
		}
	}//end run()
}//end class
