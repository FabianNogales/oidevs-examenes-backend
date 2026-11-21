<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Auth\LoginUserResolver;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

// Verify the missing-user path performs the password check; no timing claims or DB needed.
class PasswordVerificationSecurityTest extends TestCase
{
    public function test_nonexistent_user_still_requires_a_valid_dummy_hash_verification(): void
    {
        $resolver = Mockery::mock(LoginUserResolver::class)->makePartial();
        $resolver->shouldReceive('findUser')->once()->with('missing@umss.edu.bo')->andReturnNull();
        Hash::shouldReceive('needsRehash')->once()->andReturnFalse();
        Hash::shouldReceive('check')->once()->with('IncorrectPassword1', Mockery::on(
            fn ($hash) => password_get_info($hash)['algoName'] === 'bcrypt',
        ))->andReturnFalse();
        $this->assertNull($resolver->resolve('missing@umss.edu.bo', 'IncorrectPassword1'));
    }

    public function test_existing_user_with_wrong_password_performs_one_equivalent_check(): void
    {
        $user = (new User)->setRawAttributes(['password' => 'existing-stored-hash', 'status' => 'ACTIVE']);
        $resolver = Mockery::mock(LoginUserResolver::class)->makePartial();
        $resolver->shouldReceive('findUser')->once()->andReturn($user);
        Hash::shouldReceive('needsRehash')->once()->andReturnFalse();
        Hash::shouldReceive('check')->once()->with('IncorrectPassword1', 'existing-stored-hash')->andReturnFalse();
        $this->assertNull($resolver->resolve('existing@umss.edu.bo', 'IncorrectPassword1'));
    }

    public function test_driver_or_cost_adaptation_is_prepared_for_existing_and_missing_users(): void
    {
        $user = (new User)->setRawAttributes(['password' => 'existing-stored-hash', 'status' => 'ACTIVE']);
        $resolver = Mockery::mock(LoginUserResolver::class)->makePartial();
        $resolver->shouldReceive('findUser')->with('missing')->andReturnNull();
        $resolver->shouldReceive('findUser')->with('existing')->andReturn($user);
        Hash::shouldReceive('needsRehash')->twice()->andReturnTrue();
        Hash::shouldReceive('make')->twice()->with(Mockery::on(fn ($value) => strlen($value) === 64))->andReturn('configured-dummy-hash');
        Hash::shouldReceive('check')->once()->with('IncorrectPassword1', 'configured-dummy-hash')->andReturnFalse();
        Hash::shouldReceive('check')->once()->with('IncorrectPassword1', 'existing-stored-hash')->andReturnFalse();
        $this->assertNull($resolver->resolve('missing', 'IncorrectPassword1'));
        $this->assertNull($resolver->resolve('existing', 'IncorrectPassword1'));
    }

    public function test_dummy_hash_match_cannot_authenticate_a_nonexistent_account(): void
    {
        $resolver = Mockery::mock(LoginUserResolver::class)->makePartial();
        $resolver->shouldReceive('findUser')->once()->andReturnNull();
        Hash::shouldReceive('needsRehash')->once()->andReturnFalse();
        Hash::shouldReceive('check')->once()->andReturnTrue();
        $this->assertNull($resolver->resolve('missing', 'synthetic dummy match'));
    }
}
