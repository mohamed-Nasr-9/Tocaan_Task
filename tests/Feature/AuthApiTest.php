<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('registers a user and returns a JWT bearer token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Alex Example',
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertCreated()
        ->assertJsonPath('token_type', 'bearer')
        ->assertJsonPath('user.email', 'alex@example.com');
    expect($response->json('access_token'))->toBeString()->not->toBeEmpty();
    $this->assertDatabaseHas('users', ['email' => 'alex@example.com']);
});

it('returns 401 for incorrect login credentials', function () {
    User::factory()->create(['email' => 'alex@example.com', 'password' => 'correct-horse-battery']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'alex@example.com',
        'password' => 'incorrect-password',
    ])->assertUnauthorized()->assertJsonPath('message', 'The provided credentials are incorrect.');
});

it('logs in with valid credentials and returns a usable JWT', function () {
    User::factory()->create(['email' => 'alex@example.com', 'password' => 'correct-horse-battery']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk()->assertJsonPath('token_type', 'bearer');
    $this->withToken($response->json('access_token'))->getJson('/api/v1/auth/me')->assertOk();
});

it('returns 401 when a protected endpoint has no valid JWT', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();

    $this->withToken('not-a-jwt')->getJson('/api/v1/orders')->assertUnauthorized();
});

it('returns a JSON 401 for API requests without an Accept header', function () {
    $this->get('/api/v1/orders')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});

it('returns the authenticated user and invalidates a logged out token', function () {
    $user = User::factory()->create();
    $token = apiToken($user);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});
