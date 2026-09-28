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
