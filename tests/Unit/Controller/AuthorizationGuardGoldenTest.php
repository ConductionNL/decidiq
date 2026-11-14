<?php

/**
 * Golden tests for the caller resolution and the forbidden mapping the
 * guarded controllers share.
 *
 * Pins, for each controller that carries them, who the authorization guard is
 * asked about (nobody signed in, an administrator, a member) and which service
 * results become a 403 and which go on to the ordinary mapping.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ConflictOfInterestController;
use OCA\Decidiq\Controller\IntegrationController;
use OCA\Decidiq\Controller\ProxyVoteController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins the guard's caller and the 403 mapping in every guarded controller.
 */
final class AuthorizationGuardGoldenTest extends TestCase {
	/**
	 * A controller with the given session and admins, every other dependency a mock.
	 *
	 * @param class-string $class  The controller.
	 * @param string|null  $uid    The signed-in user, null for nobody.
	 * @param list<string> $admins The administrators.
	 *
	 * @return object The controller.
	 */
	private function controller(string $class, ?string $uid, array $admins = []): object {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $who): bool => in_array($who, $admins, true));

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
			$args[] = match ($name) {
				IUserSession::class => $session,
				IGroupManager::class => $groups,
				default => $this->createMock($name),
			};
		}

		return new $class(...$args);
	}//end controller()

	/**
	 * Call one private method.
	 *
	 * @param object      $controller The controller.
	 * @param string      $method     The method.
	 * @param list<mixed> $args       Its arguments.
	 *
	 * @return mixed The answer.
	 */
	private function call(object $controller, string $method, array $args = []): mixed {
		$reflection = new \ReflectionMethod($controller, $method);
		return $reflection->invokeArgs($controller, $args);
	}//end call()

	/**
	 * The guarded controllers.
	 *
	 * @return array<string, array{0: class-string}> The cases.
	 */
	public static function controllers(): array {
		return [
			'conflicts' => [ConflictOfInterestController::class],
			'proxy votes' => [ProxyVoteController::class],
			'integration' => [IntegrationController::class],
		];
	}//end controllers()

	/**
	 * Nobody and an administrator are both asked about as null; a member by uid.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider controllers
	 */
	public function testTheGuardIsAskedAboutTheCaller(string $class): void {
		$this->assertNull($this->call($this->controller($class, null), 'resolveCallerUid'));
		$this->assertNull($this->call($this->controller($class, 'root', ['root']), 'resolveCallerUid'));
		$this->assertSame('alice', $this->call($this->controller($class, 'alice', ['root']), 'resolveCallerUid'));
	}//end testTheGuardIsAskedAboutTheCaller()

	/**
	 * A failure whose message starts with Forbidden: (any case) is a bare 403; anything else maps as usual.
	 *
	 * @return void
	 */
	public function testForbiddenFailuresBecomeA403(): void {
		foreach ([ConflictOfInterestController::class, ProxyVoteController::class] as $class) {
			$controller = $this->controller($class, 'alice');
			$answers = [];
			foreach ([
				['success' => false, 'message' => 'Forbidden: not yours'],
				['success' => false, 'message' => 'forbidden: lower case'],
				['success' => false, 'message' => 'Not Forbidden: later'],
				['success' => false],
				['message' => 'Forbidden: no success key'],
				['success' => true, 'message' => 'Forbidden: but succeeded', 'item' => ['id' => 1]],
			] as $result) {
				/** @var JSONResponse $response */
				$response = $this->call($controller, 'respondFromAuthorizedResult', [$result, 'item', 201]);
				$answers[] = [$response->getStatus(), $response->getData()];
			}

			$this->assertSame(
				[
					[403, ['message' => 'Forbidden.']],
					[403, ['message' => 'Forbidden.']],
					[422, ['message' => 'Not Forbidden: later']],
					[422, ['message' => 'Operation failed.']],
					[403, ['message' => 'Forbidden.']],
					[201, ['id' => 1]],
				],
				$answers,
				$class
			);
		}
	}//end testForbiddenFailuresBecomeA403()
}//end class
