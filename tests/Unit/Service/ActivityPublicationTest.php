<?php

/**
 * Tests: a public meeting is published to the residents' calendar as an activity.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Service\AgendaPapers;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Opis\JsonSchema\Validator;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Service\PublicationEligibilityService
 * @covers \OCA\Decidiq\Service\PublicationPayloadService
 * @covers \OCA\Decidiq\Service\PublicationService
 * @uses   \OCA\Decidiq\Service\AgendaPapers
 * @uses   \OCA\Decidiq\Service\ConfidentialityRestrictions
 * @uses   \OCA\Decidiq\Service\PublicationConfigService
 * @uses   \OCA\Decidiq\Service\PublicationRepository
 *
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar
 */
class ActivityPublicationTest extends TestCase {

	/**
	 * Objects written, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $written = [];

	/**
	 * The merged schema for a slug: the base register plus every register.d
	 * fragment, lists concatenated as OpenRegister merges them.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedSchema(string $slug): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files    = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		$schema   = [];
		foreach ($files as $file) {
			$doc = (array)json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $fragment) {
				if (($fragment['slug'] ?? null) === $slug) {
					$schema = self::merge($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * Merge a fragment into a schema; a list is concatenated, a map merged.
	 *
	 * @param array<mixed> $base     The schema so far
	 * @param array<mixed> $fragment The fragment
	 *
	 * @return array<mixed>
	 */
	private static function merge(array $base, array $fragment): array {
		if (array_is_list($fragment) === true && array_is_list($base) === true) {
			return array_values(array_unique(array_merge($base, $fragment), SORT_REGULAR));
		}

		foreach ($fragment as $key => $value) {
			if (is_array($value) === true && is_array($base[$key] ?? null) === true) {
				$base[$key] = self::merge($base[$key], $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end merge()

	/**
	 * Validate an object against a merged schema, refusing undeclared keys.
	 *
	 * @param array<string, mixed> $data The object
	 * @param string               $slug The schema slug
	 *
	 * @return bool
	 */
	private static function validates(array $data, string $slug): bool {
		$schema     = self::mergedSchema(slug: $slug);
		$properties = $schema['properties'];
		foreach (array_keys($properties) as $key) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['format']);
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		return $result->isValid();
	}//end validates()

	/**
	 * The information evening: public, no convocation, no agenda.
	 *
	 * @param bool $public Whether the meeting is public.
	 *
	 * @return array<string,mixed>
	 */
	private static function evening(bool $public=true): array {
		return [
			'id' => 'meeting-1',
			'title' => 'Informatieavond windpark Noord',
			'scheduledDate' => '2026-04-08T19:30:00+02:00',
			'location' => 'Dorpshuis De Linde',
			'isPublic' => $public,
			'type' => 'type-info',
			'governanceBody' => 'body-1',
			'bodyName' => 'Gemeenteraad',
			'chair' => 'uid-anna',
			'participants' => ['uid-anna', 'uid-bert'],
		];
	}//end evening()

	/**
	 * An entity double.
	 *
	 * @param array<string,mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize', 'getObject'])->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The publication path over one meeting and its type.
	 *
	 * @param array<string,mixed> $meeting The meeting.
	 *
	 * @return PublicationService
	 */
	private function service(array $meeting): PublicationService {
		$store   = [
			'meeting-1' => $meeting,
			'type-info' => ['id' => 'type-info', 'name' => 'Informatieavond', 'audiences' => ['residents', 'council']],
		];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => isset($store[(string)$id]) === true ? $this->entity($store[(string)$id]) : null);
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				$this->written[] = ['_schema' => $schema] + $object;
				return $this->entity(['id' => 'obj-' . count($this->written)] + $object);
			}
		);

		$logger    = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"body-1":{"catalog":"council-catalog"}}');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(false);
		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => '']);

		return new PublicationService(
			$logger,
			$apps,
			new PublicationEligibilityService($logger, $objects),
			new PublicationPayloadService($container, $logger, new PublicationConfigService($appConfig), new AgendaPapers($objects, $container, $logger)),
			new PublicationConfigService($appConfig),
			$this->createMock(OpenCatalogiPublisher::class),
			$audit,
			$objects,
			eventRecorder: $this->createMock(\OCA\Decidiq\Service\PublicationEventRecorder::class),
		);
	}//end service()

	/**
	 * A public meeting without a convocation or agenda is published as an
	 * activity: date, title, body, location, type name and audiences, and no
	 * participant, no chair, no UID.
	 *
	 * @return void
	 */
	public function testAPublicMeetingWithoutAnAgendaIsPublishedAsAnActivity(): void {
		$this->service(self::evening())->publish('activity', 'meeting-1', 'griffier');

		$payload = $this->written[0];
		self::assertSame('Vergadering', $payload['oriType']);
		self::assertSame('activity', $payload['documentType']);
		self::assertSame('Informatieavond windpark Noord', $payload['title']);
		self::assertSame('2026-04-08T19:30:00+02:00', $payload['meetingDate']);
		self::assertSame('Dorpshuis De Linde', $payload['location']);
		self::assertSame('Informatieavond', $payload['meetingType']);
		self::assertSame(['residents', 'council'], $payload['audiences']);
		self::assertStringNotContainsString('uid-', (string)json_encode($payload));
		self::assertArrayNotHasKey('agendaItems', $payload);

		$record = $this->written[1];
		self::assertSame('activity', $record['sourceType']);

		unset($payload['_schema'], $record['_schema']);
		self::assertTrue(self::validates($payload, 'publication-payload'), 'The activity payload does not pass the PublicationPayload schema.');
		self::assertTrue(self::validates($record, 'publication-record'), 'The activity record does not pass the PublicationRecord schema.');
	}//end testAPublicMeetingWithoutAnAgendaIsPublishedAsAnActivity()

	/**
	 * A meeting that is not public is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testANonPublicMeetingIsRefused(): void {
		try {
			$this->service(self::evening(false))->publish('activity', 'meeting-1', 'griffier');
			self::fail('A non-public meeting was published to the residents calendar.');
		} catch (AccessDeniedException $e) {
			self::assertStringContainsString('Only public meetings', $e->getMessage());
		}

		self::assertSame([], $this->written);
	}//end testANonPublicMeetingIsRefused()
}//end class
