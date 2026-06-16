<?php

/**
 * Unit tests for MemberImportService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MemberImportService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MemberImportService (group listing, group-member mapping,
 * email-to-account matching incl. validation and the row cap).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class MemberImportServiceTest extends TestCase
{

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock user manager.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

    /**
     * Service under test.
     *
     * @var MemberImportService
     */
    private MemberImportService $service;

    /**
     * Set up mocks and the service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager  = $this->createMock(IUserManager::class);
        $this->service      = new MemberImportService(
            $this->groupManager,
            $this->userManager
        );
    }//end setUp()

    /**
     * Build a mock IUser.
     *
     * @param string      $uid         The user id.
     * @param string      $displayName The display name.
     * @param string|null $email       The email address.
     *
     * @return IUser&MockObject
     */
    private function mockUser(string $uid, string $displayName, ?string $email): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($displayName);
        $user->method('getEMailAddress')->willReturn($email);
        return $user;
    }//end mockUser()

    /**
     * listGroups maps gid, display name and member count.
     *
     * @return void
     */
    public function testListGroupsMapsGroups(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('bestuur');
        $group->method('getDisplayName')->willReturn('Bestuur');
        $group->method('count')->willReturn(5);

        $this->groupManager->method('search')->with('')->willReturn([$group]);

        $result = $this->service->listGroups();

        self::assertSame(
            [
                [
                    'id'          => 'bestuur',
                    'displayName' => 'Bestuur',
                    'userCount'   => 5,
                ],
            ],
            $result
        );
    }//end testListGroupsMapsGroups()

    /**
     * getGroupMembers maps uid, display name and email; null email becomes ''.
     *
     * @return void
     */
    public function testGetGroupMembersMapsUsers(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn(
            [
                $this->mockUser('anna', 'Anna de Vries', 'anna@example.test'),
                $this->mockUser('bram', 'Bram Bakker', null),
            ]
        );
        $this->groupManager->method('get')->with('bestuur')->willReturn($group);

        $result = $this->service->getGroupMembers('bestuur');

        self::assertSame(
            [
                [
                    'uid'         => 'anna',
                    'displayName' => 'Anna de Vries',
                    'email'       => 'anna@example.test',
                ],
                [
                    'uid'         => 'bram',
                    'displayName' => 'Bram Bakker',
                    'email'       => '',
                ],
            ],
            $result
        );
    }//end testGetGroupMembersMapsUsers()

    /**
     * getGroupMembers returns null for an unknown group.
     *
     * @return void
     */
    public function testGetGroupMembersUnknownGroupReturnsNull(): void
    {
        $this->groupManager->method('get')->with('nope')->willReturn(null);
        self::assertNull($this->service->getGroupMembers('nope'));
    }//end testGetGroupMembersUnknownGroupReturnsNull()

    /**
     * matchEmails resolves accounts, normalises case, and returns null for
     * unmatched or malformed emails.
     *
     * @return void
     */
    public function testMatchEmailsMatchesAndValidates(): void
    {
        $anna = $this->mockUser('anna', 'Anna de Vries', 'anna@example.test');
        $this->userManager->method('getByEmail')->willReturnCallback(
            function (string $email) use ($anna): array {
                if ($email === 'anna@example.test') {
                    return [$anna];
                }

                return [];
            }
        );

        $result = $this->service->matchEmails(
            [
                'Anna@Example.Test',
                'unknown@example.test',
                'not-an-email',
                '',
                42,
            ]
        );

        self::assertSame(
            ['uid' => 'anna', 'displayName' => 'Anna de Vries'],
            $result['Anna@Example.Test']
        );
        self::assertNull($result['unknown@example.test']);
        self::assertNull($result['not-an-email']);
        self::assertNull($result['']);
        // Non-string entries are dropped entirely.
        self::assertArrayNotHasKey(42, $result);
    }//end testMatchEmailsMatchesAndValidates()

    /**
     * matchEmails enforces the MAX_MATCH_ROWS server-side cap.
     *
     * @return void
     */
    public function testMatchEmailsEnforcesRowCap(): void
    {
        $emails = array_map(
            static fn (int $i): string => "user{$i}@example.test",
            range(1, (MemberImportService::MAX_MATCH_ROWS + 1))
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->matchEmails($emails);
    }//end testMatchEmailsEnforcesRowCap()

    /**
     * matchEmails at exactly the cap is accepted.
     *
     * @return void
     */
    public function testMatchEmailsAtCapIsAccepted(): void
    {
        $this->userManager->method('getByEmail')->willReturn([]);
        $emails = array_map(
            static fn (int $i): string => "user{$i}@example.test",
            range(1, MemberImportService::MAX_MATCH_ROWS)
        );

        $result = $this->service->matchEmails($emails);
        self::assertCount(MemberImportService::MAX_MATCH_ROWS, $result);
    }//end testMatchEmailsAtCapIsAccepted()
}//end class
