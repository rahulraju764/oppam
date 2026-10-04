<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Masters;

use App\Actions\Admin\Masters\DeleteMasterRow;
use App\Actions\Admin\Masters\ImportMasterRows;
use App\Actions\Admin\Masters\ReorderMasterRows;
use App\Actions\Admin\Masters\SaveMasterRow;
use App\Actions\Admin\Masters\SetMasterRowActive;
use App\Data\Masters\MasterImportRow;
use App\Data\Masters\MasterRowData;
use App\Domain\Masters\MasterList;
use App\Domain\Masters\MasterLists;
use App\Domain\Masters\MasterUsage;
use App\Exceptions\Admin\MasterRowInUse;
use App\Models\AdminUser;
use App\Models\Masters\MasterRecord;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A11 list editor (one master list; castes / states / districts one parent at a time): add a row
 * (code once, label, Malayalam label), edit labels inline, activate / deactivate, delete an unused
 * row, reorder by drag-and-drop or the move buttons, see how many profiles use each row, export
 * and import CSV with a preview diff. Viewing needs masters.view; every change masters.edit,
 * checked again in its Action. Every write flushes the masters cache (MasterDataObserver), so the
 * wizard shows it on the next request.
 */
#[Layout('layouts::admin', ['title' => 'Master data'])]
final class ListEditor extends Component
{
    use WithFileUploads;

    private const MAX_ROWS = 1000;

    #[Locked]
    public string $listKey = '';

    #[Url(except: '')]
    public string $parent = '';

    #[Url(except: '')]
    public string $q = '';

    public string $newCode = '';

    public string $newLabel = '';

    public string $newLabelMl = '';

    #[Locked]
    public ?int $editId = null;

    public string $editLabel = '';

    public string $editLabelMl = '';

    /** @var UploadedFile|null */
    public $csv = null;

    /** @var list<array<string, mixed>> the last import preview (shown only; apply parses the file again) */
    #[Locked]
    public array $preview = [];

    public function mount(string $list): void
    {
        $this->authorize('masters.view');
        $this->listKey = $list;
        $master = $this->list();

        if ($master->hasParent() && ! array_key_exists((int) $this->parent, $this->parents())) {
            $this->parent = (string) (array_key_first($this->parents()) ?? '');
        }
    }

    public function updatedParent(): void
    {
        $this->reset('editId', 'preview', 'csv');
        if ($this->list()->hasParent() && ! array_key_exists((int) $this->parent, $this->parents())) {
            $this->parent = (string) (array_key_first($this->parents()) ?? '');
        }
    }

    /** A new file needs a new preview: applying always goes through what was previewed. */
    public function updatedCsv(): void
    {
        $this->preview = [];
        $this->resetErrorBag('csv');
    }

    public function add(SaveMasterRow $save): void
    {
        $this->authorize('masters.edit');
        $this->attempt(function () use ($save): void {
            $save->create($this->admin(), $this->list(), $this->parentId(), MasterRowData::from($this->newCode, $this->newLabel, $this->newLabelMl));
            $this->reset('newCode', 'newLabel', 'newLabelMl');
            $this->dispatch('toast', type: 'success', message: __('Added.'));
        }, 'new');
    }

    public function edit(int $id): void
    {
        $this->authorize('masters.edit');
        $row = $this->list()->query($this->parentId())->whereKey($id)->firstOrFail();
        $this->editId = (int) $row->getKey();
        $this->editLabel = $row->label;
        $this->editLabelMl = (string) $row->label_ml;
    }

    public function cancelEdit(): void
    {
        $this->reset('editId', 'editLabel', 'editLabelMl');
    }

    public function saveEdit(SaveMasterRow $save): void
    {
        $this->authorize('masters.edit');
        if ($this->editId === null) {
            return;
        }

        $this->attempt(function () use ($save): void {
            $row = $this->list()->query($this->parentId())->whereKey((int) $this->editId)->firstOrFail();
            $save->update($this->admin(), $this->list(), $this->parentId(), (int) $this->editId,
                MasterRowData::from($row->code, $this->editLabel, $this->editLabelMl));
            $this->cancelEdit();
            $this->dispatch('toast', type: 'success', message: __('Saved.'));
        }, 'edit');
    }

    public function toggle(int $id, SetMasterRowActive $set): void
    {
        $this->authorize('masters.edit');
        $row = $this->list()->query($this->parentId())->whereKey($id)->firstOrFail();
        $set->handle($this->admin(), $this->list(), $this->parentId(), $id, ! $row->is_active);
    }

    public function delete(int $id, DeleteMasterRow $delete): void
    {
        $this->authorize('masters.edit');

        try {
            $delete->handle($this->admin(), $this->list(), $this->parentId(), $id);
            $this->dispatch('toast', type: 'success', message: __('Deleted.'));
        } catch (MasterRowInUse $inUse) {
            $this->dispatch('toast', type: 'error', message: $inUse->getMessage());
        }
    }

