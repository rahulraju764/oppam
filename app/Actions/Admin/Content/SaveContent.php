<?php

declare(strict_types=1);

namespace App\Actions\Admin\Content;

use App\Contracts\HtmlSanitizer;
use App\Data\Content\ConsentInput;
use App\Domain\Content\LegalBodyFormatter;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Exceptions\Admin\ContentRefused;
use App\Models\AdminUser;
use App\Models\EditorialContent;
use App\Models\HomeBlock;
use App\Models\LegalDocument;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use App\Rules\CasteBelongsToReligion;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ContentImageStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A08 edit (`content.edit`, audited): only the type's own fields are taken, rich text is sanitised.
 * A live row keeps showing its published text; the edit waits in `draft` until a publisher releases
 * it. An edit to an IN_REVIEW or SCHEDULED row sends it back to DRAFT: what a publisher approved is
 * what goes out. Consent is recorded on a row that isn't live; on a live story it only changes by
 * withdrawing. A dialog opened on an older version is refused, so a stale form can't overwrite a change
 * or re-record consent on a story withdrawn meanwhile. A withdrawn story takes no edits until restored.
 * A published legal version takes no edits at all (P8.1b): members agreed to that exact text, so the
 * change goes into a new version. A version's kind and number are fixed when it is created.
 */
