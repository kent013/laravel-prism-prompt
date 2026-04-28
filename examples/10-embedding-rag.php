<?php

/**
 * Example 10: Embedding — RAG document indexing
 *
 * `EmbeddingPrompt` is the vectoriser counterpart to `Prompt`. It uses
 * the same YAML mechanism to declare provider/model and is invoked with
 * the input text directly.
 *
 * The example below covers the indexing side of a RAG pipeline:
 * chunk an internal document, embed each chunk, store into pgvector.
 *
 * The query side (embedding the user's question, then cosine-distance
 * search) uses the same `EmbeddingPrompt`.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kent013\PrismPrompt\EmbeddingPrompt;

// ── YAML template ──────────────────────────────────
// resources/prompts/document-embedding.yaml
//
// name: document-embedding
// provider: openai
// model: text-embedding-3-small      # 1536 dimensions / inexpensive
//
// # Note: EmbeddingPrompt does not use system_prompt / prompt fields.
// # Pass the input text directly to executeSync($text).

// ════════════════════════════════════════════════════
// Scenario A: One-off embedding
// ════════════════════════════════════════════════════

/** @var array<int, float> $vector */
$vector = EmbeddingPrompt::load('document-embedding')->executeSync('Internal onboarding procedure');

// $vector is array<int, float> (length depends on the model). Drop it
// straight into pgvector / Pinecone / Qdrant / etc.

// ════════════════════════════════════════════════════
// Scenario B: Bulk indexing chunked documents
// ════════════════════════════════════════════════════

/** @var iterable<array{document_id: int, chunk_index: int, content: string}> $chunks */
$chunks = [
    ['document_id' => 1, 'chunk_index' => 0, 'content' => 'Company values...'],
    ['document_id' => 1, 'chunk_index' => 1, 'content' => 'Office hours...'],
    ['document_id' => 2, 'chunk_index' => 0, 'content' => 'Onboarding checklist...'],
];

foreach ($chunks as $chunk) {
    $vector = EmbeddingPrompt::load('document-embedding')
        ->executeSync($chunk['content']);

    // pgvector column. Laravel raw bindings expect the vector as a
    // string in the '[0.1, 0.2, ...]' format.
    DB::table('document_chunks')->updateOrInsert(
        ['document_id' => $chunk['document_id'], 'chunk_index' => $chunk['chunk_index']],
        [
            'content' => $chunk['content'],
            'embedding' => '['.implode(',', $vector).']',
            'updated_at' => now(),
        ]
    );
}

// ════════════════════════════════════════════════════
// Scenario C: BYOK — embed with the user's API key
// ════════════════════════════════════════════════════

$vector = EmbeddingPrompt::load('document-embedding')
    ->withApiKey($userApiKeys['openai'])
    ->executeSync($query);

// ════════════════════════════════════════════════════
// Scenario D: Query-time similarity search
// ════════════════════════════════════════════════════

$queryVector = EmbeddingPrompt::load('document-embedding')->executeSync('Walk me through onboarding');

// pgvector's '<=>' is cosine distance (lower = more similar).
$rows = DB::select(
    'SELECT document_id, chunk_index, content, embedding <=> ? AS distance
     FROM document_chunks
     ORDER BY distance ASC
     LIMIT 5',
    ['['.implode(',', $queryVector).']']
);

// Feed the retrieved chunks into a regular `Prompt::load('answer_with_context', ...)`
// to generate the final answer — that's the full RAG loop.

// ════════════════════════════════════════════════════
// Limitations
// ════════════════════════════════════════════════════
//
// - EmbeddingPrompt does not yet dispatch
//   PromptExecutionCompleted / PromptExecutionFailed events.
//   Cost tracking goes through the legacy PerformanceLogger
//   (PRISM_PROMPT_DEBUG=true) for now (events migration is planned).
// - Make `(document_id, chunk_index)` a unique key so re-indexing is
//   idempotent.
// - text-embedding-3-small accepts up to 8191 tokens per input; chunk
//   large documents beforehand (LangChain-style splitters work well).
