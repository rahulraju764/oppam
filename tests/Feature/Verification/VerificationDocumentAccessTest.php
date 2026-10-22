<?php

declare(strict_types=1);

use App\Actions\Admin\Verification\ClaimVerificationRequest;
use App\Actions\Admin\Verification\OpenVerificationDocuments;
use App\Contracts\DocumentVault;
use App\Enums\DocumentSide;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

/*
| P6.1 Done-when — "document never reachable by public URL (test); every view audited."
| M09 acceptance: "ID documents are never served from a public URL; access requires
| verification.document.view and is audited." A05: 10-minute signed links, in-browser viewer
| only (no-store), watermark, every view audited, separate permission.
*/

beforeEach(function (): void {
    seedAdminRoles();
    fakeVerificationDisk();
    Storage::fake('public');
});

/** A waiting request with a photo front and a PDF back, and an officer whose session opened the viewer. */
function openViewer(mixed $test = null, ?AdminUser $officer = null): array
{
    $test ??= test();
    $request = submitId(verificationMember(), ['back' => idPdf()]);
    $officer ??= adminWithRole('verification_officer');
    signInAdmin($test, $officer);
    $urls = app(OpenVerificationDocuments::class)->handle($officer, $request);

    return [$request->refresh(), $officer, $urls];
}

function views(): int
{
    return AuditLog::query()->where('action', 'verification.document_viewed')->count();
}

it('Done-when: the file has no public URL — not on the public disk, not under /storage, stored encrypted under a name that says nothing', function (): void {
    $request = submitId(verificationMember());
    $document = $request->documents->first();
    $path = (string) $document->path;

    expect(Storage::disk('public')->exists($path))->toBeFalse()
        ->and($path)->toMatch('#^verification/[0-9a-z]{26}$#')
        ->and(Storage::disk($document->disk)->get($path))->not->toContain("\xFF\xD8\xFF");   // no JPEG marker: ciphertext

    // Member site, public paths and the framework's storage route: nothing.
    $this->get('/storage/'.$path)->assertNotFound();
    $this->get('/storage/app/private/'.$path)->assertNotFound();
    $this->get('/'.$path)->assertNotFound();

    // The admin route doesn't exist on the member host; a member never reaches it.
    $this->seed(Database\Seeders\PlansSeeder::class);   // the member layout of the 404 page shows the plan
    $this->actingAs($request->profile->user)->get('http://'.config('oppam.app_domain').'/verification/'.$request->profile->code.'/document/front')->assertNotFound();
    expect(views())->toBe(0);
});

