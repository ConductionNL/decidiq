<?php

/**
 * `occ decidiq:approval-routes:legacy`: the routes still running on the old engine.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Command
 * @package  OCA\Decidiq\Command
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

namespace OCA\Decidiq\Command;

use OCA\Decidiq\Service\LegacyApprovalRoutes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists every decision whose route still has an open step on the old engine.
 *
 * The old engine's services are deleted only once this lists nothing on the
 * reference instances.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */
class ApprovalRoutesLegacyCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param LegacyApprovalRoutes $legacy Finds the decisions.
	 */
	public function __construct(
		private readonly LegacyApprovalRoutes $legacy,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and description.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
	 */
	protected function configure(): void {
		$this->setName(name: 'decidiq:approval-routes:legacy')
			->setDescription(description: 'List the decisions whose approval route still runs on the old engine');
	}//end configure()

	/**
	 * List them, one line each.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0, also when nothing is left.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The Command contract fixes this signature.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$open = $this->legacy->open();
		if ($open === []) {
			$output->writeln('No approval route runs on the old engine.');
			return 0;
		}

		foreach ($open as $row) {
			$step = sprintf('step %d', $row['sequence']);
			if ($row['label'] !== '') {
				$step .= ' (' . $row['label'] . ')';
			}

			$output->writeln(sprintf('%s: %s is open', $row['decision'], $step));
		}

		$output->writeln(sprintf('%d decision(s) still on the old engine.', count($open)));

		return 0;
	}//end execute()
}//end class
