<?php

/**
 * Decidesk Admin Settings
 *
 * Provides the admin settings form for the Decidesk application.
 *
 * @category Settings
 * @package  OCA\Decidesk\Settings
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

namespace OCA\Decidesk\Settings;

use OCA\Decidesk\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Decidesk application.
 */
class AdminSettings implements IDelegatedSettings
{
    /**
     * Constructor.
     *
     * @param IAppManager $appManager The app manager.
     */
    public function __construct(
        private IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * Get the settings form template.
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);

        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            ['version' => $version]
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     */
    public function getSection(): string
    {
        return 'decidesk';
    }//end getSection()

    /**
     * Get the priority for ordering within the section.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()

    /**
     * Get the sub-name for differentiating settings within a section.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return null;
    }//end getName()

    /**
     * Get authorized app config keys that delegated admins may modify.
     *
     * @return array<string, array<string>>
     */
    public function getAuthorizedAppConfig(): array
    {
        return [
            Application::APP_ID => ['/register/'],
        ];
    }//end getAuthorizedAppConfig()
}//end class
