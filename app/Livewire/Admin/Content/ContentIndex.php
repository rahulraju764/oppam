<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Content;

use App\Actions\Admin\Content\IssueContentPreview;
use App\Actions\Admin\Content\SaveContent;
use App\Actions\Admin\Content\TransitionContent;
use App\Actions\Admin\Content\WithdrawStory;
use App\Data\Content\ConsentInput;
use App\Domain\Content\LegalBodyFormatter;
use App\Domain\Content\TextDiff;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\HomeBlockKey;
use App\Exceptions\Admin\ContentRefused;
use App\Models\AdminUser;
use App\Models\EditorialContent;
use App\Models\HomeBlock;
use App\Models\LegalDocument;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * A08 (P8.1a): one screen for each content type (route default `type`). List by status, edit in a
 * dialog, move through the workflow, signed preview. State and calls only: every rule and permission
 * is in the Actions, which re-check on every call. Buttons are only hidden with @can.
 * Legal pages (P8.1b) list newest version first, start a new version from a published one and
 * compare a version with the one before it.
 */
final class ContentIndex extends Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $type = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    /** The row open in a dialog. */
    #[Locked]
    public ?string $editing = null;

    /** The version that dialog opened on: a save is refused if the row has moved on. */
    #[Locked]
    public ?int $editingVersion = null;

    #[Locked]
    public ?string $acting = null;

    #[Locked]
    public ?int $actingVersion = null;

    /** @var array<string, mixed> */
    public array $fields = [];

    public mixed $image = null;

    public bool $consentFirst = false;

    public bool $consentSecond = false;

    public string $consentNote = '';

    public string $reason = '';

    public string $scheduleAt = '';

    #[Locked]
    public ?string $previewUrl = null;

    /** The legal version being compared with the one before it. */
    #[Locked]
    public ?string $comparing = null;

    public function mount(string $type): void
    {
        $this->authorize('content.view');
        abort_if(ContentType::tryFrom($type) === null, 404);
        $this->type = $type;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('content.edit');
        abort_unless($this->contentType()->canCreate(), 404);
        $this->resetForm();
        $this->fields = ['sort_order' => 0] + match ($this->contentType()) {
            ContentType::Legal => ['effective_on' => now((string) config('oppam.display_timezone'))->format('Y-m-d'), 'is_material' => false, 'legally_reviewed' => false],
            ContentType::Ad => ['weight' => 1],
            default => [],
        };
        $this->dispatch('open-modal', name: 'content-form');
    }

    /** A new legal version, starting from the text of an existing one (P8.1b). */
    public function newVersion(string $code): void
    {
        $this->authorize('content.edit');
        abort_unless($this->contentType() === ContentType::Legal, 404);
        $source = $this->find($code);
        abort_unless($source instanceof LegalDocument, 404);
        $this->resetForm();

        $this->fields = [
            'sort_order' => 0,
            'kind' => $source->kind->value,
            'title' => $source->title,
            'summary' => '',
            'body' => $source->body,
            'effective_on' => now((string) config('oppam.display_timezone'))->format('Y-m-d'),
            'is_material' => false,
            'legally_reviewed' => false,
        ];
        $this->dispatch('open-modal', name: 'content-form');
    }

    public function compare(string $code): void
    {
        abort_unless($this->contentType() === ContentType::Legal, 404);
        $this->comparing = $this->find($code)->code;
        $this->dispatch('open-modal', name: 'content-compare');
    }

    public function edit(string $code): void
    {
        $this->authorize('content.edit');
        $row = $this->find($code);
        $this->resetForm();

        $this->editing = $row->code;
        $this->editingVersion = $row->lock_version;
        $current = $row->withDraft();
        $this->fields = collect(array_keys($this->contentType()->rules($row instanceof HomeBlock ? $row->block_key : null)))
            ->reject(fn (string $field): bool => str_starts_with($field, 'content.'))
            ->mapWithKeys(fn (string $field): array => [$field => $this->formValue($field, $current->getAttribute($field))])
            ->all();

        if ($current instanceof HomeBlock) {
            $this->fields['content'] = $current->content;
        }
        if ($row instanceof SuccessStory) {
            $this->consentFirst = $row->consent_a_at !== null;
            $this->consentSecond = $row->consent_b_at !== null;
            $this->consentNote = (string) $row->consent_note;
        }
        if ($row instanceof Testimonial) {
            $this->consentFirst = $row->consent_at !== null;
        }

        $this->dispatch('open-modal', name: 'content-form');
    }

    public function save(SaveContent $save): void
    {
        $this->resetErrorBag();
        $row = $this->editing !== null ? $this->find($this->editing) : null;
        $type = $this->contentType();
        $image = $this->image instanceof UploadedFile ? $this->image : null;
        $consent = in_array($type, [ContentType::Story, ContentType::Testimonial], true)
            ? new ConsentInput($this->consentFirst, $this->consentSecond, $this->consentNote)
            : null;

        try {
            $saved = $save->handle($this->admin(), $type, $row, $this->fields, $image, $consent, $this->editingVersion);
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $field => $messages) {
                $this->addError(in_array($field, ['image', 'consentNote'], true) ? $field : 'fields.'.$field, $messages[0]);
            }

            return;
        } catch (ContentRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'content-form');
        $this->dispatch('toast', type: 'success', title: $saved->isLive() ? __('Saved. The site keeps the published version until these changes are published.') : __('Saved.'));
        $this->resetForm();
    }

    public function submit(TransitionContent $transition, string $code): void
    {
        $this->step(fn () => $transition->submit($this->admin(), $this->find($code)), __('Sent for review.'));
    }

    public function backToDraft(TransitionContent $transition, string $code): void
    {
        $this->step(fn () => $transition->backToDraft($this->admin(), $this->find($code)), __('Back to draft.'));
    }

    public function publish(TransitionContent $transition, string $code, ?int $version = null): void
    {
        $this->step(fn () => $transition->publish($this->admin(), $this->find($code), $version), __('Published.'));
    }

    public function publishChanges(TransitionContent $transition, string $code, ?int $version = null): void
    {
        $this->step(fn () => $transition->publishChanges($this->admin(), $this->find($code), $version), __('Changes published.'));
    }

    public function discardChanges(TransitionContent $transition, string $code): void
    {
        $this->step(fn () => $transition->discardChanges($this->admin(), $this->find($code)), __('Changes discarded.'));
    }

    public function confirm(string $code, string $dialog, ?int $version = null): void
    {
        abort_unless(in_array($dialog, ['content-archive', 'content-withdraw', 'content-schedule'], true), 404);
        $row = $this->find($code);
        $this->acting = $row->code;
        $this->actingVersion = $version ?? $row->lock_version;
        $this->reset('reason', 'scheduleAt');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: $dialog);
    }

    public function schedule(TransitionContent $transition): void
    {
        $this->resetErrorBag();
        try {
            $at = CarbonImmutable::parse($this->scheduleAt, (string) config('oppam.display_timezone'));
        } catch (Throwable) {
            $this->addError('scheduleAt', __('Enter a date and time.'));

            return;
        }

        if ($this->step(fn () => $transition->schedule($this->admin(), $this->acted(), $at, $this->actingVersion), __('Scheduled.'))) {
            $this->dispatch('close-modal', name: 'content-schedule');
        }
    }

    public function archive(TransitionContent $transition): void
    {
        $this->resetErrorBag();
        if ($this->step(fn () => $transition->archive($this->admin(), $this->acted(), $this->reason), __('Archived.'))) {
            $this->dispatch('close-modal', name: 'content-archive');
        }
    }

    public function withdraw(WithdrawStory $withdraw): void
    {
        $this->resetErrorBag();
        $story = $this->acted();
        abort_unless($story instanceof SuccessStory, 404);
        if ($this->step(fn () => $withdraw->handle($this->admin(), $story, $this->reason), __('Story withdrawn. It is off the site and its photo is deleted.'))) {
            $this->dispatch('close-modal', name: 'content-withdraw');
        }
    }

    public function preview(IssueContentPreview $issue, string $code): void
    {
        $this->previewUrl = $issue->handle($this->admin(), $this->find($code));
        $this->dispatch('open-modal', name: 'content-preview');
    }

    public function render(): View
    {
        $type = $this->contentType();
        $status = ContentStatus::tryFrom($this->statusFilter);

        return view('livewire.admin.content.content-index', [
            'contentType' => $type,
            'rows' => $type->model()::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->when($type === ContentType::Legal, fn ($q) => $q->orderBy('kind')->orderByDesc('version'), fn ($q) => $q->orderBy('sort_order')->orderBy('id'))
                ->paginate(25),
            'comparison' => $this->comparison(),
            'statuses' => ContentStatus::cases(),
            'blockKey' => $this->editingBlockKey(),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::admin', ['title' => $type->label()]);
    }

    /** @param  Closure(): mixed  $action */
    private function step(Closure $action, string $done): bool
    {
        try {
            $action();
        } catch (ContentRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return false;
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }

        $this->dispatch('toast', type: 'success', title: $done);

        return true;
    }

    private function editingBlockKey(): ?HomeBlockKey
    {
        if ($this->editing === null || $this->contentType() !== ContentType::HomeBlock) {
            return null;
        }

        $row = $this->find($this->editing);

        return $row instanceof HomeBlock ? $row->block_key : null;
    }

    private function formValue(string $field, mixed $value): mixed
    {
        if ($value instanceof CarbonImmutable) {
            return in_array($field, $this->contentType()->dateTimeFields(), true)
                ? $value->timezone((string) config('oppam.display_timezone'))->format('Y-m-d\TH:i')
                : $value->format('Y-m-d');
        }

        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /** @return array{version: LegalDocument, previous: LegalDocument|null, lines: list<\App\Data\Content\DiffLineData>|null}|null */
    private function comparison(): ?array
    {
        if ($this->comparing === null) {
            return null;
        }
        $version = LegalDocument::query()->where('code', $this->comparing)->first();
        if ($version === null) {
            return null;
        }
        $previous = $version->previous();
        $formatter = app(LegalBodyFormatter::class);

        return [
            'version' => $version,
            'previous' => $previous,
            'lines' => app(TextDiff::class)->compare($formatter->blocks((string) $previous?->body), $formatter->blocks($version->body)),
        ];
    }

    private function contentType(): ContentType
    {
        return ContentType::from($this->type);
    }

    /** A row of this screen's type only; anything else is a 404. */
    private function find(string $code): EditorialContent
    {
        /** @var EditorialContent */
        return $this->contentType()->model()::query()->where('code', $code)->firstOrFail();
    }

    private function acted(): EditorialContent
    {
        return $this->find((string) $this->acting);
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'editingVersion', 'fields', 'image', 'consentFirst', 'consentSecond', 'consentNote', 'previewUrl');
        $this->resetErrorBag();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
