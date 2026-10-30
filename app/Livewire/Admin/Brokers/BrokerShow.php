<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\DeactivateBroker;
use App\Actions\Admin\Brokers\ReactivateBroker;
use App\Actions\Admin\Brokers\RevokeBrokerKyc;
use App\Actions\Admin\Brokers\SetBrokerCreationPause;
use App\Actions\Admin\Brokers\SetBrokerPayoutHold;
use App\Actions\Admin\Brokers\SuspendBroker;
use App\Actions\Admin\Brokers\UpdateBureauTerms;
use App\Data\Broker\BureauTermsData;
use App\Domain\Broker\StaffSeats;
use App\Enums\BrokerStatus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A07 bureau detail (§11A B.12, `brokers.view`): header + tabs. Overview: the bureau's facts, the
 * terms admins edit (commission — `brokers.commission_bps`; interest allowance, seat limit, import
 * cap — `brokers.edit`; R-A07-11), suspend / deactivate / reactivate (`brokers.edit`, R-A07-8) and
 * payouts hold (`brokers.payout`) — each with a typed reason and authorized again in its Action.
 * KYC revocation (`brokers.kyc`, R-M13-21) and the create / import pause (`brokers.edit`, R-A07-10).
 * The other tabs are lazy components, each authorizing its own permission (TABS); a tab the admin
 * may not open is neither shown nor selectable. The bureau is found by its code (#[Locked]), never a ULID.
 */
#[Layout('layouts::admin', ['title' => 'Bureau'])]
final class BrokerShow extends Component
{
    /** tab => the permission it needs (§11A B.12) */
    public const TABS = [
        'overview' => 'brokers.view', 'referrals' => 'brokers.view', 'profiles' => 'brokers.view',
        'staff' => 'brokers.staff.view', 'imports' => 'brokers.imports.view', 'earnings' => 'brokers.view',
        'payouts' => 'brokers.view', 'exports' => 'brokers.exports.view', 'disputes' => 'brokers.view',
        'activity' => 'brokers.view',
    ];

    #[Locked]
    public string $code = '';

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $reason = '';

    public string $commissionBps = '';

    public string $interestAllowance = '';

    public string $seatLimit = '';

    public string $importDailyCap = '';

    public function mount(string $broker): void
    {
        $this->authorize('brokers.view');
        $this->code = strtoupper($broker);
        $this->fillTerms($this->broker());
        $this->tab = $this->allowedTab($this->tab);
    }

    public function updatedTab(): void
    {
        $this->tab = $this->allowedTab($this->tab);
    }

    public function revokeKyc(RevokeBrokerKyc $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), $this->reason), 'revoke-kyc',
            __('KYC revoked. The bureau can’t create, import or export until its KYC is approved again.'));
    }

    public function pauseCreation(SetBrokerCreationPause $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), true, $this->reason), 'pause-creation',
            __('Adding and importing profiles is paused for this bureau.'));
    }

    public function resumeCreation(SetBrokerCreationPause $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), false, $this->reason), 'resume-creation',
            __('The bureau can add and import profiles again.'));
    }

    public function saveTerms(UpdateBureauTerms $action): void
    {
        $this->resetErrorBag();
        $terms = new BureauTermsData(
            commissionBps: $this->int('commissionBps'),
            interestAllowanceMonthly: $this->int('interestAllowance'),
            staffSeatLimit: $this->int('seatLimit'),
            importDailyCap: $this->int('importDailyCap'),
        );

        try {
            $action->handle($this->admin(), $this->broker(), $terms, $this->reason);
        } catch (ValidationException $invalid) {
            throw ValidationException::withMessages(collect($invalid->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                'commission_bps' => 'commissionBps', 'interest_allowance_monthly' => 'interestAllowance',
                'staff_seat_limit' => 'seatLimit', 'import_daily_cap' => 'importDailyCap', default => $key,
            } => $messages])->all());
        }

        $this->reset('reason');
        $this->fillTerms($this->broker());
        $this->dispatch('close-modal', name: 'broker-terms');
        $this->dispatch('toast', type: 'success', message: __('Terms updated for :code.', ['code' => $this->code]));
    }

    public function suspend(SuspendBroker $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), $this->reason), 'suspend',
            __('Bureau suspended. The owner and every staff login were signed out.'));
    }

    public function deactivate(DeactivateBroker $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), $this->reason), 'deactivate',
            __('Bureau deactivated. The owner and every staff login were signed out.'));
    }

    public function reactivate(ReactivateBroker $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), $this->reason), 'reactivate',
            __('Bureau reactivated. Its logins can sign in again.'));
    }

    public function holdPayouts(SetBrokerPayoutHold $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), true, $this->reason), 'hold',
            __('Payouts to this bureau are on hold.'));
    }

    public function releasePayouts(SetBrokerPayoutHold $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), false, $this->reason), 'release',
            __('Payouts to this bureau are released.'));
    }

    public function render(StaffSeats $seats): View
    {
        $broker = $this->broker();
        $broker->loadMissing(['district:id,label', 'latestKyc']);
        $waitingForOwner = $broker->created_by_admin_id !== null && $broker->status === BrokerStatus::Applied
            && ! $broker->staff()->exists();

        return view('livewire.admin.brokers.broker-show', [
            'broker' => $broker,
            'seatsUsed' => $seats->used($broker),
            'waitingForOwner' => $waitingForOwner,
            'applyUrl' => $waitingForOwner ? route('broker.apply', ['bureau' => $broker->code]) : null,
            'canSuspend' => $broker->status === BrokerStatus::Active,
            'canReactivate' => $broker->status === BrokerStatus::Suspended,
            'canDeactivate' => in_array($broker->status, [BrokerStatus::Active, BrokerStatus::Suspended], true),
            'tabs' => array_keys(array_filter(self::TABS, fn (string $permission): bool => $this->admin()->can($permission))),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->title($broker->code.' · '.__('Bureau'));
    }

    /** Run a quick action; a state conflict becomes a toast, success closes its dialog. */
    private function run(Closure $action, string $modal, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (BrokerStateConflict $conflict) {
            $this->dispatch('toast', type: 'error', message: $conflict->getMessage());

            return;
        }

        $this->reset('reason');
        $this->dispatch('close-modal', name: 'broker-'.$modal);
        $this->dispatch('toast', type: 'success', message: $done);
    }

    /** The requested tab when it exists and the admin may open it, else Overview. */
    private function allowedTab(string $tab): string
    {
        $permission = self::TABS[$tab] ?? null;

        return $permission !== null && $this->admin()->can($permission) ? $tab : 'overview';
    }

    private function fillTerms(Broker $broker): void
    {
        $this->commissionBps = (string) $broker->commission_bps;
        $this->interestAllowance = (string) $broker->interest_allowance_monthly;
        $this->seatLimit = (string) $broker->staff_seat_limit;
        $this->importDailyCap = (string) $broker->import_daily_cap;
    }

    /** A whole number from a text field; anything else fails the Action's range check. */
    private function int(string $property): int
    {
        $value = trim((string) $this->{$property});

        return preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : -1;
    }

    private function broker(): Broker
    {
        return Broker::query()->where('code', $this->code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
