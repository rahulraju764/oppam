<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\RunIntegrationCheck;
use App\Models\AdminUser;
use App\Services\Health\Probes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A15 integrations (P8.4): what each third-party service is set to (masked public ids, drivers —
 * secrets stay in .env and are never shown or editable here) and a Test button that makes a real,
 * read-only call through RunIntegrationCheck (settings.edit, rate-limited, audited).
 */
#[Layout('layouts::admin', ['title' => 'Integrations'])]
final class Integrations extends Component
{
    /** @var array<string, array{state: string, variant: string, message: string}> last result per integration (server-written) */
    #[Locked]
    public array $results = [];

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function test(string $integration, RunIntegrationCheck $check): void
    {
        try {
            $result = $check->handle($this->admin(), $integration);
        } catch (TooManyRequestsHttpException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->results[$integration] = ['state' => $result->state, 'variant' => $result->variant(), 'message' => $result->message];
    }

    public function render(Probes $probes): View
    {
        return view('livewire.admin.settings.integrations', [
            'probes' => $probes->all(),
            'canTest' => Gate::forUser($this->admin())->allows('settings.edit'),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
