<?php
/**
 * Decidesk Initialize Settings Repair Step
 *
 * Repair step that initializes Decidesk register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Decidesk\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Repair;

use OCA\Decidesk\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Decidesk configuration via SettingsService.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.1
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings.
     *
     * @param SettingsService $settingsService The settings service
     * @param IAppConfig      $appConfig       The app config
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function getName(): string
    {
        return 'Initialize Decidesk register and schemas via ConfigurationService';
    }//end getName()

    /**
     * Run the repair step to initialize Decidesk configuration.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing Decidesk configuration...');

        // Ensure voter_token_secret is initialized exactly once, at install/upgrade time,
        // to prevent a concurrent first-call race in VotingService::voterTokenSecret().
        if ($this->appConfig->getValueString('decidesk', 'voter_token_secret', '') === '') {
            // The `sensitive: true` flag below is required: this is the HMAC key that
            // signs voting tokens and mail-reply links. Without the flag it is stored as
            // an ordinary appconfig string, so it prints in cleartext in `occ config:list`
            // and every support dump that feeds. Anyone who reads it can forge a vote.
            $this->appConfig->setValueString(
                'decidesk',
                'voter_token_secret',
                bin2hex(random_bytes(32)),
                sensitive: true
            );
            $output->info('Generated voter_token_secret for Decidesk.');
        }

        // Re-flag a token stored before this release: flagging the WRITE path only fixes
        // new installs, and nobody regenerates the key. `updateSensitive()` re-encrypts
        // the existing row at rest and redacts it from CLI output in place.
        if ($this->appConfig->getValueString('decidesk', 'voter_token_secret', '') !== '') {
            try {
                $this->appConfig->updateSensitive('decidesk', 'voter_token_secret', true);
            } catch (\Throwable $e) {
                // Never fatal — leaving it as-is is the pre-existing state, not a regression.
                $output->warning('Could not flag voter_token_secret as sensitive: '.$e->getMessage());
            }
        }

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not installed or enabled. Skipping auto-configuration.'
            );
            $this->logger->warning(
                'Decidesk: OpenRegister not available, skipping register initialization'
            );
            return;
        }

        try {
            $result = $this->settingsService->loadConfiguration();

            if ($result['success'] === true) {
                $version = ($result['version'] ?? 'unknown');
                $output->info(
                    'Decidesk configuration imported successfully (version: '.$version.')'
                );
                return;
            }

            $message = ($result['message'] ?? 'unknown error');
            $output->warning(
                'Decidesk configuration import issue: '.$message
            );
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Decidesk: '.$e->getMessage());
            $this->logger->error(
                'Decidesk initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
