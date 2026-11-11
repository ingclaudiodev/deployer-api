<?php

namespace App\Console\Commands;

use App\Exceptions\Ai\GenerativeModelException;
use App\Services\Ai\Contracts\GenerativeModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A conversation with the model from the terminal. The exchange lives only in
 * this process and is sent again with every message, so each turn is billed for
 * the whole conversation so far: --history caps how much of it travels.
 */
#[Signature('ai:chat
    {message? : Ask this single question and exit instead of opening a conversation}
    {--system= : Instruction that sets how the model behaves}
    {--history=20 : Messages of the conversation sent with each new one}')]
#[Description('Talk with the Vertex AI model from the terminal')]
class ChatWithAiCommand extends Command
{
    private const string DEFAULT_SYSTEM_INSTRUCTION = 'You are a helpful assistant for the developers of deployer.cl, a platform that deploys applications on Google Cloud Run. Answer briefly and in the language of the user.';

    private const array EXIT_WORDS = ['salir', 'exit', 'quit'];

    public function handle(GenerativeModel $model): int
    {
        $system = $this->option('system');
        $system = is_string($system) && $system !== '' ? $system : self::DEFAULT_SYSTEM_INSTRUCTION;
        $history = max(1, (int) $this->option('history'));
        $single = $this->argument('message');

        /** @var array<int, array{role: 'user'|'model', text: string}> $messages */
        $messages = [];

        if (! is_string($single)) {
            $this->components->info('Escribe tu mensaje. Para terminar: salir (o una línea vacía).');
        }

        while (true) {
            $text = is_string($single) ? $single : $this->ask('Tú');

            if (! is_string($text) || trim($text) === '' || in_array(strtolower(trim($text)), self::EXIT_WORDS, true)) {
                return self::SUCCESS;
            }

            $messages[] = ['role' => 'user', 'text' => $text];
            $messages = $this->recent($messages, $history);

            try {
                $answer = $model->converse($system, $messages);
            } catch (GenerativeModelException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            $messages[] = ['role' => 'model', 'text' => $answer->text];

            $this->newLine();
            $this->line($answer->text);
            $this->line("<fg=gray>{$answer->model} · {$answer->inputTokens} tokens de entrada, ".($answer->outputTokens + $answer->thinkingTokens).' de salida</>');
            $this->newLine();

            if (is_string($single)) {
                return self::SUCCESS;
            }
        }
    }

    /**
     * The last $limit messages, starting at a user one as the API requires.
     *
     * @param  array<int, array{role: 'user'|'model', text: string}>  $messages
     * @return array<int, array{role: 'user'|'model', text: string}>
     */
    private function recent(array $messages, int $limit): array
    {
        $messages = array_slice($messages, -$limit);

        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }
}
