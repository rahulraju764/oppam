<?php

declare(strict_types=1);

namespace App\Livewire\Member\Chat;

use App\Data\Content\SeoData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /messages (PRD M07, template messages.php — 3/6/3: conversations | open thread | ads). The open
 * thread is addressed by the OTHER member's profile code (`?with=OPM…`; URLs never carry a ULID);
 * the Chat button of a connected pair links here. Below 992 px one pane at a time: the list until
 * a thread is chosen, then the thread with a back arrow. The page only composes Inbox and Thread;
 * each re-checks who may see what. A malformed code opens no thread.
 */
final class Messages extends Component
{
    #[Url(as: 'with', except: '')]
    public string $with = '';

    public function mount(): void
    {
        $this->with = self::validCode($this->with);
    }

    public static function validCode(string $code): string
    {
        return preg_match('/^OPM\d{1,10}$/', $code) === 1 ? $code : '';
    }

    public function render(): View
    {
        return view('livewire.member.chat.messages', [
            'open' => self::validCode($this->with),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Messages | Oppam Matrimony'),
        ]);
    }
}
