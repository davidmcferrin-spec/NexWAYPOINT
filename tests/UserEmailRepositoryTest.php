<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Users\UserRepository;

final class UserEmailRepositoryTest extends NexWaypointTestCase
{
    public function testFindByEmailMatchesAlias(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create(
            'dave',
            'dave@work.example',
            'test-password-12',
            'Dave',
            'manager',
            null,
        );

        $repo->addEmail((int) $user->id, 'dave.personal@gmail.example', 'Personal', (int) $user->id);

        $byPrimary = $repo->findByEmail('dave@work.example');
        $byAlias = $repo->findByEmail('DAVE.PERSONAL@gmail.example');

        self::assertNotNull($byPrimary);
        self::assertNotNull($byAlias);
        self::assertSame($user->id, $byPrimary->id);
        self::assertSame($user->id, $byAlias->id);
        self::assertCount(2, $repo->emailsForUser((int) $user->id));
    }

    public function testCannotStealAnotherUsersEmail(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $a = $repo->create('alice', 'alice@example.com', 'test-password-12', 'Alice', 'manager', null);
        $b = $repo->create('bob', 'bob@example.com', 'test-password-12', 'Bob', 'subordinate', $a->id);

        $this->expectException(\InvalidArgumentException::class);
        $repo->addEmail((int) $b->id, 'alice@example.com', null, (int) $b->id);
    }

    public function testCannotRemovePrimaryEmail(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create('dave', 'dave@example.com', 'test-password-12', 'Dave', 'manager', null);
        $emails = $repo->emailsForUser((int) $user->id);
        self::assertTrue($emails[0]['is_primary']);

        $this->expectException(\InvalidArgumentException::class);
        $repo->removeEmail((int) $user->id, $emails[0]['id'], (int) $user->id);
    }
}
