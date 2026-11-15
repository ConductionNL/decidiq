<?php

/**
 * Decidiq Migrate Action Items Command
 *
 * `occ decidiq:migrate-action-items` projects legacy task, delegation and
 * ActionItem objects onto VTODOs, one owning user at a time, acting as that
 * user so each VTODO lands in the owner's own calendar.
 *
 * @category Command
 * @package  OCA\Decidiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Command;

use OCA\Decidiq\Service\ActionItemMigrationService;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The per-user driver of the legacy action-item migration.
 *
 * Only an administrator with shell access runs occ, so the impersonation is
 * never reachable from a request. A user who cannot be loaded is skipped and
 * logged; the acting user is reset after every user, whatever happened.
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 */
class MigrateActionItems extends Command {

	/**
	 * Constructor.
	 *
	 * @param ActionItemMigrationService $migration   The migration.
	 * @param IUserManager               $userManager Loads each owning user.
	 * @param IUserSession               $userSession Makes the owner the acting user.
	 * @param LoggerInterface            $logger      The logger.
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	public function __construct(
		private readonly ActionItemMigrationService $migration,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	protected function configure(): void {
		$this->setName(name: 'decidiq:migrate-action-items')
			->setDescription(description: 'Project legacy task, delegation and ActionItem objects onto each owner\'s VTODO action items')
			->addOption(name: 'user', shortcut: 'u', mode: InputOption::VALUE_REQUIRED, description: 'Migrate only this user');
	}//end configure()

	/**
	 * Migrate every owning user, or the one asked for.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 when every user ran without failures, 1 otherwise.
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$owners = $this->migration->pendingOwners();
		$only   = $input->getOption(name: 'user');
		if (is_string($only) === true && $only !== '') {
			$owners = array_values(array_intersect($owners, [$only]));
		}

		if ($owners === []) {
			$output->writeln('No legacy action items to migrate.');
			return 0;
		}

		$failures = 0;
		foreach ($owners as $owner) {
			$failures += $this->migrateOwner(owner: $owner, output: $output);
		}

		if ($failures > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Migrate one owner as that owner.
	 *
	 * @param string          $owner  The owning user id.
	 * @param OutputInterface $output The output.
	 *
	 * @return int The number of failures.
	 */
	private function migrateOwner(string $owner, OutputInterface $output): int {
		$user = $this->userManager->get($owner);
		if ($user === null) {
			$output->writeln(sprintf('Skipped %s: the user does not exist.', $owner));
			$this->logger->warning('Decidiq: legacy action items of an unknown user were left as they are', ['user' => $owner]);
			return 1;
		}

		try {
			$this->userSession->setUser($user);
			$counts = $this->migration->migrateUser(userId: $owner);
		} catch (Throwable $e) {
			$output->writeln(sprintf('Skipped %s: %s', $owner, $e->getMessage()));
			$this->logger->warning('Decidiq: action-item migration for a user stopped', ['user' => $owner, 'error' => $e->getMessage()]);
			return 1;
		} finally {
			$this->userSession->setUser(null);
		}

		$output->writeln(
			sprintf(
				'%s: %d migrated, %d delegations applied, %d skipped, %d failed',
				$owner,
				$counts['migrated'],
				$counts['delegations'],
				$counts['skipped'],
				$counts['failed']
			)
		);

		return $counts['failed'];
	}//end migrateOwner()
}//end class
