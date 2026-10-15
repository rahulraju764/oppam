<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Contracts\PresenceCounter;
use App\Enums\Metric;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Queries\Admin\DashboardAlerts;
use App\Queries\Admin\DashboardMetrics;
use App\Queries\Admin\WorkQueues;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The admin dashboard (PRD A02). For admins with `dashboard.view`: the alert strip, the KPI row
 * (from `metric_snapshots` — never a live aggregate — with a 30-day change, a definition and the
 * "as of" time; "online now" from the WebSocket server), three charts and the recent activity
 * feed (`audit.view`). Work-queue cards (count + oldest item) for admins with `moderation.view`.
 * Everyone else gets a welcome. Live: a queue count change (`admin.queues`) re-reads the page;
 * a KPI tick (`admin.dashboard`) changes its tile in the browser, without a query.
 * Only data that exists is shown — each later module adds its own tile, chart and card.
 */
#[Layout('layouts::admin', ['title' => 'Dashboard'])]
final class Dashboard extends Component
{
    private const ACTIVITY = 10;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        // Subscribe only to what this admin may join — Echo would otherwise report a refused channel.
        return $this->admin()->can('moderation.view')
            ? ['echo-private:admin.queues,.queue.count' => '$refresh']
            : [];
    }

    public function render(DashboardMetrics $metrics, WorkQueues $queues, DashboardAlerts $alerts, PresenceCounter $presence): View
    {
        $admin = $this->admin();
        $canView = $admin->can('dashboard.view');
        $workQueues = $queues->for($admin);

        return view('livewire.admin.dashboard', [
            'canView' => $canView,
            'queues' => $workQueues,
            // Queue alerts go with the queues; failed jobs / backlog are system health: dashboard.view only.
            'alerts' => $alerts->for($workQueues, system: $canView),
            'kpis' => $canView ? $metrics->kpis() : [],
            'online' => $canView ? $presence->online() : null,
            'asOf' => $canView ? $metrics->asOf()?->setTimezone((string) config('oppam.display_timezone')) : null,
            'charts' => $canView ? $this->charts($metrics) : [],
            'activity' => $canView && $admin->can('audit.view')
                ? AuditLog::query()->latest('created_at')->orderByDesc('id')->limit(self::ACTIVITY)->get()
                : null,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /**
     * Chart definitions for the Alpine `adminChart` wrapper — labels and numbers only.
     *
     * @return list<array{id: string, title: string, type: string, labels: list<string>, datasets: list<array{label: string, data: list<int>}>}>
     */
    private function charts(DashboardMetrics $metrics): array
    {
        $signups = $metrics->series(Metric::NewMembers);
        $sent = $metrics->series(Metric::InterestsSent);

        return [
            ['id' => 'signups', 'title' => __('Signups & approved profiles (30 days)'), 'type' => 'line', 'labels' => $signups['labels'], 'datasets' => [
                ['label' => __('Signups'), 'data' => $signups['values']],
                ['label' => __('Profiles approved'), 'data' => $metrics->series(Metric::Activations)['values']],
            ]],
            ['id' => 'interests', 'title' => __('Interests sent vs accepted (30 days)'), 'type' => 'bar', 'labels' => $sent['labels'], 'datasets' => [
                ['label' => __('Sent'), 'data' => $sent['values']],
                ['label' => __('Accepted'), 'data' => $metrics->series(Metric::InterestsAccepted)['values']],
            ]],
            ['id' => 'gender', 'title' => __('Active profiles by gender'), 'type' => 'doughnut', 'labels' => [__('Grooms'), __('Brides')], 'datasets' => [
                ['label' => __('Active profiles'), 'data' => [(int) $metrics->latest(Metric::ActiveMale), (int) $metrics->latest(Metric::ActiveFemale)]],
            ]],
        ];
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
