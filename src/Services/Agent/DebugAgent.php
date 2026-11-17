<?php

namespace ChristianHnl\AiDebugger\Services\Agent;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaThinkingBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use ChristianHnl\AiDebugger\Enums\StepType;
use ChristianHnl\AiDebugger\Exceptions\SessionCancelledException;
use ChristianHnl\AiDebugger\Models\DebugSession;
use Exception;
use Illuminate\Support\Str;

/**
 * The agent loop: sends the conversation to Claude, executes the requested tools on the server
 * and repeats until Claude calls the finish tool.
 */
class DebugAgent
{
    // Models that support server-side refusal fallbacks
    protected const FALLBACK_MODELS = ['claude-fable-5-2', 'claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    protected const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    protected ?Client $client = null;

    public function __construct(protected DebugSession $session, protected ToolRegistry $tools) {}

    public static function isConfigured(): bool
    {
        return filled(config('ai-debugger.anthropic.api_key'));
    }

    public static function model(): string
    {
        return (string) (config('ai-debugger.anthropic.model') ?: 'claude-opus-5-5');
    }

    protected function client(): Client
    {
        if (!self::isConfigured()) {
            throw new Exception(trans('ai-debugger::strings.notifications.no_api_key'));
        }

        return $this->client ??= new Client(
            apiKey: (string) config('ai-debugger.anthropic.api_key'),
            baseUrl: config('ai-debugger.anthropic.base_url') ?: null,
            requestOptions: [
                'timeout' => (float) max(60, (int) config('ai-debugger.anthropic.timeout', 900)),
                'maxRetries' => 4,
            ],
        );
    }

    /**
     * Runs the loop. Returns the report passed to the finish tool, or null if the agent stopped without one.
     *
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    public function run(): ?array
    {
        $model = self::model();
        $maxIterations = max(1, (int) config('ai-debugger.max_iterations', 80));
        $definitions = $this->tools->definitions();
        $system = [['type' => 'text', 'text' => SystemPrompt::system()]];

        $messages = [
            ['role' => 'user', 'content' => SystemPrompt::firstMessage($this->session)],
        ];

        $this->session->update(['model' => $model]);

        $nudges = 0;

        for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
            $this->assertNotCancelled();

            $response = $this->request($model, $system, $messages, $definitions);

            $this->trackUsage($response, $iteration);
            $this->logContent($response);

            // Always send the content back unchanged (thinking blocks, fallback blocks, ...)
            $messages[] = ['role' => 'assistant', 'content' => $response->content];

            $toolUses = array_values(array_filter($response->content, fn ($block) => $block instanceof BetaToolUseBlock));

            switch ($response->stopReason) {
                case 'tool_use':
                    $results = [];
                    foreach ($toolUses as $toolUse) {
                        $this->assertNotCancelled();
                        $results[] = $this->runTool($toolUse);
                    }

                    if ($this->tools->finish !== null) {
                        return $this->tools->finish;
                    }

                    $messages[] = ['role' => 'user', 'content' => $results];

                    break;

                case 'max_tokens':
                    // Tool calls of a cut off response can not be trusted, answer each with an error
                    $content = array_map(fn (BetaToolUseBlock $toolUse) => [
                        'type' => 'tool_result',
                        'toolUseID' => $toolUse->id,
                        'content' => 'Error: your response hit the output limit before this tool call was complete. Send it again, smaller.',
                        'isError' => true,
                    ], $toolUses);
                    $content[] = ['type' => 'text', 'text' => 'Your last response was cut off at the output token limit. Continue, keep responses and file writes shorter.'];
                    $messages[] = ['role' => 'user', 'content' => $content];

                    break;

                case 'pause_turn':
                    // The server paused a long turn, sending the conversation again continues it
                    break;

                case 'refusal':
                    $details = $response->stopDetails;
                    $this->session->log(StepType::Error, trans('ai-debugger::strings.log.refusal'), trim(($details->category ?? '') . ' ' . ($details->explanation ?? '')) ?: null);

                    return null;

                case 'model_context_window_exceeded':
                    $this->session->log(StepType::Error, trans('ai-debugger::strings.log.context_exceeded'));

                    return null;

                default:
                    // end_turn without calling finish: remind the model twice, then give up
                    if ($this->tools->finish !== null) {
                        return $this->tools->finish;
                    }

                    if (++$nudges > 2) {
                        return null;
                    }

                    $messages[] = ['role' => 'user', 'content' => 'Please continue working on the server. If you are done or can not do anything else, call the finish tool with your report.'];
            }
        }

        $this->session->log(StepType::Warning, trans('ai-debugger::strings.log.max_iterations', ['max' => $maxIterations]));

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $system
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    protected function request(string $model, array $system, array $messages, array $tools): BetaMessage
    {
        $effort = (string) config('ai-debugger.anthropic.effort', 'high');

        $parameters = [
            'maxTokens' => max(4096, (int) config('ai-debugger.anthropic.max_tokens', 64000)),
            'messages' => $messages,
            'model' => $model,
            'system' => $system,
            'tools' => $tools,
            // Caches tools + system + the conversation so far, every turn only pays for the new part
            'cacheControl' => ['type' => 'ephemeral'],
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'outputConfig' => ['effort' => in_array($effort, self::EFFORTS, true) ? $effort : 'high'],
        ];

        if (in_array($model, self::FALLBACK_MODELS, true)) {
            // If the model declines a request, the API retries it on its default fallback model
            $parameters['fallbacks'] = 'default';
            $parameters['betas'] = ['server-side-fallback-2026-07-01'];
        }

        $stream = $this->client()->beta->messages->createStream(...$parameters);

        $accumulator = MessageAccumulator::forBetaMessages();
        foreach ($stream as $event) {
            $accumulator->accumulate($event);
        }

        /** @var BetaMessage $message */
        $message = $accumulator->message();

        return $message;
    }

