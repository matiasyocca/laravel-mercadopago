# Laravel Mercado Pago

Mercado Pago subscriptions for Laravel 13 SaaS, with a Filament 5 tenant billing provider, plus a small core for Checkout Pro payments and verified webhooks.

- **Billing:** a `Billable` trait for your tenant model, a local `mercadopago_subscriptions` table, trials, cancellation with a grace period, plan swaps, webhook sync and a reconciliation command.
- **Filament:** `->tenantBillingProvider(new MercadoPagoBillingProvider(plan: 'pro'))` and `->requiresTenantSubscription()`, nothing else.
- **Core:** Checkout Pro preferences, payment lookup, one signed webhook endpoint that dispatches events for every topic, and an in-memory fake for tests.

## Requirements

- PHP 8.5
- Laravel 13
- Filament 5
- `mercadopago/dx-php` 3.11+ (installed automatically)

## Installation

```bash
composer require matiasyocca/laravel-mercadopago
```

```bash
php artisan vendor:publish --tag=mercadopago-config
```

```bash
php artisan vendor:publish --tag=mercadopago-migrations
```

```bash
php artisan migrate
```

```env
MERCADOPAGO_ACCESS_TOKEN=APP_USR-...
MERCADOPAGO_WEBHOOK_SECRET=...
```

If your tenant keys are UUIDs or ULIDs, call `Schema::morphUsingUuids()` (or `morphUsingUlids()`) before running the migration, or edit the published migration.

## Plans

Plans live in `config/mercadopago.php`, not in Mercado Pago. Each checkout creates a Mercado Pago subscription *without* an associated plan, using these values, so there are no plan IDs to keep in sync between sandbox and production.

```php
'billing' => [
    'plans' => [
        'pro' => [
            'reason' => 'My SaaS Pro',       // Shown to the payer
            'amount' => 12000,
            'currency' => 'ARS',
            'frequency' => 1,
            'frequency_type' => 'months',    // "days" or "months"
            'trial' => ['frequency' => 14, 'frequency_type' => 'days'], // Optional
            'back_url' => null,              // Optional, Filament sets it to the panel
        ],
    ],
],
```

Changing a price only affects new checkouts. Move existing subscribers with `swap()`.

## Filament

Add the trait to the tenant model:

```php
use MatiasYocca\MercadoPago\Billing\Billable;

class Workshop extends Model
{
    use Billable;
}
```

Configure the panel:

```php
use MatiasYocca\MercadoPago\Filament\MercadoPagoBillingProvider;

$panel
    ->tenant(Workshop::class)
    ->tenantBillingProvider(new MercadoPagoBillingProvider(plan: 'pro'))
    ->requiresTenantSubscription();
```

What happens:

- A tenant without access is redirected to the panel's billing route.
- The billing route creates a pending Mercado Pago subscription and redirects to its checkout. Visiting it again reuses the same checkout.
- After authorizing, Mercado Pago sends the payer back to the panel, and the webhook marks the subscription `authorized`.
- A subscribed tenant visiting the billing route goes to `mercadopago.billing.manage_url` (for example `https://www.mercadopago.com.ar/subscriptions`), or back to the panel if that's not set. Mercado Pago has no customer portal, so payers change cards on Mercado Pago's side.

The payer email is the tenant's `email` attribute, falling back to the signed-in user's email. Override `mercadoPagoPayerEmail()` on the tenant to change it.

## Webhooks

The package registers `POST /mercadopago/webhook` (named `mercadopago.webhook`, in the `api` middleware group, so no CSRF token is needed).

In the Mercado Pago developer panel, under **Your integrations > Webhooks**:

1. Set the URL to `https://your-app.com/mercadopago/webhook`.
2. Enable **Payments** and **Plans and subscriptions**.
3. Copy the secret into `MERCADOPAGO_WEBHOOK_SECRET`.

Every request is checked against the `x-signature` header. Requests are rejected with a 401 while the secret is missing. The body is never trusted: the package re-fetches the subscription or payment from the API. If that fetch fails, the endpoint returns a 500 so Mercado Pago retries.

For local development, expose the app through a tunnel (for example `cloudflared tunnel --url http://localhost:8000`) and use the test credentials of a test seller.

### Reconciliation

Webhooks can be lost. Schedule the sync command:

```php
// routes/console.php
Schedule::command('mercadopago:sync-subscriptions')->daily();
```

It re-reads every subscription that isn't cancelled. Pass `--all` to include cancelled ones.

## Billing API

