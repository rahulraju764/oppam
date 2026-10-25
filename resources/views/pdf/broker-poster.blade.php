{{--
    A4 referral poster for a bureau (§11A B.5 materials), rendered by dompdf (ReferralPosterRenderer).
    A PDF, not a page: it carries its own small stylesheet (dompdf can't read the app's CSS / tokens).
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __(':bureau — :site', ['bureau' => $bureau, 'site' => $site]) }}</title>
    <style>
        @page { margin: 48px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #2d2d2d; text-align: center; }
        .brand { font-size: 16px; letter-spacing: 2px; text-transform: uppercase; color: #b51b3b; margin: 0 0 32px; }
        h1 { font-size: 30px; margin: 0 0 8px; }
        .district { font-size: 15px; color: #5e6f80; margin: 0 0 32px; }
        .lead { font-size: 20px; margin: 0 0 24px; }
        .qr { width: 320px; height: 320px; margin: 0 auto 24px; }
        .code { display: inline-block; font-size: 28px; font-weight: bold; letter-spacing: 3px; border: 2px solid #b51b3b; padding: 8px 24px; margin: 0 0 16px; }
        .link { font-size: 14px; color: #5e6f80; margin: 0 0 40px; }
        .steps { font-size: 14px; line-height: 1.7; margin: 0; }
    </style>
</head>
<body>
    <p class="brand">{{ $site }}</p>
    <h1>{{ $bureau }}</h1>
    @if ($district)
        <p class="district">{{ $district }}</p>
    @endif
    <p class="lead">{{ __('Find your life partner on :site', ['site' => $site]) }}</p>
    <img class="qr" src="{{ $qr }}" alt="{{ __('QR code for :link', ['link' => $link]) }}">
    <div class="code">{{ $code }}</div>
    <p class="link">{{ $link }}</p>
    <p class="steps">
        {{ __('Scan the code or open the link, register free,') }}<br>
        {{ __('and enter :code as your bureau referral code.', ['code' => $code]) }}
    </p>
</body>
</html>
