<?php
/**
 * Decidesk Translation Queue Background Job
 *
 * Phase 6 — hourly background job that processes a batch of queued
 * translation requests through MultilingualReconciliationService. The
 * default adapter is the dormant LogTranslationAdapter which delegates
 * to openconnector when its translation source is bound; otherwise the
 * job logs the request and leaves the source text in place.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\Service\MultilingualReconciliationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly TimedJob that drains the translation queue.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class TranslationQueueJob extends TimedJob
{

    /**
     * Interval between job runs: 3600 seconds = 1 hour.
     */
    private const INTERVAL_SECONDS = 3600;

    /**
     * Maximum entries processed per job invocation.
     */
    private const BATCH_SIZE = 20;

    /**
     * Constructor.
     *
     * @param ITimeFactory                      $time                  Nextcloud time factory
     * @param MultilingualReconciliationService $reconciliationService Reconciliation service
     * @param LoggerInterface                   $logger                Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly MultilingualReconciliationService $reconciliationService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * Drain a batch of queued translation requests.
     *
     * @param mixed $argument Required by TimedJob; unused
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('Decidesk: TranslationQueueJob started');

        try {
            $result = $this->reconciliationService->processQueue(maxEntries: self::BATCH_SIZE);
            $this->logger->info(
                sprintf(
                    'Decidesk: TranslationQueueJob finished — processed %d (%d completed, %d failed)',
                    $result['processed'],
                    $result['completed'],
                    $result['failed']
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: TranslationQueueJob failed',
                ['exception' => $e->getMessage()]
            );
        }

    }//end run()
}//end class
