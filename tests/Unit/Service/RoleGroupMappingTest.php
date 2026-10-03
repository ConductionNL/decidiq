<?php

/**
 * Role to group mapping on register import (platform-role-rights-per-record-type, plt-03).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\RoleGroupMapping;
use OCA\Decidiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The register's rules name decidiq's own groups; OpenRegister evaluates them
 * literally. A mapped group must end up in the imported rules, at the schema
 * level and in the register baseline that schemas without rules inherit.
 *
 * Runs over the real merged register (decidesk_register.json + register.d).
 *
 * @covers \OCA\Decidiq\Service\RoleGroupMapping
 * @covers \OCA\Decidiq\Service\SettingsService
 */
class RoleGroupMappingTest extends TestCase {

	/**
	 * The merged register, as the import sees it.
	 *
	 * @return array<string,mixed>
	 */
	private function register(): array {
		$settings = new SettingsService(
			appConfig: $this->createMock(IAppConfig::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		return $settings->mergedRegisterConfig();
	}//end register()

	/**
	 * The schema carrying a slug.
	 *
	 * @param array<string,mixed> $config The configuration.
	 * @param string              $slug   The slug.
	 *
	 * @return array<string,mixed>
	 */
	private function schema(array $config, string $slug): array {
		foreach ($config['components']['schemas'] as $key => $schema) {
			if (($schema['slug'] ?? $key) === $slug) {
				return $schema;
			}
		}

		$this->fail('No schema '.$slug);
	}//end schema()

	public function testAMappedGroupMayChangeMinutes(): void {
		$mapping = new RoleGroupMapping();
		$config  = $mapping->rewrite(config: $this->register(), mapping: $mapping->normalise(['administrators' => ['Griffie']]));

		// Minutes carry no rules of their own: they take the register baseline.
		$rows    = array_column($mapping->overview(config: $config), null, 'slug');
		$this->assertTrue($rows['minutes']['inherited']);
		$update = array_column($rows['minutes']['rules']['update'], 'group');
		$this->assertContains('decidiq-administrators', $update, 'the role keeps its own group');
		$this->assertContains('Griffie', $update);
	}//end testAMappedGroupMayChangeMinutes()

	public function testTheRegisterBaselineCarriesTheMappingForSchemasWithoutRules(): void {
		$mapping   = new RoleGroupMapping();
		$config    = $mapping->rewrite(config: $this->register(), mapping: $mapping->normalise(['administrators' => ['Griffie']]));
		$registers = $config['components']['registers'];
		$baseline  = reset($registers)['authorization'];
		$this->assertContains('Griffie', $baseline['update']);
		$this->assertContains('Griffie', $baseline['delete']);

		$inherited = array_filter($mapping->overview(config: $config), static fn (array $row): bool => $row['inherited'] === true);
		$this->assertNotEmpty($inherited, 'the register has schemas without rules of their own');
		foreach ($inherited as $row) {
			$this->assertContains('Griffie', array_column($row['rules']['update'], 'group'), $row['slug']);
		}
	}//end testTheRegisterBaselineCarriesTheMappingForSchemasWithoutRules()

	public function testAConditionalRuleIsCopiedWithItsCondition(): void {
		$mapping = new RoleGroupMapping();
		$config  = ['components' => ['schemas' => ['S' => ['authorization' => [
			'read' => [['group' => 'decidiq-secretariat', 'match' => ['lifecycle' => 'answered']], 'authenticated'],
		]]]]];
		$out = $mapping->rewrite(config: $config, mapping: $mapping->normalise(['secretariat' => ['Griffie', 'Griffie', ' ']]));
		$this->assertSame(
			[
				['group' => 'decidiq-secretariat', 'match' => ['lifecycle' => 'answered']],
				['group' => 'Griffie', 'match' => ['lifecycle' => 'answered']],
				'authenticated',
			],
			$out['components']['schemas']['S']['authorization']['read']
		);
	}//end testAConditionalRuleIsCopiedWithItsCondition()

	public function testPublicAndSignedInUsersAreNotRoles(): void {
		$mapping = new RoleGroupMapping();
		$clean   = $mapping->normalise(['public' => ['Griffie'], 'authenticated' => ['Griffie']]);
		$this->assertSame(array_keys(RoleGroupMapping::ROLES), array_keys($clean));
		$this->assertSame($this->register(), $mapping->rewrite(config: $this->register(), mapping: $clean));
		$this->assertSame('', $mapping->signature(mapping: $clean));
	}//end testPublicAndSignedInUsersAreNotRoles()

	public function testTheImportSendsTheRewrittenRulesAndANewVersion(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === RoleGroupMapping::CONFIG_KEY ? '{"administrators":["Griffie"]}' : $default
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		$importer = new class {
			/** @var array<string,mixed> */
			public array $data = [];
			public string $version = '';

			/**
			 * The shape of OpenRegister's ConfigurationService::importFromApp.
			 *
			 * @param string              $appId   The app.
			 * @param array<string,mixed> $data    The register.
			 * @param string              $version The version.
			 * @param bool                $force   Force.
			 *
			 * @return array<string,mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force=false): array {
				$this->data    = $data;
				$this->version = $version;
				return ['version' => $version];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$settings = new SettingsService(
			appConfig: $appConfig,
			appManager: $appManager,
			container: $container,
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		$result = $settings->reloadConfiguration();

		$this->assertTrue($result['success']);
		$registers = $importer->data['components']['registers'];
		$this->assertContains('Griffie', reset($registers)['authorization']['update']);
		$this->assertContains('Griffie', $this->schema(config: $importer->data, slug: 'decision')['authorization']['update']);
		$mapping = new RoleGroupMapping();
		$this->assertStringContainsString('+roles.'.$mapping->signature(mapping: $mapping->decode('{"administrators":["Griffie"]}')), $importer->version);
	}//end testTheImportSendsTheRewrittenRulesAndANewVersion()
}//end class
