<?php

namespace App\Console\Commands;

use App\Services\KnowledgeSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class KnowledgeSearchEvaluateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'knowledge:search-eval
        {--user-id= : User ID for scoped evaluation}
        {--limit=10 : Result depth for MRR@k}
        {--show-failures=10 : Number of failing queries to print}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate KB search before/after search_v2 flag with zero-results and MRR@k metrics.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = (int) ($this->option('user-id') ?: config('knowledge.mcp.default_user_id', 1));
        $limit = max(1, min((int) $this->option('limit'), 20));
        $showFailures = max(1, min((int) $this->option('show-failures'), 20));

        /** @var KnowledgeSearchService $service */
        $service = app(KnowledgeSearchService::class);
        $queries = $this->querySet();

        $before = $this->evaluate($service, $queries, $userId, $limit, false);
        $after = $this->evaluate($service, $queries, $userId, $limit, true);

        $this->info("User scope: {$userId}");
        $this->line('Dataset size: '.count($queries).' queries');

        $this->table(
            ['Metric', 'Before (v1)', 'After (v2)'],
            [
                ['Zero-results rate', $this->percent($before['zero_results_rate']), $this->percent($after['zero_results_rate'])],
                ['Average results/query', number_format($before['avg_results_per_query'], 2), number_format($after['avg_results_per_query'], 2)],
                ["MRR@{$limit}", number_format($before['mrr_at_k'], 4), number_format($after['mrr_at_k'], 4)],
            ]
        );

        $this->line('');
        $this->info("Top {$showFailures} failing queries before patch:");
        $this->printFailures(collect($before['failures'])->take($showFailures));

        $this->line('');
        $this->info("Top {$showFailures} failing queries after patch:");
        $this->printFailures(collect($after['failures'])->take($showFailures));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{bucket:string,query:string,expected_slugs:array<int, string>}>  $queries
     * @return array{
     *     zero_results_rate: float,
     *     avg_results_per_query: float,
     *     mrr_at_k: float,
     *     failures: array<int, array{bucket:string,query:string,expected:string,top5:string}>
     * }
     */
    private function evaluate(
        KnowledgeSearchService $service,
        array $queries,
        int $userId,
        int $limit,
        bool $v2Enabled
    ): array {
        config(['knowledge.search_v2.enabled' => $v2Enabled]);

        $zeroResults = 0;
        $totalReturned = 0;
        $mrr = 0.0;
        $failures = [];

        foreach ($queries as $entry) {
            $results = $service->search(
                query: $entry['query'],
                limit: $limit,
                category: null,
                tags: [],
                includeDrafts: true,
                userId: $userId
            );

            $slugs = collect($results)
                ->map(fn (array $row): string => (string) ($row['item']['slug'] ?? ''))
                ->filter(fn (string $slug): bool => $slug !== '')
                ->values()
                ->all();

            if ($slugs === []) {
                $zeroResults++;
            }

            $totalReturned += count($slugs);

            $rank = null;
            foreach ($slugs as $index => $slug) {
                if (in_array($slug, $entry['expected_slugs'], true)) {
                    $rank = $index + 1;
                    break;
                }
            }

            if (is_int($rank)) {
                $mrr += 1 / $rank;
            } else {
                $failures[] = [
                    'bucket' => $entry['bucket'],
                    'query' => $entry['query'],
                    'expected' => implode(', ', $entry['expected_slugs']),
                    'top5' => implode(', ', array_slice($slugs, 0, 5)),
                ];
            }
        }

        $totalQueries = max(1, count($queries));

        return [
            'zero_results_rate' => $zeroResults / $totalQueries,
            'avg_results_per_query' => $totalReturned / $totalQueries,
            'mrr_at_k' => $mrr / $totalQueries,
            'failures' => $failures,
        ];
    }

    /**
     * @param  Collection<int, array{bucket:string,query:string,expected:string,top5:string}>  $failures
     */
    private function printFailures(Collection $failures): void
    {
        if ($failures->isEmpty()) {
            $this->line('  none');

            return;
        }

        foreach ($failures as $failure) {
            $top5 = $failure['top5'] !== '' ? $failure['top5'] : '(no results)';
            $this->line("  [{$failure['bucket']}] {$failure['query']}");
            $this->line("    expected: {$failure['expected']}");
            $this->line("    top5: {$top5}");
        }
    }

    private function percent(float $value): string
    {
        return number_format($value * 100, 1).'%';
    }

    /**
     * @return array<int, array{bucket:string,query:string,expected_slugs:array<int, string>}>
     */
    private function querySet(): array
    {
        $stripe = 'integrations-payments-stripe-checkout-fulfillment-webhook';
        $speedy = 'integrations-shipping-speedy-rest-create-shipment-print-label';
        $tbi = 'integrations-financing-tbi-bnpl-authorize-application-status-callback';
        $embeddings = 'ai-embeddings-laravel-ai-sdk-pgvector-wherevectorsimilarto';

        return [
            ['bucket' => '1-word', 'query' => 'stripe', 'expected_slugs' => [$stripe]],
            ['bucket' => '1-word', 'query' => 'checkout', 'expected_slugs' => [$stripe]],
            ['bucket' => '1-word', 'query' => 'webhook', 'expected_slugs' => [$stripe, $tbi]],
            ['bucket' => '1-word', 'query' => 'idempotent', 'expected_slugs' => [$stripe]],
            ['bucket' => '1-word', 'query' => 'speedy', 'expected_slugs' => [$speedy]],
            ['bucket' => '1-word', 'query' => 'shipment', 'expected_slugs' => [$speedy]],
            ['bucket' => '1-word', 'query' => 'label', 'expected_slugs' => [$speedy]],
            ['bucket' => '1-word', 'query' => 'tbi', 'expected_slugs' => [$tbi]],
            ['bucket' => '1-word', 'query' => 'bnpl', 'expected_slugs' => [$tbi]],
            ['bucket' => '1-word', 'query' => 'embeddings', 'expected_slugs' => [$embeddings]],
            ['bucket' => '1-word', 'query' => 'pgvector', 'expected_slugs' => [$embeddings]],
            ['bucket' => '1-word', 'query' => 'authorize', 'expected_slugs' => [$tbi]],
            ['bucket' => '1-word', 'query' => 'queueing', 'expected_slugs' => [$stripe]],

            ['bucket' => '2-3 words', 'query' => 'stripe webhook', 'expected_slugs' => [$stripe]],
            ['bucket' => '2-3 words', 'query' => 'checkout session', 'expected_slugs' => [$stripe]],
            ['bucket' => '2-3 words', 'query' => 'webhook signature', 'expected_slugs' => [$stripe]],
            ['bucket' => '2-3 words', 'query' => 'speedy rest api', 'expected_slugs' => [$speedy]],
            ['bucket' => '2-3 words', 'query' => 'speedy print label', 'expected_slugs' => [$speedy]],
            ['bucket' => '2-3 words', 'query' => 'tbi callback', 'expected_slugs' => [$tbi]],
            ['bucket' => '2-3 words', 'query' => 'tbi authorize token', 'expected_slugs' => [$tbi]],
            ['bucket' => '2-3 words', 'query' => 'laravel ai embeddings', 'expected_slugs' => [$embeddings]],
            ['bucket' => '2-3 words', 'query' => 'vector similarity query', 'expected_slugs' => [$embeddings]],
            ['bucket' => '2-3 words', 'query' => 'application status callback', 'expected_slugs' => [$tbi]],
            ['bucket' => '2-3 words', 'query' => 'pdf shipping label', 'expected_slugs' => [$speedy]],
            ['bucket' => '2-3 words', 'query' => 'create shipment', 'expected_slugs' => [$speedy]],
            ['bucket' => '2-3 words', 'query' => 'register application', 'expected_slugs' => [$tbi]],

            ['bucket' => 'long', 'query' => 'how to create stripe checkout session and handle webhook idempotently', 'expected_slugs' => [$stripe]],
            ['bucket' => 'long', 'query' => 'what is the laravel pattern for speedy shipment creation and label pdf printing', 'expected_slugs' => [$speedy]],
            ['bucket' => 'long', 'query' => 'how to integrate tbi bnpl and receive async status callback updates', 'expected_slugs' => [$tbi]],
            ['bucket' => 'long', 'query' => 'how do i generate and store embeddings in laravel with pgvector', 'expected_slugs' => [$embeddings]],
            ['bucket' => 'long', 'query' => 'what headers are required to call tbi register application endpoint', 'expected_slugs' => [$tbi]],
            ['bucket' => 'long', 'query' => 'how to verify stripe webhook signature and avoid duplicate event processing', 'expected_slugs' => [$stripe]],
            ['bucket' => 'long', 'query' => 'what does wherevectorsimilarto do in laravel ai sdk', 'expected_slugs' => [$embeddings]],
            ['bucket' => 'long', 'query' => 'how to call speedy rest api without using deprecated eps soap service', 'expected_slugs' => [$speedy]],
            ['bucket' => 'long', 'query' => 'how to keep bnpl application status synchronized in merchant backend', 'expected_slugs' => [$tbi]],
            ['bucket' => 'long', 'query' => 'what is the best laravel flow for stripe checkout order fulfillment', 'expected_slugs' => [$stripe]],

            ['bucket' => 'ids/acronyms', 'query' => 'CHECKOUT_SESSION_ID', 'expected_slugs' => [$stripe]],
            ['bucket' => 'ids/acronyms', 'query' => 'STRIPE_WEBHOOK_SECRET', 'expected_slugs' => [$stripe]],
            ['bucket' => 'ids/acronyms', 'query' => 'Ocp-Apim-Subscription-Key', 'expected_slugs' => [$tbi]],
            ['bucket' => 'ids/acronyms', 'query' => 'AuthorizationToken', 'expected_slugs' => [$tbi]],
            ['bucket' => 'ids/acronyms', 'query' => 'Schema::ensureVectorExtensionExists', 'expected_slugs' => [$embeddings]],
            ['bucket' => 'ids/acronyms', 'query' => 'whereVectorSimilarTo', 'expected_slugs' => [$embeddings]],
            ['bucket' => 'ids/acronyms', 'query' => 'tbi-apim.azure-api.net', 'expected_slugs' => [$tbi]],
            ['bucket' => 'ids/acronyms', 'query' => 'services.speedy.bg', 'expected_slugs' => [$speedy]],

            ['bucket' => 'typos', 'query' => 'strpie', 'expected_slugs' => [$stripe]],
            ['bucket' => 'typos', 'query' => 'checokut', 'expected_slugs' => [$stripe]],
            ['bucket' => 'typos', 'query' => 'webhok', 'expected_slugs' => [$stripe]],
            ['bucket' => 'typos', 'query' => 'speady', 'expected_slugs' => [$speedy]],
            ['bucket' => 'typos', 'query' => 'shipemnt', 'expected_slugs' => [$speedy]],
            ['bucket' => 'typos', 'query' => 'bnlp', 'expected_slugs' => [$tbi]],
            ['bucket' => 'typos', 'query' => 'autherize token', 'expected_slugs' => [$tbi]],
            ['bucket' => 'typos', 'query' => 'pgvecto', 'expected_slugs' => [$embeddings]],
            ['bucket' => 'typos', 'query' => 'embeding', 'expected_slugs' => [$embeddings]],
            ['bucket' => 'typos', 'query' => 'subscrption key', 'expected_slugs' => [$tbi]],
        ];
    }
}
