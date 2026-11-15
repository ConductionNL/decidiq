<?php

/**
 * Edge tests for PortalCreateOpenParentGuardListener: how a create is
 * identified when it carries its schema rather than a field signature, the
 * exact refusal when no parent can be resolved, and a parent entity that only
 * offers getObject().
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\PortalCreateOpenParentGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Listener\PortalCreateOpenParentGuardListener
 * @uses   \OCA\Decidiq\Listener\MotionAdviceGuard
 * @uses   \OCA\Decidiq\Portal\PortalContributionProvider
 * @uses   \OCA\Decidiq\Portal\PortalLabelTranslator
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 */
class PortalCreateOpenParentGuardListenerEdgeTest extends TestCase {

	/**
	 * The finds the object service received, as [id, register, schema].
	 *
	 * @var list<array{0: string, 1: mixed, 2: mixed}>
	 */
	private array $finds = [];

	/**
	 * A listener whose object service returns the given parent objects.
	 *
	 * @param array<string, object> $parents Parent entities by id.
	 *
	 * @return PortalCreateOpenParentGuardListener
	 */
	private function listener(array $parents): PortalCreateOpenParentGuardListener {
		$finds         = &$this->finds;
		$objectService = new class($parents, $finds) {

			/**
			 * Constructor.
			 *
			 * @param array<string, object> $parents The parents by id.
			 * @param array<int, mixed>     $finds   Where the finds are recorded.
			 */
			public function __construct(
				private array $parents,
				private array &$finds,
			) {
			}//end __construct()

			/**
			 * Find a parent, with the signature of ObjectService::find().
			 *
			 * @param int|string      $id       Object id.
			 * @param string|int|null $register Register slug.
			 * @param string|int|null $schema   Schema slug.
			 *
			 * @return object|null
			 */
			public function find(int|string $id, string|int|null $register=null, string|int|null $schema=null): ?object {
				$this->finds[] = [(string)$id, $register, $schema];
				return ($this->parents[(string)$id] ?? null);
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		return new PortalCreateOpenParentGuardListener(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * A creating entity that carries the row and, optionally, a schema slug method.
	 *
	 * @param array<string, mixed> $row        The object payload.
	 * @param string|null          $schemaSlug What getSchemaSlug() returns; null for no such method.
	 * @param string|null          $schema     What getSchema() returns.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $row, ?string $schemaSlug=null, ?string $schema=null): ObjectEntity {
		if ($schemaSlug !== null) {
			return new class($row, $schemaSlug) extends ObjectEntity {

				/**
				 * Constructor.
				 *
				 * @param array<string, mixed> $row  The payload.
				 * @param string               $slug The schema slug.
				 */
				public function __construct(
					private array $row,
					private string $slug,
				) {
				}//end __construct()

				/**
				 * The payload.
				 *
				 * @return array<string, mixed>
				 */
				public function getObject(): array {
					return $this->row;
				}//end getObject()

				/**
				 * The schema slug.
				 *
				 * @return string
				 */
				public function getSchemaSlug(): string {
					return $this->slug;
				}//end getSchemaSlug()
			};
		}//end if

		return new class($row, $schema) extends ObjectEntity {

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $row         The payload.
			 * @param string|null          $schemaValue The schema.
			 */
			public function __construct(
				private array $row,
				private ?string $schemaValue,
			) {
			}//end __construct()

			/**
			 * The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->row;
			}//end getObject()

			/**
			 * The schema.
			 *
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return $this->schemaValue;
			}//end getSchema()
		};
	}//end entity()

	/**
	 * A parent entity offering only getObject(), as some OpenRegister doubles do.
	 *
	 * @param array<string, mixed> $row The parent payload.
	 *
	 * @return object
	 */
	private static function objectOnlyParent(array $row): object {
		return new class($row) {

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $row The payload.
			 */
			public function __construct(
				private array $row,
			) {
			}//end __construct()

			/**
			 * The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->row;
			}//end getObject()
		};
	}//end objectOnlyParent()

	/**
	 * A creating event without an entity is let through without any lookup.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnEntityIsLetThrough(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$event = new ObjectCreatingEvent(null);

		(new PortalCreateOpenParentGuardListener($container, $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testAnEventWithoutAnEntityIsLetThrough()

	/**
	 * A reaction named by its schema slug but without any parent reference is refused with the constraint spelled out.
	 *
	 * @return void
	 */
	public function testAReactionWithoutAParentIsRefusedWithTheConstraint(): void {
		$event = new ObjectCreatingEvent(self::entity(['_schema' => 'Consultation-Reaction', 'body' => 'Waar is de consultatie?']));

		$this->listener([])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => "consultation-reaction requires the parent public-consultation to have status == 'open'"],
			$event->getErrors()
		);
		self::assertSame([], $this->finds);
	}//end testAReactionWithoutAParentIsRefusedWithTheConstraint()

	/**
	 * The entity's schema slug identifies a budget proposal whose parent sits in the relations.
	 *
	 * @return void
	 */
	public function testTheEntitySchemaSlugIdentifiesTheCreate(): void {
		$row = [
			'title' => 'Speeltuin Oost',
			'relations' => [
				'not-a-relation',
				['schema' => 'public-consultation', 'id' => 'c-1'],
				['schema' => 'participatory-budget', 'id' => 'pb-2027'],
			],
		];
		$event = new ObjectCreatingEvent(self::entity($row, 'Budget-Proposal'));

		$this->listener(['pb-2027' => self::objectOnlyParent(['status' => 'submission'])])->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([['pb-2027', 'decidiq', 'participatory-budget']], $this->finds);
	}//end testTheEntitySchemaSlugIdentifiesTheCreate()

	/**
	 * The entity's getSchema() identifies the create when there is no slug; a parent read through getObject() in the wrong state refuses it.
	 *
	 * @return void
	 */
	public function testTheEntitySchemaIdentifiesTheCreateAndAClosedParentRefuses(): void {
		$event = new ObjectCreatingEvent(self::entity(['participatoryBudget' => 'pb-2027'], null, 'BUDGET-PROPOSAL'));

		$this->listener(['pb-2027' => self::objectOnlyParent(['status' => 'voting'])])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => "budget-proposal requires the parent participatory-budget to have status == 'submission'"],
			$event->getErrors()
		);
	}//end testTheEntitySchemaIdentifiesTheCreateAndAClosedParentRefuses()

	/**
	 * A citizen vote that is not advice on a motion is not this guard's business.
	 *
	 * @return void
	 */
	public function testACitizenVoteWithoutAMotionIsIgnored(): void {
		$event = new ObjectCreatingEvent(self::entity(['schema' => 'citizen-vote', 'voterId' => 'v-1', 'voteValue' => 'voor', 'motionId' => '']));

		$this->listener([])->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $this->finds);
	}//end testACitizenVoteWithoutAMotionIsIgnored()
}//end class
