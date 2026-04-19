<?php
/**
 * Decidesk Initialize Settings Repair Step
 *
 * Repair step that initializes Decidesk register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Decidesk\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
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
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing Decidesk configuration...');

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
            $result = $this->settingsService->loadConfiguration(force: true);

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
