<?php

/**
 * Example 9: PromptPool — 並列実行 + プロンプトキャッシュ
 *
 * 「同じ大きなコンテキスト + 軸だけ違うプロンプトを N 回回す」ワークロード
 * (rubric 採点 / heuristic 監査 / per-page SEO チェック) に最適化された API。
 *
 * 順次 executeSync() を回すと:
 *   - input トークンが N 倍課金される
 *   - レイテンシが累積する
 *
 * `PromptPool::executeWithWarmup()` は:
 *   1. 1 本目を単独で発射 (warmup) → shared section が Anthropic prompt cache に書き込まれる
 *   2. 残り N-1 本を Http::pool で並列発射 → 各リクエストは shared section を cache hit で読む
 *
 * このサンプルは 5 軸 rubric grading (encounter 全体を 5 次元で採点) を想定。
 *
 * ⚠ Anthropic 専用機能。OpenAI/Google には executeSync() を使うこと。
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\PoolExecutionException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\PromptPool;
use Kent013\PrismPrompt\Values\CacheType;

// ── DTO ────────────────────────────────────────────
class RubricAxisScoreDto
{
    public function __construct(
        public readonly string $axis,
        public readonly int $score,        // 1-5
        public readonly string $feedback,  // 50-100 字
    ) {}
}

// ── Prompt サブクラス ──────────────────────────────

/**
 * @extends Prompt<RubricAxisScoreDto>
 */
class RubricAxisPrompt extends Prompt
{
    public function __construct(
        public readonly string $axis,                 // 'empathy' / 'logic' / ...
        public readonly string $conversationContext,  // 数 KB の会話ログ (5 本共通)
        public readonly float $averageScore,
        public readonly int $turnCount,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $text): RubricAxisScoreDto
    {
        $data = $this->extractJson($text);

        /** @var array{axis: string, score: int, feedback: string} $data */
        return new RubricAxisScoreDto($data['axis'], $data['score'], $data['feedback']);
    }
}

// ── YAML ───────────────────────────────────────────
// resources/prompts/rubric/axis.yaml
//
// name: rubric.axis
// provider: anthropic
// model: claude-haiku-4-5-20251001
// max_tokens: 800
// temperature: 0.2
//
// system_prompt: |
//   あなたはビジネスコミュニケーション訓練の評価者です。
//   指定された 1 軸を 1-5 で採点し JSON で返してください。
//
// # sections は YAML 上の名前付きフラグメント。各 prompt がどのセクションを
// # cacheable にするかを withCacheBreakpoints() で宣言する。
// sections:
//   shared: |
//     # 会話ログ (採点対象)
//     {{ $conversationContext }}
//
//     # 補助情報 (heuristic)
//     平均スコア: {{ $averageScore }}
//     ターン数: {{ $turnCount }}
//   axis: |
//     # 採点軸: {{ $axis }}
//
// prompt: |
//   出力形式: {"axis": "{{ $axis }}", "score": <1-5>, "feedback": "<50-100字>"}

// ════════════════════════════════════════════════════
// 並列実行
// ════════════════════════════════════════════════════

$conversationContext = '...（数 KB の会話ログ）';
$averageScore = 3.4;
$turnCount = 12;

$axes = ['empathy', 'logic', 'specificity', 'inquiry', 'listening'];

// 5 本の Prompt を組み立てる
$prompts = collect($axes)
    ->map(fn (string $axis) => (new RubricAxisPrompt(
        axis: $axis,
        conversationContext: $conversationContext,
        averageScore: $averageScore,
        turnCount: $turnCount,
    ))
        // shared セクションを ephemeral cache 対象に。byte-stable 必須。
        ->withCacheBreakpoints(['shared' => CacheType::Ephemeral])
        ->withMetadata([
            'organization_id' => $orgId,
            'subject_type' => 'App\\Models\\Encounter',
            'subject_id' => $encounterId,
            'rubric_axis' => $axis,  // listener 側で軸ごとにコスト集計できるように
        ]))
    ->all();

try {
    /** @var array<int, RubricAxisScoreDto> $results */
    $results = PromptPool::executeWithWarmup($prompts, concurrency: 5);
    // $results[0] = empathy, $results[1] = logic, ...
} catch (PoolExecutionException $e) {
    // どの軸で落ちたか分かる
    $failedAxis = $axes[$e->getPromptIndex()];
    Log::error('rubric_axis_failed', [
        'axis' => $failedAxis,
        'previous' => $e->getPrevious()?->getMessage(),
    ]);

    throw $e;
}

// ════════════════════════════════════════════════════
// キャッシュが効いたか確認
// ════════════════════════════════════════════════════
//
// PromptExecutionCompleted の listener で usage を見ればわかる:
//
//   Event::listen(PromptExecutionCompleted::class, function ($e) {
//       // 1 本目 (warmup): cache_creation_input_tokens に shared 部のトークンが乗る
//       // 2 本目以降:       cache_read_input_tokens に shared 部のトークンが乗る
//       Log::info('llm_pool_call', [
//           'axis' => $e->metadata['rubric_axis'] ?? null,
//           'usage' => $e->totalUsage,
//           'cost_usd' => $e->cost?->totalCostUsd,
//           'cache_read' => $e->cost?->cacheReadCostUsd,
//       ]);
//   });
//
// shared セクションに 4-8 KB のテキストを乗せると、cache_read 単価は通常
// input の 1/10 になるので、N=5 なら実効コストは 1.4-1.8 倍 (素朴に N 倍
// より大幅減) に収まる。

// ════════════════════════════════════════════════════
// 注意点
// ════════════════════════════════════════════════════
//
// - shared セクションは byte-stable でなければ cache hit しない。
//   日付・乱数・浮動小数の桁ブレを混ぜないこと。
// - withCacheBreakpoints のキー名は YAML sections のキー名と一致させる。
//   不一致のキーを渡すと InvalidCacheBreakpointException。
// - concurrency は config('prism-prompt.pool.concurrency') がデフォルト
//   (env PRISM_PROMPT_POOL_CONCURRENCY)。Anthropic の rate limit と相談。
// - Anthropic 以外の provider に対しては PromptPool は対応していない。
//   sequential に Prompt::executeSync() を回すこと。
