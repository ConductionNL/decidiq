<?php

/**
 * Recording the report of an urgent decision to its ratifying body, end to
 * end over an in-memory register.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use JsonSerializable;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use OCA\Decidiq\Service\DecisionContextResolver;
use OCA\Decidiq\Service\RatifyingBodyReport;
use OCA\Decidiq\Service\UrgencyWriteGrant;
use OCA\Decidiq\Service\UrgentReportService;
use OCA\Decidiq\Tests\Unit\Support\UrgentProcedureStore;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for UrgentReportService and RatifyingBodyReport.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */
class UrgentReportServiceTest extends TestCase {

	/**
	 * The policy of the college's template: the chair and the secretary may act on urgency.
	 *
	 * @var array<string, mixed>
	 */
	private const POLICY = ['allowedTriggerRoles' => ['chair', 'secretary'], 'ratificationRequired' => true, 'ratifyingBody' => 'body-raad'];

	/**
	 * The urgent decision of the college meeting chaired by the burgemeester.
	 *
	 * @var array<string, mixed>
	 */
	private const URGENT = [
		'id' => 'dec-1',
		'title' => 'Noodverordening Havenfestival 2026',
		'meeting' => 'meet-college',
		'isUrgent' => true,
		'urgencyDeclaredByBody' => 'body-college',
		'urgencyDeclaredInOffice' => 'Burgemeester',
	];

	/**
	 * The register.
	 *
	 * @var UrgentProcedureStore
	 */
	private UrgentProcedureStore $store;

	/**
	 * The grant, to see it is held during the save and dropped after.
	 *
	 * @var UrgencyWriteGrant
	 */
	private UrgencyWriteGrant $grant;

	/**
	 * Build the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->grant = new UrgencyWriteGrant();
		$grant       = $this->grant;
		$this->store = new class($grant) extends UrgentProcedureStore {
			/**
			 * Whether the grant was held at each save.
			 *
			 * @var list<bool>
			 */
			public array $grantedAtSave = [];

			/**
			 * @param UrgencyWriteGrant $grant The grant to watch.
			 */
			public function __construct(private readonly UrgencyWriteGrant $grant) {
			}

