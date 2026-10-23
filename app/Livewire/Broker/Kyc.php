<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Actions\Broker\SubmitBrokerKyc;
use App\Data\Broker\BrokerKycData;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BrokerKycStatus;
use App\Enums\BureauPermission;
use App\Models\BrokerKyc;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /broker/kyc (PRD §11A B.11 "PAN + bank details, status", Owner only — `kyc.manage`): the KYC
 * status and, until it is verified or while a submission waits, the form. SubmitBrokerKyc decides
 * everything; the PAN and account number never come back to the browser (masked last 4 only), and
 * the temporary upload is deleted right after the submit.
 */
final class Kyc extends Component
{
    use WithFileUploads;

    public string $pan = '';

    public ?TemporaryUploadedFile $panImage = null;

    public string $accountNumber = '';

    public string $ifsc = '';

    public string $accountName = '';

    public ?string $status = null;

    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::KycManage);
    }

    public function submit(SubmitBrokerKyc $submit): void
    {
        $image = $this->panImage instanceof TemporaryUploadedFile ? $this->panImage : null;

        try {
            $submit->handle($this->user(), new BrokerKycData($this->pan, $image, $this->accountNumber, $this->ifsc, $this->accountName));
        } finally {
            // Secrets never go back in the snapshot; the unencrypted temporary upload never outlives this request.
            $image?->delete();
            $this->panImage = null;
            $this->reset('pan', 'accountNumber');
        }

        $this->reset('ifsc', 'accountName');
        $this->resetErrorBag();
        $this->status = __('Thank you — your KYC has been sent. We’ll let you know as soon as it is checked.');
    }

    public function render(BureauGate $gate): View
    {
        $staff = $gate->authorize($this->user(), BureauPermission::KycManage);
        $broker = $staff->broker ?? abort(404);
        $latest = BrokerKyc::query()->where('broker_id', $broker->id)->latest()->orderByDesc('id')->first();

        return view('livewire.broker.kyc', [
            'broker' => $broker,
            'latest' => $latest,
            'showForm' => in_array($broker->kyc_status, [BrokerKycStatus::NotSubmitted, BrokerKycStatus::Rejected, BrokerKycStatus::Revoked], true),
            'maxMb' => intdiv((int) config('oppam.verification.max_kb'), 1024),
        ])->layout('layouts::broker', ['seo' => SeoData::private('Bureau KYC | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
