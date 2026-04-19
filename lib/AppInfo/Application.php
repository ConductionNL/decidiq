<?php

/**
 * Decidesk Application
 *
 * Main application class for the Decidesk Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Decidesk\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\AppInfo;

use OCA\Decidesk\BackgroundJob\MailReplyHandler;
use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCA\Decidesk\Controller\DecisionController;
use OCA\Decidesk\Controller\DecisionPublicController;
use OCA\Decidesk\Controller\DecisionSearchController;
use OCA\Decidesk\Controller\MinutesApprovalController;
use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Controller\MinutesVersionController;
use OCA\Decidesk\Controller\NotificationSubscriptionController;
use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCA\Decidesk\Reference\DecisionReferenceProvider;
use OCA\Decidesk\Repair\InitializeSettings;
use OCA\Decidesk\Search\DecisionsSearchProvider;
use OCA\Decidesk\Service\DecisionNotificationService;
use OCA\Decidesk\Service\DecisionService;
use OCA\Decidesk\Service\MinutesApprovalService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesVersionService;
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

        // Register DecisionNotificationService for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
        $context->registerService(
                DecisionNotificationService::class,
                static function ($c): DecisionNotificationService {
                    return new DecisionNotificationService(
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    notificationManager: $c->get(\OCP\Notification\IManager::class),
                    );
                }
                );

        // Register NotificationSubscriptionController for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
        $context->registerService(
                NotificationSubscriptionController::class,
                static function ($c): NotificationSubscriptionController {
                    return new NotificationSubscriptionController(
                    request: $c->get(\OCP\IRequest::class),
                    notificationService: $c->get(DecisionNotificationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    );
                }
                );

        // Register MinutesVersionService for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
        $context->registerService(
                MinutesVersionService::class,
                static function ($c): MinutesVersionService {
                    return new MinutesVersionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    );
                }
                );

        // Register MinutesVersionController for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
        $context->registerService(
                MinutesVersionController::class,
                static function ($c): MinutesVersionController {
                    return new MinutesVersionController(
                    request: $c->get(\OCP\IRequest::class),
                    versionService: $c->get(MinutesVersionService::class),
                    );
                }
                );

        // Register MinutesApprovalService for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
        $context->registerService(
                MinutesApprovalService::class,
                static function ($c): MinutesApprovalService {
                    return new MinutesApprovalService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    notificationService: $c->get(DecisionNotificationService::class),
                    );
                }
                );

        // Register MinutesApprovalController for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
        $context->registerService(
                MinutesApprovalController::class,
                static function ($c): MinutesApprovalController {
                    return new MinutesApprovalController(
                    request: $c->get(\OCP\IRequest::class),
                    approvalService: $c->get(MinutesApprovalService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    );
                }
                );

        // Register DecisionService for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
        $context->registerService(
                DecisionService::class,
                static function ($c): DecisionService {
                    return new DecisionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    shareManager: $c->get(\OCP\IShareManager::class),
                    notificationService: $c->get(DecisionNotificationService::class),
                    );
                }
                );

        // Update DecisionController registration to include DecisionService (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
        $context->registerService(
                DecisionController::class,
                static function ($c): DecisionController {
                    return new DecisionController(
                    request: $c->get(\OCP\IRequest::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    decisionService: $c->get(DecisionService::class),
                    );
                }
                );

        // Register DecisionPublicController for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
        $context->registerService(
                DecisionPublicController::class,
                static function ($c): DecisionPublicController {
                    return new DecisionPublicController(
                    request: $c->get(\OCP\IRequest::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    );
                }
                );

        // Register DecisionSearchController for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
        $context->registerService(
                DecisionSearchController::class,
                static function ($c): DecisionSearchController {
                    return new DecisionSearchController(
                    request: $c->get(\OCP\IRequest::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    );
                }
                );

        // Register DecisionsSearchProvider for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
        $context->registerService(
                DecisionsSearchProvider::class,
                static function ($c): DecisionsSearchProvider {
                    return new DecisionsSearchProvider(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    l10n: $c->get(\OCP\IL10N::class),
                    );
                }
                );

        // Register search provider with Nextcloud (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
        $context->registerSearchProvider(DecisionsSearchProvider::class);

        // Register DecisionReferenceProvider for DI (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
        $context->registerService(
                DecisionReferenceProvider::class,
                static function ($c): DecisionReferenceProvider {
                    return new DecisionReferenceProvider(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    l10n: $c->get(\OCP\IL10N::class),
                    );
                }
                );

        // Register reference provider with Nextcloud (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
        $context->registerReferenceProvider(DecisionReferenceProvider::class);

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