			/**
			 * Record the grant, then save.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions|null $options The save options.
			 * @param bool                 $_rbac   Unused.
			 *
			 * @return JsonSerializable
			 */
			public function saveObject(array $object, ?ObjectSaveOptions $options=null, bool $_rbac=true): JsonSerializable {
				$this->grantedAtSave[] = $this->grant->isGranted(decisionId: (string)($options?->uuid ?? ''));
				return parent::saveObject(object: $object, options: $options, _rbac: $_rbac);
			}
		};
		$this->store->put(schema: 'decision', object: self::URGENT);
		$this->store->put(schema: 'meeting', object: ['id' => 'meet-college', 'governanceBody' => 'body-college', 'chair' => 'part-burgemeester']);
		$this->store->put(schema: 'participant', object: ['id' => 'part-burgemeester', 'nextcloudUserId' => 'burgemeester']);
	}//end setUp()

	/**
	 * The service over the register, on 6 October 2026.
	 *
	 * @param array<string, mixed>|null $policy The college's urgency policy.
	 * @param array<string, bool>       $roles  Users holding an allowed role on the meeting.
	 *
	 * @return UrgentReportService
	 */
	private function service(?array $policy=self::POLICY, array $roles=['griffier' => true]): UrgentReportService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->store);
		$templates = $this->createMock(ProcessTemplateServiceInterface::class);
		$templates->method('resolveUrgencyPolicyForBody')->willReturnCallback(
			static fn (?string $body): ?array => $body === 'body-college' ? $policy : null
		);
		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('hasRole')->willReturnCallback(static fn (string $meeting, string $uid): bool => ($roles[$uid] ?? false));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-06T16:20:00+02:00'));

		return new UrgentReportService(
			container: $container,
			context: new DecisionContextResolver(logger: new NullLogger()),
			templates: $templates,
			guard: new UrgencyTriggerGuard(participants: $participants),
			report: new RatifyingBodyReport(timeFactory: $time, grant: $this->grant),
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * Reported before the ratifying meeting: the griffier records it and the
	 * decision stores the day, under the grant.
	 *
	 * @return void
	 */
	public function testTheGriffierRecordsTheReport(): void {
		$result = $this->service()->report(decisionId: 'dec-1', userId: 'griffier');

		$this->assertSame(200, $result['status']);
		$this->assertFalse($result['data']['alreadyReported']);
		$this->assertSame('2026-10-06', $result['data']['decision']['reportedToRatifyingBodyAt']);
		$this->assertSame(['decision', 'dec-1'], [$this->store->saves[0][0], $this->store->saves[0][1]]);
		$this->assertSame(array_merge(self::URGENT, ['reportedToRatifyingBodyAt' => '2026-10-06']), $this->store->saves[0][2]);
		$this->assertSame([true], $this->store->grantedAtSave);
		$this->assertFalse($this->grant->isGranted(decisionId: 'dec-1'));
	}//end testTheGriffierRecordsTheReport()

	/**
	 * The chair may record it too.
	 *
	 * @return void
	 */
	public function testTheChairRecordsTheReport(): void {
		$this->assertSame(200, $this->service(roles: [])->report(decisionId: 'dec-1', userId: 'burgemeester')['status']);
	}//end testTheChairRecordsTheReport()

	/**
	 * A second report keeps the first date and saves nothing.
	 *
	 * @return void
	 */
	public function testASecondReportKeepsTheFirstDate(): void {
		$this->store->put(schema: 'decision', object: array_merge(self::URGENT, ['reportedToRatifyingBodyAt' => '2026-10-01']));

		$result = $this->service()->report(decisionId: 'dec-1', userId: 'griffier');

		$this->assertSame(200, $result['status']);
		$this->assertTrue($result['data']['alreadyReported']);
		$this->assertSame('2026-10-01', $result['data']['decision']['reportedToRatifyingBodyAt']);
		$this->assertSame([], $this->store->saves);
	}//end testASecondReportKeepsTheFirstDate()

	/**
	 * A member without the role is refused and nothing changes, before the
	 * decision's state is looked at.
	 *
	 * @return void
	 */
	public function testAMemberWithoutTheRoleIsRefused(): void {
		$this->store->put(schema: 'decision', object: array_merge(self::URGENT, ['isUrgent' => false]));

		$result = $this->service()->report(decisionId: 'dec-1', userId: 'raadslid');

		$this->assertSame(403, $result['status']);
		$this->assertSame([], $this->store->saves);
	}//end testAMemberWithoutTheRoleIsRefused()

	/**
	 * A decision that is not urgent cannot be reported; a body without a
	 * policy fails closed; an unknown decision is 404.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(404, $this->service()->report(decisionId: 'nope', userId: 'griffier')['status']);
		$this->assertSame(422, $this->service(policy: null)->report(decisionId: 'dec-1', userId: 'griffier')['status']);

		$this->store->put(schema: 'decision', object: array_merge(self::URGENT, ['isUrgent' => false]));
		$this->assertSame(409, $this->service()->report(decisionId: 'dec-1', userId: 'griffier')['status']);
		$this->assertSame([], $this->store->saves);
	}//end testRefusals()

	/**
	 * A failed save answers 500 and drops the grant.
	 *
	 * @return void
	 */
	public function testAFailedSaveDropsTheGrant(): void {
		$this->store->failingSaves = ['decision'];

		$this->assertSame(500, $this->service()->report(decisionId: 'dec-1', userId: 'griffier')['status']);
		$this->assertFalse($this->grant->isGranted(decisionId: 'dec-1'));
	}//end testAFailedSaveDropsTheGrant()

	/**
	 * Only an urgent decision without a report date is due.
	 *
	 * @return void
	 */
	public function testWhichDecisionsAreDue(): void {
		$time   = $this->createMock(ITimeFactory::class);
		$report = new RatifyingBodyReport(timeFactory: $time, grant: new UrgencyWriteGrant());

		$this->assertTrue($report->isDue(decision: ['isUrgent' => true]));
		$this->assertTrue($report->isDue(decision: ['isUrgent' => true, 'reportedToRatifyingBodyAt' => '']));
		$this->assertFalse($report->isDue(decision: ['isUrgent' => true, 'reportedToRatifyingBodyAt' => '2026-10-06']));
		$this->assertFalse($report->isDue(decision: ['isUrgent' => false]));
		$this->assertFalse($report->isDue(decision: []));
	}//end testWhichDecisionsAreDue()
}//end class
