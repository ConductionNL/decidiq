<?php

/**
 * Decidesk Application
 *
 * Main application class for the Decidesk Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Decidesk\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\AppInfo;

use OCA\Decidesk\BackgroundJob\DecisionDigestJob;
use OCA\Decidesk\BackgroundJob\MailReplyHandler;
use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCA\Decidesk\Controller\DecisionApprovalController;
use OCA\Decidesk\Controller\DecisionAnalyticsController;
use OCA\Decidesk\Controller\DecisionController;
use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCA\Decidesk\Repair\InitializeSettings;
use OCA\Decidesk\Service\DecisionApprovalService;
use OCA\Decidesk\Service\DecisionAutoRecordService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

/**
 * Main application class for the Decidesk Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'decidesk';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function register(IRegistrationContext $context): void
    {
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // Initialize register and schemas on install/upgrade.
        $context->registerRepairStep(InitializeSettings::class);

        // Register DecisionApprovalService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
        $context->registerService(
                DecisionApprovalService::class,
                static function ($c): DecisionApprovalService {
                    return new DecisionApprovalService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register DecisionAutoRecordService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
        $context->registerService(
                DecisionAutoRecordService::class,
                static function ($c): DecisionAutoRecordService {
                    return new DecisionAutoRecordService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register MinutesGenerationService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.
        $context->registerService(
                MinutesGenerationService::class,
                static function ($c): MinutesGenerationService {
                    return new MinutesGenerationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register MinutesController for DI.
        // userId is NOT injected here — it must be resolved per-request inside each
        // action method via $this->userSession->getUser()?->getUID() to avoid the
        // DI singleton caching a null uid from an early unauthenticated bootstrap.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.
        $context->registerService(
                MinutesController::class,
                static function ($c): MinutesController {
                    return new MinutesController(
                    request: $c->get(\OCP\IRequest::class),
                    minutesGenerationService: $c->get(MinutesGenerationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    );
                }
                );

        // Register DecisionController for DI.
        // Explicit registration matches the MinutesController pattern and ensures
        // reliable resolution in all Nextcloud environments (≥28).
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2.
        $context->registerService(
                DecisionController::class,
                static function ($c): DecisionController {
                    return new DecisionController(
                    request: $c->get(\OCP\IRequest::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register DecisionApprovalController for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
        $context->registerService(
                DecisionApprovalController::class,
                static function ($c): DecisionApprovalController {
                    return new DecisionApprovalController(
                    appName: self::APP_ID,
                    request: $c->get(\OCP\IRequest::class),
                    approvalService: $c->get(DecisionApprovalService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register DecisionAnalyticsController for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
        $context->registerService(
                DecisionAnalyticsController::class,
                static function ($c): DecisionAnalyticsController {
                    return new DecisionAnalyticsController(
                    appName: self::APP_ID,
                    request: $c->get(\OCP\IRequest::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    cache: $c->get(\OCP\ICache::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register OverdueActionItemsJob for DI.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2.
        $context->registerService(
                OverdueActionItemsJob::class,
                static function ($c): OverdueActionItemsJob {
                    return new OverdueActionItemsJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register DecisionDigestJob for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
        $context->registerService(
                DecisionDigestJob::class,
                static function ($c): DecisionDigestJob {
                    return new DecisionDigestJob(
                    timeFactory: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        $serverContainer = $context->getServerContainer();
        $jobList         = $serverContainer->get(IJobList::class);
        if ($jobList->has(MailReplyHandler::class, null) === false) {
            $jobList->add(MailReplyHandler::class);
        }

    }//end boot()
}//end class
