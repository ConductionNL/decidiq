<?php

/**
 * Unit tests for ProcessTemplateService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Lifecycle\ProcessTemplatePolicyResolver;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\StateMachineValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests CRUD + built-in protection + duplicate + transition-graph validation +
 * template-driven policy/voting-rule resolution.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplateServiceTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Service under test.
	 *
	 * @var ProcessTemplateService
	 */
	private ProcessTemplateService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->container->method('get')->willReturn($this->objectService);

		$this->service = new ProcessTemplateService(
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
			resolver: new ProcessTemplatePolicyResolver(),
			validator: new StateMachineValidator(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock that serializes to the given array.
	 *
	 * @param array<string, mixed> $data Object payload
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A minimal valid template payload.
	 *
	 * @return array<string, mixed>
	 */
	private function validTemplate(): array {
		return [
			'name' => 'Test Template',
			'initialState' => 'draft',
			'stateMachine' => [
				'states' => [['name' => 'draft'], ['name' => 'proposed'], ['name' => 'decided']],
				'transitions' => [
					['from' => 'draft', 'to' => 'proposed'],
					['from' => 'proposed', 'to' => 'decided'],
				],
			],
		];
	}//end validTemplate()

	/**
	 * A valid state machine passes validation.
	 *
	 * @return void
	 */
	public function testValidateAcceptsWellFormedGraph(): void {
		$result = $this->service->validateStateMachine(template: $this->validTemplate());
		self::assertTrue(condition: $result['valid'], message: implode(' ', $result['errors']));
		self::assertSame([], $result['errors']);

	}//end testValidateAcceptsWellFormedGraph()

	/**
	 * A transition referencing an undeclared state is rejected (dangling).
	 *
	 * @return void
	 */
	public function testValidateRejectsDanglingTransition(): void {
		$template = $this->validTemplate();
		$template['stateMachine']['transitions'][] = ['from' => 'decided', 'to' => 'ghost'];

		$result = $this->service->validateStateMachine(template: $template);
		self::assertFalse(condition: $result['valid']);
		self::assertStringContainsString(needle: 'ghost', haystack: implode(' ', $result['errors']));

	}//end testValidateRejectsDanglingTransition()

	/**
	 * A state with no inbound/outbound edge (and not the initial state) is
	 * rejected as unreachable.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnreachableState(): void {
		$template = $this->validTemplate();
		$template['stateMachine']['states'][] = ['name' => 'orphan'];

		$result = $this->service->validateStateMachine(template: $template);
		self::assertFalse(condition: $result['valid']);
		self::assertStringContainsString(needle: 'unreachable', haystack: strtolower(implode(' ', $result['errors'])));

	}//end testValidateRejectsUnreachableState()

	/**
	 * An unknown guard token is rejected.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnknownGuard(): void {
		$template = $this->validTemplate();
		$template['stateMachine']['transitions'][0]['guards'] = ['quorum_met', 'made_up_token'];

		$result = $this->service->validateStateMachine(template: $template);
		self::assertFalse(condition: $result['valid']);
		self::assertStringContainsString(needle: 'made_up_token', haystack: implode(' ', $result['errors']));

	}//end testValidateRejectsUnknownGuard()

	/**
	 * create() refuses an invalid graph (fail closed) and never persists.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidGraph(): void {
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->create(template: ['name' => 'x', 'stateMachine' => ['states' => [], 'transitions' => []]]);

	}//end testCreateRejectsInvalidGraph()

	/**
	 * create() persists a valid template and forces builtIn=false.
	 *
	 * @return void
	 */
	public function testCreatePersistsAndClearsBuiltIn(): void {
		$this->objectService->expects(self::once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) {
					self::assertFalse($object['builtIn'], 'Created templates must never be built-in.');
					return $this->entity($object + ['id' => 'tpl-1']);
				}
			);

		$created = $this->service->create(template: ($this->validTemplate() + ['builtIn' => true]));
		self::assertSame('tpl-1', $created['id']);
		self::assertFalse($created['builtIn']);

	}//end testCreatePersistsAndClearsBuiltIn()

	/**
	 * update() refuses a built-in template (read-only).
	 *
	 * @return void
	 */
	public function testUpdateRefusesBuiltIn(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'tpl-1', 'builtIn' => true]));
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(\RuntimeException::class);
		$this->service->update(templateId: 'tpl-1', template: $this->validTemplate());

	}//end testUpdateRefusesBuiltIn()

	/**
	 * delete() refuses a built-in template (read-only).
	 *
	 * @return void
	 */
	public function testDeleteRefusesBuiltIn(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'tpl-1', 'builtIn' => true]));
		$this->objectService->expects(self::never())->method('deleteObject');

		$this->expectException(\RuntimeException::class);
		$this->service->delete(templateId: 'tpl-1');

	}//end testDeleteRefusesBuiltIn()

	/**
	 * duplicate() copies a built-in into an editable (builtIn=false) copy with a
	 * fresh identity; the original is untouched.
	 *
	 * @return void
	 */
	public function testDuplicateClearsBuiltInAndIdentity(): void {
		$source = ($this->validTemplate() + ['id' => 'tpl-src', 'builtIn' => true, 'name' => 'Association ALV']);
		$this->objectService->method('find')->willReturn($this->entity($source));

		$this->objectService->expects(self::once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) {
					self::assertArrayNotHasKey('id', $object, 'Duplicate must drop the source id.');
					self::assertFalse($object['builtIn']);
					self::assertSame('My ALV', $object['name']);
					return $this->entity($object + ['id' => 'tpl-copy']);
				}
			);

		$copy = $this->service->duplicate(templateId: 'tpl-src', newName: 'My ALV');
		self::assertSame('tpl-copy', $copy['id']);

	}//end testDuplicateClearsBuiltInAndIdentity()

	/**
	 * resolvePolicyForBody() loads the body's template by slug and translates it;
	 * a body with no template (or no body) yields null (caller falls back).
	 *
	 * @return void
	 */
	public function testResolvePolicyForBody(): void {
		$body = $this->entity(['id' => 'body-1', 'processTemplate' => 'association-alv']);
		$template = $this->entity(
			[
				'slug' => 'association-alv',
				'quorumRequired' => true,
				'allowDecideWithoutVote' => false,
				'stateMachine' => [
					'transitions' => [['from' => 'deliberating', 'to' => 'voting', 'chairOnly' => true]],
				],
			]
		);

		// find(body) returns the body; findAll(slug) returns the template.
		$this->objectService->method('find')->willReturn($body);
		$this->objectService->method('findAll')->willReturn([$template]);

		$policy = $this->service->resolvePolicyForBody(governanceBodyId: 'body-1');
		self::assertNotNull($policy);
		self::assertContains('deliberating:voting', $policy['chairOnlyTransitions']);

		// Null body id -> null override.
		self::assertNull($this->service->resolvePolicyForBody(governanceBodyId: null));

	}//end testResolvePolicyForBody()

	/**
	 * resolveVotingRuleForBody() returns the template's default voting rule.
	 *
	 * @return void
	 */
	public function testResolveVotingRuleForBody(): void {
		$body = $this->entity(['id' => 'body-1', 'processTemplate' => 'corporate-board']);
		$template = $this->entity(
			[
				'slug' => 'corporate-board',
				'votingRule' => ['voteThreshold' => 'qualified-majority-two-thirds'],
			]
		);

		$this->objectService->method('find')->willReturn($body);
		$this->objectService->method('findAll')->willReturn([$template]);

		$rule = $this->service->resolveVotingRuleForBody(governanceBodyId: 'body-1');
		self::assertSame('qualified-majority-two-thirds', $rule['voteThreshold']);

	}//end testResolveVotingRuleForBody()
}//end class
