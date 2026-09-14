<?php

/**
 * ConnectionReportService unit tests.
 *
 * The service tells integriq's connection registry which eIDAS signing
 * service and which translation adapter answer, and asks integriq to resolve
 * ORI again after a save. Every test guards one way it could quietly stop
 * telling the truth: calling a log-only fallback configured, calling a signing
 * service that cannot find its source configured, letting one broken binding
 * stop the other report, turning a listener's failure into a failed save, or
 * logging a fault when integriq is simply not installed.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\ConnectionReportService;
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCA\Decidiq\Service\ITranslationAdapter;
use OCA\Decidiq\Service\LogEIDASSignatureService;
use OCA\Decidiq\Service\LogTranslationAdapter;
use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReportService.
 *
 * @covers \OCA\Decidiq\Service\ConnectionReportService
 */
class ConnectionReportServiceTest extends TestCase {

	/**
	 * Integriq's source lookup, under the namespace integriq ships today.
	 *
	 * @var string
	 */
	private const SOURCE_MAPPER = 'OCA\Integriq\Db\SourceMapper';

	/**
	 * Integriq's translation service, under the namespace integriq ships today.
	 *
	 * @var string
	 */
	private const TRANSLATION_SERVICE = 'OCA\Integriq\Service\TranslationService';

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->sent = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
	}//end setUp()

	/**
	 * A container that answers with the given bindings, or throws for a missing one.
	 *
	 * @param array<string, object> $bindings Service per id.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $bindings): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($bindings): object {
				if (isset($bindings[$id]) === false) {
					throw new RuntimeException('No binding for ' . $id);
				}

				return $bindings[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * The fallbacks decidiq binds when no provider is around.
	 *
	 * @return array<string, object>
	 */
	private function fallbackBindings(): array {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return [
			IEIDASSignatureService::class => new LogEIDASSignatureService(
				logger: $logger,
				auditLogService: $this->createMock(originalClassName: AuditLogService::class),
			),
			ITranslationAdapter::class => new LogTranslationAdapter(
				container: $this->container(bindings: []),
				logger: $logger,
			),
		];
	}//end fallbackBindings()

	/**
	 * The delegating signing service, as the registrar binds it when integriq is present.
	 *
	 * @return EIDASSignatureService
	 */
	private function delegatingSigner(): EIDASSignatureService {
		return new EIDASSignatureService(
			container: $this->container(bindings: []),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			auditLogService: $this->createMock(originalClassName: AuditLogService::class),
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
		);
	}//end delegatingSigner()

	/**
	 * A source lookup that knows the given slugs.
	 *
	 * @param array<int, string> $slugs The slugs that exist.
	 *
	 * @return object
	 */
	private function sourceMapper(array $slugs): object {
		return new class($slugs) {

			/**
			 * Constructor.
			 *
			 * @param array<int, string> $slugs The slugs that exist.
			 */
			public function __construct(private readonly array $slugs) {
			}//end __construct()

			/**
			 * Find a source by slug.
			 *
			 * @param string $slug The slug.
			 *
			 * @return object|null
			 */
			public function findBySlug(string $slug): ?object {
				if (in_array($slug, $this->slugs, true) === true) {
					return (object)['slug' => $slug];
				}

				return null;
			}//end findBySlug()
		};
	}//end sourceMapper()

	/**
	 * The service as production builds it.
	 *
	 * @param array<string, object> $bindings Service per id.
	 *
	 * @return ConnectionReportService
	 */
	private function service(array $bindings): ConnectionReportService {
		return new ConnectionReportService(
			container: $this->container(bindings: $bindings),
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * The shipped fallbacks are reported simulated, one event per connection.
	 *
	 * @return void
	 */
	public function testTheLogFallbacksAreReportedSimulated(): void {
		$this->service(bindings: $this->fallbackBindings())->reportBindings();

		$this->assertCount(expectedCount: 2, haystack: $this->sent);

		[$eidas, $translation] = $this->sent;
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $eidas);
		$this->assertSame(expected: 'decidiq', actual: $eidas->app);
		$this->assertSame(expected: 'eidas', actual: $eidas->key);
		$this->assertSame(expected: 'simulated', actual: $eidas->status);
		$this->assertStringContainsString(needle: 'nothing is signed', haystack: $eidas->message);

		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $translation);
		$this->assertSame(expected: 'translation', actual: $translation->key);
		$this->assertSame(expected: 'simulated', actual: $translation->status);
		$this->assertStringContainsString(needle: 'original text', haystack: $translation->message);
	}//end testTheLogFallbacksAreReportedSimulated()

	/**
	 * The delegating signer without integriq's source lookup is an error, not configured.
	 *
	 * Integriq on development ships no `Db\SourceMapper`, so every signing
	 * request throws before it reaches a source.
	 *
	 * @return void
	 */
	public function testTheDelegatingSignerWithoutASourceLookupIsAnError(): void {
		$service = $this->service(bindings: [IEIDASSignatureService::class => $this->delegatingSigner()]);

		[$status, $message] = $service->observeSigning();

		$this->assertSame(expected: 'error', actual: $status);
		$this->assertStringContainsString(needle: 'every signing request fails', haystack: $message);
	}//end testTheDelegatingSignerWithoutASourceLookupIsAnError()

	/**
	 * The delegating signer with a signing source is configured, and says it is untested.
	 *
	 * @return void
	 */
	public function testTheDelegatingSignerWithASourceIsConfigured(): void {
		$service = $this->service(
			bindings: [
				IEIDASSignatureService::class => $this->delegatingSigner(),
				self::SOURCE_MAPPER => $this->sourceMapper(slugs: [EIDASSignatureService::ESIGN_SOURCE_SLUG]),
			]
		);

		[$status, $message] = $service->observeSigning();

		$this->assertSame(expected: 'configured', actual: $status);
		$this->assertStringContainsString(needle: 'eidas-qes exists', haystack: $message);
		$this->assertStringContainsString(needle: 'does not test', haystack: $message);
	}//end testTheDelegatingSignerWithASourceIsConfigured()

	/**
	 * The delegating signer with a lookup but no signing source is unconfigured, naming both slugs.
	 *
	 * @return void
	 */
	public function testTheDelegatingSignerWithoutASourceIsUnconfigured(): void {
		$service = $this->service(
			bindings: [
				IEIDASSignatureService::class => $this->delegatingSigner(),
				self::SOURCE_MAPPER => $this->sourceMapper(slugs: ['some-other-source']),
			]
		);

		[$status, $message] = $service->observeSigning();

		$this->assertSame(expected: 'unconfigured', actual: $status);
		$this->assertStringContainsString(needle: 'docudesk-signing', haystack: $message);
		$this->assertStringContainsString(needle: 'eidas-qes', haystack: $message);
	}//end testTheDelegatingSignerWithoutASourceIsUnconfigured()

	/**
	 * Another bound signing service is configured, naming its class.
	 *
	 * @return void
	 */
	public function testAnotherSigningServiceIsConfiguredByName(): void {
		$other = $this->createMock(originalClassName: IEIDASSignatureService::class);
		$service = $this->service(bindings: [IEIDASSignatureService::class => $other]);

		[$status, $message] = $service->observeSigning();

		$this->assertSame(expected: 'configured', actual: $status);
		$this->assertStringContainsString(needle: get_class($other), haystack: $message);
	}//end testAnotherSigningServiceIsConfiguredByName()

	/**
	 * The log translation adapter with an integriq translation service is configured.
	 *
	 * @return void
	 */
	public function testTheLogAdapterWithAnIntegriqProviderIsConfigured(): void {
		$bindings = $this->fallbackBindings();
		$bindings[self::TRANSLATION_SERVICE] = new \stdClass();

		[$status, $message] = $this->service(bindings: $bindings)->observeTranslation();

		$this->assertSame(expected: 'configured', actual: $status);
		$this->assertStringContainsString(needle: 'integriq translation service', haystack: $message);
	}//end testTheLogAdapterWithAnIntegriqProviderIsConfigured()

	/**
	 * Another bound translation adapter is configured, naming its class.
	 *
	 * @return void
	 */
	public function testAnotherTranslationAdapterIsConfiguredByName(): void {
		$other = $this->createMock(originalClassName: ITranslationAdapter::class);

		[$status, $message] = $this->service(bindings: [ITranslationAdapter::class => $other])->observeTranslation();

		$this->assertSame(expected: 'configured', actual: $status);
		$this->assertStringContainsString(needle: get_class($other), haystack: $message);
	}//end testAnotherTranslationAdapterIsConfiguredByName()

	/**
	 * A binding that throws is reported error, and the other connection still reports.
	 *
	 * @return void
	 */
	public function testABrokenBindingIsReportedErrorAndTheOtherStillReports(): void {
		$bindings = $this->fallbackBindings();
		unset($bindings[IEIDASSignatureService::class]);

		$this->service(bindings: $bindings)->reportBindings();

		$this->assertSame(expected: ['error', 'simulated'], actual: array_map(static fn ($event): string => $event->status, $this->sent));
		$this->assertStringContainsString(
			needle: 'could not load the signing service: No binding for ' . IEIDASSignatureService::class,
			haystack: $this->sent[0]->message
		);
	}//end testABrokenBindingIsReportedErrorAndTheOtherStillReports()

	/**
	 * Without integriq nothing is resolved, sent or logged, on either path.
	 *
	 * Only the class lookup is replaced. The stubs make the event classes
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new class($container, $this->dispatcher, $this->logger) extends ConnectionReportService {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};

		$service->reportBindings();
		$service->refreshFromSave(saved: ['ori_endpoint' => 'https://ori.example.nl']);
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for a stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReportService::class, 'resolveEventClass');
		$service = $this->service(bindings: []);

		$this->assertNull(actual: $method->invoke($service, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReportService::STATUS_EVENT,
			actual: $method->invoke($service, ConnectionReportService::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event names are the ones the contract fixes.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stubs' real names.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReportService::STATUS_EVENT);
		$this->assertSame(expected: ConnectionRefreshRequestedEvent::class, actual: ConnectionReportService::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * A listener that throws never escapes, and is logged per connection.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(count: 3))->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$service = new ConnectionReportService(
			container: $this->container(bindings: $this->fallbackBindings()),
			eventDispatcher: $dispatcher,
			logger: $this->logger,
		);

		// Reaching the next line is the assertion that nothing escaped.
		$service->reportBindings();
		$service->refreshFromSave(saved: ['ori_endpoint' => 'https://ori.example.nl']);
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * A save that writes an ORI key asks integriq to resolve ORI, and nothing else.
	 *
	 * @return void
	 */
	public function testAnOriSaveRefreshesOri(): void {
		$service = $this->service(bindings: []);

		$service->refreshFromSave(saved: ['ori_bearer_secret' => 's3cret']);

		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$this->assertInstanceOf(expected: ConnectionRefreshRequestedEvent::class, actual: $this->sent[0]);
		$this->assertSame(expected: 'decidiq', actual: $this->sent[0]->app);
		$this->assertSame(expected: 'ori', actual: $this->sent[0]->key);
	}//end testAnOriSaveRefreshesOri()

	/**
	 * A save that writes no ORI key sends no refresh.
	 *
	 * @return void
	 */
	public function testAnUnrelatedSaveSendsNoRefresh(): void {
		$this->service(bindings: [])->refreshFromSave(saved: ['organisation_name' => 'Waterschap']);

		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAnUnrelatedSaveSendsNoRefresh()
}//end class
