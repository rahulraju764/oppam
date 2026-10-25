<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\ApproveBrokerKyc;
use App\Actions\Admin\Brokers\OpenBrokerPanImage;
use App\Actions\Admin\Brokers\RejectBrokerApplication;
use App\Actions\Admin\Brokers\RejectBrokerKyc;
use App\Domain\Broker\KycDocumentAccess;
use App\Enums\BrokerStatus;
use App\Enums\KycSubmissionStatus;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerKyc;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 "Applications & KYC queue" (PRD §11A B.12, `brokers.kyc`): KYC submissions to review (oldest
 * first), applications that sent no KYC yet, and recent decisions. Selecting a bureau (`?broker=`
 * code) opens its detail: application, masked PAN / account, the PAN image on demand (signed,
 * session-bound, audited link), and the decisions — approve (bureau ACTIVE, OWNER login active),
 * reject KYC (owner resends), reject application. Every decision is an audited Action.
 *
 * @property-read LengthAwarePaginator<int, Broker> $brokers
 */
#[Layout('layouts::admin', ['title' => 'Bureau KYC'])]
final class KycQueue extends Component
{
    use WithPagination;

    public const LISTS = ['kyc', 'applications', 'decided'];

    #[Url(as: 'list', except: 'kyc')]
    public string $list = 'kyc';

    #[Url(as: 'broker', except: '')]
    public string $selected = '';

    public string $reason = '';

    #[Locked]
    public string $panUrl = '';

    #[Locked]
    public int $panUntil = 0;

    public function mount(): void
    {
        Gate::authorize('brokers.kyc');

        if (! in_array($this->list, self::LISTS, true)) {
            $this->list = 'kyc';
        }
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:admin.queues,.queue.count' => '$refresh'];
    }

    public function show(string $list): void
    {
        $this->list = in_array($list, self::LISTS, true) ? $list : 'kyc';
        $this->selected = '';
        $this->closePan();
        $this->resetPage();
    }

    public function select(string $code): void
    {
        $this->selected = $code;
        $this->reason = '';
        $this->closePan();
        $this->resetErrorBag();
    }

    public function viewPan(OpenBrokerPanImage $open): void
    {
        $kyc = $this->pendingOrLatestKyc() ?? abort(404);

        try {
            $this->panUrl = $open->handle($this->admin(), $kyc);
            $this->panUntil = now()->addMinutes(KycDocumentAccess::minutes())->getTimestamp();
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: (string) collect($e->errors())->flatten()->first());
        }
    }

    public function closePan(): void
    {
        if ($this->selected !== '') {
            app(KycDocumentAccess::class)->forget($this->selected);
        }
        $this->panUrl = '';
        $this->panUntil = 0;
    }

    public function approve(ApproveBrokerKyc $approve): void
    {
        $kyc = $this->pendingKyc() ?? abort(404);
        $this->decide(fn () => $approve->handle($this->admin(), $kyc), __('Bureau :code approved — it is active.', ['code' => $this->selected]));
    }

    public function rejectKyc(RejectBrokerKyc $reject): void
    {
        $kyc = $this->pendingKyc() ?? abort(404);
        $this->decide(fn () => $reject->handle($this->admin(), $kyc, $this->reason), __('KYC of :code rejected; the owner can send it again.', ['code' => $this->selected]));
    }

    public function rejectApplication(RejectBrokerApplication $reject): void
    {
        $broker = $this->selectedBroker() ?? abort(404);
        $this->decide(fn () => $reject->handle($this->admin(), $broker, $this->reason), __('Application of :code rejected.', ['code' => $this->selected]));
    }

    /** @return LengthAwarePaginator<int, Broker> */
    #[Computed]
    public function brokers(): LengthAwarePaginator
    {
        $query = Broker::query()->with(['district:id,label', 'latestKyc']);

        $query = match ($this->list) {
            'applications' => $query->applicationsToReview()->orderBy('created_at'),
            'decided' => $query->whereIn('status', [BrokerStatus::Active, BrokerStatus::Rejected])->where('updated_at', '>=', now()->subDays(90))->orderByDesc('updated_at'),
            default => $query->whereHas('kycSubmissions', fn ($q) => $q->where('status', KycSubmissionStatus::Pending))->orderBy('updated_at'),
        };

        return $query->orderBy('id')->paginate(25);
    }

    public function render(): View
    {
        $broker = $this->selectedBroker();

        if ($this->panUntil !== 0 && $this->panUntil < now()->getTimestamp()) {
            $this->panUrl = '';
            $this->panUntil = 0;
        }

        return view('livewire.admin.brokers.kyc-queue', [
            'broker' => $broker,
            'kyc' => $broker !== null ? $this->pendingOrLatestKyc() : null,
            'history' => $broker !== null ? BrokerKyc::query()->where('broker_id', $broker->id)->with('reviewedBy:id,name')->latest()->orderByDesc('id')->limit(10)->get() : collect(),
            'watermark' => __(':email · :time IST · access logged', [
                'email' => $this->admin()->email,
                'time' => now()->timezone((string) config('oppam.display_timezone'))->format('d M Y H:i'),
            ]),
            'counts' => [
                'kyc' => BrokerKyc::query()->where('status', KycSubmissionStatus::Pending)->count(),
                'applications' => Broker::query()->applicationsToReview()->count(),
            ],
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function decide(Closure $action, string $done): void
    {
        $action();   // a ValidationException (reason, already decided) shows under the reason field

        $this->closePan();
        $this->resetErrorBag();
        $this->reason = '';
        $this->selected = '';
        unset($this->brokers);
        $this->dispatch('toast', type: 'success', message: $done);
    }

    private function selectedBroker(): ?Broker
    {
        return $this->selected === '' ? null : Broker::query()->where('code', $this->selected)->with('district:id,label')->first();
    }

    private function pendingKyc(): ?BrokerKyc
    {
        $broker = $this->selectedBroker();

        return $broker === null ? null : BrokerKyc::query()->where('broker_id', $broker->id)->where('status', KycSubmissionStatus::Pending)->latest()->first();
    }

    private function pendingOrLatestKyc(): ?BrokerKyc
    {
        $broker = $this->selectedBroker();

        return $broker === null ? null : ($this->pendingKyc() ?? BrokerKyc::query()->where('broker_id', $broker->id)->latest()->orderByDesc('id')->first());
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
