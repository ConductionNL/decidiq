<?php

/**
 * Unit tests for SubjectClearanceService.
 *
 * The question a sibling app gates closure on. The tests that matter are the
 * ones where "not cleared" has to win: an unset `required`, a route with no
 * stages at all, a stage still pending.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SubjectClearanceService;
use PHPUnit\Framework\TestCase;

/**
 * Covers what clears a subject and what refuses to.
 */
final class SubjectClearanceServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var SubjectClearanceService
	 */
	private SubjectClearanceService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new SubjectClearanceService();
	}//end setUp()

	/**
	 * A route on step two, still waiting on a named person.
	 *
	 * @param array<string, mixed> $overrides Route-level overrides.
	 *
	 * @return array<string, mixed> The route with its stages.
	 */
	private function waitingRoute(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'route-1',
				'name' => 'Parafering',
				'stages' => [
					['sequence' => 1, 'status' => 'decided', 'outcome' => 'approved', 'assignedPerson' => 'j.jansen'],
					[
						'sequence' => 2,
						'status' => 'active',
						'label' => 'Afdelingshoofd',
						'assignedPerson' => 'd.devries',
						'dueAt' => '2026-09-25T12:00:00+00:00',
					],
				],
			],
			$overrides
		);
	}//end waitingRoute()

	/**
	 * Not cleared, and the answer names what to chase.
	 *
	 * @return void
	 */
	public function testAWaitingRouteBlocksAndNamesWhoItWaitsOn(): void {
		$clearance = $this->service->clearanceFor([$this->waitingRoute()]);

		$this->assertFalse($clearance['cleared']);
		$this->assertCount(1, $clearance['waitingOn']);
		$this->assertSame('route-1', $clearance['waitingOn'][0]['route']);
		$this->assertSame(2, $clearance['waitingOn'][0]['stage']);
		$this->assertSame('Afdelingshoofd', $clearance['waitingOn'][0]['stageName']);
		$this->assertSame('d.devries', $clearance['waitingOn'][0]['actor']);
		$this->assertSame('2026-09-25T12:00:00+00:00', $clearance['waitingOn'][0]['dueAt']);
	}//end testAWaitingRouteBlocksAndNamesWhoItWaitsOn()

	/**
	 * Every stage finished, so the subject is cleared.
	 *
	 * @return void
	 */
	public function testAConcludedRouteClearsTheSubject(): void {
		$route = $this->waitingRoute();
		$route['stages'][1]['status'] = 'decided';
		$route['stages'][1]['outcome'] = 'approved';

		$clearance = $this->service->clearanceFor([$route]);

		$this->assertTrue($clearance['cleared']);
		$this->assertSame([], $clearance['waitingOn']);
		$this->assertSame('Every required sign-off has been given.', $this->service->describe($clearance));
	}//end testAConcludedRouteClearsTheSubject()

	/**
	 * A route somebody marked optional never blocks.
	 *
	 * @return void
	 */
	public function testARouteMarkedNotRequiredNeverBlocks(): void {
		$clearance = $this->service->clearanceFor([$this->waitingRoute(['required' => false])]);

		$this->assertTrue($clearance['cleared']);
	}//end testARouteMarkedNotRequiredNeverBlocks()

	/**
	 * An unset `required` reads as required. This is the default that decides
	 * whether a route written before this change blocks or not.
	 *
	 * @return void
	 */
	public function testAnUnsetRequiredReadsAsRequired(): void {
		$route = $this->waitingRoute();
		$this->assertArrayNotHasKey('required', $route);

		$this->assertFalse($this->service->clearanceFor([$route])['cleared']);
	}//end testAnUnsetRequiredReadsAsRequired()

	/**
	 * A required route that never got its stages is not a finished one.
	 *
	 * @return void
	 */
	public function testARequiredRouteWithNoStagesDoesNotClear(): void {
		$clearance = $this->service->clearanceFor([['id' => 'route-2', 'name' => 'Advies', 'stages' => []]]);

		$this->assertFalse($clearance['cleared']);
		$this->assertSame('route-2', $clearance['waitingOn'][0]['route']);
	}//end testARequiredRouteWithNoStagesDoesNotClear()

	/**
	 * A step nobody has reached yet still holds the subject.
	 *
	 * @return void
	 */
	public function testAPendingStageStillBlocks(): void {
		$route = $this->waitingRoute();
		$route['stages'][1]['status'] = 'pending';

		$this->assertFalse($this->service->clearanceFor([$route])['cleared']);
	}//end testAPendingStageStillBlocks()

	/**
	 * A subject with nothing on it is cleared, because there is nothing to wait
	 * for.
	 *
	 * @return void
	 */
	public function testASubjectWithNoRoutesIsCleared(): void {
		$this->assertTrue($this->service->clearanceFor([])['cleared']);
	}//end testASubjectWithNoRoutesIsCleared()

	/**
	 * The sentence a consumer shows names the route and the person.
	 *
	 * @return void
	 */
	public function testTheSentenceNamesTheRouteAndThePerson(): void {
		$sentence = $this->service->describe($this->service->clearanceFor([$this->waitingRoute()]));

		$this->assertStringContainsString('Parafering', $sentence);
		$this->assertStringContainsString('d.devries', $sentence);
	}//end testTheSentenceNamesTheRouteAndThePerson()

	/**
	 * Two required routes both report, so nobody chases one and finds another.
	 *
	 * @return void
	 */
	public function testEveryWaitingRouteIsNamed(): void {
		$second = $this->waitingRoute(['id' => 'route-3', 'name' => 'Juridisch']);
		$second['stages'][1]['assignedPerson'] = 'b.bakker';

		$clearance = $this->service->clearanceFor([$this->waitingRoute(), $second]);

		$this->assertCount(2, $clearance['waitingOn']);
		$this->assertStringContainsString('b.bakker', $this->service->describe($clearance));
	}//end testEveryWaitingRouteIsNamed()
}//end class
