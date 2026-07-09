<?php
/**
 * Decidesk Overdue Action Items Background Job (RETIRED)
 *
 * Historically transitioned ActionItems to taskStatus "overdue" via
 * ObjectService::saveObject(). Action items are now CalDAV VTODOs exposed as a
 * READ-ONLY OpenRegister projection (action-items-vtodo-deck-reconcile), so this
 * job can no longer write — and does not need to: overdue is DERIVED at read
 * time from dueDate < now (the frontend already does this in widgetLogic.js).
 * The job is kept as a no-op so the registered oc_jobs row reaps cleanly.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Retired no-op: overdue is derived at read time from dueDate, not persisted.
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
 */
class OverdueActionItemsJob extends TimedJob
{

    /**
     * Interval between job runs: 86400 seconds = 24 hours.
     */
    private const INTERVAL_SECONDS = 86400;

    /**
     * Constructor for OverdueActionItemsJob.
     *
     * @param ITimeFactory    $time   Nextcloud time factory (injected by TimedJob).
     * @param LoggerInterface $logger The logger.
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     */
    public function __construct(
        ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * No-op: overdue ActionItems are derived at read time (dueDate < now), not
     * written — the action-item schema is a read-only VTODO projection.
     *
     * @param mixed $argument Not used; required by TimedJob contract.
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameters)
     */
    protected function run(mixed $argument): void
    {
        $this->logger->debug(
            'Decidesk OverdueActionItemsJob is retired: overdue is derived at read time from dueDate.'
        );
    }//end run()
}//end class
