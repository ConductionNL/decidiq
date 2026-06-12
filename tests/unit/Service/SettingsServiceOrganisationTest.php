<?php

/**
 * Unit tests for SettingsService organization configuration.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Round-trip tests for the organization config keys (admin-settings spec:
 * Organization Configuration) plus the write-only secret-key posture.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsServiceOrganisationTest extends TestCase
{

    /**
     * In-memory app-config storage backing the IAppConfig mock.
     *
     * @var array<string,string>
     */
    private array $configStore = [];

    /**
     * Service under test.
     *
     * @var SettingsService
     */
    private SettingsService $service;

    /**
     * Set up an in-memory IAppConfig and the service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * @var IAppConfig&MockObject $appConfig
         */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default='') => ($this->configStore[$key] ?? $default)
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->configStore[$key] = $value;
                return true;
            }
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $this->service = new SettingsService(
            $appConfig,
            $appManager,
            $this->createMock(ContainerInterface::class),
            $this->createMock(IGroupManager::class),
            $userSession,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Organization keys round-trip through updateSettings/getSettings.
     *
     * @return void
     */
    public function testOrganisationConfigRoundTrip(): void
    {
        $this->service->updateSettings(
            [
                'organisation_name'     => 'Vereniging De Harmonie',
                'organisation_logo'     => 'https://example.org/logo.png',
                'organisation_timezone' => 'Europe/Amsterdam',
                'organisation_locale'   => 'nl',
                'organisation_currency' => 'EUR',
                'organisation_retention_days' => '3650',
            ]
        );

        $settings = $this->service->getSettings();

        self::assertSame('Vereniging De Harmonie', $settings['organisation_name']);
        self::assertSame('https://example.org/logo.png', $settings['organisation_logo']);
        self::assertSame('Europe/Amsterdam', $settings['organisation_timezone']);
        self::assertSame('nl', $settings['organisation_locale']);
        self::assertSame('EUR', $settings['organisation_currency']);
        self::assertSame('3650', $settings['organisation_retention_days']);
    }//end testOrganisationConfigRoundTrip()

    /**
     * Unknown keys are ignored by updateSettings.
     *
     * @return void
     */
    public function testUnknownKeysAreIgnored(): void
    {
        $this->service->updateSettings(['not_a_real_key' => 'x']);

        self::assertArrayNotHasKey('not_a_real_key', $this->configStore);
        self::assertArrayNotHasKey('not_a_real_key', $this->service->getSettings());
    }//end testUnknownKeysAreIgnored()

    /**
     * Organization keys default to the empty string when unset.
     *
     * @return void
     */
    public function testOrganisationDefaultsAreEmpty(): void
    {
        $settings = $this->service->getSettings();

        self::assertSame('', $settings['organisation_name']);
        self::assertSame('', $settings['organisation_timezone']);
        self::assertSame('', $settings['organisation_locale']);
    }//end testOrganisationDefaultsAreEmpty()

    /**
     * The ORI bearer secret is write-only: persisted by updateSettings but
     * never echoed by getSettings (the index route is reachable by any
     * authenticated user).
     *
     * @return void
     */
    public function testOriBearerSecretIsWriteOnly(): void
    {
        $settings = $this->service->updateSettings(['ori_bearer_secret' => 's3cret']);

        self::assertSame('s3cret', $this->configStore['ori_bearer_secret']);
        self::assertArrayNotHasKey('ori_bearer_secret', $settings);
        self::assertArrayNotHasKey('ori_bearer_secret', $this->service->getSettings());
    }//end testOriBearerSecretIsWriteOnly()
}//end class
