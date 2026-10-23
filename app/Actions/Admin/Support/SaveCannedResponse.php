<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Enums\TicketCategory;
use App\Models\AdminUser;
use App\Models\SupportCannedResponse;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create, edit or delete a canned response (PRD A10, decisions 2026-10-23: anyone with
 * `support.respond`). Title 3 – 100, body 5 – 5000 characters, optional category. Audited.
 */
final class SaveCannedResponse
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws AuthorizationException|ValidationException */
    public function save(AdminUser $admin, ?SupportCannedResponse $response, string $title, string $body, ?string $category): SupportCannedResponse
    {
        Gate::forUser($admin)->authorize('support.respond');

        $data = Validator::make(['title' => trim($title), 'body' => trim($body), 'category' => $category === '' ? null : $category], [
            'title' => ['required', 'string', 'min:3', 'max:100'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
            'category' => ['nullable', Rule::enum(TicketCategory::class)],
        ], [], ['title' => __('title'), 'body' => __('text'), 'category' => __('topic')])->validate();

        $response ??= new SupportCannedResponse;
        $created = ! $response->exists;
        $response->title = $data['title'];
        $response->body = $data['body'];
        $response->category = $data['category'] !== null ? TicketCategory::from($data['category']) : null;
        $response->created_by_admin_id ??= $admin->id;
        $response->save();

        $this->audit->record($created ? 'support.canned.created' : 'support.canned.updated', $response, after: ['title' => $response->title], actor: $admin);

        return $response;
    }

    /** @throws AuthorizationException */
    public function delete(AdminUser $admin, SupportCannedResponse $response): void
    {
        Gate::forUser($admin)->authorize('support.respond');

        $response->delete();
        $this->audit->record('support.canned.deleted', $response, before: ['title' => $response->title], actor: $admin);
    }
}
