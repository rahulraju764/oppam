<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Data\Content\SeoData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /settings (PRD M14): the member's settings in tabs, switched without a reload (`?tab=`). Each tab
 * is its own lazy component that loads and saves only the member's own data through its Action.
 * Account arrived with P8.2b; Hide / delete (P8.2c) is added when it is built — no empty tabs.
 */
final class Settings extends Component
{
    /** @var array<string, string> tab => label key */
    public const TABS = [
        'privacy' => 'Privacy',
        'account' => 'Account',
        'notifications' => 'Notifications',
        'blocked' => 'Blocked & ignored',
    ];

    #[Url(except: 'privacy')]
    public string $tab = 'privacy';

    public function show(string $tab): void
    {
        $this->tab = self::allowed($tab);
    }

    public function render(): View
    {
        $this->tab = self::allowed($this->tab);

        return view('livewire.member.settings.settings', ['tabs' => self::TABS])
            ->layout('layouts::member', ['seo' => SeoData::private(__('Settings').' | Oppam Matrimony')]);
    }

    private static function allowed(string $tab): string
    {
        return array_key_exists($tab, self::TABS) ? $tab : 'privacy';
    }
}
