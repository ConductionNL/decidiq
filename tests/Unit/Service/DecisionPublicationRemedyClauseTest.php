<?php

/**
 * The remedy clause on the publish path.
 *
 * REQ-DWP-007 says a decision carries the clause telling a person how to contest
 * it, and task 7.3 claimed publication of a type declaring no remedies was
 * refused. Neither was true: `LegalRemedyResolver` had no caller at all, so a
 * besluit published today told nobody how to disagree with it.
 *
 * These tests assert what a READER RECEIVES, not what the resolver returns. The
 * assertion is on the payload `DecisionPublicationService::publish()` answers,
 * which is what the endpoint renders verbatim, and on the object that was
 * actually saved. Deleting either call in publish() reddens a named assertion
 * here.
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

use OCA\Decidiq\Service\DecisionPublicationService;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What a published decision tells the person reading it.
 */
class DecisionPublicationRemedyClauseTest extends TestCase {
	/**
	 * The object rows the fake register answers with, by schema.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * What was saved, or null when nothing was.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * An adopted, unpublished decision naming a type.
	 *
	 * @param string $typeId The type it names; empty for an untyped decision.
	 *
	 * @return array<string, mixed> The decision.
	 */
	private function adoptedDecision(string $typeId = 'type-1'): array {
		return [
			'id' => 'decision-1',
			'title' => 'Vaststelling begroting',
			'outcome' => 'adopted',
			'isPublished' => 'internal',
			'type' => $typeId,
		];
	}

	/**
	 * Build the service over a fake register.
	 *
	 * @param array<string, mixed> $decision The decision to publish.
	 * @param array<string, mixed>|null $type The type it names, or null for none stored.
	 *
	 * @return DecisionPublicationService The service.
	 */
	private function service(array $decision, ?array $type): DecisionPublicationService {
		$this->rows = ['decision' => ['decision-1' => $decision]];
		if ($type !== null) {
			$this->rows['decision-template'] = ['type-1' => $type];
		}

		$test = $this;
		$objectService = new class ($test) {
			/**
			 * @param object $test The test holding the rows.
			 */
			public function __construct(private readonly object $test) {
			}

			/**
			 * @var string
			 */
			private string $schema = 'decision';

			/**
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->schema = $schema;
			}

			/**
			 * @param string $id The uuid.
			 *
			 * @return object|null The entity.
			 */
			public function find(string $id): ?object {
				$row = ($this->test->rowFor(schema: $this->schema, id: $id));
				if ($row === null) {
					return null;
				}

				return new class ($row) {
					/**
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private readonly array $row) {
					}

					/**
					 * @return array<string, mixed> The row.
					 */
					public function getObject(): array {
						return $this->row;
					}
				};
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return object The stored entity.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): object {
				$this->test->recordSave(object: $object);

				return new class ($object) {
					/**
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private readonly array $row) {
					}

					/**
					 * @return array<string, mixed> The row.
					 */
					public function getObject(): array {
						return $this->row;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new DecisionPublicationService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A stored row, or null.
	 *
	 * @param string $schema The schema.
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function rowFor(string $schema, string $id): ?array {
		return ($this->rows[$schema][$id] ?? null);
	}

	/**
	 * Remember what was saved.
	 *
	 * @param array<string, mixed> $object The saved object.
	 *
	 * @return void
	 */
	public function recordSave(array $object): void {
		$this->saved = $object;
	}

	/**
	 * The reader receives the clause, composed from the type's declaration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testAPublishedDecisionTellsTheReaderHowToContestIt(): void {
		$service = $this->service(
			decision: $this->adoptedDecision(),
			type: ['id' => 'type-1', 'legalRemedies' => [['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college']]],
		);

		$result = $service->publish(decisionId: 'decision-1', actorUid: 'alice');

		$this->assertSame(Http::STATUS_OK, $result['status']);

		$clause = ($result['data']['legalRemedyClause'] ?? null);
		$this->assertIsArray($clause, 'A published decision reached the reader with no remedy clause.');
		$this->assertSame('bezwaar', $clause['kind']);
		$this->assertSame(42, $clause['termDays']);
		$this->assertSame('het college', $clause['body']);
		$this->assertStringContainsString(
			'zes weken',
			(string)$clause['text'],
			'The clause did not tell the reader how long they have.'
		);

		$this->assertArrayHasKey(
			'legalRemedyClause',
			(array)$this->saved,
			'The clause reached the response but was never stored.'
		);
	}

	/**
	 * A type declaring no remedies is refused, and nothing is published.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testATypeDeclaringNoRemediesIsRefusedAndNothingIsSaved(): void {
		$service = $this->service(
			decision: $this->adoptedDecision(),
			type: ['id' => 'type-1'],
		);

		$result = $service->publish(decisionId: 'decision-1', actorUid: 'alice');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result['status']);
		$this->assertStringContainsString(
			'legal remedies',
			(string)($result['data']['message'] ?? ''),
			'The refusal did not say what was missing.'
		);
		$this->assertNull($this->saved, 'A decision with no declared remedies was published anyway.');
	}

	/**
	 * An already-stamped decision keeps the clause it told somebody.
	 *
	 * Re-resolving on every publish would quietly rewrite what a person was
	 * told, which is what stamp() exists to prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testAnAlreadyStampedClauseIsNotRewritten(): void {
		$decision = ($this->adoptedDecision() + [
			'legalRemedyClause' => ['kind' => 'beroep', 'termDays' => 99, 'body' => 'de rechtbank', 'text' => 'Al verteld.'],
		]);

		$result = $this->service(
			decision: $decision,
			type: ['id' => 'type-1', 'legalRemedies' => [['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college']]],
		)->publish(decisionId: 'decision-1', actorUid: 'alice');

		$this->assertSame(99, $result['data']['legalRemedyClause']['termDays']);
	}

	/**
	 * A decision naming no type publishes unchanged.
	 *
	 * The compatibility half. We cannot read remedies off a type that was never
	 * chosen, and refusing would stop every untyped decision from publishing,
	 * which is a break rather than a guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testAnUntypedDecisionStillPublishes(): void {
		$result = $this->service(decision: $this->adoptedDecision(typeId: ''), type: null)
			->publish(decisionId: 'decision-1', actorUid: 'alice');

		$this->assertSame(Http::STATUS_OK, $result['status']);
		$this->assertArrayNotHasKey('legalRemedyClause', $result['data']);
	}
}
