<?php

/**
 * Publishing to and retracting from OpenCatalogi, and every way that quietly does nothing.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\OpenCatalogiPublisher
 */
class OpenCatalogiPublisherTest extends TestCase {

	/**
	 * What the publisher logged: "message: exception".
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A store recording saves and deletes and answering saves with $answer.
	 *
	 * @param mixed $answer What saveObject() returns, or a throwable to throw from both calls.
	 *
	 * @return object The store.
	 */
	private function store(mixed $answer): object {
		return new class($answer) {
			/**
			 * Every save, as named arguments.
			 *
			 * @var list<array>
			 */
			public array $saved = [];

			/**
			 * Every delete, as named arguments.
			 *
			 * @var list<array>
			 */
			public array $deleted = [];

			/**
			 * The store.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private readonly mixed $answer) {
			}//end __construct()

			/**
			 * Record a save.
			 *
			 * @param array  $object   The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed The answer.
			 */
			public function saveObject(array $object, string $register, string $schema): mixed {
				$this->saved[] = compact('object', 'register', 'schema');
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end saveObject()

			/**
			 * Record a delete.
			 *
			 * @param string $uuid     The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return bool True.
			 */
			public function deleteObject(string $uuid, string $register, string $schema): bool {
				$this->deleted[] = compact('uuid', 'register', 'schema');
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return true;
			}//end deleteObject()
		};
	}//end store()

	/**
	 * The publisher with OpenCatalogi enabled or not and a store, or none.
	 *
	 * @param bool        $enabled Whether OpenCatalogi is enabled.
	 * @param object|null $store   The store, or null when unresolvable.
	 *
	 * @return OpenCatalogiPublisher The publisher.
	 */
	private function publisher(bool $enabled, ?object $store): OpenCatalogiPublisher {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id !== 'OCA\OpenRegister\Service\ObjectService' || $store === null) {
					throw new RuntimeException('not registered');
				}

				return $store;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => ($app === 'opencatalogi' && $enabled));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context=[]): void {
			$this->logged[] = $message . ': ' . ($context['exception'] ?? '');
		});

		return new OpenCatalogiPublisher($container, $apps, $logger);
	}//end publisher()

	/**
	 * A publication is saved in OpenCatalogi pointing back at the payload, and its uuid returned.
	 *
	 * @return void
	 */
	public function testAPublicationIsSavedAndItsUuidReturned(): void {
		$saved = new class {
			/**
			 * The uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'pub-1';
			}//end getUuid()
		};
		$store = $this->store($saved);

		$reference = $this->publisher(true, $store)->publish('cat-1', 'payload-1', ['title' => 'Minutes 1 Sept']);

		self::assertSame('pub-1', $reference);
		self::assertCount(1, $store->saved);
		self::assertSame('opencatalogi', $store->saved[0]['register']);
		self::assertSame('publication', $store->saved[0]['schema']);
		$object = $store->saved[0]['object'];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $object['published']);
		unset($object['published']);
		self::assertSame(
			[
				'title' => 'Minutes 1 Sept',
				'summary' => 'Minutes 1 Sept',
				'catalog' => 'cat-1',
				'reference' => 'payload-1',
				'register' => 'decidiq',
				'schema' => 'publication-payload',
			],
			$object
		);
	}//end testAPublicationIsSavedAndItsUuidReturned()

	/**
	 * Without a uuid getter the id comes from the serialised publication, in order of preference.
	 *
	 * @return void
	 */
	public function testTheReferenceFallsBackToTheSerialisedId(): void {
		$serialised = static fn (array $data): object => new class($data) {
			/**
			 * The publication.
			 *
			 * @param array $data Its data.
			 */
			public function __construct(private readonly array $data) {
			}//end __construct()

			/**
			 * An empty uuid.
			 *
			 * @return string|null The uuid.
			 */
			public function getUuid(): ?string {
				return null;
			}//end getUuid()

			/**
			 * The data.
			 *
			 * @return array The data.
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};

		self::assertSame('id-1', $this->publisher(true, $this->store($serialised(['id' => 'id-1', 'uuid' => 'u'])))->publish('c', 'p', []));
		self::assertSame('u-2', $this->publisher(true, $this->store($serialised(['uuid' => 'u-2'])))->publish('c', 'p', []));
		self::assertSame('s-3', $this->publisher(true, $this->store($serialised(['@self' => ['id' => 's-3']])))->publish('c', 'p', []));
		self::assertSame('', $this->publisher(true, $this->store($serialised(['id' => ''])))->publish('c', 'p', []));
		self::assertSame('', $this->publisher(true, $this->store(['id' => 'an array is no publication']))->publish('c', 'p', []));
	}//end testTheReferenceFallsBackToTheSerialisedId()

	/**
	 * Disabled OpenCatalogi or no object service publishes nothing; a failing save is logged.
	 *
	 * @return void
	 */
	public function testPublishingDegradesToNoReference(): void {
		$store = $this->store(null);
		self::assertSame('', $this->publisher(false, $store)->publish('c', 'p', []));
		self::assertSame([], $store->saved);

		self::assertSame('', $this->publisher(true, null)->publish('c', 'p', []));
		self::assertSame('', $this->publisher(true, $this->store(new RuntimeException('schema missing')))->publish('c', 'p', []));

		self::assertSame(
			[
				'Decidiq publication: OpenCatalogi ObjectService unresolvable: not registered',
				'Decidiq publication: OpenCatalogi publish failed: schema missing',
			],
			$this->logged
		);
	}//end testPublishingDegradesToNoReference()

	/**
	 * A retraction deletes the catalogue publication; nothing to retract counts as done.
	 *
	 * @return void
	 */
	public function testARetractionDeletesThePublication(): void {
		$store = $this->store(null);

		self::assertTrue($this->publisher(true, $store)->retract('pub-1'));
		self::assertSame([['uuid' => 'pub-1', 'register' => 'opencatalogi', 'schema' => 'publication']], $store->deleted);

		self::assertTrue($this->publisher(false, null)->retract(''), 'an empty reference is already retracted');
	}//end testARetractionDeletesThePublication()

	/**
	 * A retraction that cannot happen reports failure.
	 *
	 * @return void
	 */
	public function testARetractionThatCannotHappenFails(): void {
		$store = $this->store(null);
		self::assertFalse($this->publisher(false, $store)->retract('pub-1'));
		self::assertSame([], $store->deleted);

		self::assertFalse($this->publisher(true, null)->retract('pub-1'));
		self::assertFalse($this->publisher(true, $this->store(new RuntimeException('locked')))->retract('pub-1'));
		self::assertSame('Decidiq publication: OpenCatalogi retraction failed: locked', end($this->logged));
	}//end testARetractionThatCannotHappenFails()
}//end class
