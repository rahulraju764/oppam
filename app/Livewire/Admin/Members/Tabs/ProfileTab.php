<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Actions\Admin\Members\UpdateMemberProfile;
use App\Data\Admin\MemberProfileEditData;
use App\Domain\Profile\ProfileRules;
use App\Enums\MaritalStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Services\Masters\Masters;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A03 "Profile (edit with diff + reason)": the admin corrects core profile fields; the screen
 * shows old → new for every changed field before saving, and UpdateMemberProfile validates with
 * the wizard's rules, saves only what changed and audits it.
 */
final class ProfileTab extends Component
{
    use IsMemberTab;

    /** @var array<string, string> column => value as typed */
    public array $form = [];

    public string $reason = '';

    /** Fingerprint of the saved values the form was filled with (UpdateMemberProfile refuses a stale form). */
    #[Locked]
    public string $loaded = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
        $this->fillForm();
    }

    public function updatedFormReligionId(): void
    {
        $this->form['caste_id'] = '';
    }

    public function save(UpdateMemberProfile $update): void
    {
        $this->authorize('members.edit');

        try {
            $changed = $update->handle($this->admin(), $this->member(), MemberProfileEditData::fromInput($this->form), $this->reason, $this->loaded);
        } catch (MemberStateConflict $conflict) {
            $this->dispatch('toast', type: 'error', message: $conflict->getMessage());
            $this->fillForm();

            return;
        } catch (ValidationException $invalid) {
            // Field errors come back keyed by column; the inputs are bound to form.<column>.
            foreach ($invalid->errors() as $key => $messages) {
                $this->addError(in_array($key, MemberProfileEditData::FIELDS, true) ? 'form.'.$key : $key, $messages[0]);
            }

            return;
        }

        $this->reason = '';
        $this->fillForm();
        $this->dispatch('toast', type: 'success', message: trans_choice(':count field updated.|:count fields updated.', count($changed)));
    }

    public function render(Masters $masters): View
    {
        $religionId = (int) ($this->form['religion_id'] ?? 0);
        $labels = ProfileRules::attributes();
        $current = $this->current();
        $maritalStatuses = MaritalStatus::options();
        $religions = Masters::forSelect($masters->religions());
        $castes = $religionId > 0 ? Masters::forSelect($masters->castesForReligion($religionId)) : [];
        $oldCastes = (int) $current['religion_id'] > 0 ? Masters::forSelect($masters->castesForReligion((int) $current['religion_id'])) : [];

        // Old → new for the diff, with labels instead of ids / enum values.
        $show = fn (string $column, string $value, array $casteLabels): string => $value === '' ? '—' : match ($column) {
            'marital_status' => $maritalStatuses[$value] ?? $value,
            'religion_id' => $religions[(int) $value] ?? $value,
            'caste_id' => $casteLabels[(int) $value] ?? $value,
            default => $value,
        };
        $diff = [];

        foreach (MemberProfileEditData::FIELDS as $column) {
            $old = $current[$column] ?? '';
            $new = trim((string) ($this->form[$column] ?? ''));
            if ($old !== $new) {
                $diff[] = ['label' => $labels[$column] ?? $column, 'old' => $show($column, $old, $oldCastes), 'new' => $show($column, $new, $castes)];
            }
        }

        return view('livewire.admin.members.tabs.profile-tab', [
            'maritalStatuses' => $maritalStatuses,
            'religions' => $religions,
            'castes' => $castes,
            'diff' => $diff,
            'canEdit' => $this->admin()->can('members.edit') && ! $this->member()->trashed(),
        ]);
    }

    private function fillForm(): void
    {
        $this->form = $this->current();
        $this->loaded = UpdateMemberProfile::fingerprint($this->profile());
    }

    /** @return array<string, string> the saved values, as form strings */
    private function current(): array
    {
        $profile = $this->profile()->refresh();
        $values = [];

        foreach (MemberProfileEditData::FIELDS as $column) {
            $value = $profile->getAttribute($column);
            $values[$column] = match (true) {
                $value instanceof BackedEnum => (string) $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return $values;
    }
}
