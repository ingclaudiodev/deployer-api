<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

test('a known email gets a reset link that opens the frontend', function (): void {
    Notification::fake();
    config()->set('app.frontend_url', 'https://app.deployer.test/');
    config()->set('auth.passwords.users.frontend_path', '/restablecer');
    $user = User::factory()->create(['email' => 'dev@acme.com']);

    $this->postJson(route('auth.forgot-password'), ['email' => 'Dev@Acme.com'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $url = $mail->actionUrl;

        expect($mail->subject)->toContain('Restablece tu contraseña');

        return str_starts_with($url, 'https://app.deployer.test/restablecer?token=')
            && str_contains($url, 'email=dev%40acme.com');
    });
});

test('an unknown email gets the same answer and no notification', function (): void {
    Notification::fake();

    $this->postJson(route('auth.forgot-password'), ['email' => 'ghost@acme.com'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertNothingSent();
});

test('the email is validated', function (): void {
    $this->postJson(route('auth.forgot-password'), ['email' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});