it('Done-when: the signed link serves the granted officer a watermarked, uncached copy — and every fetch is audited', function (): void {
    [$request, $officer, $urls] = openViewer($this);

    expect(array_keys($urls))->toBe(['front', 'back'])
        ->and(AuditLog::query()->where('action', 'verification.documents_opened')->sole()->actor_id)->toBe($officer->id);

    $front = $this->get($urls['front'])->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
    expect($front->headers->get('Cache-Control'))->toContain('no-store')
        ->and($front->headers->get('Content-Disposition'))->toStartWith('inline')
        // Watermarked: not the stored copy.
        ->and($front->getContent())->not->toBe(app(DocumentVault::class)->get((string) $request->documents->firstWhere('side', DocumentSide::Front)?->path));

    $this->get($urls['back'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get($urls['front'])->assertOk();

    $rows = AuditLog::query()->where('action', 'verification.document_viewed')->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([$officer->id])
        ->and($rows->pluck('subject_id')->unique()->all())->toBe([$request->id])
        ->and($rows->pluck('after.side')->all())->toBe(['FRONT', 'BACK', 'FRONT']);
});

it('refuses an unsigned, tampered or expired link (and audits nothing)', function (): void {
    [, , $urls] = openViewer($this);

    $this->get(strtok($urls['front'], '?'))->assertForbidden();
    $this->get($urls['front'].'x')->assertForbidden();
    $this->get(str_replace('/front?', '/back?', $urls['front']))->assertForbidden();

    $this->travel(11)->minutes();
    $this->get($urls['front'])->assertForbidden();

    expect(views())->toBe(0);
});

it('a copied link is useless to another admin, even one with the permission (bound to the granted session)', function (): void {
    [, , $urls] = openViewer($this);

    $this->flushSession();
    signInAdmin($this, adminWithRole('super_admin'));

    $this->get($urls['front'])->assertNotFound();
    expect(views())->toBe(0);
});

it('needs verification.document.view — separate from approve (A05)', function (): void {
    $request = submitId(verificationMember());
    $approver = adminWithRole('verification_officer');
    $approver->revokePermissionTo('verification.document.view');
    $approver->syncRoles([]);
    $approver->givePermissionTo(['verification.queue.view', 'verification.approve']);

    expect(fn () => app(OpenVerificationDocuments::class)->handle($approver->refresh(), $request))->toThrow(AuthorizationException::class);

    // Even a session that somehow holds a grant gets 403 without the permission.
    [, , $urls] = openViewer($this);
    signInAdmin($this, adminWithRole('moderator'));
    $this->get($urls['front'])->assertForbidden();
    expect(views())->toBe(0);
});

it('is never opened as a page of its own or framed (Sec-Fetch-Dest)', function (string $destination): void {
    [, , $urls] = openViewer($this);

    $this->withHeader('Sec-Fetch-Dest', $destination)->get($urls['front'])->assertNotFound();
    expect(views())->toBe(0);
})->with(['document', 'iframe', 'embed', 'object']);

it('while another officer holds the claim, the documents stay closed', function (): void {
    $request = submitId(verificationMember());
    $holder = adminWithRole('verification_officer');
    app(ClaimVerificationRequest::class)->handle($holder, $request);

    expect(fn () => app(OpenVerificationDocuments::class)->handle(adminWithRole('verification_officer'), $request->refresh()))
        ->toThrow(VerificationRequestUnavailable::class)
        ->and(AuditLog::query()->where('action', 'verification.documents_opened')->count())->toBe(0);
});

it('a purged document is gone for good (404), and the viewer won’t open', function (): void {
    [$request, $officer, $urls] = openViewer($this);
    $request->documents->each(fn (VerificationDocument $d) => app(DocumentVault::class)->delete((string) $d->path));
    VerificationDocument::query()->update(['purged_at' => now(), 'path' => null]);

    $this->get($urls['front'])->assertNotFound();

    $request->forceFill(['documents_purged_at' => now()])->save();
    expect(fn () => app(OpenVerificationDocuments::class)->handle($officer, $request))->toThrow(VerificationRequestUnavailable::class)
        ->and(views())->toBe(0);
});

it('a link names only the profile code and the side — never a database id', function (): void {
    [$request, , $urls] = openViewer($this);

    foreach ($urls as $url) {
        expect($url)->toContain('/verification/'.$request->profile->code.'/document/')
            ->not->toContain($request->id)
            ->not->toContain((string) $request->documents->first()?->id);
    }

    expect(VerificationRequest::query()->count())->toBe(1);
});

it('A05 pages: guests go to the admin login, admins without verification.queue.view get 403, officers 200', function (): void {
    $request = submitId(verificationMember());
    $code = $request->profile->code;

    $this->get(adminUrl('/verification'))->assertRedirect(adminUrl('/login'));

    signInAdmin($this, adminWithRole('finance'));
    $this->get(adminUrl('/verification'))->assertForbidden();
    $this->get(adminUrl('/verification/'.$code))->assertForbidden();

    $this->flushSession();
    signInAdmin($this, adminWithRole('verification_officer'));
    $this->get(adminUrl('/verification'))->assertOk()->assertSee($code);
    $this->get(adminUrl('/verification/'.$code))->assertOk()->assertSee('View documents');
    $this->get(adminUrl('/verification/OPM99999'))->assertNotFound();
});
