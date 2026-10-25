<?php

declare(strict_types=1);

use App\Actions\Broker\EarnReferralCommission;
use App\Enums\BureauRole;
use App\Enums\ReferralStatus;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\Materials;
use App\Livewire\Broker\Referrals;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P7.2 — /broker/referrals (B.11 "own referrals only", R-M13-6 / R-M13-7: profile code, join date,
| stage, commission for the Owner — never names, photos or contact) and /broker/materials (code,
| link, QR SVG / PNG, A4 poster PDF), plus the dashboard's referral widget.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

function ownerOf(Broker $broker, BureauRole $role = BureauRole::Owner): User
{
    return actingAsBureau(bureauLogin($role, broker: $broker));
}

it('R-M13-6 / R-M13-7: the Owner sees their own referrals — code, join date, stage, commission — and nothing personal', function (): void {
    $broker = Broker::factory()->create();
    $converted = referredMember($broker, '+919811100001');
    paidOrder($converted, subtotal: 1_198_800);
    app(EarnReferralCommission::class)->handle($converted->profile->id);
    $joined = referredMember($broker, '+919811100002', App\Enums\ProfileStatus::Draft);
    $joined->profile->forceFill(['published_at' => null])->save();
    $foreign = referredMember(Broker::factory()->create(), '+919811100003');
    ownerOf($broker);

    Livewire::test(Referrals::class)
        ->assertSee($converted->profile->code)->assertSee('Converted')->assertSee('₹1,198.80')
        ->assertSee($joined->profile->code)->assertSee('Joined')
        ->assertSee('Commission')
        ->assertDontSee($foreign->profile->code)
        ->assertDontSee($converted->profile->first_name)->assertDontSee($converted->phone)->assertDontSee($converted->email ?? 'no-email@x');
});

it('B.10: a Manager sees the referral list without the commission column (Owner only)', function (): void {
    $broker = Broker::factory()->create();
    $member = referredMember($broker);
    paidOrder($member);
    app(EarnReferralCommission::class)->handle($member->profile->id);
    ownerOf($broker, BureauRole::Manager);

    Livewire::test(Referrals::class)->assertSee($member->profile->code)->assertSee('Converted')
        ->assertDontSee('Commission')->assertDontSee('₹999');
});

it('members who never proved their mobile are not listed (an unproved sign-up never counts)', function (): void {
    $broker = Broker::factory()->create();
    $member = referredMember($broker);
    $member->forceFill(['phone_verified_at' => null])->save();
    ownerOf($broker);

    Livewire::test(Referrals::class)->assertDontSee($member->profile->code)->assertSee('No referrals yet');
});

it('stage filter (#[Url]) and the refunded stage', function (): void {
    $broker = Broker::factory()->create();
    $live = referredMember($broker, '+919811100011');
    $refunded = referredMember($broker, '+919811100012');
    ledgerRow($refunded)->forceFill(['status' => ReferralStatus::Void])->save();
    ownerOf($broker);

    Livewire::withQueryParams(['stage' => 'refunded'])->test(Referrals::class)
        ->assertSee($refunded->profile->code)->assertSee('Refunded')->assertDontSee($live->profile->code)
        ->set('stage', 'live')
        ->assertSee($live->profile->code)->assertDontSee($refunded->profile->code)
        ->set('stage', 'converted')
        ->assertSee('No referrals at this stage');
});

