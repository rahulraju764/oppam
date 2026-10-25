{{--
    Bureau biodata (§11A B.7), rendered by dompdf (DompdfBiodataRenderer). A PDF, not a page: it
    carries its own small stylesheet (dompdf can't read the app's CSS / tokens). The footer and the
    watermark are `position: fixed`, so dompdf repeats them on every page.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Biodata :code', ['code' => $bio->profileCode]) }}</title>
    <style>
        @font-face { font-family: "Noto Sans"; font-weight: normal; src: url("{{ $fonts['regular'] }}") format("truetype"); }
        @font-face { font-family: "Noto Sans"; font-weight: bold; src: url("{{ $fonts['bold'] }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Malayalam"; font-weight: normal; src: url("{{ $fonts['mlRegular'] }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Malayalam"; font-weight: bold; src: url("{{ $fonts['mlBold'] }}") format("truetype"); }
        @page { margin: 40px 44px 72px; }
        body { font-family: "Noto Sans", "Noto Sans Malayalam", sans-serif; font-size: 11px; color: #2d2d2d; line-height: 1.45; }
        .watermark { position: fixed; top: 42%; left: -10%; width: 120%; text-align: center; transform: rotate(-30deg);
            font-size: 30px; font-weight: bold; color: #e02349; opacity: 0.09; }
        .footer { position: fixed; bottom: -48px; left: 0; right: 0; border-top: 1px solid #d9d9d9; padding-top: 6px;
            font-size: 9px; color: #5e6f80; text-align: center; }
        .brand { font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: #b51b3b; margin: 0 0 12px; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .head td { vertical-align: top; }
        .photo { width: 150px; height: auto; border: 1px solid #d9d9d9; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .code { font-size: 11px; color: #5e6f80; margin: 0 0 8px; }
        .headline { margin: 0; padding: 0; list-style: none; }
        .headline li { margin: 0 0 2px; }
        h2 { font-size: 13px; color: #b51b3b; border-bottom: 1px solid #f0c6cf; padding-bottom: 3px; margin: 14px 0 6px; }
        table.facts { width: 100%; border-collapse: collapse; }
        table.facts th { width: 34%; text-align: left; font-weight: normal; color: #5e6f80; padding: 2px 8px 2px 0; vertical-align: top; }
        table.facts td { padding: 2px 0; vertical-align: top; }
        p.text { margin: 0; white-space: pre-line; }
    </style>
</head>
<body>
    <div class="watermark">{{ __('Prepared by :bureau, :site', ['bureau' => $bio->bureauName, 'site' => $site]) }}</div>
    <div class="footer">
        {{ __('Prepared by :bureau (:code) · :phone · :site · :date', [
            'bureau' => $bio->bureauName, 'code' => $bio->bureauCode, 'phone' => $bio->bureauPhone,
            'site' => $site, 'date' => $bio->preparedOn,
        ]) }}
    </div>

    <p class="brand">{{ $site }}</p>

    <table class="head">
        <tr>
            @if ($bio->photo)
                <td style="width: 166px;"><img class="photo" src="{{ $bio->photo }}" alt="{{ __('Photo of :name', ['name' => $bio->name]) }}"></td>
            @endif
            <td>
                <h1>{{ $bio->name }}</h1>
                <p class="code">{{ __('Profile :code', ['code' => $bio->profileCode]) }}</p>
                <ul class="headline">
                    @foreach ($bio->headline as $fact)
                        <li>{{ $fact }}</li>
                    @endforeach
                </ul>
            </td>
        </tr>
    </table>

    @if ($bio->about)
        <h2>{{ __('About') }}</h2>
        <p class="text">{{ $bio->about }}</p>
    @endif

    @foreach ($bio->sections as $title => $rows)
        <h2>{{ $title }}</h2>
        <table class="facts">
            @foreach ($rows as $label => $value)
                <tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    @endforeach

    @if ($bio->partner !== [] || $bio->aboutPartner)
        <h2>{{ __('Partner Preferences') }}</h2>
        @if ($bio->partner !== [])
            <table class="facts">
                @foreach ($bio->partner as $label => $value)
                    <tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        @endif
        @if ($bio->aboutPartner)
            <p class="text">{{ $bio->aboutPartner }}</p>
        @endif
    @endif
</body>
</html>
