<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Actions\Reports\ExportReport;
use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\ReportRegistry;
use App\Enums\ExportFormat;
use App\Enums\ReportKey;
use App\Enums\ReportPreset;
use App\Enums\SettingKey;
use App\Http\Controllers\Admin\ReportExportController;
use App\Models\AdminUser;
use App\Queries\Reports\LiveMetrics;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL as SignedUrl;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * One A09 report (P9.1a): a preset or custom period (IST days, up to 400, ending yesterday at the
 * latest), compare-to-previous, headline numbers, charts, breakdown tables and — for Marketplace
 * health — the "no interest" work queue. Export as CSV / Excel (`reports.export`, audited; large
 * exports are built in the background and emailed). Needs `reports.view`.
 */
final class ReportView extends Component
{
    use WithPagination;

    #[Locked]
    public string $report = '';

    #[Url(except: '30d')]
    public string $preset = '30d';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: false)]
    public bool $compare = false;

    public function mount(string $report): void
    {
        $this->authorize('reports.view');
        $key = ReportKey::tryFrom($report);
        abort_if($key === null, 404);
        $this->report = $key->value;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['preset', 'from', 'to'], true)) {
            $this->resetPage('queue');
        }
    }

    public function export(string $format, ExportReport $export): mixed
    {
        $kind = ExportFormat::tryFrom($format);
        abort_if($kind === null, 404);
        $range = $this->range();
        if ($range === null) {
            return null;
        }

        try {
            $queued = $export->handle($this->admin(), $this->key(), $range, $kind);
        } catch (AuthorizationException|TooManyRequestsHttpException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        if ($queued !== null) {
            $this->dispatch('toast', type: 'success', message: __('This export is large: we\'re preparing it and will email you when it\'s ready (:code).', ['code' => $queued->code]));

            return null;
        }

        $token = Str::random(40);
        Cache::put(ReportExportController::CACHE_PREFIX.$token, [
            'admin' => $this->admin()->id, 'report' => $this->report, 'format' => $kind->value,
            'from' => $range->from->toDateString(), 'to' => $range->to->toDateString(),
        ], now()->addMinutes(5));

        return $this->redirect(SignedUrl::temporarySignedRoute('admin.reports.export', now()->addMinutes(5), ['token' => $token]));
    }

    public function render(ReportRegistry $reports, LiveMetrics $live): View
    {
        $range = $this->range();
        $data = $range === null ? null : $reports->for($this->key())->build($range, $this->compare, $this->admin());

        return view('livewire.admin.reports.report-view', [
            'key' => $this->key(),
            'range' => $range,
            'data' => $data,
            'presets' => ReportPreset::options(),
            'canExport' => Gate::forUser($this->admin())->allows('reports.export'),
            'queue' => $range !== null && $this->key() === ReportKey::Marketplace
                ? $live->zeroInterest(Settings::int(SettingKey::ReportsZeroInterestDays), $this->getPage('queue')) : null,
            'queueDays' => Settings::int(SettingKey::ReportsZeroInterestDays),
            'maxDate' => CarbonImmutable::now((string) config('oppam.display_timezone'))->subDay()->toDateString(),
        ])->layout('layouts::admin', ['title' => $this->key()->label().' — '.__('Reports')]);
    }

    /** The period asked for, validated on the server; null (with an error) for a bad custom range. */
    private function range(): ?ReportRange
    {
        $preset = ReportPreset::tryFrom($this->preset) ?? ReportPreset::Last30;
        if ($preset !== ReportPreset::Custom) {
            return $preset->range();
        }

        $this->resetErrorBag(['from', 'to']);
        $tz = (string) config('oppam.display_timezone');
        $yesterday = CarbonImmutable::now($tz)->startOfDay()->subDay();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $this->from, $tz) : false;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $this->to, $tz) : false;

        $error = match (true) {
            ! $from instanceof CarbonImmutable => ['from', __('Choose a start date.')],
            ! $to instanceof CarbonImmutable => ['to', __('Choose an end date.')],
            $from->greaterThan($to) => ['to', __('The end date must be on or after the start date.')],
            $to->greaterThan($yesterday) => ['to', __('Reports cover up to yesterday.')],
            $from->diffInDays($to) >= ReportRange::MAX_DAYS => ['to', __('Choose at most :n days.', ['n' => ReportRange::MAX_DAYS])],
            default => null,
        };
        if ($error !== null) {
            $this->addError(...$error);

            return null;
        }

        return new ReportRange($from, $to);
    }

    private function key(): ReportKey
    {
        return ReportKey::from($this->report);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
