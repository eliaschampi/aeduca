<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EmployeeAuthenticationTest extends TestCase
{
    public function test_active_employee_can_log_in(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();

        $response = $this->post('/login', [
            'login' => strtoupper($account->login),
            'password' => $this->validPassword,
        ]);

        $response
            ->assertRedirect(route('branches.index'))
            ->assertSessionHas('current_branch_code', $branch->code);
        $this->assertAuthenticatedAs($account);
        $this->assertNotNull($account->fresh()->last_login_at);
        $this->assertDatabaseHas('sessions', ['user_id' => $account->code]);
    }

    public function test_account_model_hashes_plain_passwords(): void
    {
        $account = $this->createEmployeeAccount();

        $this->assertNotSame($this->validPassword, $account->password);
        $this->assertTrue(Hash::check($this->validPassword, $account->password));
    }

    public function test_successful_login_upgrades_an_outdated_password_hash(): void
    {
        $outdatedHash = Hash::make($this->validPassword, ['rounds' => 4]);
        $account = $this->createEmployeeAccount([
            'password' => $outdatedHash,
        ]);
        Hash::getFacadeRoot()->setRounds(5);

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])->assertRedirect(route('branches.index'));

        $upgradedHash = $account->fresh()->password;

        $this->assertNotSame($outdatedHash, $upgradedHash);
        $this->assertFalse(Hash::needsRehash($upgradedHash));
    }

    public function test_invalid_credentials_are_rejected_with_a_generic_message(): void
    {
        $account = $this->createEmployeeAccount();

        $response = $this->post('/login', [
            'login' => $account->login,
            'password' => 'incorrect-'.$this->validPassword,
        ]);

        $response->assertSessionHasErrors([
            'login' => 'No pudimos iniciar sesión con esos datos.',
        ]);
        $this->assertGuest();
    }

    public function test_unknown_login_is_rejected_with_the_same_generic_message(): void
    {
        $response = $this->post('/login', [
            'login' => 'unknown-employee',
            'password' => $this->validPassword,
        ]);

        $response->assertSessionHasErrors([
            'login' => 'No pudimos iniciar sesión con esos datos.',
        ]);
        $this->assertGuest();
    }

    public function test_unicode_login_cannot_overflow_the_database_rate_limit_key(): void
    {
        config()->set('cache.default', 'database');
        app()->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        $this->post('/login', [
            'login' => str_repeat('㌀', 100),
            'password' => $this->validPassword,
        ])->assertSessionHasErrors([
            'login' => 'No pudimos iniciar sesión con esos datos.',
        ]);

        $keys = DB::table('cache')->pluck('key');
        $this->assertNotEmpty($keys);
        $this->assertTrue(
            $keys->every(fn (string $key): bool => strlen($key) <= 255),
        );
        $this->assertGuest();
    }

    public function test_inactive_account_is_rejected(): void
    {
        $account = $this->createEmployeeAccount(['is_active' => false]);

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_inactive_employee_is_rejected(): void
    {
        $account = $this->createEmployeeAccount(
            userAttributes: ['is_active' => false],
        );

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_inactive_role_is_rejected(): void
    {
        $account = $this->createEmployeeAccount(
            roleAttributes: ['is_active' => false],
        );

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_employee_without_an_active_branch_is_rejected(): void
    {
        $account = $this->createEmployeeAccount(branchCount: 0);

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])->assertSessionHasErrors([
            'login' => 'Tu cuenta no tiene una sede activa asignada. Contacta a un administrador.',
        ]);

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $account = $this->createEmployeeAccount();

        foreach (range(1, 5) as $_attempt) {
            $this->post('/login', [
                'login' => $account->login,
                'password' => 'incorrect-'.$this->validPassword,
            ])->assertSessionHasErrors('login');
        }

        $response = $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString(
            'Demasiados intentos',
            $response->getSession()->get('errors')->first('login'),
        );
        $this->assertGuest();
    }

    public function test_login_regenerates_the_session_identifier(): void
    {
        $account = $this->createEmployeeAccount();
        $this->withSession(['session_marker' => true]);
        $previousSessionId = $this->app['session']->driver()->getId();

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])->assertRedirect(route('branches.index'));

        $this->assertNotSame(
            $previousSessionId,
            $this->app['session']->driver()->getId(),
        );
    }

    public function test_logout_invalidates_the_session_and_regenerates_the_csrf_token(): void
    {
        $account = $this->createEmployeeAccount();
        $oldToken = 'old-session-token';

        $response = $this
            ->actingAs($account)
            ->withSession([
                '_token' => $oldToken,
                'session_marker' => true,
            ])
            ->delete('/logout');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('session_marker')
            ->assertSessionHas('_token', fn ($token) => $token !== $oldToken);
        $this->assertGuest();
    }

    public function test_inactive_employee_is_logged_out_during_an_existing_session(): void
    {
        $account = $this->createEmployeeAccount();
        $authenticatedSessionId = $this->loginAndContinueSession($account);
        $this->assertDatabaseHas('sessions', [
            'id' => $authenticatedSessionId,
            'user_id' => $account->code,
        ]);

        $account->user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertDatabaseMissing('sessions', ['id' => $authenticatedSessionId]);
        $this->assertGuest();
    }

    public function test_inactive_account_is_logged_out_during_an_existing_session(): void
    {
        $account = $this->createEmployeeAccount();
        $this->loginAndContinueSession($account);

        $account->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_inactive_role_is_logged_out_during_an_existing_session(): void
    {
        $account = $this->createEmployeeAccount();
        $this->loginAndContinueSession($account);

        $account->user->employeeRole->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }
}
