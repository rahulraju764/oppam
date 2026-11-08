<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Auth\ConfirmEmailChange;
use App\Data\Content\SeoData;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /settings/email/verify/{token} (M14, P8.2b): the page an email confirmation link opens. Works
 * signed in or out (the link itself is the proof); a click confirms — opening the page alone
 * changes nothing, so mail scanners that fetch links can't use it up.
 */
final class VerifyEmail extends Component
{
    #[Locked]
    public string $token = '';

    public bool $done = false;

    public ?string $error = null;

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function confirm(ConfirmEmailChange $confirm): void
    {
        $signedIn = auth('web')->user();

        try {
            $confirm->handle($this->token, $signedIn instanceof User ? $signedIn : null);
        } catch (AccountChangeRefused $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->done = true;
        $this->error = null;
    }

    public function render(): View
    {
        return view('livewire.member.settings.verify-email', ['signedIn' => auth('web')->check()])
            ->layout('layouts::public', ['seo' => SeoData::private(__('Confirm your email').' | Oppam Matrimony')]);
    }
}
