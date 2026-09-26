<?php

namespace Tests\Unit;

use Mordomus\Identity\Services\InvitationTokenService;
use Tests\TestCase;

/**
 * Token de convite: valor único na URL, apenas hash persiste.
 */
class InvitationTokenTest extends TestCase
{
    public function test_generate_returns_plain_and_matching_hash(): void
    {
        $token = InvitationTokenService::generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token['plain']);
        $this->assertSame(hash('sha256', $token['plain']), $token['hash']);
        $this->assertSame(64, strlen($token['hash']));
    }

    public function test_plain_tokens_are_unique(): void
    {
        $first = InvitationTokenService::generate();
        $second = InvitationTokenService::generate();

        $this->assertNotSame($first['plain'], $second['plain']);
        $this->assertNotSame($first['hash'], $second['hash']);
    }

    public function test_hash_is_stable_and_hex(): void
    {
        $hash = InvitationTokenService::hash('abc');

        $this->assertSame($hash, InvitationTokenService::hash('abc'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame($hash, InvitationTokenService::hash('abd'));
    }

    public function test_invitation_expires_in_seven_days(): void
    {
        $expiresAt = InvitationTokenService::expiresAt();

        $this->assertTrue($expiresAt->isFuture());
        $this->assertSame(now()->addDays(7)->format('Y-m-d H:i'), $expiresAt->format('Y-m-d H:i'));
    }
}
