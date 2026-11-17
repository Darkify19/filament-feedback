<?php

namespace CarlJanzell\FilamentFeedback\Actions;

use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\SuggestionConfidence;
use CarlJanzell\FilamentFeedback\Enums\SuggestionOutcome;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Turns the suggestions JSON (from Claude, a script, saved replies…) into pending suggestions in
 * the inbox. Nothing reaches an author here: a triager sends them. A new suggestion for a post
 * supersedes its older pending one. A dry run does the same work and rolls it back.
 *
 *     {"version": 1, "drafted_by": "…", "suggestions": [{"post_id": 9, "for_reply_id": null,
 *      "reply": "…", "status": "done", "confidence": "high", "note": "…", "sources": ["…"]}]}
 */
class ImportSuggestions
{
    public const MAX_REPLY_LENGTH = 5000;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{created: int, superseded: int, skipped: list<array{post_id: mixed, reason: string}>}
     *
     * @throws InvalidArgumentException when the payload isn't the contract at all
     */
    public function execute(array $payload, bool $dryRun = false): array
    {
        if (($payload['version'] ?? 1) !== 1) {
            throw new InvalidArgumentException('Unknown version: expected "version": 1.');
        }

        $items = $payload['suggestions'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException('Expected a "suggestions" list.');
        }

        $report = ['created' => 0, 'superseded' => 0, 'skipped' => []];

        DB::beginTransaction();

        try {
            foreach ($items as $item) {
                $reason = $this->import(is_array($item) ? $item : [], $payload['drafted_by'] ?? null, $report);

                if ($reason !== null) {
                    $report['skipped'][] = ['post_id' => is_array($item) ? ($item['post_id'] ?? null) : null, 'reason' => $reason];
                }
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{created: int, superseded: int, skipped: list<array{post_id: mixed, reason: string}>}  $report
     * @return string|null why it was skipped
     */
    protected function import(array $item, mixed $draftedBy, array &$report): ?string
    {
        $post = is_int($item['post_id'] ?? null) ? FeedbackPost::query()->find($item['post_id']) : null;

        if ($post === null) {
            return 'unknown post';
        }

        $body = $item['reply'] ?? null;

        if ($body !== null && ! is_string($body)) {
            return 'reply must be text or null';
        }

        $body = filled($body) ? trim($body) : null;

        if ($body !== null && mb_strlen($body) > static::MAX_REPLY_LENGTH) {
            return 'reply is longer than '.static::MAX_REPLY_LENGTH.' characters';
        }

        $status = $item['status'] ?? null;

        if ($status !== null && FeedbackStatus::tryFrom(is_string($status) ? $status : '') === null) {
            return 'invalid status '.json_encode($status);
        }

        if ($body === null && $status === null) {
            return 'no reply and no status';
        }

        $confidence = isset($item['confidence'])
            ? SuggestionConfidence::tryFrom(is_string($item['confidence']) ? $item['confidence'] : '')
            : SuggestionConfidence::Medium;

        if ($confidence === null) {
            return 'invalid confidence '.json_encode($item['confidence']);
        }

        $forReplyId = $item['for_reply_id'] ?? null;

        if ($forReplyId !== null && ! is_int($forReplyId)) {
            return 'for_reply_id must be a reply id or null';
        }

        $report['superseded'] += $post->suggestions()->pending()->update([
            'outcome' => SuggestionOutcome::Superseded->value,
            'decided_at' => now(),
        ]);

        $post->suggestions()->create([
            'body' => $body,
            'status' => $status,
            'note' => is_string($item['note'] ?? null) && filled($item['note']) ? trim($item['note']) : null,
            'sources' => is_array($item['sources'] ?? null) ? array_values(array_filter($item['sources'], 'is_string')) : null,
            'confidence' => $confidence,
            'drafted_by' => Str::limit((string) ($item['drafted_by'] ?? $draftedBy ?? ''), 97) ?: null,
            // Left out by a tool that doesn't track threads: drafted against the thread as it is now.
            'for_reply_id' => array_key_exists('for_reply_id', $item) ? $forReplyId : $post->latestReplyId(),
        ]);

        $report['created']++;

        return null;
    }
}