    protected function trackUsage(BetaMessage $response, int $iteration): void
    {
        $usage = $response->usage;

        $this->session->forceFill([
            'iterations' => $iteration,
            'input_tokens' => $this->session->input_tokens + $usage->inputTokens,
            'output_tokens' => $this->session->output_tokens + $usage->outputTokens,
            'cache_read_tokens' => $this->session->cache_read_tokens + (int) $usage->cacheReadInputTokens,
            'cache_write_tokens' => $this->session->cache_write_tokens + (int) $usage->cacheCreationInputTokens,
        ])->save();
    }

    protected function logContent(BetaMessage $response): void
    {
        foreach ($response->content as $block) {
            if ($block instanceof BetaThinkingBlock && trim($block->thinking) !== '') {
                $this->session->log(StepType::Thinking, Str::limit(Str::squish($block->thinking), 140), $block->thinking);
            } elseif ($block instanceof BetaTextBlock && trim($block->text) !== '') {
                $this->session->log(StepType::Message, Str::limit(Str::squish($block->text), 140), $block->text);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function runTool(BetaToolUseBlock $toolUse): array
    {
        $input = is_array($toolUse->input) ? $toolUse->input : [];

        $step = $this->session->log(StepType::ToolCall, self::describe($toolUse->name, $input), null, [
            'tool' => $toolUse->name,
            'input' => self::shortInput($input),
            'running' => true,
        ]);

        $started = microtime(true);
        $result = $this->tools->execute($toolUse->name, $input);

        $step->update([
            'type' => $result['is_error'] ? StepType::Error : StepType::ToolCall,
            'content' => Str::limit($result['content'], 6000),
            'meta' => [
                'tool' => $toolUse->name,
                'input' => self::shortInput($input),
                'running' => false,
                'error' => $result['is_error'],
                'duration' => round(microtime(true) - $started, 1),
            ],
        ]);

        return [
            'type' => 'tool_result',
            'toolUseID' => $toolUse->id,
            'content' => $result['content'],
            'isError' => $result['is_error'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function shortInput(array $input): array
    {
        return array_map(fn ($value) => is_string($value) ? Str::limit($value, 300) : (is_array($value) ? array_slice($value, 0, 50) : $value), $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function describe(string $tool, array $input): string
    {
        $key = 'ai-debugger::strings.tools.' . $tool;
        $path = $input['path'] ?? $input['folder'] ?? $input['from'] ?? $input['project'] ?? $input['query'] ?? $input['action'] ?? null;

        $label = trans()->has($key) ? trans($key) : Str::headline($tool);

        if ($tool === 'remove_files' && is_array($input['paths'] ?? null)) {
            return $label . ' (' . count($input['paths']) . ')';
        }

        return $label . (is_string($path) && $path !== '' ? ': ' . Str::limit($path, 120) : '');
    }

    protected function assertNotCancelled(): void
    {
        if ($this->session->isCancelRequested()) {
            throw new SessionCancelledException();
        }
    }
}