it('paginates 25 a page, newest first', function (): void {
    $broker = Broker::factory()->create();
    $members = collect(range(1, 26))->map(function (int $i) use ($broker): User {
        $this->travel(1)->minutes();

        return referredMember($broker, '+9198111002'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    });
    ownerOf($broker);

    Livewire::test(Referrals::class)
        ->assertSee($members->last()->profile->code)->assertDontSee($members->first()->profile->code)
        ->call('gotoPage', 2)
        ->assertSee($members->first()->profile->code)
        ->assertSeeHtml('wire:loading.class="is-loading"');
});

it('security: a Telecaller or Data entry login gets 403 (BUREAU_PERMISSION); a member gets 404', function (BureauRole $role): void {
    ownerOf(Broker::factory()->create(), $role);

    Livewire::test(Referrals::class)->assertForbidden();
    $this->get(brokerUrl('/referrals'))->assertForbidden()->assertHeader('X-Error-Code', App\Domain\Broker\BureauGate::NOT_PERMITTED);
    $this->actingAs(groom(), 'web')->get(brokerUrl('/referrals'))->assertNotFound();
})->with([BureauRole::Telecaller, BureauRole::DataEntry]);

it('the Owner’s page listens for commission.earned on its own bureau channel only', function (): void {
    $broker = Broker::factory()->create();
    ownerOf($broker);

    expect((new Referrals)->getListeners())->toBe(['echo-private:broker-owner.'.$broker->id.',.commission.earned' => '$refresh']);

    ownerOf($broker, BureauRole::Manager);
    expect((new Referrals)->getListeners())->toBe([]);
});

it('B.4: the dashboard referral widget counts this bureau’s referrals', function (): void {
    $broker = Broker::factory()->create();
    $converted = referredMember($broker, '+919811100031');
    paidOrder($converted);
    app(EarnReferralCommission::class)->handle($converted->profile->id);
    referredMember($broker, '+919811100032');
    referredMember(Broker::factory()->create(), '+919811100033');
    ownerOf($broker);

    Livewire::test(Dashboard::class)->assertSeeHtml('<div><dt>Total</dt><dd>2</dd></div>')
        ->assertSeeHtml('<div><dt>Converted</dt><dd>1</dd></div>')
        ->assertSeeHtml('<div><dt>Conversion</dt><dd>50 %</dd></div>');
});

it('B.5 materials: code, copyable link with ?ref=, inline QR, the three downloads', function (): void {
    $broker = Broker::factory()->create(['code' => 'BRK1042']);
    ownerOf($broker, BureauRole::Telecaller);

    Livewire::test(Materials::class)
        ->assertSee('BRK1042')
        ->assertSee(route('home', ['ref' => 'BRK1042']))
        ->assertSeeHtml('src="data:image/svg+xml;base64,')
        ->assertSeeHtml('href="'.route('broker.materials.qr.svg').'"')
        ->assertSeeHtml('href="'.route('broker.materials.qr.png').'"')
        ->assertSeeHtml('href="'.route('broker.materials.poster').'"')
        ->assertDontSee('Referrals start counting');
});

it('materials downloads: SVG, PNG and an A4 PDF of the caller’s own bureau', function (): void {
    $broker = Broker::factory()->create(['code' => 'BRK1042', 'bureau_name' => 'Malabar Marriage Bureau']);
    ownerOf($broker, BureauRole::DataEntry);

    $svg = $this->get(brokerUrl('/materials/qr.svg'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Content-Disposition', 'attachment; filename="BRK1042-qr.svg"');
    expect($svg->getContent())->toContain('<svg');

    $png = $this->get(brokerUrl('/materials/qr.png'))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr((string) $png->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $pdf = $this->get(brokerUrl('/materials/poster.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('materials before KYC: open, with the note that referrals count after approval', function (): void {
    actingAsBureau(applicantOwner());

    Livewire::test(Materials::class)->assertSee('Referrals start counting once your bureau');
});

it('materials: guests are sent to log in; members get 404', function (): void {
    $this->get(brokerUrl('/materials/qr.png'))->assertRedirect(memberUrl('/login'));
    $this->actingAs(groom(), 'web')->get(brokerUrl('/materials/poster.pdf'))->assertNotFound();
});

it('no second ledger row ever appears for a profile (one commission per profile)', function (): void {
    $broker = Broker::factory()->create();
    $member = referredMember($broker);

    expect(fn () => BrokerReferral::factory()->create(['broker_id' => $broker->id, 'profile_id' => $member->profile->id]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
