<?php

declare(strict_types=1);

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ReconcileOrder;
use App\Contracts\InvoiceRenderer;
use App\Enums\GstSplit;
use App\Enums\OrderStatus;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Models\Invoice;
use App\Models\Masters\District;
use App\Models\Masters\State;
use App\Models\Order;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P5.1 — GST invoices (PRD M10, A06): gapless numbers per Indian financial year allocated inside the
| activation transaction, CGST + SGST vs IGST by the member's state, a PDF on the private disk, the
| member's own download only; and ReconcileOrder for a late webhook.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Storage::fake('local');
});

it('A06: invoice numbers are gapless and sequential within the financial year', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 21));
    capturePayment(checkoutOrder(groom()));
    capturePayment(checkoutOrder(bride()));

    expect(Invoice::query()->orderBy('sequence')->pluck('number')->all())->toBe(['OPM-2627-000001', 'OPM-2627-000002'])
        ->and(strlen('OPM-2627-000001'))->toBeLessThanOrEqual(16);      // GST rule 46
});

it('A06: the series restarts on 1 April (IST)', function (): void {
    $this->travelTo(CarbonImmutable_parse('2027-03-31 18:29:00'));
    capturePayment(checkoutOrder(groom()));
    $this->travelTo(CarbonImmutable_parse('2027-03-31 18:31:00'));        // 1 April 00:01 IST
    capturePayment(checkoutOrder(bride()));

    expect(Invoice::query()->orderBy('issued_at')->pluck('number')->all())->toBe(['OPM-2627-000001', 'OPM-2728-000001']);
});

it('A06 gapless: an activation that rolls back gives its number back', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 21));
    $first = checkoutOrder(groom());

    try {
        DB::transaction(function () use ($first): void {
            app(IssueInvoice::class)->handle($first, $first->profile, now()->toImmutable());
            throw new RuntimeException('failure after the invoice row');
        });
    } catch (RuntimeException) {
    }

    capturePayment($first);
    expect(Invoice::query()->sole()->number)->toBe('OPM-2627-000001');
});

it('GST split: CGST + SGST for a Kerala member, IGST for another state', function (string $stateCode, GstSplit $split): void {
    $member = groom();
    $state = masterId(State::class, $stateCode);
    $district = District::query()->where('state_id', $state)->first();
    if ($district === null) {   // the masters seed Kerala's districts only
        $district = new District;
        $district->forceFill(['state_id' => $state, 'code' => 'CHENNAI', 'label' => 'Chennai', 'sort_order' => 1, 'is_active' => true])->save();
    }
    $member->profile->forceFill(['district_id' => $district->id])->save();

    $order = checkoutOrder($member);
    capturePayment($order);
    $invoice = Invoice::query()->sole();

    expect($invoice->gst_split)->toBe($split)
        ->and($invoice->cgst_paise + $invoice->sgst_paise + $invoice->igst_paise)->toBe($order->gst_paise)
        ->and($invoice->buyer['state_code'])->toBe($stateCode)
        ->and($invoice->seller['state_code'])->toBe('KL');

    if ($split === GstSplit::IntraState) {
        expect($invoice->cgst_paise)->toBe(8_991)->and($invoice->sgst_paise)->toBe(8_991)->and($invoice->igst_paise)->toBe(0);
    } else {
        expect($invoice->igst_paise)->toBe(17_982)->and($invoice->cgst_paise)->toBe(0);
    }
})->with([
    'Kerala' => ['KL', GstSplit::IntraState],
    'Tamil Nadu' => ['TN', GstSplit::InterState],
]);

it('the PDF job renders the invoice to the private disk once', function (): void {
    capturePayment(checkoutOrder(groom()));
    $invoice = Invoice::query()->sole();

    (new GenerateInvoicePdf($invoice->id))->handle(app(InvoiceRenderer::class));
    $path = $invoice->refresh()->pdf_path;

    expect($path)->toBe('invoices/'.$invoice->financial_year.'/'.$invoice->number.'.pdf')
        ->and(Storage::disk('local')->get($path))->toStartWith('%PDF');

    Storage::disk('local')->put($path, 'kept');
    (new GenerateInvoicePdf($invoice->id))->handle(app(InvoiceRenderer::class));
    expect(Storage::disk('local')->get($path))->toBe('kept');
});

it('M10: the member downloads their own invoice; anyone else gets a 404, a guest the sign-in page', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member));
    $url = memberUrl('/billing/invoices/'.Invoice::query()->sole()->number);

    $this->actingAs($member)->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs(bride())->get($url)->assertNotFound();
    $this->actingAs($member)->get(memberUrl('/billing/invoices/OPM-2627-999999'))->assertNotFound();

    auth()->logout();
    $this->get($url)->assertRedirect(memberUrl('/login'));
});

it('F04: with no webhook, the success page\'s reconcile asks the gateway and activates', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    fakeGateway()->capture((string) $order->gateway_order_id);   // captured at the gateway, webhook never arrives

    expect(app(ReconcileOrder::class)->handle($member, $order)->status)->toBe(OrderStatus::Paid);
});

it('reconcile is owner-scoped and at most once per 10 seconds per order', function (): void {
    $member = groom();
    $order = checkoutOrder($member);

    expect(fn () => app(ReconcileOrder::class)->handle(bride(), $order))->toThrow(NotFoundHttpException::class);

    app(ReconcileOrder::class)->handle($member, $order);                  // nothing captured yet
    fakeGateway()->capture((string) $order->gateway_order_id);
    expect(app(ReconcileOrder::class)->handle($member, $order->refresh())->status)->toBe(OrderStatus::Created);

    $this->travel(11)->seconds();
    expect(app(ReconcileOrder::class)->handle($member, $order->refresh())->status)->toBe(OrderStatus::Paid)
        ->and(Order::query()->count())->toBe(1);
});

function CarbonImmutable_parse(string $utc): Carbon\CarbonImmutable
{
    return Carbon\CarbonImmutable::parse($utc, 'UTC');
}
