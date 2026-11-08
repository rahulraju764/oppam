<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Auth\SignInMember;
use App\Actions\Profile\RestoreClosedAccount;
use App\Data\Content\SeoData;
use App\Domain\Auth\ClosedAccountLookup;
use App\Enums\SettingKey;
use App\Exceptions\Auth\LoginFailed;
use App\Models\User;
use App\Support\Auth\PendingAccountRestore;
use App\Support\Facades\Settings;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * /account/restore — "Your account is closed. Restore it?" (M14, P8.2c — owner 2026-11-08). Reached
 * only from the Login screen after the right password or login code for an account the member
 * closed themselves (PendingAccountRestore, 10 minutes). Nothing here takes an id from the
 * browser. "Restore" brings the account back and signs the member in; "Not now" leaves it closed.
 */
final class RestoreAccount extends Component
{
    public function mount(PendingAccountRestore $pending, ClosedAccountLookup $closed): mixed
    {
        if ($this->account($pending, $closed) === null) {
            $pending->forget();

            return $this->redirectRoute('login', navigate: false);
        }

        return null;
    }

    public function restore(PendingAccountRestore $pending, ClosedAccountLookup $closed, RestoreClosedAccount $restore,
        SignInMember $signIn, MemberLanding $landing): mixed
    {
        $state = $pending->current();
        $account = $this->account($pending, $closed);

        if ($state === null || $account === null) {
            $pending->forget();
            session()->flash('status', LoginFailed::invalidCredentials()->getMessage());

            return $this->redirectRoute('login', navigate: false);
        }

        try {
            $member = $restore->handle($account);
        } catch (LoginFailed $failed) {
            $pending->forget();
            session()->flash('status', $failed->getMessage());

            return $this->redirectRoute('login', navigate: false);
        }

        $pending->forget();
        $signIn->handle($member, $state['method'], $state['remember'], request());
        session()->flash('status', __('Welcome back! Your account and profile are restored.'));

        return $this->redirect($landing->url($member), navigate: false);
    }

    public function notNow(PendingAccountRestore $pending): mixed
    {
        $pending->forget();

        return $this->redirectRoute('home', navigate: false);
    }

    public function render(PendingAccountRestore $pending, ClosedAccountLookup $closed): View
    {
        $account = $this->account($pending, $closed);
        $closure = $account !== null ? $closed->closureFor($account) : null;
        $deletedAt = $account?->deleted_at;

        return view('livewire.member.auth.restore-account', [
            'closedOn' => $closure?->created_at->timezone('Asia/Kolkata'),
            'restoreUntil' => $deletedAt !== null
                ? Carbon::parse($deletedAt)->addDays(Settings::int(SettingKey::MembersPurgeAfterDays))->timezone('Asia/Kolkata')
                : null,
        ])->layout('layouts::public', ['seo' => SeoData::private('Restore your account | Oppam Matrimony')]);
    }

    private function account(PendingAccountRestore $pending, ClosedAccountLookup $closed): ?User
    {
        $state = $pending->current();

        return $state !== null ? $closed->restorable(User::onlyTrashed()->find($state['user'])) : null;
    }
}
