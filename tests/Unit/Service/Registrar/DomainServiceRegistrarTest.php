<?php

/**
 * What DomainServiceRegistrar registers, and what its factories resolve to.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Registrar;

use OCA\Decidiq\Mcp\DecidiqToolProvider;
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCA\Decidiq\Service\ITranslationAdapter;
use OCA\Decidiq\Service\LogEIDASSignatureService;
use OCA\Decidiq\Service\LogTranslationAdapter;
use OCA\Decidiq\Service\Registrar\DomainServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the MCP provider alias, that eIDAS signing resolves to the delegating
 * service only when integriq's CallService resolves (else the dormant log
 * service), and that the translation adapter is the log adapter built from the
 * container and logger.
 *
 * @covers \OCA\Decidiq\Service\Registrar\DomainServiceRegistrar
 * @uses   \OCA\Decidiq\Service\ContractBindings
 * @uses   \OCA\Decidiq\Support\FleetAppId
 * @uses   \OCA\Decidiq\Service\LogTranslationAdapter
 */
class DomainServiceRegistrarTest extends TestCase {

	/**
	 * Aliases registered: alias => target.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * Factories registered: name => factory.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Register into a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);

		(new DomainServiceRegistrar())->register(context: $context);
	}//end setUp()

	/**
	 * The MCP tool provider is registered under the alias OpenRegister enumerates.
	 *
	 * @return void
	 */
	public function testTheMcpToolProviderIsAliased(): void {
		$this->assertSame(DecidiqToolProvider::class, $this->aliases['OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidiq']);
		$this->assertGreaterThan(1, count($this->aliases), 'the contract bindings are registered too');
	}//end testTheMcpToolProviderIsAliased()

	/**
	 * With integriq's CallService present, eIDAS signing delegates.
	 *
	 * @return void
	 */
	public function testEidasDelegatesWhenIntegriqResolves(): void {
		$delegating = $this->createMock(IEIDASSignatureService::class);
		$container = $this->container(
			services: [EIDASSignatureService::class => $delegating],
			callService: true
		);

		$this->assertSame($delegating, ($this->factories[IEIDASSignatureService::class])($container));
	}//end testEidasDelegatesWhenIntegriqResolves()

	/**
	 * Without integriq, eIDAS signing is the dormant log service.
	 *
	 * @return void
	 */
	public function testEidasIsDormantWithoutIntegriq(): void {
		$dormant = $this->createMock(IEIDASSignatureService::class);
		$container = $this->container(services: [LogEIDASSignatureService::class => $dormant], callService: false);

		$this->assertSame($dormant, ($this->factories[IEIDASSignatureService::class])($container));
	}//end testEidasIsDormantWithoutIntegriq()

	/**
	 * The translation adapter is the log adapter.
	 *
	 * @return void
	 */
	public function testTheTranslationAdapterIsTheLogAdapter(): void {
		$container = $this->container(
			services: [ContainerInterface::class => $this->createMock(ContainerInterface::class), LoggerInterface::class => $this->createMock(LoggerInterface::class)],
			callService: false
		);

		$adapter = ($this->factories[ITranslationAdapter::class])($container);

		$this->assertInstanceOf(LogTranslationAdapter::class, $adapter);
	}//end testTheTranslationAdapterIsTheLogAdapter()

	/**
	 * A container holding the given services; any CallService id resolves only when asked to.
	 *
	 * @param array<string, object> $services    Services by id.
	 * @param bool                  $callService Whether integriq's CallService resolves.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services, bool $callService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, $callService): object {
				if (str_ends_with($id, '\\Service\\CallService') === true) {
					if ($callService === true) {
						return new \stdClass();
					}

					throw new RuntimeException('not registered: ' . $id);
				}

				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return $container;
	}//end container()
}//end class
