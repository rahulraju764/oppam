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

use App\Http\Controllers\Webhooks\MailBounceController;
use App\Http\Controllers\Webhooks\Msg91DeliveryReportController;
use App\Http\Controllers\Webhooks\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

// Razorpay (M10, F04): payment.captured / order.paid / payment.failed. Signature-verified inside.
Route::post('/razorpay', RazorpayWebhookController::class)->middleware('throttle:600,1')->name('razorpay');

// A14 delivery log (P8.3a): MSG91 delivery reports (shared secret) and mail bounces (provider signature).
Route::post('/msg91/dlr', Msg91DeliveryReportController::class)->middleware('throttle:600,1')->name('msg91.dlr');
Route::post('/mail/bounce', MailBounceController::class)->middleware('throttle:600,1')->name('mail.bounce');