final class SaveContent
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ContentImageStore $images,
        private readonly LegalBodyFormatter $legal,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ContentRefused
     */
    public function handle(AdminUser $admin, ContentType $type, ?EditorialContent $row, array $input, ?UploadedFile $image = null, ?ConsentInput $consent = null, ?int $expectedVersion = null): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.edit');

        if ($row === null && ! $type->canCreate()) {
            throw ContentRefused::cannotCreate();
        }

        $values = $this->validated($type, $row, $input, $image);
        $this->validateConsent($type, $row, $consent);
        $stored = [];

        try {
            return DB::transaction(function () use ($admin, $type, $row, $values, $image, $consent, $expectedVersion, &$stored): EditorialContent {
                $class = $type->model();
                /** @var EditorialContent $locked */
                $locked = $row === null ? new $class : $class::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

                if ($locked instanceof SuccessStory && $locked->isWithdrawn()) {
                    throw ContentRefused::withdrawnRow();
                }
                if ($locked instanceof LegalDocument && $locked->exists && ($locked->isLive() || $locked->published_at !== null)) {
                    throw ContentRefused::publishedVersion();
                }
                if ($locked->exists && $expectedVersion !== null && $locked->lock_version !== $expectedVersion) {
                    throw ContentRefused::changedMeanwhile();
                }

                $before = $locked->exists ? ['status' => $locked->status->value, ...$this->consentState($locked)] : [];
                $stored = $image !== null ? $this->images->store($image) : [];
                // First at commit: from here on the row points at this file, whatever a later callback does.
                DB::afterCommit(function () use (&$stored): void {
                    $stored = [];
                });
                $changes = [...$values, ...$stored];

                // The upload this one replaces, if nothing else shows it: a waiting image, or a row not on the site.
                $replaced = $stored === [] ? null : ($locked->isLive() ? ($locked->draft['image_path'] ?? null) : $locked->getAttribute('image_path'));
                DB::afterCommit(fn () => $this->images->delete(is_string($replaced) ? $replaced : null));

                if ($locked->isLive()) {
                    $locked->forceFill(['draft' => [...($locked->draft ?? []), ...$changes]]);
                } else {
                    $locked->forceFill($changes);
                    $this->applyConsent($locked, $consent);
                    if (in_array($locked->status, [ContentStatus::Scheduled, ContentStatus::InReview], true)) {
                        $locked->forceFill(['status' => ContentStatus::Draft, 'scheduled_at' => null]);
                    }
                }

                if (! $locked->exists) {
                    $this->prepareNew($locked, $type);
                }

                $locked->forceFill(['updated_by_admin_id' => $admin->id])->save();

                // Field names and consent yes / no only: story text can name people and must be erasable, the audit log isn't.
                $this->audit->record('content.saved', $locked, $before, ['status' => $locked->status->value, 'fields' => array_keys($changes), 'waiting' => $locked->hasPendingChanges(), ...$this->consentState($locked)], actor: $admin, subjectLabel: $locked->code);

                return $locked;
            });
        } catch (Throwable $failed) {
            $this->images->delete($stored['image_path'] ?? null);

            throw $failed;
        }
    }

    /** @return array<string, array<string, bool>> who has consented, for the audit log; no note text */
    private function consentState(EditorialContent $row): array
    {
        return match (true) {
            $row instanceof SuccessStory => ['consent' => ['first' => $row->consent_a_at !== null, 'second' => $row->consent_b_at !== null]],
            $row instanceof Testimonial => ['consent' => ['first' => $row->consent_at !== null]],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(ContentType $type, ?EditorialContent $row, array $input, ?UploadedFile $image): array
    {
        $block = $row instanceof HomeBlock ? $row->block_key : null;
        $rules = [...$type->rules($block), ...$this->contextRules($type, $row, $input)];
        if ($row !== null) {
            foreach ($type->createOnlyFields() as $field) {
                unset($rules[$field], $input[$field]);
            }
        }

        foreach ($type->richFields() as $field) {
            $input[$field] = is_string($input[$field] ?? null)
                ? ($type->allowsDocumentMarkup() ? $this->legal->format($this->sanitizer->cleanDocument($input[$field])) : $this->sanitizer->clean($input[$field]))
                : '';
            if (trim(strip_tags((string) $input[$field])) === '') {
                $input[$field] = '';
            }
        }

        $checked = Validator::make($input + ['image' => $image], $rules + ['image' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp']])->validate();
        unset($checked['image']);

        $checked = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $checked);

        // Typed in IST on a datetime-local input, stored in UTC.
        foreach ($type->dateTimeFields() as $field) {
            if (is_string($checked[$field] ?? null)) {
                $checked[$field] = CarbonImmutable::parse($checked[$field], (string) config('oppam.display_timezone'))->utc();
            }
        }

        return $checked;
    }

    /**
     * Rules that depend on the row or the other input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    private function contextRules(ContentType $type, ?EditorialContent $row, array $input): array
    {
        if ($type !== ContentType::Community) {
            return [];
        }

        $religion = is_numeric($input['religion_id'] ?? null) ? (int) $input['religion_id'] : null;

        return [
            'slug' => [...$type->rules()['slug'], Rule::unique('community_pages', 'slug')->ignore($row?->id)],
            'caste_id' => [...$type->rules()['caste_id'], new CasteBelongsToReligion($religion)],
        ];
    }

    /** @throws ValidationException */
    private function validateConsent(ContentType $type, ?EditorialContent $row, ?ConsentInput $consent): void
    {
        if ($type === ContentType::Story && $consent !== null && ($row === null || ! $row->isLive())) {
            $note = trim($consent->note);
            if (($consent->first || $consent->second) && (mb_strlen($note) < 5 || mb_strlen($note) > 500)) {
                throw ValidationException::withMessages(['consentNote' => __('Say how consent was given, in 5 to 500 characters.')]);
            }
        }
    }

    private function applyConsent(EditorialContent $row, ?ConsentInput $consent): void
    {
        if ($consent === null) {
            return;
        }

        if ($row instanceof SuccessStory) {
            $row->forceFill([
                'consent_a_at' => $consent->first ? ($row->consent_a_at ?? now()) : null,
                'consent_b_at' => $consent->second ? ($row->consent_b_at ?? now()) : null,
                'consent_note' => $consent->first || $consent->second ? trim($consent->note) : null,
            ]);
        }

        if ($row instanceof Testimonial) {
            $row->forceFill(['consent_at' => $consent->first ? ($row->consent_at ?? now()) : null]);
        }
    }

    private function prepareNew(EditorialContent $row, ContentType $type): void
    {
        $class = $type->model();
        do {
            $code = $type->codePrefix().Str::upper(Str::random(8));
        } while ($class::query()->where('code', $code)->exists());

        $row->forceFill(['code' => $code, 'status' => ContentStatus::Draft]);

        if ($row instanceof LegalDocument) {
            $row->forceFill(['version' => (int) LegalDocument::query()->where('kind', $row->kind)->max('version') + 1]);
        }

        if ($row instanceof SuccessStory) {
            // The anchor stays the same for the story's life, whatever is edited later.
            $row->forceFill(['slug' => Str::slug($row->couple).'-'.Str::lower(Str::random(6))]);
        }
    }
}
