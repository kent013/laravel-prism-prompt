<?php

/**
 * Example 10: Embedding — RAG ドキュメントインデキシング
 *
 * EmbeddingPrompt は LLM テキスト生成ではなくベクトル化用の API。
 * `Prompt::load()` 同様 YAML で provider/model を宣言できる。
 *
 * このサンプルは社内ドキュメントを chunk 化 → embedding → pgvector に保存する
 * RAG (Retrieval-Augmented Generation) の indexing 側を想定。
 *
 * 検索側 (query 文を embedding して cosine 距離で類似 chunk を引く) も
 * 同じ EmbeddingPrompt を使えば良い。
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kent013\PrismPrompt\EmbeddingPrompt;

// ── YAML テンプレート ──────────────────────────────
// resources/prompts/document-embedding.yaml
//
// name: document-embedding
// provider: openai
// model: text-embedding-3-small      # 1536 次元 / 安価
//
// # 注: EmbeddingPrompt は system_prompt / prompt フィールドを使わない。
// # 入力テキストは executeSync($text) に直接渡す。

// ════════════════════════════════════════════════════
// Scenario A: 単発の embedding
// ════════════════════════════════════════════════════

/** @var array<int, float> $vector */
$vector = EmbeddingPrompt::load('document-embedding')->executeSync('社内オンボーディングの手順');

// $vector は array<int, float> (model に応じた次元数)。
// pgvector や Pinecone, Qdrant などにそのまま投入できる。

// ════════════════════════════════════════════════════
// Scenario B: ドキュメントを chunk 化して bulk index
// ════════════════════════════════════════════════════

/** @var iterable<array{document_id: int, chunk_index: int, content: string}> $chunks */
$chunks = [
    ['document_id' => 1, 'chunk_index' => 0, 'content' => '会社の理念...'],
    ['document_id' => 1, 'chunk_index' => 1, 'content' => '営業時間...'],
    ['document_id' => 2, 'chunk_index' => 0, 'content' => '入社手続き...'],
];

foreach ($chunks as $chunk) {
    $vector = EmbeddingPrompt::load('document-embedding')
        ->executeSync($chunk['content']);

    // pgvector カラム想定 (Laravel の生 SQL bind では vector 型を文字列で送る)
    DB::table('document_chunks')->updateOrInsert(
        ['document_id' => $chunk['document_id'], 'chunk_index' => $chunk['chunk_index']],
        [
            'content' => $chunk['content'],
            // pgvector は '[0.1, 0.2, ...]' 形式の文字列を受ける
            'embedding' => '['.implode(',', $vector).']',
            'updated_at' => now(),
        ]
    );
}

// ════════════════════════════════════════════════════
// Scenario C: BYOK — ユーザーの API key で embed
// ════════════════════════════════════════════════════

$vector = EmbeddingPrompt::load('document-embedding')
    ->withApiKey($userApiKeys['openai'])
    ->executeSync($query);

// ════════════════════════════════════════════════════
// Scenario D: 検索クエリ → 類似 chunk 取得
// ════════════════════════════════════════════════════

$queryVector = EmbeddingPrompt::load('document-embedding')->executeSync('入社時の流れを教えて');

// pgvector の '<=>' は cosine 距離 (低いほど類似)
$rows = DB::select(
    'SELECT document_id, chunk_index, content, embedding <=> ? AS distance
     FROM document_chunks
     ORDER BY distance ASC
     LIMIT 5',
    ['['.implode(',', $queryVector).']']
);

// あとは取り出した chunk を Prompt::load('answer_with_context', [...]) に
// 渡して回答生成すれば RAG が完成する (回答側は通常の Prompt で良い)。

// ════════════════════════════════════════════════════
// 制約
// ════════════════════════════════════════════════════
//
// - EmbeddingPrompt は PromptExecutionCompleted / Failed event を発火しない。
//   コスト集計は legacy PerformanceLogger 経由 (PRISM_PROMPT_DEBUG=true) で
//   ログを取り、out-of-band で集計する必要がある (将来 events に移行予定)。
// - 同じ chunk をリインデックスする際は idempotent に書けるよう
//   (document_id, chunk_index) を unique key にしておくと安全。
// - text-embedding-3-small は 1 入力あたり 8191 トークンが上限。
//   大きなドキュメントは事前に chunk 化する (LangChain 等の splitter を参考に)。