    /** Move one row up (-1) or down (+1) — the keyboard alternative to drag-and-drop. */
    public function move(int $id, int $direction, ReorderMasterRows $reorder): void
    {
        $this->authorize('masters.edit');
        $ids = $this->list()->query($this->parentId())->orderBy('sort_order')->orderBy('label')->pluck('id')->map(fn ($v): int => (int) $v)->all();
        $at = array_search($id, $ids, true);
        $to = is_int($at) ? $at + ($direction < 0 ? -1 : 1) : -1;

        if (! is_int($at) || $to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->reorder($ids, $reorder);
    }

    /** @param  list<int|string>  $ids  the new order from drag-and-drop */
    public function reorder(array $ids, ReorderMasterRows $reorder): void
    {
        $this->authorize('masters.edit');

        try {
            $reorder->handle($this->admin(), $this->list(), $this->parentId(), array_map('intval', $ids));
        } catch (ValidationException $stale) {
            $this->dispatch('toast', type: 'error', message: (string) collect($stale->errors())->flatten()->first());
        }
    }

    public function previewImport(ImportMasterRows $import): void
    {
        $this->authorize('masters.edit');
        $this->validate(['csv' => ['required', 'file', 'max:1024', 'mimes:csv,txt']]);

        $this->attempt(function () use ($import): void {
            $this->preview = array_map(fn (MasterImportRow $row): array => $row->toArray(),
                $import->preview($this->admin(), $this->list(), $this->parentId(), $this->csvContents()));
        }, 'csv');
    }

    public function applyImport(ImportMasterRows $import): void
    {
        $this->authorize('masters.edit');

        $this->attempt(function () use ($import): void {
            $result = $import->apply($this->admin(), $this->list(), $this->parentId(), $this->csvContents());
            $this->reset('csv', 'preview');
            $this->dispatch('toast', type: 'success', message: __(':added added, :updated updated.', $result));
        }, 'csv');
    }

    public function render(MasterUsage $usage): View
    {
        $list = $this->list();

        /** @var Collection<int, MasterRecord> $rows */
        $rows = $list->query($this->parentId())
            ->when($this->q !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('label', 'like', addcslashes($this->q, '%_\\').'%')
                ->orWhere('code', 'like', addcslashes(strtoupper($this->q), '%_\\').'%')))
            ->orderBy('sort_order')->orderBy('label')
            ->limit(self::MAX_ROWS)->get();

        return view('livewire.admin.masters.list-editor', [
            'list' => $list,
            'rows' => $rows,
            'usage' => $usage->counts($list, $rows->map(fn (MasterRecord $row): int => (int) $row->getKey())->values()->all()),
            'parents' => $list->hasParent() ? $this->parents() : [],
            'parentLabel' => $list->hasParent() ? __(MasterLists::find((string) $list->parentKey)->label ?? '') : null,
            'canEdit' => $this->admin()->can('masters.edit'),
            'canAdd' => $this->admin()->can('masters.edit') && $list->allowsNewRows,
            // Reordering sends every row of the scope; beyond the page limit it can't, so it's off.
            'sortable' => $this->q === '' && $rows->count() < self::MAX_ROWS,
        ])->title(__($list->label).' · '.__('Master data'));
    }

    private function list(): MasterList
    {
        return MasterLists::find($this->listKey) ?? abort(404);
    }

    private function parentId(): ?int
    {
        return $this->list()->hasParent() ? (int) $this->parent : null;
    }

    /** @return array<int, string> parent row id => label (inactive parents included) */
    private function parents(): array
    {
        $parentList = MasterLists::find((string) $this->list()->parentKey);

        if ($parentList === null) {
            return [];
        }

        // A parent that has its own parent (a state) is shown with it: "Kerala (India)".
        $grandparents = [];
        if ($parentList->hasParent()) {
            $grandparentList = MasterLists::find((string) $parentList->parentKey);
            $grandparents = $grandparentList?->anyParentQuery()->pluck('label', 'id')->all() ?? [];
        }

        return $parentList->anyParentQuery()->orderBy('label')->get()
            ->mapWithKeys(function (MasterRecord $row) use ($parentList, $grandparents): array {
                $label = $row->label;
                if ($parentList->hasParent()) {
                    $grandparent = $grandparents[(int) $row->getAttribute((string) $parentList->parentColumn)] ?? null;
                    $label .= is_string($grandparent) ? ' ('.$grandparent.')' : '';
                }

                return [(int) $row->getKey() => $label];
            })->all();
    }

    private function csvContents(): string
    {
        if (! $this->csv instanceof UploadedFile) {
            throw ValidationException::withMessages(['csv' => __('Choose the CSV file again.')]);
        }

        return (string) $this->csv->get();
    }

    /** Run an action; field errors from the Action land on this form's fields ($prefix + Field). */
    private function attempt(Closure $action, string $prefix): void
    {
        $this->resetErrorBag();   // a corrected retry must not keep showing the old error

        try {
            $action();
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $key => $messages) {
                $field = $prefix === 'csv' ? 'csv' : $prefix.ucfirst($key);
                $this->addError($field, (string) ($messages[0] ?? ''));
            }
        }
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
