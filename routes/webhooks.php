<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inbound webhooks — /webhooks/* (PRD §13)
|--------------------------------------------------------------------------
| No session, no CSRF: every endpoint MUST verify the provider signature before
| doing anything, and must be idempotent (Razorpay retries). Added in P5.1 (razorpay),
| P1.1/P8.3 (msg91 delivery reports, mail bounces).
*/

use App\Http\Controllers\Webhooks\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

// Razorpay (M10, F04): payment.captured / order.paid / payment.failed. Signature-verified inside.
Route::post('/razorpay', RazorpayWebhookController::class)->middleware('throttle:600,1')->name('razorpay');
