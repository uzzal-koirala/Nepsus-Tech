<?php

namespace App\Support;

use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Talks to Claude for the AI page. Sends the chat, runs the tools Claude asks for
 * (App\Support\AiTools) and loops until Claude has a final answer.
 */
class AiAssistant
{
    /** Stops a confused model from looping forever. */
    private const MAX_TOOL_ROUNDS = 12;

    /** The key from .env, or one pasted on the AI page (stored encrypted). */
    public static function apiKey(): ?string
    {
        if ($key = config('services.anthropic.key')) {
            return $key;
        }
        $stored = Setting::get('anthropic_api_key');

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function configured(): bool
    {
        return (bool) static::apiKey();
    }

    /**
     * @param  list<array{role: string, content: string}>  $history  Earlier turns plus the new user message last.
     * @return array{text: string, actions: list<array{icon: string, text: string, url: ?string}>}
     */
    public function reply(array $history, string $userName): array
    {
        $client = new Client(apiKey: static::apiKey());
        $tools = new AiTools;
        $messages = $history;

        try {
            for ($round = 0; ; $round++) {
                $response = $client->beta->messages->create(
                    model: config('services.anthropic.model'),
                    maxTokens: 16000,
                    system: [['type' => 'text', 'text' => $this->systemPrompt($userName)]],
                    messages: $messages,
                    tools: AiTools::definitions(),
                    outputConfig: ['effort' => 'medium'],
                    // Opt-in: if a request is declined, the API retries it on a fallback model.
                    fallbacks: 'default',
                    betas: ['server-side-fallback-2026-07-01'],
                );

                if ($response->stopReason !== 'tool_use' || $round >= self::MAX_TOOL_ROUNDS) {
                    break;
                }

                $results = [];
                foreach ($response->content as $block) {
                    if (! $block instanceof BetaToolUseBlock) {
                        continue;
                    }
                    try {
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $tools->run($block->name, $block->input)];
                    } catch (ValidationException $e) {
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'isError' => true, 'content' => implode(' ', $e->validator->errors()->all())];
                    } catch (\Throwable $e) {
                        Log::error('AI tool failed', ['tool' => $block->name, 'error' => $e->getMessage()]);
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'isError' => true, 'content' => 'That did not work because of an error in the app.'];
                    }
                }

                // All results for this turn go back in one user message.
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AuthenticationException) {
            return ['text' => __('The AI key is not valid. Check it in the AI settings.'), 'actions' => $tools->actions];
        } catch (RateLimitException) {
            return ['text' => __('The AI is busy right now. Please try again in a minute.'), 'actions' => $tools->actions];
        } catch (APIConnectionException) {
            return ['text' => __('Could not reach the AI. Check the internet connection and try again.'), 'actions' => $tools->actions];
        } catch (APIStatusException $e) {
            Log::error('AI request failed', ['status' => $e->getCode(), 'error' => $e->getMessage()]);

            return ['text' => __('The AI could not answer this time. Please try again.'), 'actions' => $tools->actions];
        }

        $text = collect($response->content)->filter(fn ($b) => $b->type === 'text')->map(fn ($b) => $b->text)->implode("\n\n");

        if ($response->stopReason === 'refusal') {
            $text = __('Sorry, I can’t help with that request.');
        } elseif ($response->stopReason === 'max_tokens') {
            $text .= "\n\n_".__('(The answer was cut short. Ask me to continue.)').'_';
        }

        return ['text' => trim($text) ?: __('Done.'), 'actions' => $tools->actions];
    }

    private function systemPrompt(string $userName): string
    {
        $today = today();
        $business = Setting::get('business_name') ?: 'the business';

        return <<<PROMPT
        You are the assistant inside the management app of {$business}, a digital agency in Nepal (social media, websites, video). You are talking with {$userName}, who runs the business with their team.

        Today is {$today->format('l')}, {$this->bs($today)} in the Nepali calendar ({$today->toDateString()}). People here usually give dates in the Nepali (Bikram Sambat) calendar; months are Baisakh, Jestha, Asar, Shrawan, Bhadra, Ashoj, Kartik, Mangsir, Poush, Magh, Falgun, Chaitra. When you send a Nepali date to a tool, set calendar to "bs". Show dates to the user in Nepali form, like "15 Kartik".

        You can look up and change the app's tasks, boxes and team assignments with your tools, and read business numbers. When asked to add or change something, do it with the tools rather than describing how. Look up ids with find_clients, find_people or list_boxes first; never guess an id. If a name matches more than one person or client, or something essential is missing, ask one short question instead of guessing. You cannot delete anything; tell the user to delete from the Tasks page.

        After you change something, confirm in one or two sentences what you did (task, client, people, date). Answer questions directly and briefly, using the tools for real numbers. Use simple English; the user may write in Nepali or Romanized Nepali, and you can reply the same way. Use Markdown lists or tables only when they make the answer easier to read.
        PROMPT;
    }

    private function bs($date): string
    {
        return NepaliDate::format($date);
    }
}
