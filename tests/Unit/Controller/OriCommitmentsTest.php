<?php

/**
 * Unit tests for the ORI commitments resource (followup-public-progress).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
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

namespace OCA\Decidiq\Tests\Unit\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Controller\OriController;
use OCA\Decidiq\Service\OriSerializer;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Commitments on the public ORI API: public fields only, and only once the
 * publication date has passed.
 *
 * @covers \OCA\Decidiq\Controller\OriController
 * @covers \OCA\Decidiq\Service\OriSerializer
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-001-the-public-sees-commitment-and-motion-progress
 */
class OriCommitmentsTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The controller under test.
	 *
	 * @var OriController
	 */
	private OriController $controller;

	/**
	 * Build the controller over a real serializer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->controller = new OriController(
			$this->createMock(IRequest::class),
			$this->createMock(IConfig::class),
			$container,
			$this->createMock(LoggerInterface::class),
			new OriSerializer(),
		);
	}//end setUp()

	/**
	 * An entity double that serializes to $data.
	 *
	 * @param array<string,mixed> $data The object
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A commitment as the register stores it, with every internal field set.
	 *
	 * @param string      $uuid            The id
	 * @param string|null $publicationDate The publication date, or null
	 *
	 * @return array<string,mixed>
	 */
	private function commitment(string $uuid, ?string $publicationDate): array {
		return [
			'uuid' => $uuid,
			'@self' => ['id' => 'internal-7', 'owner' => 'griffier'],
			'text' => 'The alderman sends the council a housing report by 1 December.',
			'madeBy' => 'person-uuid-1',
			'meeting' => 'meeting-uuid-1',
			'agendaItem' => 'item-uuid-1',
			'directedTo' => 'body-uuid-1',
			'deadline' => '2026-12-01',
			'lifecycle' => 'in-execution',
			'settlementNotes' => '',
			'settlementEvidence' => 'https://intranet.example/evidence.pdf',
			'relatedMotion' => 'motion-uuid-1',
			'migratedFromObject' => 'toezegging-12',
			'progress' => [
				['date' => '2026-10-01', 'note' => 'Draft report sent to the committee', 'author' => 'griffier'],
			],
			'publicationDate' => $publicationDate,
		];
	}//end commitment()

	/**
	 * A past publication date.
	 *
	 * @return string
	 */
	private function past(): string {
		return (new DateTimeImmutable('-1 day'))->format(DateTimeInterface::ATOM);
	}//end past()

	/**
	 * A journalist reads the commitments list and sees status, deadline and
	 * progress, and nothing internal.
	 *
	 * @return void
	 */
	public function testPublishedCommitmentListsPublicFieldsOnly(): void {
		$this->objectService->expects(self::once())->method('findAll')
			->with(self::callback(static fn (array $config): bool => ($config['filters']['schema'] ?? null) === 'governance-commitment' && isset($config['filters']['lifecycle']) === false))
			->willReturn([$this->entity($this->commitment(uuid: 'c-1', publicationDate: $this->past()))]);

		$response = $this->controller->index(resource: 'commitments');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->getData();
		self::assertSame(1, $body['count']);
		$item = $body['items'][0];
		self::assertSame(
			['@context', '@type', 'id', 'status', 'text', 'deadline', 'progress', 'settlement_notes', 'motion', 'published_at'],
			array_keys($item)
		);
		self::assertSame('Commitment', $item['@type']);
		self::assertSame('c-1', $item['id']);
		self::assertSame('in-execution', $item['status']);
		self::assertSame('2026-12-01', $item['deadline']);
		self::assertSame([['date' => '2026-10-01', 'note' => 'Draft report sent to the committee']], $item['progress']);
		self::assertSame('motion-uuid-1', $item['motion']);
	}//end testPublishedCommitmentListsPublicFieldsOnly()

	/**
	 * A commitment before its publication date, or without one, is not on
	 * the list.
	 *
	 * @return void
	 */
	public function testUnpublishedCommitmentsAreNotListed(): void {
		$future = (new DateTimeImmutable('+7 days'))->format(DateTimeInterface::ATOM);
		$this->objectService->method('findAll')->willReturn(
			[
				$this->entity($this->commitment(uuid: 'c-future', publicationDate: $future)),
				$this->entity($this->commitment(uuid: 'c-none', publicationDate: null)),
			]
		);

		$body = $this->controller->index(resource: 'commitments')->getData();

		self::assertSame(0, $body['count']);
		self::assertSame([], $body['items']);
	}//end testUnpublishedCommitmentsAreNotListed()

	/**
	 * Asking for an unpublished commitment by id answers not found.
	 *
	 * @return void
	 */
	public function testUnpublishedCommitmentByIdIsNotFound(): void {
		$future = (new DateTimeImmutable('+7 days'))->format(DateTimeInterface::ATOM);
		$this->objectService->method('find')->willReturn($this->entity($this->commitment(uuid: 'c-future', publicationDate: $future)));

		$response = $this->controller->show(resource: 'commitments', id: 'c-future');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnpublishedCommitmentByIdIsNotFound()

	/**
	 * A published commitment by id carries the same public fields only.
	 *
	 * @return void
	 */
	public function testPublishedCommitmentByIdCarriesPublicFieldsOnly(): void {
		$this->objectService->method('find')->willReturn($this->entity($this->commitment(uuid: 'c-1', publicationDate: $this->past())));

		$response = $this->controller->show(resource: 'commitments', id: 'c-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$item = $response->getData();
		foreach (['madeBy', 'meeting', 'agendaItem', 'directedTo', 'settlementEvidence', 'migratedFromObject', '@self', 'lifecycle'] as $internal) {
			self::assertArrayNotHasKey($internal, $item);
		}

		self::assertSame([['date' => '2026-10-01', 'note' => 'Draft report sent to the committee']], $item['progress']);
	}//end testPublishedCommitmentByIdCarriesPublicFieldsOnly()
}//end class