```php
// Start a checkout (outside Filament)
return $team->newSubscription('default', 'pro')
    ->payerEmail($request->user()->email)  // Optional
    ->backUrl(route('dashboard'))          // Optional
    ->skipTrial()                          // Optional
    ->checkout();                          // Responsable: redirects to Mercado Pago

// Check access
$team->subscribed();                  // Active, on trial or in the grace period
$team->subscribed('default', 'pro');  // On a specific plan
$team->onTrial();

$subscription = $team->subscription(); // Latest subscription of type "default"
$subscription->active();
$subscription->onGracePeriod();
$subscription->ended();

// Change it
$subscription->swap('business');      // Same billing cycle only, no proration
$subscription->cancel();              // Keeps access until ends_at
$subscription->syncFromMercadoPago();
```

### Access rules

| State | Access |
|---|---|
| `authorized` | Yes |
| `pending` or `authorized`, with `trial_ends_at` in the future | Yes (trial) |
| `cancelled`, with `ends_at` in the future | Yes (grace period) |
| `paused`, `pending` after the trial, or `cancelled` and ended | No |

Both status lists are configurable (`billing.active_statuses` and `billing.trial_statuses`). Remove `pending` from `trial_statuses` to require a completed checkout before the trial starts.

When a subscription is cancelled, `ends_at` becomes the later of the trial end and the next charge date, which is the end of the period already paid for.

### Things to know

- **One free trial per billable.** A billable that ever had a trial won't get another one. Changing plan before finishing the checkout keeps the days left.
- **The trial is tracked locally.** Mercado Pago starts its own free trial when the payer authorizes the subscription. A payer who authorizes late in the local trial still gets the full free period on Mercado Pago's side.
- **No proration.** `swap()` changes the amount from the next charge on. Mercado Pago can't change a subscription's frequency, so swapping between cycles (monthly to yearly) throws: cancel and start a new checkout instead.
- **One subscription per type.** Use different types (`newSubscription('addons', ...)`) for independent subscriptions.

## Events

| Event | When |
|---|---|
| `WebhookReceived` | Every verified webhook, any topic |
| `PaymentUpdated` | A `payment` webhook. The payment is only fetched when something listens to this event |
| `SubscriptionCreated` | A subscription row was created, at checkout or adopted from a webhook |
| `SubscriptionActivated` | A subscription became active (the payer completed the checkout) |
| `SubscriptionUpdated` | Local state changed; `$event->changed` lists the attributes |
| `SubscriptionCancelled` | A subscription was cancelled, here or in Mercado Pago |
| `SubscriptionPaymentReceived` | Mercado Pago reported a subscription charge. Check `$event->payment->status` |

All events live in `MatiasYocca\MercadoPago\Events`.

## Checkout Pro (one-off payments)

```php
use MatiasYocca\MercadoPago\Facades\MercadoPago;

return MercadoPago::createPreference([
    'items' => [[
        'title' => 'Credit pack',
        'quantity' => 1,
        'unit_price' => 5000,
        'currency_id' => 'ARS',
    ]],
    'external_reference' => "order-{$order->id}",
    'back_urls' => ['success' => route('orders.show', $order)],
    'auto_return' => 'approved',
], idempotencyKey: "order-{$order->id}");  // Responsable: redirects to Mercado Pago
```

Then listen for the result:

```php
Event::listen(function (PaymentUpdated $event) {
    if ($event->payment->approved()) {
        Order::where('reference', $event->payment->externalReference)->first()?->markPaid();
    }
});
```

Other API calls go straight through the SDK: `mercadopago/dx-php` is installed with this package.

## Testing

`MercadoPago::fake()` swaps the gateway for an in-memory one. Subscriptions created through it are stored, so later finds and updates see them.

```php
use MatiasYocca\MercadoPago\Facades\MercadoPago;

$mercadoPago = MercadoPago::fake();

$this->actingAs($user)->get("/app/{$team->id}/billing")
    ->assertRedirect($team->subscription()->init_point);

$mercadoPago->assertSubscriptionCreated(fn (array $payload) => $payload['reason'] === 'My SaaS Pro');

// Simulate the payer authorizing it
$mercadoPago->addSubscription(['id' => $team->subscription()->preapproval_id, 'status' => 'authorized']);
$team->subscription()->syncFromMercadoPago();

$this->assertTrue($team->subscribed());
```

Seed other state with `addSubscription()`, `addPayment()` and `addAuthorizedPayment()`.

Package development:

```bash
composer test
```

```bash
composer lint
```

## License

MIT
