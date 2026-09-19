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
 * 🔴 AND THEY STILL COULD NOT FAIL, FOR A SECOND REASON, FOR A DAY.
 * The fake ObjectService this file used to carry accepted any slug from
 * `setSchema()`. The shipped register carried no `decision-template` at all —
 * declared in `components.schemas`, absent from
 * `components.registers.decidiq.schemas` — so in production that call threw,
 * `typeOf()`'s `catch (\Throwable)` logged a warning and returned null, and
 * BOTH the guard and the stamp below were skipped. Green here, no clause there.
 *
 * The fake is now `RegisterScopedObjectServiceFake`, which resolves slugs
 * against the register the app actually ships. Detach `decision-template` from
 * that list and these tests go red, which is the only reason to trust them.
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
use OCA\Decidiq\Tests\Unit\Support\RegisterScopedObjectServiceFake;
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
	 * The register the service publishes into.
	 *
	 * @var RegisterScopedObjectServiceFake
	 */
	private RegisterScopedObjectServiceFake $register;

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

		$this->register = new RegisterScopedObjectServiceFake(rows: $this->rows);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->register);

		return new DecisionPublicationService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The decision as it was actually stored, or null when nothing was stored.
	 *
	 * @return array<string, mixed>|null The saved decision.
	 */
	private function saved(): ?array {
		return $this->register->lastWriteTo(schema: 'decision');
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
			(array)$this->saved(),
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
		$this->assertNull($this->saved(), 'A decision with no declared remedies was published anyway.');
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
