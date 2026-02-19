<?php

namespace Database\Seeders;

use App\Models\CodeExample;
use App\Models\KnowledgeAccountAccess;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class KnowledgeDemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $users = $this->seedUsers();

        $this->resetDemoData($users);
        $this->seedAccessGrants($users);
        $this->seedKnowledge($users);
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(): array
    {
        $accounts = [
            ['key' => 'owner', 'name' => 'Main Owner', 'email' => 'owner@acme.test'],
            ['key' => 'manager', 'name' => 'Engineering Manager', 'email' => 'manager@acme.test'],
            ['key' => 'support', 'name' => 'Support Lead', 'email' => 'support@acme.test'],
            ['key' => 'security', 'name' => 'Security Analyst', 'email' => 'security@acme.test'],
            ['key' => 'finance', 'name' => 'Finance Lead', 'email' => 'finance@acme.test'],
            ['key' => 'viewer', 'name' => 'Read Only User', 'email' => 'viewer@acme.test'],
            ['key' => 'legacy', 'name' => 'Test User', 'email' => 'test@example.com'],
        ];

        $users = [];

        foreach ($accounts as $account) {
            $users[$account['key']] = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'email_verified_at' => now(),
                ]
            );
        }

        return $users;
    }

    /**
     * @param  array<string, User>  $users
     */
    private function resetDemoData(array $users): void
    {
        $userIds = collect($users)->map(fn (User $user) => $user->id)->values()->all();

        KnowledgeAccountAccess::query()
            ->whereIn('owner_user_id', $userIds)
            ->orWhereIn('grantee_user_id', $userIds)
            ->delete();

        KnowledgeItem::query()
            ->whereIn('created_by', $userIds)
            ->delete();
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedAccessGrants(array $users): void
    {
        $grants = [
            ['owner' => 'owner', 'grantee' => 'manager', 'permission' => 'editor'],
            ['owner' => 'owner', 'grantee' => 'support', 'permission' => 'viewer'],
            ['owner' => 'owner', 'grantee' => 'security', 'permission' => 'editor'],
            ['owner' => 'owner', 'grantee' => 'viewer', 'permission' => 'viewer'],
            ['owner' => 'owner', 'grantee' => 'legacy', 'permission' => 'viewer'],
            ['owner' => 'manager', 'grantee' => 'support', 'permission' => 'editor'],
            ['owner' => 'finance', 'grantee' => 'owner', 'permission' => 'viewer'],
            ['owner' => 'finance', 'grantee' => 'legacy', 'permission' => 'viewer'],
        ];

        foreach ($grants as $grant) {
            KnowledgeAccountAccess::query()->updateOrCreate(
                [
                    'owner_user_id' => $users[$grant['owner']]->id,
                    'grantee_user_id' => $users[$grant['grantee']]->id,
                ],
                [
                    'permission' => $grant['permission'],
                ]
            );
        }
    }

    /**
     * CHANGED: replaced synthetic “company runbooks” with curated integration patterns + real links.
     *
     * @param  array<string, User>  $users
     */
    private function seedKnowledge(array $users): void
    {
        $entries = $this->knowledgeEntries();

        $chunkRows = [];
        $embeddingDimensions = (int) config('knowledge.defaults.embedding_dimensions', 1536);
        $zeroVector = $this->zeroVector($embeddingDimensions);
        $timestamp = now();

        KnowledgeItem::withoutEvents(function () use (
            $users,
            $entries,
            &$chunkRows,
            $embeddingDimensions,
            $zeroVector,
            $timestamp
        ): void {
            CodeExample::withoutEvents(function () use (
                $users,
                $entries,
                &$chunkRows,
                $embeddingDimensions,
                $zeroVector,
                $timestamp
            ): void {
                KnowledgeResource::withoutEvents(function () use (
                    $users,
                    $entries,
                    &$chunkRows,
                    $embeddingDimensions,
                    $zeroVector,
                    $timestamp
                ): void {
                    foreach ($entries as $entry) {
                        $owner = $users[$entry['owner']];

                        $item = KnowledgeItem::query()->create([
                            'slug' => $entry['slug'],
                            'title' => $entry['title'],
                            'content_markdown' => $entry['content_markdown'],
                            'category' => $entry['category'],
                            'tags' => $entry['tags'],
                            'status' => 'published',
                            'published_at' => $timestamp,
                            'source' => 'human',
                            'created_by' => $owner->id,
                            'chunk_size' => (int) config('knowledge.defaults.chunk_size'),
                            'chunk_overlap' => (int) config('knowledge.defaults.chunk_overlap'),
                            'embedding_dimensions' => $embeddingDimensions,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);

                        $chunkRows[] = $this->chunkRow(
                            knowledgeItemId: $item->id,
                            sourceType: 'article',
                            sourceId: null,
                            chunkKind: 'markdown',
                            chunkIndex: 0,
                            text: $this->buildArticleChunkText(
                                title: $entry['title'],
                                category: $entry['category'],
                                tags: $entry['tags'],
                                markdown: $entry['content_markdown'],
                            ),
                            meta: [
                                'seed' => 'integration_kb',
                                'owner' => $entry['owner'],
                                'category' => $entry['category'],
                            ],
                            titleText: $entry['title'],
                            categoryText: $entry['category'],
                            tagsText: $this->normalizeTagText($entry['tags']),
                            vector: $zeroVector,
                            dimensions: $embeddingDimensions,
                            timestamp: $timestamp
                        );

                        foreach (($entry['code_examples'] ?? []) as $idx => $example) {
                            $codeExample = CodeExample::query()->create([
                                'knowledge_item_id' => $item->id,
                                'sort_order' => $idx,
                                'title' => $example['title'],
                                'language' => $example['language'],
                                'filename' => $example['filename'],
                                'description' => $example['description'],
                                'code' => $example['code'],
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]);

                            $chunkRows[] = $this->chunkRow(
                                knowledgeItemId: $item->id,
                                sourceType: 'code',
                                sourceId: (int) $codeExample->id,
                                chunkKind: 'code',
                                chunkIndex: $idx,
                                text: (string) $codeExample->code,
                                meta: [
                                    'language' => $codeExample->language,
                                    'title' => $codeExample->title,
                                    'filename' => $codeExample->filename,
                                ],
                                titleText: $entry['title'],
                                categoryText: $entry['category'],
                                tagsText: $this->normalizeTagText($entry['tags']),
                                vector: $zeroVector,
                                dimensions: $embeddingDimensions,
                                timestamp: $timestamp
                            );
                        }

                        foreach (($entry['resources'] ?? []) as $idx => $resource) {
                            $res = KnowledgeResource::query()->create([
                                'knowledge_item_id' => $item->id,
                                'sort_order' => $idx,
                                'type' => 'link',
                                'label' => $resource['label'],
                                'url' => $resource['url'],
                                'extracted_text' => $resource['extracted_text'],
                                'extracted_at' => $timestamp,
                                'extract_attempts' => 1,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]);

                            if ($res->extracted_text) {
                                $chunkRows[] = $this->chunkRow(
                                    knowledgeItemId: $item->id,
                                    sourceType: 'resource',
                                    sourceId: (int) $res->id,
                                    chunkKind: 'text',
                                    chunkIndex: $idx,
                                    text: (string) $res->extracted_text,
                                    meta: [
                                        'label' => $res->label,
                                        'url' => $res->url,
                                    ],
                                    titleText: $entry['title'],
                                    categoryText: $entry['category'],
                                    tagsText: $this->normalizeTagText($entry['tags']),
                                    vector: $zeroVector,
                                    dimensions: $embeddingDimensions,
                                    timestamp: $timestamp
                                );
                            }
                        }

                        if (count($chunkRows) >= 200) {
                            KnowledgeChunk::query()->insert($chunkRows);
                            $chunkRows = [];
                        }
                    }
                });
            });
        });

        if ($chunkRows !== []) {
            KnowledgeChunk::query()->insert($chunkRows);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function knowledgeEntries(): array
    {
        $catalog = [
            // Payments (Stripe) — 14
            [
                'owner' => 'owner',
                'slug' => 'integrations-payments-stripe-checkout-fulfillment-webhook',
                'title' => 'Stripe Checkout in Laravel: session creation + fulfillment webhook (idempotent)',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'stack-php', 'pattern-checkout', 'pattern-webhooks', 'pattern-idempotency', 'pattern-signature'],
                'purpose' => 'Use Stripe-hosted Checkout UI; fulfill orders only via verified webhooks with dedupe.',
                'flow' => [
                    'Create local order row in pending_payment.',
                    'Create Checkout Session with metadata.order_id or client_reference_id.',
                    'Redirect user to session.url.',
                    'Handle checkout.session.completed webhook: verify signature, dedupe, mark paid, enqueue fulfillment.',
                ],
                'pitfalls' => [
                    'Treating the browser redirect as “paid”. Webhooks are the source of truth.',
                    'Doing slow work in the webhook handler instead of queueing.',
                ],
                'resources' => [
                    ['label' => 'Checkout overview', 'url' => 'https://docs.stripe.com/payments/checkout', 'text' => 'Official Stripe Checkout integration overview.'],
                    ['label' => 'Create Checkout Session', 'url' => 'https://docs.stripe.com/api/checkout/sessions/create', 'text' => 'API reference for creating Checkout Sessions (metadata, urls, line_items).'],
                    ['label' => 'Webhooks overview', 'url' => 'https://docs.stripe.com/webhooks', 'text' => 'Webhook delivery, signature verification, retries.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Checkout Session creation (server)',
                        'language' => 'php',
                        'filename' => 'app/Http/Controllers/Billing/CreateCheckoutSessionController.php',
                        'description' => 'Create session server-side; return session.url.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Http\Request;
use Stripe\StripeClient;

public function __invoke(Request $request)
{
    $request->validate(['order_id' => ['required','string']]);

    $orderId = (string) $request->string('order_id');
    $stripe = new StripeClient(config('services.stripe.secret'));

    $session = $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'client_reference_id' => $orderId,
        'metadata' => ['order_id' => $orderId],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => 1999,
                'product_data' => ['name' => 'Example order'],
            ],
        ]],
        'success_url' => route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => route('checkout.cancel'),
    ]);

    return response()->json(['checkout_url' => $session->url]);
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'integrations-payments-stripe-webhooks-cashier-signature-csrf',
                'title' => 'Stripe webhooks in Laravel: signature verification + CSRF bypass + queueing',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'pattern-webhooks', 'pattern-signature', 'pattern-csrf-bypass', 'pattern-queueing', 'laravel-cashier'],
                'purpose' => 'Keep webhook endpoints secure (signature) and stable (fast + queued).',
                'flow' => [
                    'Expose webhook route.',
                    'Exclude route from CSRF protection.',
                    'Verify Stripe signature for every request.',
                    'Deduplicate events and dispatch jobs.',
                ],
                'pitfalls' => [
                    'Breaking signature verification by mutating payload before verification.',
                    'Letting the webhook handler do heavy work (timeouts).',
                ],
                'resources' => [
                    ['label' => 'Cashier billing docs', 'url' => 'https://laravel.com/docs/12.x/billing', 'text' => 'Laravel Cashier docs include Stripe webhook handling notes.'],
                    ['label' => 'Stripe webhooks', 'url' => 'https://docs.stripe.com/webhooks', 'text' => 'Stripe webhook signatures and retries.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Laravel 12 CSRF exclusion for Stripe webhooks',
                        'language' => 'php',
                        'filename' => 'bootstrap/app.php',
                        'description' => 'Exclude stripe/* from CSRF validation.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
        ]);
    })
    ->create();
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'owner',
                'slug' => 'integrations-payments-stripe-payment-intents-payment-element',
                'title' => 'Stripe PaymentIntents in Laravel: create intent + client_secret for Payment Element',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'stack-php', 'pattern-payment-intents', 'pattern-client-secret', 'pattern-sca', 'pattern-idempotency'],
                'purpose' => 'Custom UI payment flow: backend creates PaymentIntent; frontend confirms using client_secret.',
                'flow' => [
                    'Server creates PaymentIntent with amount/currency and returns client_secret.',
                    'Frontend initializes Stripe Elements and confirms payment.',
                    'Webhook handles payment_intent.succeeded to finalize fulfillment.',
                ],
                'pitfalls' => [
                    'Creating multiple PaymentIntents for the same cart instead of using idempotency.',
                    'Finalizing orders on client success instead of webhook.',
                ],
                'resources' => [
                    ['label' => 'PaymentIntents API (overview)', 'url' => 'https://docs.stripe.com/payments/payment-intents', 'text' => 'Stripe canonical payment flow incl. SCA handling.'],
                    ['label' => 'Create PaymentIntent', 'url' => 'https://docs.stripe.com/api/payment_intents/create', 'text' => 'API reference for creating PaymentIntents.'],
                    ['label' => 'Payment Element', 'url' => 'https://docs.stripe.com/payments/payment-element', 'text' => 'Stripe Payment Element integration docs.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create PaymentIntent (server) and return client_secret',
                        'language' => 'php',
                        'filename' => 'app/Http/Controllers/Billing/CreatePaymentIntentController.php',
                        'description' => 'Minimal PaymentIntent creation endpoint.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Http\Request;
use Stripe\StripeClient;

public function __invoke(Request $request)
{
    $request->validate(['order_id' => ['required','string']]);

    $stripe = new StripeClient(config('services.stripe.secret'));

    $intent = $stripe->paymentIntents->create([
        'amount' => 1999,
        'currency' => 'eur',
        'metadata' => ['order_id' => (string) $request->string('order_id')],
        'automatic_payment_methods' => ['enabled' => true],
    ]);

    return response()->json(['client_secret' => $intent->client_secret]);
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-setup-intents-save-payment-method',
                'title' => 'Stripe SetupIntents in Laravel: save card for future off-session charges',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'stack-php', 'pattern-setup-intents', 'pattern-save-card', 'pattern-off-session', 'pattern-customer'],
                'purpose' => 'Collect and store a payment method for later usage (subscriptions, off-session).',
                'flow' => [
                    'Create (or load) Stripe Customer for your user.',
                    'Create SetupIntent for that customer.',
                    'Frontend confirms SetupIntent; store payment_method id.',
                ],
                'pitfalls' => [
                    'Trying to store a reusable payment method without a customer.',
                    'Not handling required authentication steps for the confirmation.',
                ],
                'resources' => [
                    ['label' => 'SetupIntents API (overview)', 'url' => 'https://docs.stripe.com/payments/save-and-reuse', 'text' => 'Saving and reusing payment details in Stripe.'],
                    ['label' => 'SetupIntents API reference', 'url' => 'https://docs.stripe.com/api/setup_intents', 'text' => 'SetupIntent object and lifecycle.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create SetupIntent for customer',
                        'language' => 'php',
                        'filename' => 'app/Http/Controllers/Billing/CreateSetupIntentController.php',
                        'description' => 'Create SetupIntent and return client_secret.',
                        'code' => <<<'PHP'
<?php

use Stripe\StripeClient;

public function __invoke()
{
    $stripe = new StripeClient(config('services.stripe.secret'));

    $customerId = 'cus_xxx'; // In real code: store per-user.
    $intent = $stripe->setupIntents->create(['customer' => $customerId]);

    return response()->json(['client_secret' => $intent->client_secret]);
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-cashier-subscriptions-create-swap-cancel',
                'title' => 'Cashier subscriptions in Laravel: create + swap price + cancel with grace',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'laravel-cashier', 'pattern-subscriptions', 'pattern-swap', 'pattern-cancel', 'pattern-grace-period'],
                'purpose' => 'Use Cashier as the canonical subscription integration when you’re on Laravel.',
                'flow' => [
                    'Attach a default payment method to the user.',
                    'Create subscription using price id.',
                    'Swap plans using price id.',
                    'Cancel with grace; handle webhooks for state changes.',
                ],
                'pitfalls' => [
                    'Hard-coding plan pricing instead of using Stripe Price IDs.',
                    'Ignoring webhook-driven state changes (invoice failures, cancellations).',
                ],
                'resources' => [
                    ['label' => 'Cashier (Stripe) docs', 'url' => 'https://laravel.com/docs/12.x/billing', 'text' => 'Official Cashier billing guidance for Stripe subscriptions.'],
                    ['label' => 'Stripe Billing test clocks', 'url' => 'https://docs.stripe.com/billing/testing/test-clocks', 'text' => 'Testing subscription timelines deterministically.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create and manage subscription with Cashier',
                        'language' => 'php',
                        'filename' => 'app/Actions/Billing/ManageSubscription.php',
                        'description' => 'Cashier-style subscription operations.',
                        'code' => <<<'PHP'
<?php

// $user is Billable (Cashier)
$user->newSubscription('default', 'price_123')->create($paymentMethodId);

$user->subscription('default')->swap('price_456');

$user->subscription('default')->cancel(); // grace period by default
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-customer-portal-session',
                'title' => 'Stripe Customer Portal in Laravel: create portal session + return_url',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'stack-php', 'pattern-customer-portal', 'pattern-subscriptions', 'pattern-self-service', 'pattern-return-url'],
                'purpose' => 'Let customers self-manage billing via Stripe-hosted portal.',
                'flow' => [
                    'Resolve Stripe customer ID for user.',
                    'Create portal session with return_url.',
                    'Redirect user to session.url.',
                ],
                'pitfalls' => [
                    'Creating portal session without return_url when not configured in Stripe dashboard.',
                    'Losing mapping between app user and Stripe customer.',
                ],
                'resources' => [
                    ['label' => 'Customer portal overview', 'url' => 'https://docs.stripe.com/customer-management', 'text' => 'Stripe customer portal documentation.'],
                    ['label' => 'Create portal session API', 'url' => 'https://docs.stripe.com/api/customer_portal/sessions/create', 'text' => 'API reference for Billing Portal sessions.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create Billing Portal session',
                        'language' => 'php',
                        'filename' => 'app/Http/Controllers/Billing/CreatePortalSessionController.php',
                        'description' => 'Creates portal session and redirects user.',
                        'code' => <<<'PHP'
<?php

use Stripe\StripeClient;

public function __invoke()
{
    $stripe = new StripeClient(config('services.stripe.secret'));

    $session = $stripe->billingPortal->sessions->create([
        'customer' => 'cus_xxx',
        'return_url' => route('billing.settings'),
    ]);

    return redirect()->away($session->url);
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-invoices-draft-finalize-pay',
                'title' => 'Stripe Invoices in Laravel: create invoice items + finalize invoice',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'stack-php', 'pattern-invoices', 'pattern-invoice-items', 'pattern-one-off-billing', 'pattern-finalize'],
                'purpose' => 'One-off billing via invoices (not subscriptions).',
                'flow' => [
                    'Create invoice item(s) for customer.',
                    'Create invoice (draft) for customer.',
                    'Finalize invoice to attempt payment / send invoice.',
                ],
                'pitfalls' => [
                    'Assuming invoice is payable before finalization.',
                    'Not handling invoice.payment_failed webhooks if charging automatically.',
                ],
                'resources' => [
                    ['label' => 'Create invoice API', 'url' => 'https://docs.stripe.com/api/invoices/create', 'text' => 'API reference for invoice creation.'],
                    ['label' => 'Invoices overview', 'url' => 'https://docs.stripe.com/invoicing', 'text' => 'Stripe invoicing docs.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Invoice creation + finalize',
                        'language' => 'php',
                        'filename' => 'app/Actions/Billing/CreateInvoice.php',
                        'description' => 'Create invoice items and finalize invoice.',
                        'code' => <<<'PHP'
<?php

use Stripe\StripeClient;

$stripe = new StripeClient(config('services.stripe.secret'));

$stripe->invoiceItems->create([
    'customer' => 'cus_xxx',
    'amount' => 1999,
    'currency' => 'eur',
    'description' => 'One-off charge',
]);

$invoice = $stripe->invoices->create(['customer' => 'cus_xxx']);

$stripe->invoices->finalizeInvoice($invoice->id);
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-refunds-partial-refund-paymentintent',
                'title' => 'Stripe refunds in Laravel: create partial refund + store refund id',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'stack-php', 'pattern-refunds', 'pattern-partial-refund', 'pattern-audit-log', 'pattern-webhooks'],
                'purpose' => 'Refund via API and keep local state consistent.',
                'flow' => [
                    'Record a refund request in DB (pending).',
                    'Call refund API referencing PaymentIntent or Charge.',
                    'Update local refund row with Stripe refund id and status; handle updates via webhooks.',
                ],
                'pitfalls' => [
                    'Issuing multiple refunds due to retries without idempotency or local locking.',
                    'Not persisting refund id for reconciliation.',
                ],
                'resources' => [
                    ['label' => 'Refunds API', 'url' => 'https://docs.stripe.com/api/refunds', 'text' => 'Refund object and endpoints.'],
                    ['label' => 'Create refund API', 'url' => 'https://docs.stripe.com/api/refunds/create', 'text' => 'API reference for creating refunds.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create partial refund',
                        'language' => 'php',
                        'filename' => 'app/Actions/Billing/CreateRefund.php',
                        'description' => 'Refund 10.00 EUR from a PaymentIntent.',
                        'code' => <<<'PHP'
<?php

use Stripe\StripeClient;

$stripe = new StripeClient(config('services.stripe.secret'));

$refund = $stripe->refunds->create([
    'payment_intent' => 'pi_xxx',
    'amount' => 1000,
]);

// Store $refund->id and $refund->status in your DB
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-disputes-webhooks-evidence',
                'title' => 'Stripe disputes in Laravel: handle dispute events + create internal workflow',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'pattern-disputes', 'pattern-webhooks', 'pattern-evidence', 'pattern-workflow', 'pattern-notifications'],
                'purpose' => 'Turn disputes into a deterministic internal process (collect evidence, respond, monitor outcome).',
                'flow' => [
                    'Listen to dispute-related webhooks.',
                    'Create internal dispute case row and link to charge/payment.',
                    'Notify ops; collect evidence; update case status.',
                ],
                'pitfalls' => [
                    'Ignoring disputes until funds are already lost.',
                    'Not correlating dispute to order/payment in your DB.',
                ],
                'resources' => [
                    ['label' => 'Disputes API', 'url' => 'https://docs.stripe.com/api/disputes', 'text' => 'Dispute object and lifecycle.'],
                    ['label' => 'Event types', 'url' => 'https://docs.stripe.com/api/events/types', 'text' => 'Event type list for webhook routing decisions.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Webhook router snippet for disputes',
                        'language' => 'php',
                        'filename' => 'app/Actions/Billing/HandleStripeEvent.php',
                        'description' => 'Route dispute events to internal workflow.',
                        'code' => <<<'PHP'
<?php

switch ($event->type) {
    case 'charge.dispute.created':
    case 'charge.dispute.funds_withdrawn':
        // Create/update internal dispute case; notify ops
        break;
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'owner',
                'slug' => 'integrations-payments-stripe-connect-standard-onboarding-account-links',
                'title' => 'Stripe Connect Standard onboarding: create account + account link redirect',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-marketplaces', 'stack-laravel', 'stack-php', 'pattern-connect', 'pattern-account-links', 'pattern-onboarding', 'pattern-marketplace'],
                'purpose' => 'Marketplace flow: onboard sellers via Connect Standard and store connected account id.',
                'flow' => [
                    'Create Connect account (type=standard) for seller.',
                    'Create Account Link for onboarding; redirect seller.',
                    'Handle return/refresh; persist account id in DB.',
                ],
                'pitfalls' => [
                    'Not persisting the connected account id for future transfers/payouts.',
                    'Not handling refresh_url when onboarding isn’t completed.',
                ],
                'resources' => [
                    ['label' => 'Connect Standard accounts', 'url' => 'https://docs.stripe.com/connect/standard-accounts', 'text' => 'Standard Connect onboarding overview.'],
                    ['label' => 'Create Account Link API', 'url' => 'https://docs.stripe.com/api/account_links/create', 'text' => 'API reference for account_links.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create account link and redirect',
                        'language' => 'php',
                        'filename' => 'app/Http/Controllers/Connect/OnboardSellerController.php',
                        'description' => 'Create account + onboarding link.',
                        'code' => <<<'PHP'
<?php

use Stripe\StripeClient;

$stripe = new StripeClient(config('services.stripe.secret'));

$account = $stripe->accounts->create(['type' => 'standard']);

$link = $stripe->accountLinks->create([
    'account' => $account->id,
    'type' => 'account_onboarding',
    'refresh_url' => route('connect.refresh'),
    'return_url' => route('connect.return'),
]);

return redirect()->away($link->url);
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'integrations-payments-stripe-idempotency-keys-safe-retries',
                'title' => 'Stripe idempotency keys: safe retries for POST requests (Laravel pattern)',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'pattern-idempotency', 'pattern-retries', 'pattern-network-failures', 'pattern-deduplication', 'pattern-uuid'],
                'purpose' => 'Prevent doubles when you retry POST calls due to network failures or timeouts.',
                'flow' => [
                    'Generate a stable idempotency key per “business operation” (order_id + action).',
                    'Send the same key when retrying the same POST request.',
                    'If you change request params, generate a new key.',
                ],
                'pitfalls' => [
                    'Reusing idempotency key for *different* requests (Stripe will return cached response).',
                    'Not making idempotency key stable per operation, causing duplicates.',
                ],
                'resources' => [
                    ['label' => 'Idempotent requests', 'url' => 'https://docs.stripe.com/api/idempotent_requests', 'text' => 'Stripe’s idempotency semantics and caching behavior.'],
                    ['label' => 'Advanced error handling', 'url' => 'https://docs.stripe.com/error-low-level', 'text' => 'Notes about cached 400/500 responses and when to generate a new key.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Idempotency key strategy',
                        'language' => 'bash',
                        'filename' => 'docs/examples/stripe-idempotency.strategy.txt',
                        'description' => 'Human-readable strategy example.',
                        'code' => <<<'BASH'
# Example stable idempotency key:
# "order:{ORDER_ID}:capture"
# If request body changes, generate a new key.
BASH,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-tax-calculations',
                'title' => 'Stripe Tax: calculate tax and persist tax breakdown for receipts',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'pattern-tax', 'pattern-calculation', 'pattern-receipts', 'pattern-audit', 'pattern-checkout'],
                'purpose' => 'Compute tax using Stripe Tax and persist breakdown for invoices/receipts.',
                'flow' => [
                    'Collect customer location/address.',
                    'Use Stripe Tax to compute tax lines.',
                    'Store tax breakdown in DB; include in payment/invoice metadata.',
                ],
                'pitfalls' => [
                    'Not persisting tax breakdown, making reconciliation and invoices painful.',
                    'Calculating tax after payment without immutable recorded amounts.',
                ],
                'resources' => [
                    ['label' => 'Stripe Tax docs', 'url' => 'https://docs.stripe.com/tax', 'text' => 'Stripe Tax product documentation.'],
                    ['label' => 'Tax API reference', 'url' => 'https://docs.stripe.com/api/tax', 'text' => 'Tax API endpoints and objects.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Persist tax breakdown',
                        'language' => 'json',
                        'filename' => 'docs/examples/tax-breakdown.json',
                        'description' => 'Example stored tax structure for an order.',
                        'code' => <<<'JSON'
{
  "order_id": "ORDER_123",
  "currency": "EUR",
  "subtotal": 10000,
  "tax_total": 2000,
  "lines": [
    { "rate": 0.20, "amount": 2000, "jurisdiction": "BG" }
  ]
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'integrations-payments-stripe-cli-listen-trigger-fixtures',
                'title' => 'Stripe CLI for Laravel dev: listen/forward + trigger events + fixtures',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'pattern-local-dev', 'pattern-webhooks', 'pattern-cli', 'pattern-fixtures', 'pattern-testing'],
                'purpose' => 'Make webhook development deterministic: forward events locally and trigger snapshots/fixtures.',
                'flow' => [
                    'Run stripe listen --forward-to locally to receive events.',
                    'Use stripe trigger for common event snapshots.',
                    'Use fixtures JSON to replay multi-step flows.',
                ],
                'pitfalls' => [
                    'Testing webhooks only in live env instead of using CLI forwarding.',
                    'Assuming trigger emits only one event (many prerequisite events may fire).',
                ],
                'resources' => [
                    ['label' => 'Use the Stripe CLI', 'url' => 'https://docs.stripe.com/stripe-cli/use-cli', 'text' => 'CLI can forward events, stream logs, trigger events.'],
                    ['label' => 'CLI trigger reference', 'url' => 'https://docs.stripe.com/cli/trigger', 'text' => 'Trigger snapshot events like payment_intent.succeeded.'],
                    ['label' => 'Stripe fixtures', 'url' => 'https://docs.stripe.com/cli/fixtures', 'text' => 'Run a series of API requests using a fixtures JSON file.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'CLI commands you actually use',
                        'language' => 'bash',
                        'filename' => 'docs/examples/stripe-cli.sh',
                        'description' => 'Forward webhooks + trigger events.',
                        'code' => <<<'BASH'
stripe listen --forward-to http://localhost:8000/stripe/webhook
stripe trigger payment_intent.succeeded
# Fixtures (multi-step):
stripe fixtures fixtures.json
BASH,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-payments-stripe-billing-test-clocks',
                'title' => 'Stripe Billing test clocks: simulate subscription time and verify webhooks',
                'category' => 'Integrations / Payments',
                'tags' => ['provider-stripe', 'domain-payments', 'stack-laravel', 'pattern-billing', 'pattern-test-clocks', 'pattern-subscriptions', 'pattern-webhooks', 'pattern-sandbox'],
                'purpose' => 'Test subscription lifecycle without waiting days/weeks by advancing time in sandbox.',
                'flow' => [
                    'Create a test clock.',
                    'Attach subscription/customer objects to the clock.',
                    'Advance time and validate webhook-driven state changes.',
                ],
                'pitfalls' => [
                    'Manually waiting for billing cycles instead of using test clocks.',
                    'Not asserting webhooks/events; only checking dashboard UI.',
                ],
                'resources' => [
                    ['label' => 'Test clocks docs', 'url' => 'https://docs.stripe.com/billing/testing/test-clocks', 'text' => 'Test clocks simulate forward movement of time in sandbox.'],
                    ['label' => 'Test clocks API reference', 'url' => 'https://docs.stripe.com/api/test_clocks', 'text' => 'Create and advance test clocks via API.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Describe test clock usage',
                        'language' => 'bash',
                        'filename' => 'docs/examples/stripe-test-clocks.txt',
                        'description' => 'High-level steps.',
                        'code' => <<<'BASH'
# Create test clock (API or dashboard)
# Create subscription using this clock
# Advance clock time -> observe invoice/payment webhooks
BASH,
                    ],
                ],
            ],

            // Shipping (Speedy) — 12
            [
                'owner' => 'owner',
                'slug' => 'integrations-shipping-speedy-rest-create-shipment-print-label',
                'title' => 'Speedy REST API in Laravel: create shipment + print label (PDF)',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'stack-php', 'pattern-rest-json', 'pattern-print', 'pattern-pdf', 'pattern-http-client'],
                'purpose' => 'Create shipment and print labels using Speedy REST endpoints with JSON body auth.',
                'flow' => [
                    'Build payload per Speedy example (sender, recipient, service, content, payment).',
                    'POST to /shipment/ and persist returned shipment/parcels ids.',
                    'POST to /print/ using parcel ids; store returned PDF bytes.',
                ],
                'pitfalls' => [
                    'Not persisting parcel IDs; you need them for print/track.',
                    'Treating PDFs as text; store bytes and correct content-type.',
                ],
                'resources' => [
                    ['label' => 'Speedy API docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Shipment and print service documentation.'],
                    ['label' => 'Speedy official examples (API_BASE_URL)', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Defines API_BASE_URL as https://api.speedy.bg/v1/.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'API base URL (from Speedy examples)',
                        'language' => 'php',
                        'filename' => 'docs/examples/speedy-base-url.php',
                        'description' => 'Speedy examples define API_BASE_URL.',
                        'code' => <<<'PHP'
<?php
define('API_BASE_URL', 'https://api.speedy.bg/v1/');
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-track-and-trace-batch-lastoperationonly',
                'title' => 'Speedy tracking in Laravel: batch by 10 parcels + lastOperationOnly',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-tracking', 'pattern-batching', 'pattern-polling', 'pattern-scheduler', 'pattern-http-client'],
                'purpose' => 'Track active shipments efficiently (Speedy supports up to 10 parcels per request).',
                'flow' => [
                    'Query active (non-final) parcel IDs from DB.',
                    'Chunk into batches of 10.',
                    'POST to /track with lastOperationOnly=true.',
                    'Map returned operations/statuses to local shipment state and stop polling on final.',
                ],
                'pitfalls' => [
                    'Calling wrong endpoint (tracking is /track, not /shipment).',
                    'Polling delivered shipments forever (waste + rate-limit risk).',
                ],
                'resources' => [
                    ['label' => 'Track endpoint (POST /track)', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Track request supports up to 10 parcels and lastOperationOnly.'],
                    ['label' => 'Tracking examples', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples section includes Track requests and guidance.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Track request payload shape',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-track.payload.json',
                        'description' => 'Minimal /track request.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "parcels": [{ "id": 299999990 }, { "id": 299999991 }],
  "lastOperationOnly": true
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-create-shipment-to-pickup-point',
                'title' => 'Speedy shipment to pickup point: office IDs + recipient pickup office flow',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-pickup-point', 'pattern-office-ids', 'pattern-location-service', 'pattern-rest-json'],
                'purpose' => 'Create shipments to an office/pickup point by resolving and using office IDs.',
                'flow' => [
                    'Resolve office IDs (search by name/city/site).',
                    'Create shipment where recipient uses pickup point (office id) instead of street address.',
                    'Print label and store parcel IDs.',
                ],
                'pitfalls' => [
                    'Using stale office list; offices open/close (cache refresh is needed).',
                    'Accepting arbitrary office IDs from client without validation.',
                ],
                'resources' => [
                    ['label' => 'Speedy examples: shipment to pickup point', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples include shipments to pickup points and office ID usage.'],
                    ['label' => 'Location service docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Location service endpoints for office lookups.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Example note: API_BASE_URL from Speedy',
                        'language' => 'bash',
                        'filename' => 'docs/examples/speedy-pickup-point.txt',
                        'description' => 'High-level checklist.',
                        'code' => <<<'BASH'
# Resolve officeId (location service)
# Create shipment referencing officeId for recipient pickup
# Persist parcel IDs -> print/track
BASH,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-location-find-office-nearest-offices',
                'title' => 'Speedy Location Service: find office + nearest offices with feature filters',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-location', 'pattern-office-search', 'pattern-nearest', 'pattern-feature-filter'],
                'purpose' => 'Implement office search and “nearest office” features for checkout UX.',
                'flow' => [
                    'Use find office for text searches (city/name).',
                    'Use nearest-offices endpoint for geo proximity (must send address with countryId; optional features).',
                    'Return a validated officeId to your checkout.',
                ],
                'pitfalls' => [
                    'Not enforcing that officeId belongs to the selected country/site.',
                    'Ignoring office features needed by business (CARD_PAYMENT, DROP_OFF, etc.).',
                ],
                'resources' => [
                    ['label' => 'Nearest offices request', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Nearest offices is POST to location/office/nearest-offices with address and optional filters.'],
                    ['label' => 'Speedy examples: office lookup', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Office lookup examples and guidance about caching office lists.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Nearest offices minimal request',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-nearest-offices.json',
                        'description' => 'Nearest offices request shape.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "address": { "countryId": 100, "siteId": 68134 }
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-office-locator-widget-frontend',
                'title' => 'Speedy office locator widget: embed + validate returned officeId server-side',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-front-end-widget', 'pattern-validation', 'pattern-officeid', 'pattern-security'],
                'purpose' => 'Use the vendor widget for UX, but validate and re-resolve office IDs server-side.',
                'flow' => [
                    'Embed Speedy office locator widget in checkout.',
                    'Receive officeId from client.',
                    'Validate officeId via Location Service before using it in shipment creation.',
                ],
                'pitfalls' => [
                    'Trusting officeId blindly from the browser.',
                    'Not reconciling officeId to country/site constraints in your checkout.',
                ],
                'resources' => [
                    ['label' => 'Speedy office locator widget link from examples page', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples page lists the office locator widget resource.'],
                    ['label' => 'Speedy API docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Use API to validate office IDs and retrieve details.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Server-side validation rule idea',
                        'language' => 'bash',
                        'filename' => 'docs/examples/speedy-officeid-validation.txt',
                        'description' => 'Validation checklist.',
                        'code' => <<<'BASH'
# Validate officeId from client:
# - exists
# - matches selected country/site
# - not permanently closed
BASH,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-calculation-service-shipping-quote',
                'title' => 'Speedy Calculation Service: shipping quote before shipment creation',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-quote', 'pattern-calculate', 'pattern-pricing', 'pattern-checkout'],
                'purpose' => 'Calculate shipping fees at checkout without creating a shipment.',
                'flow' => [
                    'Build calculation payload (recipient, service, content, payment).',
                    'POST to /calculate to get price/options.',
                    'Use returned price to show checkout totals.',
                ],
                'pitfalls' => [
                    'Calculating without enough address/office info -> invalid office/service errors.',
                    'Not aligning “who pays what” in calculation vs shipment payment config.',
                ],
                'resources' => [
                    ['label' => 'Calculation service docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Calculation service is POST to BASE_URL/calculate with recipient/service/content/payment.'],
                    ['label' => 'Calculation examples', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples include calculation request examples and related guidance.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Calculation request minimal shape',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-calculate.json',
                        'description' => 'Minimal calculation request example.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "recipient": { "address": { "countryId": 100, "siteId": 68134 } },
  "service": { "serviceId": 505 },
  "content": { "parcelsCount": 1, "totalWeight": 0.6 },
  "payment": { "courierServicePayer": "RECIPIENT" }
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-cancel-shipment',
                'title' => 'Speedy Cancel Shipment: cancel before it is ordered (POST /shipment/cancel or DELETE)',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-cancel', 'pattern-pre-pickup', 'pattern-http', 'pattern-error-handling'],
                'purpose' => 'Cancel a shipment only while Speedy allows it (not ordered yet).',
                'flow' => [
                    'Store shipmentId returned from create shipment.',
                    'When user cancels, call cancel endpoint with shipmentId and comment.',
                    'Update local shipment state to canceled.',
                ],
                'pitfalls' => [
                    'Trying to cancel after pickup/ordering (Speedy rejects).',
                    'Not storing the cancel comment required by API.',
                ],
                'resources' => [
                    ['label' => 'Cancel shipment docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Cancel is POST /shipment/cancel or DELETE /shipment with required comment and shipmentId.'],
                    ['label' => 'Speedy examples page', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples include cancel shipment references.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Cancel request shape',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-cancel.json',
                        'description' => 'CancelShipmentRequest minimal fields.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "shipmentId": "SHIPMENT_ID",
  "comment": "Customer canceled before pickup"
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-pickup-create-request',
                'title' => 'Speedy Pickup: create courier pickup request (POST /pickup)',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-pickup', 'pattern-courier-request', 'pattern-scheduling', 'pattern-ops'],
                'purpose' => 'Create a pickup order for shipments to be collected.',
                'flow' => [
                    'Determine pickup address / sender details.',
                    'POST /pickup with required fields (times, contact).',
                    'Persist returned pickup order id(s) for ops tracking.',
                ],
                'pitfalls' => [
                    'Scheduling outside allowed cutoffs (use PickupTerms).',
                    'Not storing pickup order IDs for later support workflows.',
                ],
                'resources' => [
                    ['label' => 'Pickup docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Pickup endpoint is POST /pickup.'],
                    ['label' => 'PickupTerms docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Pickup/terms returns cutoffs for allowed pickup days.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Pickup request minimal shape',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-pickup.json',
                        'description' => 'PickupRequest minimal idea.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "visitStartTime": "09:00",
  "visitEndTime": "17:00",
  "contactName": "Warehouse",
  "phoneNumber": { "number": "0888112233" }
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-pickup-terms-cutoffs',
                'title' => 'Speedy Pickup Terms: fetch cutoffs for service and sender (POST /pickup/terms)',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-pickup-terms', 'pattern-cutoffs', 'pattern-calendar', 'pattern-validation'],
                'purpose' => 'Get the next allowed pickup cutoffs and prevent invalid pickup timing.',
                'flow' => [
                    'Call /pickup/terms with serviceId and optional sender.',
                    'Use returned cutoffs list to validate date/time selection.',
                    'Block scheduling for missing days.',
                ],
                'pitfalls' => [
                    'Assuming pickup is possible every day; it isn’t.',
                    'Hard-coding calendar rules instead of using API response.',
                ],
                'resources' => [
                    ['label' => 'PickupTerms docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Pickup/terms returns cutoffs for next days and missing days are not allowed.'],
                    ['label' => 'Speedy examples guidance', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples mention using Pickup Terms to avoid invalid pickup dates.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'PickupTerms request minimal shape',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-pickup-terms.json',
                        'description' => 'PickupTermsRequest minimal fields.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "serviceId": 505,
  "startingDate": "2026-02-18"
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-print-service-pdf-vs-zpl',
                'title' => 'Speedy Print: PDF for A4 vs ZPL for thermal printers (+ dpi)',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-print', 'pattern-pdf', 'pattern-zpl', 'pattern-dpi'],
                'purpose' => 'Support both warehouse A4 printing and thermal label printing.',
                'flow' => [
                    'Call /print with parcel IDs.',
                    'Choose output format (pdf or zpl).',
                    'If zpl, choose dpi203 or dpi300 depending on printer.',
                ],
                'pitfalls' => [
                    'Sending ZPL to a PDF pipeline or vice versa.',
                    'Not persisting label bytes; reprinting becomes painful.',
                ],
                'resources' => [
                    ['label' => 'Print response formats', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Print can return application/pdf or text/plain ZPL.'],
                    ['label' => 'Speedy print request examples', 'url' => 'https://services.speedy.bg/api/api_examples.html', 'text' => 'Examples show print requests and payload structure.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Print voucher request showing pdf/zpl idea',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-print-format.json',
                        'description' => 'Format selection fields (conceptually similar to print/voucher).',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "format": "zpl",
  "dpi": "dpi203",
  "parcels": [{ "parcel": { "id": "1234567890" } }]
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-extended-print-base64-label-info',
                'title' => 'Speedy Extended Print: base64 PDF + label metadata for routing/debug',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-extended-print', 'pattern-base64', 'pattern-label-metadata', 'pattern-debugging'],
                'purpose' => 'Use /print/extended to get PDF bytes in base64 plus label info for debugging.',
                'flow' => [
                    'Send the same print request structure to /print/extended.',
                    'Decode base64 data to PDF bytes.',
                    'Persist labelInfo metadata for debugging/printer routing.',
                ],
                'pitfalls' => [
                    'Forgetting base64 decode and writing corrupt PDFs.',
                    'Not storing label metadata; harder to debug label issues.',
                ],
                'resources' => [
                    ['label' => 'Extended print docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Extended print returns JSON with base64 pdf data and label info.'],
                    ['label' => 'LabelInfo endpoint docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Label info endpoint returns printLabelsInfo for parcels.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Base64 decode idea',
                        'language' => 'php',
                        'filename' => 'docs/examples/speedy-base64-decode.php',
                        'description' => 'Decode base64 data field to bytes.',
                        'code' => <<<'PHP'
<?php

$pdfBytes = base64_decode($response['data'] ?? '');
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-shipping-speedy-bulk-tracking-data-files',
                'title' => 'Speedy bulk tracking data files: incremental processing via lastProcessedFileId',
                'category' => 'Integrations / Shipping',
                'tags' => ['provider-speedy', 'domain-shipping', 'stack-laravel', 'pattern-bulk-tracking', 'pattern-incremental', 'pattern-file-links', 'pattern-ops'],
                'purpose' => 'Process tracking updates in bulk using published tracking data files.',
                'flow' => [
                    'Call /track/bulk with lastProcessedFileId.',
                    'Download linked files in ascending id order.',
                    'Store greatest processed id and repeat.',
                ],
                'pitfalls' => [
                    'Not persisting lastProcessedFileId; you will reprocess everything.',
                    'Using too old file id (Speedy requires recent window).',
                ],
                'resources' => [
                    ['label' => 'Bulk tracking docs', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Bulk tracking returns downloadable data file links; requires enrollment.'],
                    ['label' => 'Speedy API docs root', 'url' => 'https://api.speedy.bg/api/docs/', 'text' => 'Track/bulk method details and constraints.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'BulkTracking request minimal shape',
                        'language' => 'json',
                        'filename' => 'docs/examples/speedy-track-bulk.json',
                        'description' => 'BulkTrackingDataFilesRequest minimal fields.',
                        'code' => <<<'JSON'
{
  "userName": "xxxxxx",
  "password": "yyyyyy",
  "lastProcessedFileId": 0
}
JSON,
                    ],
                ],
            ],

            // Financing (tbi) — 5
            [
                'owner' => 'finance',
                'slug' => 'integrations-financing-tbi-bnpl-authorize-application-status-callback',
                'title' => 'tbi BNPL: authorize token + register application + status callback receiver',
                'category' => 'Integrations / Financing',
                'tags' => ['provider-tbi', 'domain-financing', 'stack-laravel', 'pattern-token-auth', 'pattern-status-callback', 'pattern-webhook-receiver', 'pattern-idempotency', 'pattern-reconciliation'],
                'purpose' => 'End-to-end tbi merchant API integration: token auth, application registration, async status updates.',
                'flow' => [
                    'Authorize: obtain token.',
                    'RegisterApplication: send order/customer payload with token + subscription key headers.',
                    'Receive ApplicationStatusNotification to your status URL and update order state.',
                ],
                'pitfalls' => [
                    'Not caching token and spamming authorize.',
                    'Not deduping status callbacks (retries may occur).',
                ],
                'resources' => [
                    ['label' => 'tbi merchant API page', 'url' => 'https://tbibank.bg/repayment-method/api/', 'text' => 'Public tbi merchant API integration landing page.'],
                    ['label' => 'tbi integration specification PDF', 'url' => 'https://tbibank.gr/wp-content/uploads/2024/03/tbi_GR_Integration_Description_v5_20240228.pdf', 'text' => 'Spec covering authorize, application registration, status notifications, return endpoint.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Status callback endpoint skeleton',
                        'language' => 'php',
                        'filename' => 'routes/api.php',
                        'description' => 'Receive status notifications and map into DB.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/tbi/status', function (Request $request) {
    // Verify contract-specific security mechanism, then update local state.
    return response()->json(['status' => 'OK']);
});
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-financing-tbi-token-caching-expires-in',
                'title' => 'tbi token caching: cache Authorization token using expires_in',
                'category' => 'Integrations / Financing',
                'tags' => ['provider-tbi', 'domain-financing', 'stack-laravel', 'pattern-token-cache', 'pattern-expires-in', 'pattern-api-client', 'pattern-rate-limit'],
                'purpose' => 'Avoid excessive authorize calls by caching token based on expires_in.',
                'flow' => [
                    'Call authorize endpoint; read token + expires_in.',
                    'Cache token with TTL (expires_in minus safety buffer).',
                    'Reuse cached token for subsequent calls.',
                ],
                'pitfalls' => [
                    'Caching without safety buffer and randomly failing on expiry.',
                    'Hard-coding TTL ignoring expires_in value.',
                ],
                'resources' => [
                    ['label' => 'tbi integration spec (authorize + expires_in)', 'url' => 'https://tbibank.gr/wp-content/uploads/2024/03/tbi_GR_Integration_Description_v5_20240228.pdf', 'text' => 'Authorize response includes expires_in for token lifetime.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Laravel cache token with TTL',
                        'language' => 'php',
                        'filename' => 'app/Services/Financing/TbiTokenStore.php',
                        'description' => 'Cache token with safety buffer.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Support\Facades\Cache;

$ttlSeconds = max(60, $expiresIn - 60);
Cache::put('tbi:token', $token, $ttlSeconds);
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-financing-tbi-application-status-callback-status-codes',
                'title' => 'tbi ApplicationStatusNotification: idempotent receiver + status mapping',
                'category' => 'Integrations / Financing',
                'tags' => ['provider-tbi', 'domain-financing', 'stack-laravel', 'pattern-callback', 'pattern-idempotency', 'pattern-status-mapping', 'pattern-queueing'],
                'purpose' => 'Make the callback endpoint idempotent and map bank statuses to your order/application states.',
                'flow' => [
                    'Receive status notification payload.',
                    'Deduplicate by creditApplicationId/orderId + statusId.',
                    'Map to local domain states; enqueue side effects.',
                ],
                'pitfalls' => [
                    'Processing same status repeatedly due to retries.',
                    'No mapping table => “unknown status” chaos in support/finance.',
                ],
                'resources' => [
                    ['label' => 'tbi spec: ApplicationStatusNotification', 'url' => 'https://tbibank.gr/wp-content/uploads/2024/03/tbi_GR_Integration_Description_v5_20240228.pdf', 'text' => 'Spec describes callback payload and status changes behavior.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Deduplication key idea',
                        'language' => 'bash',
                        'filename' => 'docs/examples/tbi-dedupe-key.txt',
                        'description' => 'Stable key strategy.',
                        'code' => <<<'BASH'
# dedupe key example:
# "tbi:{creditApplicationId}:{statusId}"
BASH,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-financing-tbi-goods-availability-order-confirmation',
                'title' => 'tbi goods availability check: respond 200/4xx for Order Confirmation status',
                'category' => 'Integrations / Financing',
                'tags' => ['provider-tbi', 'domain-financing', 'stack-laravel', 'pattern-goods-check', 'pattern-status-gate', 'pattern-http-codes', 'pattern-ops'],
                'purpose' => 'Implement the “goods availability” check when tbi requests confirmation before proceeding.',
                'flow' => [
                    'tbi calls your endpoint when status indicates Order Confirmation.',
                    'Return HTTP 200 if goods are available.',
                    'Return 4xx/5xx if goods are not available.',
                ],
                'pitfalls' => [
                    'Always returning 200 (finance approves orders you cannot fulfill).',
                    'No inventory lock -> inconsistent “availability” result.',
                ],
                'resources' => [
                    ['label' => 'tbi spec: goods availability check', 'url' => 'https://tbibank.gr/wp-content/uploads/2024/03/tbi_GR_Integration_Description_v5_20240228.pdf', 'text' => 'Spec: merchant must return 200 if goods available, else 4xx/5xx.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Inventory gate example',
                        'language' => 'php',
                        'filename' => 'routes/api.php',
                        'description' => 'Return correct status based on stock.',
                        'code' => <<<'PHP'
<?php

if (! $inStock) {
    return response()->json(['error' => 'Out of stock'], 409);
}

return response()->json(['ok' => true], 200);
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'finance',
                'slug' => 'integrations-financing-tbi-full-return-application-return',
                'title' => 'tbi full return: POST /v1/Application/return pattern',
                'category' => 'Integrations / Financing',
                'tags' => ['provider-tbi', 'domain-financing', 'stack-laravel', 'pattern-return', 'pattern-full-return', 'pattern-reversal', 'pattern-reconciliation'],
                'purpose' => 'Implement full return flow using the documented return endpoint.',
                'flow' => [
                    'Capture the conditions under which return is allowed.',
                    'POST to Application/return with required identifiers.',
                    'Update local order state and ledger entries.',
                ],
                'pitfalls' => [
                    'Doing return without updating your accounting state.',
                    'Not persisting bank response for audit.',
                ],
                'resources' => [
                    ['label' => 'tbi spec: Full return endpoint', 'url' => 'https://tbibank.gr/wp-content/uploads/2024/03/tbi_GR_Integration_Description_v5_20240228.pdf', 'text' => 'Spec includes POST /v1/Application/return.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Return endpoint call checklist',
                        'language' => 'bash',
                        'filename' => 'docs/examples/tbi-return.txt',
                        'description' => 'Minimal process outline.',
                        'code' => <<<'BASH'
# POST Application/return
# persist response
# update local order + finance ledger
BASH,
                    ],
                ],
            ],

            // AI / MCP — 8
            [
                'owner' => 'manager',
                'slug' => 'ai-mcp-laravel-mcp-tool-contract-search-and-create',
                'title' => 'Laravel MCP tools for your KB: search_knowledge_base + create_knowledge_entry contracts',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-tools', 'pattern-rag', 'pattern-validation', 'pattern-schema', 'pattern-security'],
                'purpose' => 'Define two MCP tools: search (vector retrieval) and create (document a new pattern safely).',
                'flow' => [
                    'Tool 1: search_knowledge_base(query, top_k, filters) returns items + code snippets.',
                    'Tool 2: create_knowledge_entry(payload) validates and persists a new knowledge item.',
                    'Both tools must return structured JSON for Claude Code clients.',
                ],
                'pitfalls' => [
                    'Returning prose instead of schema-driven JSON (poor tool UX).',
                    'Letting AI insert unsafe links or hallucinated requirements without validation.',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP docs', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Tool schemas, server registration, auth, testing.'],
                    ['label' => 'MCP build server guide', 'url' => 'https://modelcontextprotocol.io/docs/develop/build-server', 'text' => 'MCP server building concepts and constraints.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Tool input shape example',
                        'language' => 'json',
                        'filename' => 'docs/examples/mcp-search-tool.json',
                        'description' => 'Example tool call arguments.',
                        'code' => <<<'JSON'
{
  "query": "stripe webhook signature verification",
  "top_k": 8,
  "category": "Integrations / Payments",
  "tags": ["provider-stripe", "pattern-webhooks"]
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-mcp-claude-code-config-project-mcp-json',
                'title' => 'Claude Code MCP config: project .mcp.json and ~/.claude.json basics',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-mcp', 'pattern-config', 'pattern-http-transport', 'pattern-auth-headers', 'claude-code', 'pattern-project-scope'],
                'purpose' => 'Make the MCP server discoverable by Claude Code via correct config files and HTTP headers.',
                'flow' => [
                    'Use project .mcp.json for project-scoped servers.',
                    'Use ~/.claude.json for user/local scope.',
                    'Configure HTTP server URL and Authorization header.',
                ],
                'pitfalls' => [
                    'Configuring the wrong app (Claude Desktop config is separate).',
                    'Forgetting auth headers when server is protected.',
                ],
                'resources' => [
                    ['label' => 'Claude Code MCP docs', 'url' => 'https://code.claude.com/docs/en/mcp', 'text' => 'How Claude Code connects to MCP servers.'],
                    ['label' => 'Claude Code settings', 'url' => 'https://code.claude.com/docs/en/settings', 'text' => 'Explains ~/.claude.json and project .mcp.json storage.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Project .mcp.json example',
                        'language' => 'json',
                        'filename' => '.mcp.json',
                        'description' => 'HTTP server config with auth header.',
                        'code' => <<<'JSON'
{
  "mcpServers": {
    "knowledge-base": {
      "type": "http",
      "url": "http://localhost:8000/mcp/knowledge",
      "headers": {
        "Authorization": "Bearer ${MCP_TOKEN}"
      }
    }
  }
}
JSON,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-mcp-laravel-mcp-make-server-and-register-web-local',
                'title' => 'Laravel MCP servers: register web vs local servers (Mcp::web / Mcp::local)',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-server-registration', 'pattern-web', 'pattern-local', 'pattern-throttle', 'pattern-routes'],
                'purpose' => 'Expose MCP servers via HTTP (web) or as local Artisan-managed servers.',
                'flow' => [
                    'Generate server class.',
                    'Register via routes/ai.php using Mcp::web or Mcp::local.',
                    'Apply middleware like throttle when needed.',
                ],
                'pitfalls' => [
                    'Putting MCP routes in routes/web.php instead of routes/ai.php.',
                    'Misunderstanding local server lifecycle (client usually starts it).',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP: server registration', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Documents Mcp::web and Mcp::local and middleware usage.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'routes/ai.php registration example',
                        'language' => 'php',
                        'filename' => 'routes/ai.php',
                        'description' => 'Register web and local servers.',
                        'code' => <<<'PHP'
<?php

use Laravel\Mcp\Facades\Mcp;
use App\Mcp\Servers\KnowledgeBaseServer;

Mcp::web('/mcp/knowledge', KnowledgeBaseServer::class)->middleware(['throttle:mcp']);
Mcp::local('knowledge', KnowledgeBaseServer::class);
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-mcp-laravel-mcp-make-tool-jsonschema',
                'title' => 'Laravel MCP tools: JsonSchema input + Description/Name/Title attributes',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-jsonschema', 'pattern-tool-schema', 'pattern-description', 'pattern-naming', 'pattern-typed-input'],
                'purpose' => 'Define strongly-shaped tool inputs so Claude Code can call tools reliably.',
                'flow' => [
                    'Create tool class.',
                    'Implement schema(JsonSchema $schema): array.',
                    'Add Description attribute; optionally Name/Title.',
                ],
                'pitfalls' => [
                    'Leaving schema too loose (agent calls tool incorrectly).',
                    'No Description => tool misuse.',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP: tools and schema', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Tool schema examples using JsonSchema.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Tool schema skeleton',
                        'language' => 'php',
                        'filename' => 'app/Mcp/Tools/SearchKnowledgeBaseTool.php',
                        'description' => 'JsonSchema input definition.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Searches the knowledge base using vector similarity.')]
class SearchKnowledgeBaseTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'top_k' => $schema->integer(),
        ];
    }
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'ai-mcp-laravel-mcp-tool-responses-errors-metadata',
                'title' => 'Laravel MCP tool responses: Response::text, Response::error, multi-content, withMeta',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-responses', 'pattern-errors', 'pattern-metadata', 'pattern-multi-content', 'pattern-observability'],
                'purpose' => 'Return structured, debuggable tool responses and handle error states predictably.',
                'flow' => [
                    'Use Response::error for tool failures.',
                    'Optionally return multiple Response instances for multi-part outputs.',
                    'Attach meta for debugging (source, cached, etc.).',
                ],
                'pitfalls' => [
                    'Throwing exceptions without returning tool-safe errors.',
                    'Returning huge payloads without snippets/meta.',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP: Response helpers', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Documents Response::error and multi-content responses.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Error + multi-response example',
                        'language' => 'php',
                        'filename' => 'app/Mcp/Tools/ExampleTool.php',
                        'description' => 'Return Response::error or multiple Response objects.',
                        'code' => <<<'PHP'
<?php

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

public function handle(Request $request): array
{
    if (! $request->user()) {
        return [Response::error('Unauthorized')];
    }

    return [
        Response::text('Summary')->withMeta(['cached' => true]),
        Response::text('Details...'),
    ];
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-mcp-laravel-mcp-streaming-sse-yield',
                'title' => 'Laravel MCP streaming: yield notifications + text over SSE for web servers',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-streaming', 'pattern-sse', 'pattern-progress', 'pattern-long-running', 'pattern-ux'],
                'purpose' => 'Stream progress for long-running tools without blocking clients.',
                'flow' => [
                    'Implement tool handle() as generator yielding Response messages.',
                    'Yield progress notifications and partial text.',
                    'Client receives SSE stream automatically for web servers.',
                ],
                'pitfalls' => [
                    'Blocking for minutes without streaming progress.',
                    'Writing to stdout in a way that corrupts protocol messages (in stdio transports).',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP: streaming uses SSE for web servers', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Docs: streaming responses open SSE stream for web servers.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Yield progress notifications',
                        'language' => 'php',
                        'filename' => 'app/Mcp/Tools/LongRunningTool.php',
                        'description' => 'Yield Response::notification and Response::text.',
                        'code' => <<<'PHP'
<?php

use Laravel\Mcp\Response;

public function handle(): \Generator
{
    yield Response::notification('processing/progress', ['current' => 1, 'total' => 3]);
    yield Response::text('Step 1 done');

    yield Response::notification('processing/progress', ['current' => 2, 'total' => 3]);
    yield Response::text('Step 2 done');

    yield Response::text('Complete');
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'ai-mcp-laravel-mcp-auth-oauth-passport-vs-sanctum',
                'title' => 'Laravel MCP auth: OAuth (Passport + Mcp::oauthRoutes) vs auth:sanctum',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-auth', 'pattern-oauth', 'pattern-passport', 'pattern-sanctum', 'pattern-bearer-token'],
                'purpose' => 'Protect MCP endpoints correctly depending on client capability.',
                'flow' => [
                    'If possible, use OAuth (Passport) and Mcp::oauthRoutes.',
                    'Apply auth middleware to Mcp::web route.',
                    'If already on Sanctum, use auth:sanctum and require Authorization: Bearer <token>.',
                ],
                'pitfalls' => [
                    'Exposing MCP tools without auth (prompt injection becomes worse).',
                    'Using OAuth clients that don’t support the expected flow.',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP: OAuth 2.1 + Mcp::oauthRoutes', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Docs show oauthRoutes and auth:api middleware for Passport.'],
                    ['label' => 'Laravel MCP: Sanctum auth option', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Docs show auth:sanctum and bearer tokens.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Passport OAuth routes + protected MCP server',
                        'language' => 'php',
                        'filename' => 'routes/ai.php',
                        'description' => 'Protect MCP server with OAuth.',
                        'code' => <<<'PHP'
<?php

use Laravel\Mcp\Facades\Mcp;
use App\Mcp\Servers\KnowledgeBaseServer;

Mcp::oauthRoutes();

Mcp::web('/mcp/knowledge', KnowledgeBaseServer::class)->middleware('auth:api');
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-mcp-laravel-mcp-inspector-and-unit-testing',
                'title' => 'Laravel MCP testing: mcp:inspector + unit tests for tool calls',
                'category' => 'AI / MCP',
                'tags' => ['domain-ai', 'stack-laravel', 'stack-mcp', 'pattern-testing', 'pattern-inspector', 'pattern-unit-tests', 'pattern-auth-headers', 'pattern-ci'],
                'purpose' => 'Make MCP changes safe: test tools interactively and in CI.',
                'flow' => [
                    'Run php artisan mcp:inspector <server> to test quickly.',
                    'Copy settings into MCP client if needed.',
                    'Write unit tests invoking Server::tool() for deterministic behavior.',
                ],
                'pitfalls' => [
                    'Shipping MCP tool changes without tests: you break Claude Code workflows silently.',
                ],
                'resources' => [
                    ['label' => 'Laravel MCP: MCP Inspector', 'url' => 'https://laravel.com/docs/12.x/mcp', 'text' => 'Docs show using mcp:inspector and unit test examples.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Inspector commands',
                        'language' => 'bash',
                        'filename' => 'docs/examples/mcp-inspector.sh',
                        'description' => 'Run MCP Inspector for web or local servers.',
                        'code' => <<<'BASH'
php artisan mcp:inspector mcp/knowledge
php artisan mcp:inspector knowledge
BASH,
                    ],
                ],
            ],

            // AI / Embeddings + Data / Search — 6
            [
                'owner' => 'security',
                'slug' => 'data-vector-search-pgvector-indexing-hnsw-ivfflat',
                'title' => 'pgvector in Postgres: HNSW vs IVFFlat tradeoffs and cosine search',
                'category' => 'Data / Vector Search',
                'tags' => ['domain-search', 'stack-pgvector', 'stack-postgres', 'pattern-ann', 'pattern-hnsw', 'pattern-ivfflat', 'pattern-cosine', 'pattern-indexing'],
                'purpose' => 'Choose the right index strategy for embeddings and understand speed/recall tradeoffs.',
                'flow' => [
                    'Store embeddings in vector column.',
                    'Choose ANN index: HNSW or IVFFlat.',
                    'Tune for recall vs speed and monitor latency.',
                ],
                'pitfalls' => [
                    'No index -> slow at scale.',
                    'Wrong operator class / distance metric mismatch.',
                ],
                'resources' => [
                    ['label' => 'pgvector README', 'url' => 'https://github.com/pgvector/pgvector', 'text' => 'Documents IVFFlat and HNSW behaviors and tuning ideas.'],
                    ['label' => 'pgvector on Azure Postgres', 'url' => 'https://learn.microsoft.com/en-us/azure/postgresql/extensions/how-to-use-pgvector', 'text' => 'Explains pgvector concepts and querying in Postgres variants.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Create HNSW index',
                        'language' => 'sql',
                        'filename' => 'docs/examples/pgvector-hnsw.sql',
                        'description' => 'SQL to create vector extension and index.',
                        'code' => <<<'SQL'
CREATE EXTENSION IF NOT EXISTS vector;
-- Create HNSW index (operator class depends on distance metric)
-- Example assumes cosine opclass available in your pgvector version.
SQL,
                    ],
                ],
            ],
            [
                'owner' => 'owner',
                'slug' => 'ai-embeddings-laravel-ai-sdk-generate-store-query',
                'title' => 'Laravel AI SDK embeddings: generate + store + query with whereVectorSimilarTo',
                'category' => 'AI / Embeddings',
                'tags' => ['domain-ai', 'domain-search', 'stack-laravel', 'stack-pgvector', 'pattern-embeddings', 'pattern-index', 'pattern-similarity', 'pattern-querying'],
                'purpose' => 'Use Laravel AI SDK to generate embeddings and query via Postgres pgvector.',
                'flow' => [
                    'Define vector column (dimensions: 1536).',
                    'Generate embeddings from text.',
                    'Query using whereVectorSimilarTo(minSimilarity).',
                ],
                'pitfalls' => [
                    'Not indexing vector column; results become slow quickly.',
                    'Not casting embedding column properly; you’ll fight type issues.',
                ],
                'resources' => [
                    ['label' => 'AI SDK: querying embeddings and vectors', 'url' => 'https://laravel.com/docs/12.x/ai-sdk', 'text' => 'Docs show vector columns, auto HNSW index, whereVectorSimilarTo.'],
                    ['label' => 'Query builder: vector similarity clauses', 'url' => 'https://laravel.com/docs/12.x/queries', 'text' => 'Documents whereVectorSimilarTo and minSimilarity behavior.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Migration vector column + auto HNSW index',
                        'language' => 'php',
                        'filename' => 'database/migrations/xxxx_create_documents.php',
                        'description' => 'Define vector column and index.',
                        'code' => <<<'PHP'
<?php

Schema::ensureVectorExtensionExists();

Schema::create('documents', function ($table) {
    $table->id();
    $table->text('content');
    $table->vector('embedding', dimensions: 1536)->index();
    $table->timestamps();
});
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-llm-providers-laravel-ai-sdk-provider-config-and-base-urls',
                'title' => 'Laravel AI SDK provider config: supported providers + custom base URLs',
                'category' => 'AI / Embeddings',
                'tags' => ['domain-ai', 'stack-laravel', 'laravel-ai-sdk', 'pattern-provider-config', 'pattern-env-vars', 'pattern-custom-base-url', 'pattern-proxy', 'pattern-ops'],
                'purpose' => 'Centralize AI provider configuration and support proxies/gateways via custom base URLs.',
                'flow' => [
                    'Install laravel/ai.',
                    'Configure provider keys via env/config.',
                    'Optionally set provider url to route through gateway/proxy.',
                ],
                'pitfalls' => [
                    'Hard-coding provider strings; inconsistent multi-provider behavior.',
                    'No separation between dev and prod keys/URLs.',
                ],
                'resources' => [
                    ['label' => 'Laravel AI SDK docs', 'url' => 'https://laravel.com/docs/12.x/ai-sdk', 'text' => 'Docs cover provider support and custom base URLs.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Provider config snippet',
                        'language' => 'php',
                        'filename' => 'config/ai.php',
                        'description' => 'Example provider configuration.',
                        'code' => <<<'PHP'
<?php

return [
  'providers' => [
    'openai' => [
      'driver' => 'openai',
      'key' => env('OPENAI_API_KEY'),
      'url' => env('OPENAI_BASE_URL'),
    ],
  ],
];
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'owner',
                'slug' => 'ai-embeddings-laravel-ai-sdk-embedding-caching',
                'title' => 'Laravel AI SDK embeddings caching: config-based cache + toEmbeddings(cache: ...)',
                'category' => 'AI / Embeddings',
                'tags' => ['domain-ai', 'stack-laravel', 'laravel-ai-sdk', 'pattern-caching', 'pattern-cost-control', 'pattern-determinism', 'pattern-embeddings', 'pattern-cache-store'],
                'purpose' => 'Reduce embedding cost and latency by caching identical inputs.',
                'flow' => [
                    'Enable ai.caching.embeddings.cache.',
                    'Choose cache store (database/redis/etc).',
                    'Use toEmbeddings(cache: true|seconds) for request-level caching.',
                ],
                'pitfalls' => [
                    'Caching without including model/dimensions in cache key (AI SDK handles this; don’t reimplement badly).',
                ],
                'resources' => [
                    ['label' => 'AI SDK: caching embeddings', 'url' => 'https://laravel.com/docs/12.x/ai-sdk', 'text' => 'Docs: embeddings cached for 30 days when enabled; toEmbeddings(cache).'],
                ],
                'code_examples' => [
                    [
                        'title' => 'toEmbeddings with cache',
                        'language' => 'php',
                        'filename' => 'app/Actions/Knowledge/GenerateEmbeddings.php',
                        'description' => 'Generate embeddings with caching enabled.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Support\Str;

$vector = Str::of('Example text')->toEmbeddings(cache: 3600);
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'data-search-hybrid-postgres-full-text-and-vector-reranking',
                'title' => 'Hybrid search: PostgreSQL full-text prefilter + vector reranking (2-stage retrieval)',
                'category' => 'Data / Search',
                'tags' => ['domain-search', 'stack-postgres', 'stack-laravel', 'pattern-full-text', 'pattern-tsvector', 'pattern-hybrid', 'pattern-reranking', 'pattern-2-stage'],
                'purpose' => 'Fast + relevant: use full-text to prefilter, then vector/reranking to reorder.',
                'flow' => [
                    'Stage 1: full-text search using tsvector/tsquery.',
                    'Stage 2: rerank results by semantic relevance (vector similarity / AI reranking).',
                ],
                'pitfalls' => [
                    'Only vector search: slow/costly at large candidate sets.',
                    'Only keyword search: misses semantic matches.',
                ],
                'resources' => [
                    ['label' => 'PostgreSQL text search types', 'url' => 'https://www.postgresql.org/docs/current/datatype-textsearch.html', 'text' => 'tsvector and tsquery types for full-text search.'],
                    ['label' => 'PostgreSQL text search intro', 'url' => 'https://www.postgresql.org/docs/current/textsearch-intro.html', 'text' => '@@ match operator and basic matching.',
                    ],
                    ['label' => 'Laravel search docs (vector + reranking)', 'url' => 'https://laravel.com/docs/12.x/search', 'text' => 'Laravel documents vector search + reranking as complementary stages.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Full-text match operator concept',
                        'language' => 'sql',
                        'filename' => 'docs/examples/postgres-fulltext.sql',
                        'description' => 'tsvector @@ tsquery concept snippet.',
                        'code' => <<<'SQL'
-- tsvector @@ tsquery
-- SELECT ... WHERE to_tsvector('english', body) @@ plainto_tsquery('english', 'webhook signature');
SQL,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'ai-embeddings-dimension-upgrade-migration-backfill-strategy',
                'title' => 'Embedding dimension upgrades: dual-column migration + async backfill strategy',
                'category' => 'AI / Embeddings',
                'tags' => ['domain-ai', 'domain-search', 'stack-laravel', 'stack-pgvector', 'pattern-dimensions', 'pattern-migration', 'pattern-backfill', 'pattern-zero-downtime'],
                'purpose' => 'Safely move from one embedding dimension to another without breaking reads or blocking writes.',
                'flow' => [
                    'Add a new vector column with the target dimensions (keep old column active).',
                    'Backfill new embeddings in background jobs in deterministic batches.',
                    'Switch retrieval to the new column once coverage and relevance checks pass.',
                    'Drop legacy column and index after cutover.',
                ],
                'pitfalls' => [
                    'Changing dimensions in place and breaking inserts/queries during rollout.',
                    'Cutting over before backfill completion, causing partial or stale retrieval quality.',
                ],
                'resources' => [
                    ['label' => 'Laravel AI SDK docs', 'url' => 'https://laravel.com/docs/12.x/ai-sdk', 'text' => 'Docs cover vector columns and embedding generation in Laravel.'],
                    ['label' => 'pgvector README', 'url' => 'https://github.com/pgvector/pgvector', 'text' => 'Documents vector column behavior and index considerations.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Dual-column migration idea',
                        'language' => 'php',
                        'filename' => 'database/migrations/xxxx_add_embedding_v2_to_chunks.php',
                        'description' => 'Add new embedding column and index before async backfill.',
                        'code' => <<<'PHP'
<?php

Schema::table('knowledge_chunks', function ($table): void {
    $table->vector('embedding_v2', dimensions: 3072)->nullable()->index();
});
PHP,
                    ],
                ],
            ],

            // Popular integrations — 5
            [
                'owner' => 'owner',
                'slug' => 'integrations-storage-aws-s3-laravel-filesystem-disks',
                'title' => 'S3 storage in Laravel: disk config + uploads + signed URLs',
                'category' => 'Integrations / Storage',
                'tags' => ['provider-aws', 'domain-storage', 'stack-laravel', 'pattern-filesystem', 'pattern-s3', 'pattern-uploads', 'pattern-signed-urls', 'pattern-public-private'],
                'purpose' => 'Store resources and generated PDFs/labels in S3-compatible object storage.',
                'flow' => [
                    'Configure S3 disk in filesystems config.',
                    'Upload files via Storage facade.',
                    'Generate temporary signed URLs for private assets.',
                ],
                'pitfalls' => [
                    'Public bucket when you need private customer documents.',
                    'No lifecycle rules -> storage cost creep.',
                ],
                'resources' => [
                    ['label' => 'Laravel filesystem docs', 'url' => 'https://laravel.com/docs/12.x/filesystem', 'text' => 'Laravel filesystem configuration, S3 disks, URLs.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Temporary URL example',
                        'language' => 'php',
                        'filename' => 'app/Actions/Storage/GetTemporaryUrl.php',
                        'description' => 'Generate signed URL for private file.',
                        'code' => <<<'PHP'
<?php

use Illuminate\Support\Facades\Storage;

$url = Storage::disk('s3')->temporaryUrl('knowledge/labels/123.pdf', now()->addMinutes(10));
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'support',
                'slug' => 'integrations-messaging-slack-incoming-webhooks-laravel-notifications',
                'title' => 'Slack alerts in Laravel: incoming webhook + slack-notification-channel',
                'category' => 'Integrations / Messaging',
                'tags' => ['provider-slack', 'domain-alerting', 'stack-laravel', 'pattern-notifications', 'pattern-webhooks', 'pattern-ops-alerts', 'pattern-escalation', 'pattern-oncall'],
                'purpose' => 'Notify ops/support when shipments fail, payments dispute, or MCP tool errors spike.',
                'flow' => [
                    'Create Slack Incoming Webhook URL.',
                    'Install laravel/slack-notification-channel.',
                    'Send notifications via Laravel Notifications.',
                ],
                'pitfalls' => [
                    'Hard-coding webhook URL in code (use env).',
                    'Sending noisy alerts without rate limiting/aggregation.',
                ],
                'resources' => [
                    ['label' => 'Slack incoming webhooks', 'url' => 'https://api.slack.com/messaging/webhooks', 'text' => 'Slack Incoming Webhooks documentation.'],
                    ['label' => 'Laravel Slack notification channel', 'url' => 'https://github.com/laravel/slack-notification-channel', 'text' => 'Official Laravel Slack notification channel package.'],
                    ['label' => 'Laravel notifications docs', 'url' => 'https://laravel.com/docs/12.x/notifications', 'text' => 'Notifications architecture and channels.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Send a Slack notification',
                        'language' => 'php',
                        'filename' => 'app/Notifications/OpsAlert.php',
                        'description' => 'Skeleton notification.',
                        'code' => <<<'PHP'
<?php

// Notification::route('slack', config('services.slack.webhook_url'))->notify(new OpsAlert(...));
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'integrations-identity-github-oauth-laravel-socialite',
                'title' => 'GitHub OAuth in Laravel: Socialite redirect + callback + user linking',
                'category' => 'Integrations / Identity',
                'tags' => ['provider-github', 'domain-identity', 'stack-laravel', 'pattern-oauth', 'pattern-social-login', 'pattern-account-linking', 'pattern-security', 'laravel-socialite'],
                'purpose' => 'Authenticate internal users/admins via OAuth and link provider identity safely.',
                'flow' => [
                    'Redirect to provider via Socialite.',
                    'Handle callback and fetch user profile.',
                    'Link provider user id to local user (unique constraint).',
                ],
                'pitfalls' => [
                    'Linking by email alone (unsafe; emails change).',
                    'No unique constraint on provider_id -> account takeover risk.',
                ],
                'resources' => [
                    ['label' => 'Laravel Socialite docs', 'url' => 'https://laravel.com/docs/12.x/socialite', 'text' => 'Socialite OAuth integration guide.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Redirect and callback (concept)',
                        'language' => 'php',
                        'filename' => 'routes/web.php',
                        'description' => 'Socialite flow skeleton.',
                        'code' => <<<'PHP'
<?php

// Route::get('/auth/github', fn () => Socialite::driver('github')->redirect());
// Route::get('/auth/github/callback', fn () => Socialite::driver('github')->user());
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'manager',
                'slug' => 'data-search-engines-laravel-scout-meilisearch',
                'title' => 'Laravel Scout + Meilisearch: indexing models + filtering + search UX',
                'category' => 'Data / Search',
                'tags' => ['provider-meilisearch', 'domain-search', 'stack-laravel', 'pattern-scout', 'pattern-indexing', 'pattern-filtering', 'pattern-search-ui', 'pattern-sync'],
                'purpose' => 'Keyword search complement to vector search (fast filtering, facets).',
                'flow' => [
                    'Install and configure Scout driver.',
                    'Define searchable model representation (toSearchableArray).',
                    'Index and query with filters as needed.',
                ],
                'pitfalls' => [
                    'Indexing whole markdown blobs unbounded (size and performance).',
                    'No sync strategy (stale indexes).',
                ],
                'resources' => [
                    ['label' => 'Laravel Scout docs', 'url' => 'https://laravel.com/docs/12.x/scout', 'text' => 'Scout indexing and driver configuration.'],
                    ['label' => 'Meilisearch Laravel integration guide', 'url' => 'https://www.meilisearch.com/docs/learn/resources/laravel', 'text' => 'Meilisearch usage patterns in Laravel.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'Model indexing concept',
                        'language' => 'php',
                        'filename' => 'app/Models/KnowledgeItem.php',
                        'description' => 'Scout searchable representation idea.',
                        'code' => <<<'PHP'
<?php

public function toSearchableArray(): array
{
    return [
        'title' => $this->title,
        'category' => $this->category,
        'tags' => $this->tags,
    ];
}
PHP,
                    ],
                ],
            ],
            [
                'owner' => 'security',
                'slug' => 'integrations-observability-sentry-laravel-sdk',
                'title' => 'Sentry in Laravel: error monitoring + performance tracing basics',
                'category' => 'Integrations / Observability',
                'tags' => ['provider-sentry', 'domain-observability', 'stack-laravel', 'pattern-error-monitoring', 'pattern-tracing', 'pattern-releases', 'pattern-env', 'pattern-alerting'],
                'purpose' => 'Catch integration failures early (webhooks, API calls, queues) and track regressions.',
                'flow' => [
                    'Install sentry-laravel.',
                    'Configure DSN via env.',
                    'Enable tracing carefully with sampling.',
                ],
                'pitfalls' => [
                    'Logging secrets (webhook payloads with PII) into Sentry.',
                    'Tracing everything at 100% and exploding costs.',
                ],
                'resources' => [
                    ['label' => 'Sentry Laravel installation/config', 'url' => 'https://docs.sentry.io/platforms/php/guides/laravel/configuration/', 'text' => 'Official Sentry config docs for Laravel.'],
                    ['label' => 'sentry-laravel GitHub', 'url' => 'https://github.com/getsentry/sentry-laravel', 'text' => 'Official Sentry Laravel SDK repo.'],
                ],
                'code_examples' => [
                    [
                        'title' => 'ENV config concept',
                        'language' => 'bash',
                        'filename' => 'docs/examples/sentry-env.txt',
                        'description' => 'Minimal env keys.',
                        'code' => <<<'BASH'
SENTRY_LARAVEL_DSN=https://examplePublicKey@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.1
BASH,
                    ],
                ],
            ],
        ];

        // Sanity: enforce exactly 50.
        if (count($catalog) !== 50) {
            throw new \RuntimeException('knowledgeEntries() must return exactly 50 items; got '.count($catalog));
        }

        return array_map(function (array $e): array {
            return [
                'owner' => $e['owner'],
                'slug' => $e['slug'],
                'title' => $e['title'],
                'category' => $e['category'],
                'tags' => $e['tags'],
                'content_markdown' => $this->renderEntryMarkdown(
                    title: $e['title'],
                    purpose: $e['purpose'],
                    flow: $e['flow'],
                    pitfalls: $e['pitfalls'],
                    references: $e['resources'],
                ),
                'code_examples' => $e['code_examples'],
                'resources' => array_map(fn (array $r) => [
                    'label' => $r['label'],
                    'url' => $r['url'],
                    'extracted_text' => $r['text'],
                ], $e['resources']),
            ];
        }, $catalog);
    }

    /**
     * @param  array<int, string>  $flow
     * @param  array<int, string>  $pitfalls
     * @param  array<int, array{label:string,url:string,text:string}>  $references
     */
    private function renderEntryMarkdown(
        string $title,
        string $purpose,
        array $flow,
        array $pitfalls,
        array $references,
    ): string {
        $flowLines = '';
        foreach ($flow as $i => $line) {
            $flowLines .= ($i + 1).') '.$line."\n";
        }

        $pitfallLines = '';
        foreach ($pitfalls as $p) {
            $pitfallLines .= '- '.$p."\n";
        }

        $refLines = '';
        foreach ($references as $r) {
            $refLines .= '- '.$r['label'].': '.$r['url']."\n";
        }

        return <<<MD
# {$title}

## Purpose
{$purpose}

## Canonical flow
{$flowLines}
## Pitfalls
{$pitfallLines}
## References
{$refLines}
MD;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function buildArticleChunkText(
        string $title,
        string $category,
        array $tags,
        string $markdown
    ): string {
        $plain = preg_replace('/[`#>*_|-]+/', ' ', $markdown) ?? $markdown;
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;

        return trim(implode("\n", [
            "Title: {$title}",
            "Category: {$category}",
            'Tags: '.implode(', ', $tags),
            '',
            Str::limit(trim($plain), 1200, '...'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function chunkRow(
        int $knowledgeItemId,
        string $sourceType,
        ?int $sourceId,
        string $chunkKind,
        int $chunkIndex,
        string $text,
        array $meta,
        string $titleText,
        string $categoryText,
        string $tagsText,
        string $vector,
        int $dimensions,
        \DateTimeInterface $timestamp
    ): array {
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($metaJson)) {
            $metaJson = '{}';
        }

        return [
            'knowledge_item_id' => $knowledgeItemId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'chunk_index' => $chunkIndex,
            'chunk_kind' => $chunkKind,
            'chunk_text' => $text,
            'title_text' => $titleText,
            'heading_path_text' => $this->headingPathTextFromMeta($meta),
            'tags_text' => $tagsText,
            'category_text' => $categoryText,
            'meta' => $metaJson,
            'chunk_hash' => hash('sha256', implode('|', [$knowledgeItemId, $sourceType, (string) $sourceId, $chunkIndex, $text])),
            'token_count' => str_word_count($text),
            'embedding' => $vector,
            'embedding_model' => 'seeded-demo',
            'embedding_dimensions' => $dimensions,
            'embedded_at' => $timestamp,
            'embedding_attempts' => 1,
            'embedding_error' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @param  array<int, mixed>  $tags
     */
    private function normalizeTagText(array $tags): string
    {
        return collect($tags)
            ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag): string => trim($tag))
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function headingPathTextFromMeta(array $meta): string
    {
        if (! isset($meta['heading_path']) || ! is_array($meta['heading_path'])) {
            return '';
        }

        return collect($meta['heading_path'])
            ->filter(fn ($heading): bool => is_string($heading) && trim($heading) !== '')
            ->map(fn (string $heading): string => trim($heading))
            ->implode(' > ');
    }

    private function zeroVector(int $dimensions): string
    {
        static $cache = [];

        if (! isset($cache[$dimensions])) {
            $cache[$dimensions] = '['.implode(',', array_fill(0, $dimensions, '0')).']';
        }

        return $cache[$dimensions];
    }
}
