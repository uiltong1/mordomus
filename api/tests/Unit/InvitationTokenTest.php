<?php

namespace Tests\Unit;

use Mordomus\Identity\Services\InvitationToken;
use Tests\TestCase;

/**
 * Token de convite: valor único na URL, apenas hash persiste.
 */
class InvitationTokenTest extends TestCase
{
    public function test_generate_returns_plain_and_matching_hash(): void
    {
        $token = InvitationToken::generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token['plain']);
        $this->assertSame(hash('sha256', $token['plain']), $token['hash']);
        $this->assertSame(64, strlen($token['hash']));
    }

    public function test_plain_tokens_are_unique(): void
    {
        $first = InvitationToken::generate();
        $second = InvitationToken::generate();

        $this->assertNotSame($first['plain'], $second['plain']);
        $this->assertNotSame($first['hash'], $second['hash']);
    }

    public function test_hash_is_stable_and_hex(): void
    {
        $hash = InvitationToken::hash('abc');

        $this->assertSame($hash, InvitationToken::hash('abc'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame($hash, InvitationToken::hash('abd'));
    }

    public function test_invitation_expires_in_seven_days(): void
    {
        $expiresAt = InvitationToken::expiresAt();

        $this->assertTrue($expiresAt->isFuture());
        $this->assertSame(now()->addDays(7)->format('Y-m-d H:i'), $expiresAt->format('Y-m-d H:i'));
    }
}
