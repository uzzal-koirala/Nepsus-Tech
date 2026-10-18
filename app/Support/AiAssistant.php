<?php

namespace App\Support;

use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Talks to the AI for the AI page: OpenRouter when its key is set (free models),
 * otherwise Claude through the Anthropic SDK. Runs the tools the model asks for
 * (App\Support\AiTools) and loops until there is a final answer.
 */
class AiAssistant
{
    /** Stops a confused model from looping forever. */
    private const MAX_TOOL_ROUNDS = 12;

    /** A key from .env, or one pasted on the AI page (stored encrypted). */
    public static function key(string $provider): ?string
    {
        if ($key = config("services.{$provider}.key")) {
            return $key;
        }
        $stored = Setting::get("{$provider}_api_key");

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** 'openrouter', 'anthropic' or null when nothing is connected. */
    public static function provider(): ?string
    {
        return static::key('openrouter') ? 'openrouter' : (static::key('anthropic') ? 'anthropic' : null);
    }

    public static function configured(): bool
    {
        return static::provider() !== null;
    }

    /** The model asked for first, e.g. "nvidia/nemotron-3-super-120b-a12b:free". */
    public static function model(): ?string
    {
        $provider = static::provider();

        return $provider ? config("services.{$provider}.model") : null;
    }

    /** Short name for the screen: "nemotron-3-super-120b-a12b (free)". */
    public static function label(?string $model): string
    {
        return $model ? str_replace(':free', ' (free)', Str::afterLast($model, '/')) : '';
    }

    /**
     * @param  list<array{role: string, content: string}>  $history  Earlier turns plus the new user message last.
     * @return array{text: string, actions: list<array{icon: string, text: string, url: ?string}>, model: ?string}
     */
    public function reply(array $history, string $userName): array
    {
        $tools = new AiTools;

        return static::provider() === 'openrouter'
            ? $this->openRouter($history, $userName, $tools)
            : $this->anthropic($history, $userName, $tools);
    }

    /** Runs one tool call. Bad input goes back to the model as an error it can fix. */
    private function runTool(AiTools $tools, string $name, array $input): array
    {
        try {
            return [$tools->run($name, $input), false];
        } catch (ValidationException $e) {
            return [implode(' ', $e->validator->errors()->all()), true];
        } catch (\Throwable $e) {
            Log::error('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return ['That did not work because of an error in the app.', true];
        }
    }

    private function openRouter(array $history, string $userName, AiTools $tools): array
    {
        $definitions = array_map(fn ($t) => ['type' => 'function', 'function' => [
            'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['inputSchema'],
        ]], AiTools::definitions());
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($userName)], ...$history];
        $model = config('services.openrouter.model');

        for ($round = 0; ; $round++) {
            try {
                $response = Http::withToken(static::key('openrouter'))
                    ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => Setting::get('business_name') ?: config('app.name')])
                    ->timeout(120)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        // OpenRouter tries the next model when one is busy or down.
                        'models' => array_values(array_unique([$model, ...config('services.openrouter.fallbacks', [])])),
                        'messages' => $messages,
                        'tools' => $definitions,
                        'tool_choice' => 'auto',
                    ]);
            } catch (ConnectionException) {
                return ['text' => __('Could not reach the AI. Check the internet connection and try again.'), 'actions' => $tools->actions, 'model' => null];
            }

            if ($response->failed() || ! $response->json('choices.0')) {
                Log::warning('OpenRouter request failed', ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)]);
                $text = match ($response->status()) {
                    401 => __('The OpenRouter key is not valid. Check it in the .env file or on this page.'),
                    402 => __('The OpenRouter account has no credit left for this model.'),
                    429 => __('The free AI models are busy right now. Please try again in a minute.'),
                    default => __('The AI could not answer this time. Please try again.'),
                };

                return ['text' => $text, 'actions' => $tools->actions, 'model' => null];
            }

            $message = $response->json('choices.0.message');
            $used = $response->json('model') ?: $model;
            $calls = $message['tool_calls'] ?? [];

            if (! $calls || $round >= self::MAX_TOOL_ROUNDS) {
                return ['text' => trim((string) ($message['content'] ?? '')) ?: __('Done.'), 'actions' => $tools->actions, 'model' => $used];
            }

            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $call) {
                $input = json_decode($call['function']['arguments'] ?? '', true);
                [$content] = is_array($input)
                    ? $this->runTool($tools, $call['function']['name'] ?? '', $input)
                    : ['The arguments were not valid JSON. Try again.'];
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $content];
            }
        }
    }

    private function anthropic(array $history, string $userName, AiTools $tools): array
    {
        $client = new Client(apiKey: static::key('anthropic'));
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
                    if ($block instanceof BetaToolUseBlock) {
                        [$content, $isError] = $this->runTool($tools, $block->name, $block->input);
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $content] + ($isError ? ['isError' => true] : []);
                    }
                }

                // All results for this turn go back in one user message.
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AuthenticationException) {
            return ['text' => __('The AI key is not valid. Check it in the AI settings.'), 'actions' => $tools->actions, 'model' => null];
        } catch (RateLimitException) {
            return ['text' => __('The AI is busy right now. Please try again in a minute.'), 'actions' => $tools->actions, 'model' => null];
        } catch (APIConnectionException) {
            return ['text' => __('Could not reach the AI. Check the internet connection and try again.'), 'actions' => $tools->actions, 'model' => null];
        } catch (APIStatusException $e) {
            Log::error('AI request failed', ['status' => $e->getCode(), 'error' => $e->getMessage()]);

            return ['text' => __('The AI could not answer this time. Please try again.'), 'actions' => $tools->actions, 'model' => null];
        }

        $text = collect($response->content)->filter(fn ($b) => $b->type === 'text')->map(fn ($b) => $b->text)->implode("\n\n");

        if ($response->stopReason === 'refusal') {
            $text = __('Sorry, I can’t help with that request.');
        } elseif ($response->stopReason === 'max_tokens') {
            $text .= "\n\n_".__('(The answer was cut short. Ask me to continue.)').'_';
        }

        return ['text' => trim($text) ?: __('Done.'), 'actions' => $tools->actions, 'model' => $response->model];
    }

    private function systemPrompt(string $userName): string
    {
        $today = today();
        $business = Setting::get('business_name') ?: 'the business';
        $bsToday = NepaliDate::format($today);
        [$monthStart, $monthEnd] = NepaliDate::monthRange($today);
        $weekStart = $today->copy()->subDays($today->dayOfWeek); // weeks start on Sunday here
        $period = NepaliDate::period($today);
        $dates = 'This week runs '.NepaliDate::format($weekStart).' to '.NepaliDate::format($weekStart->copy()->addDays(6))
            .'. This Nepali month is '.$period.' ('.NepaliDate::format($monthStart).' to '.NepaliDate::format($monthEnd).').';

        return <<<PROMPT
        You are the assistant inside the management app of {$business}, a digital agency in Nepal (social media, websites, video). You are talking with {$userName}, who runs the business with their team.

        Today is {$today->format('l')}, {$bsToday} in the Nepali calendar ({$today->toDateString()}). People here usually give dates in the Nepali (Bikram Sambat) calendar; months are Baisakh, Jestha, Asar, Shrawan, Bhadra, Ashoj, Kartik, Mangsir, Poush, Magh, Falgun, Chaitra. {$dates} When you send a Nepali date to a tool, set calendar to "bs". Show dates to the user in Nepali form, like "15 Kartik".

        You can look up and change the app's tasks, boxes and team assignments with your tools, read the calendar of tasks by day, and read business numbers. When asked to add or change something, do it with the tools rather than describing how. Look up ids with find_clients, find_people or list_boxes first; never guess an id. If a name matches more than one person or client, or something essential is missing, ask one short question instead of guessing. You cannot delete anything; tell the user to delete from the Tasks page.

        After you change something, confirm in one or two sentences what you did (task, client, people, date). Answer questions directly and briefly, using the tools for real numbers. Use simple English; the user may write in Nepali or Romanized Nepali, and you can reply the same way. Use Markdown lists or tables only when they make the answer easier to read.
        PROMPT;
    }
}
