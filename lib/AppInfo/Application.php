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
use OCA\Decidesk\Controller\MotionController;
use OCA\Decidesk\Controller\VotingController;
use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCA\Decidesk\Repair\InitializeSettings;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\VotingService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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

        // Register motion-and-voting services.
        $context->registerService(
                MotionService::class,
                static function ($c) {
                    return new MotionService(
                    container: $c,
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                OriPublicationService::class,
                static function ($c) {
                    return new OriPublicationService(
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    clientService: $c->get(\OCP\Http\Client\IClientService::class),
                    container: $c,
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                VotingService::class,
                static function ($c) {
                    return new VotingService(
                    container: $c,
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    motionService: $c->get(MotionService::class),
                    oriPublicationService: $c->get(OriPublicationService::class),
                    );
                }
                );

        $context->registerService(
                MotionController::class,
                static function ($c) {
                    return new MotionController(
                    request: $c->get(\OCP\IRequest::class),
                    motionService: $c->get(MotionService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    );
                }
                );

        $context->registerService(
                VotingController::class,
                static function ($c) {
                    return new VotingController(
                    request: $c->get(\OCP\IRequest::class),
                    votingService: $c->get(VotingService::class),
                    oriPublicationService: $c->get(OriPublicationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    );
                }
                );

        // Register mail reply handler background job.
        $context->registerService(
                MailReplyHandler::class,
                static function ($c) {
                    return new MailReplyHandler(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    votingService: $c->get(VotingService::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    container: $c,
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
