<?php

declare(strict_types=1);

use App\Actions\Admin\Settings\ToggleFeatureFlag;
use App\Actions\Chat\SendMessage;
use App\Actions\Engagement\ToggleLike;
use App\Data\Realtime\RealtimeContextData;
use App\Enums\Flag;
use App\Exceptions\Chat\ChatRefused;
use App\Exceptions\Engagement\EngagementRefused;
use App\Livewire\Admin\Settings\FeatureFlags;
use App\Livewire\Shared\LikeButton;
use App\Models\AuditLog;
use App\Models\FeatureFlag;
use App\Models\Like;
use App\Models\Message;
use App\Support\Facades\Feature;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P8.4 — A15 feature flags (owner 2026-11-12: wire the real ones, label the rest): every flag on the
| screen does something. likes.enabled, chat.images and realtime.enabled guard shipped features
| and default ON; push / boost are not built and can't be switched.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function flagOff(Flag $flag): void
{
    app(ToggleFeatureFlag::class)->handle(adminWithRole(), $flag, false, 'Test');
    app()->forgetScopedInstances();
}

it('A15 likes.enabled: on by default; off → a like is refused (nothing stored) and the button is gone', function (): void {
    $groom = groom();
    $bride = bride();
    $this->actingAs($groom);
    Livewire::test(LikeButton::class, ['profileCode' => $bride->profile->code])->assertSeeHtml('aria-pressed');

    flagOff(Flag::LikesEnabled);

    $refusal = null;
    try {
        app(ToggleLike::class)->handle($groom, $bride->profile, true);
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }
    expect($refusal?->reason)->toBe(EngagementRefused::FEATURE_DISABLED)
        ->and(Like::query()->count())->toBe(0);
    Livewire::test(LikeButton::class, ['profileCode' => $bride->profile->code])->assertDontSeeHtml('aria-pressed');
});

it('A15 chat.images: off → no photo message, even on a paid plan and the thread hides the photo button', function (): void {
    fakeMediaDisks();
    [$groom, $bride] = chatConnected();
    flagOff(Flag::ChatImages);

    expect(fn () => app(SendMessage::class)->image($groom, $bride->profile, gradientPhoto(), (string) Str::uuid()))
        ->toThrow(ChatRefused::class);
    expect(Message::query()->where('type', 'IMAGE')->count())->toBe(0);

    $this->actingAs($groom);
    Livewire::test(App\Livewire\Member\Chat\Thread::class, ['withCode' => $bride->profile->code])->assertDontSeeHtml('id="msgPhoto"');
});

it('A15 chat.images: on (default) → a paid member sees the photo button', function (): void {
    [$groom, $bride] = chatConnected();
    $this->actingAs($groom);
    Livewire::test(App\Livewire\Member\Chat\Thread::class, ['withCode' => $bride->profile->code])->assertSeeHtml('id="msgPhoto"');
});

it('A15 realtime.enabled: on by default; off → member pages open no WebSocket', function (): void {
    $this->actingAs(groom());
    expect(RealtimeContextData::current())->not->toBeNull();

    flagOff(Flag::RealtimeEnabled);

    expect(RealtimeContextData::current())->toBeNull();
});

it('A15 flags screen: switching asks for a reason; the server decides the new state; audited', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(FeatureFlags::class)->assertOk()
        ->assertSee(Flag::LikesEnabled->label())->assertSee('Not built yet')
        ->call('promptToggle', 'broker.staff')->assertSet('pending', 'broker.staff')->assertSet('pendingState', true)
        ->assertDispatched('open-modal', name: 'flag-toggle')
        ->set('reason', 'Staff accounts launch')->call('confirmToggle')->assertDispatched('toast');

    app()->forgetScopedInstances();
    expect(Feature::active(Flag::BrokerStaff))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'feature_flags.toggled')->sole()->reason)->toBe('Staff accounts launch');
});

it('A15 flags security: push / boost (not built) can\'t be switched; tampered target state refused; view-only → 403; no reason → error', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(FeatureFlags::class)->assertDontSeeHtml('dusk="flag-push.enabled"')
        ->call('promptToggle', 'push.enabled')->assertNotFound();
    expect(fn () => app(ToggleFeatureFlag::class)->handle(adminWithRole(), Flag::BoostEnabled, true, 'Try'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(fn () => Livewire::test(FeatureFlags::class)->call('promptToggle', 'likes.enabled')->set('pendingState', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(FeatureFlags::class)->call('promptToggle', 'likes.enabled')->set('reason', '')->call('confirmToggle')->assertHasErrors(['reason']);

    $this->actingAs(adminWithRole('read_only'), 'admin');
    Livewire::test(FeatureFlags::class)->assertOk()->assertDontSeeHtml('dusk="flag-likes.enabled"')
        ->call('promptToggle', 'likes.enabled')->assertForbidden();

    expect(FeatureFlag::query()->count())->toBe(0);
});

it('A15 data fix: the migration switches seeded-off rows of shipped features ON, but keeps an admin\'s choice', function (): void {
    (new FeatureFlag)->forceFill(['key' => 'likes.enabled', 'is_enabled' => false])->save();                                   // seeded
    (new FeatureFlag)->forceFill(['key' => 'chat.images', 'is_enabled' => false, 'updated_by_id' => adminWithRole()->id])->save(); // an admin's
    (new FeatureFlag)->forceFill(['key' => 'broker.staff', 'is_enabled' => false])->save();                                    // default off

    (require database_path('migrations/2026_11_12_200000_wire_a15_settings_and_flags.php'))->up();

    expect(FeatureFlag::query()->pluck('is_enabled', 'key')->map(fn ($on) => (bool) $on)->all())
        ->toEqualCanonicalizing(['likes.enabled' => true, 'chat.images' => false, 'broker.staff' => false]);
});
