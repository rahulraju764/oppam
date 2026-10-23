<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Contracts\DocumentVault;
use App\Data\Broker\BrokerKycData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\KycQueue;
use App\Domain\Broker\PanNumber;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauPermission;
use App\Enums\KycSubmissionStatus;
use App\Models\Broker;
use App\Models\BrokerKyc;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentUploadReader;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The bureau Owner sends KYC (PRD §11A B.3: "PAN (number + image) and bank account for payouts,
 * stored on the private disk"; R-M13-24 Owner only). Rules:
 *  - `kyc.manage` through BureauGate (Owner — the applicant Owner too); one submission under review
 *    at a time; not while verified;
 *  - the PAN must have the PAN shape and be the PAN given in the application (one PAN = one bureau);
 *  - the PAN image is read by DocumentUploadReader (sniffed, re-encoded, plain PDFs) and encrypted
 *    into the DocumentVault before the row exists (removed again on failure); PAN and account
 *    number are stored with the `encrypted` cast, only their last 4 in the clear;
 *  - the bureau moves APPLIED → KYC_PENDING; the A07 queue badge goes up live; audited without
 *    the secrets; `oppam.broker.kyc_submissions_per_day` per bureau.
 */
final class SubmitBrokerKyc
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly DocumentVault $vault,
        private readonly DocumentUploadReader $reader,
        private readonly KycQueue $queue,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without kyc.manage
     * @throws ValidationException
     */
    public function handle(User $actor, BrokerKycData $data): BrokerKyc
    {
        $staff = $this->gate->authorize($actor, BureauPermission::KycManage);
        $pan = PanNumber::normalise($data->pan);
        $ifsc = strtoupper(trim($data->ifsc));
        $account = (string) preg_replace('/\s+/', '', $data->accountNumber);

        Validator::make(
            ['pan' => $pan, 'panImage' => $data->panImage, 'accountNumber' => $account, 'ifsc' => $ifsc, 'accountName' => trim($data->accountName)],
            [
                'pan' => ['required', 'regex:'.PanNumber::PATTERN],
                'panImage' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:'.implode(',', DocumentUploadReader::MIMES), 'max:'.(int) config('oppam.verification.max_kb')],
                'accountNumber' => ['required', 'regex:/^[0-9]{9,18}$/'],
                'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                'accountName' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’&-]*$/u"],
            ],
            [
                'pan.regex' => __('A PAN has 10 characters: 5 letters, 4 digits and a letter (e.g. ABCDE1234F).'),
                'accountNumber.regex' => __('Enter the account number as digits only (9 to 18).'),
                'ifsc.regex' => __('An IFSC has 11 characters, e.g. SBIN0001234.'),
                'accountName.regex' => __('Use letters only, as the name appears on the bank account.'),
            ],
            ['pan' => __('PAN'), 'panImage' => __('PAN card image'), 'accountNumber' => __('account number'), 'ifsc' => __('IFSC'), 'accountName' => __('account holder name')],
        )->validate();

        $broker = $staff->broker ?? abort(404);
        if ($broker->pan_hash !== null && ! hash_equals($broker->pan_hash, PanNumber::hash($pan))) {
            throw ValidationException::withMessages(['pan' => __('Use the same PAN as in your application. Contact support if it changed.')]);
        }

        $key = 'broker-kyc:'.$broker->id;
        if ($this->limiter->tooManyAttempts($key, (int) config('oppam.broker.kyc_submissions_per_day'))) {
            throw ValidationException::withMessages(['panImage' => __('You have sent KYC several times today. Please try again tomorrow.')]);
        }

        /** @var \Illuminate\Http\UploadedFile $image */
        $image = $data->panImage;
        $file = $this->reader->read($image, 'panImage');
        $path = $this->vault->put($file['bytes']);

        try {
            $kyc = DB::transaction(fn (): BrokerKyc => $this->store($actor, $broker, $pan, $account, $ifsc, trim($data->accountName), $path, $file['mime']));
        } catch (Throwable $e) {
            rescue(fn () => $this->vault->delete($path), report: true);

            throw $e;
        }

        $this->limiter->hit($key, 86400);
        $this->gate->forget();

        return $kyc;
    }

    /** @throws ValidationException */
    private function store(User $actor, Broker $broker, string $pan, string $account, string $ifsc, string $name, string $path, string $mime): BrokerKyc
    {
        $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();

        if ($locked->kyc_status === BrokerKycStatus::Verified) {
            throw ValidationException::withMessages(['pan' => __('Your bureau’s KYC is already verified.')]);
        }

        if ($locked->kyc_status === BrokerKycStatus::Pending || BrokerKyc::query()->where('broker_id', $locked->id)->where('status', KycSubmissionStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['pan' => __('Your KYC is already being reviewed. We’ll let you know as soon as it is done.')]);
        }

        $kyc = new BrokerKyc;
        $kyc->forceFill([
            'broker_id' => $locked->id,
            'pan_number_encrypted' => $pan,
            'pan_last4' => PanNumber::last4($pan),
            'pan_image_path' => $path,
            'pan_image_mime' => $mime,
            'bank_account_encrypted' => $account,
            'bank_last4' => substr($account, -4),
            'ifsc' => $ifsc,
            'account_name' => $name,
            'status' => KycSubmissionStatus::Pending,
        ])->save();

        $before = ['status' => $locked->status->value, 'kyc_status' => $locked->kyc_status->value];
        $locked->forceFill([
            'kyc_status' => BrokerKycStatus::Pending,
            'status' => $locked->status === BrokerStatus::Applied ? BrokerStatus::KycPending : $locked->status,
            'pan_hash' => $locked->pan_hash ?? PanNumber::hash($pan),
            'pan_last4' => $locked->pan_last4 ?? PanNumber::last4($pan),
        ])->save();

        $this->audit->record('broker.kyc_submitted', $locked, $before,
            ['status' => $locked->status->value, 'kyc_status' => BrokerKycStatus::Pending->value, 'pan' => $kyc->maskedPan(), 'account' => $kyc->maskedAccount()],
            actor: $actor, subjectLabel: $locked->code);
        $this->queue->announce();

        return $kyc;
    }
}
