<?php

use App\Exceptions\Ai\GenerativeModelException;

test('it answers a single question and exits', function (): void {
    $model = fakeGenerativeModel('Cloud Run escala a cero.');

    $this->artisan('ai:chat', ['message' => '¿Qué es Cloud Run?'])
        ->expectsOutputToContain('Cloud Run escala a cero.')
        ->assertSuccessful();

    expect($model->conversations)->toBe([[['role' => 'user', 'text' => '¿Qué es Cloud Run?']]]);
});

test('each new message carries the conversation so far', function (): void {
    $model = fakeGenerativeModel('Respuesta.');

    $this->artisan('ai:chat')
        ->expectsQuestion('Tú', 'Hola')
        ->expectsQuestion('Tú', '¿Y después?')
        ->expectsQuestion('Tú', 'salir')
        ->assertSuccessful();

    expect($model->conversations)->toHaveCount(2)
        ->and($model->conversations[1])->toBe([
            ['role' => 'user', 'text' => 'Hola'],
            ['role' => 'model', 'text' => 'Respuesta.'],
            ['role' => 'user', 'text' => '¿Y después?'],
        ]);
});

test('it sends only the most recent messages, starting at one of the user', function (): void {
    $model = fakeGenerativeModel('Respuesta.');

    $this->artisan('ai:chat', ['--history' => 2])
        ->expectsQuestion('Tú', 'Uno')
        ->expectsQuestion('Tú', 'Dos')
        ->expectsQuestion('Tú', '')
        ->assertSuccessful();

    expect($model->conversations[1])->toBe([['role' => 'user', 'text' => 'Dos']]);
});

test('it uses the system instruction given', function (): void {
    $model = fakeGenerativeModel('Arr.');

    $this->artisan('ai:chat', ['message' => 'Hola', '--system' => 'Habla como pirata.'])->assertSuccessful();

    expect($model->prompt)->toBe('Habla como pirata.');
});

test('it reports a failure of the model instead of crashing', function (): void {
    fakeGenerativeModel(GenerativeModelException::notConfigured('VERTEX_AI_PROJECT'));

    $this->artisan('ai:chat', ['message' => 'Hola'])
        ->expectsOutputToContain('VERTEX_AI_PROJECT')
        ->assertFailed();
});
