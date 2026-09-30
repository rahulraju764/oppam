<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin RBAC — permission keys and seed roles (PRD §8.4, A01 and each admin module)
|--------------------------------------------------------------------------
| PROPOSED: the v4 §8.8 key list is not available; every key the v5 PRD names is here
| (chat.*, campaigns.*, settings.*, notifications.broadcast, verification.document.view,
| brokers.*), plus one key per remaining admin screen. Keys are `area.action`
| (project-structure.md). Guard: admin. Editable in the role editor (A01) within the
| "can't grant what you don't hold" guardrail. docs/decisions.md.
*/

return [

    'permissions' => [
        'Overview' => ['dashboard.view'],
        'Members' => ['members.view', 'members.edit', 'members.suspend', 'members.delete', 'members.export', 'members.impersonate'],
        'Moderation' => ['moderation.view', 'moderation.act'],
        'Verification' => ['verification.queue.view', 'verification.document.view', 'verification.approve'],
        'Safety' => ['safety.reports.view', 'safety.reports.act', 'safety.ban'],
        'Chat safety' => ['chat.view_flagged', 'chat.freeze', 'chat.moderate', 'chat.rules.edit', 'chat.read_conversation'],
        'Billing' => ['billing.view', 'billing.plans.edit', 'billing.coupons.edit', 'billing.refund', 'billing.force_activate', 'billing.export'],
        'Brokers' => ['brokers.view', 'brokers.edit', 'brokers.kyc', 'brokers.payout', 'brokers.commission_bps', 'brokers.staff.view', 'brokers.staff.edit', 'brokers.imports.view', 'brokers.imports.manage', 'brokers.exports.view'],
        'Content' => ['content.edit', 'content.publish'],
        'Communication' => ['campaigns.view', 'campaigns.send', 'campaigns.approve', 'templates.edit', 'notifications.broadcast'],
        'Reports' => ['reports.view', 'reports.export'],
        'Support' => ['support.view', 'support.respond'],
        'Master data' => ['masters.view', 'masters.edit'],
        'System' => ['audit.view', 'system.health.view', 'system.horizon', 'system.pulse'],
        'Settings' => ['settings.view', 'settings.edit'],
        'Staff & roles' => ['staff.view', 'staff.invite', 'staff.edit', 'staff.suspend', 'roles.view', 'roles.edit', 'sessions.revoke_any'],
    ],

    // role => permission keys. super_admin always holds every permission (not listed).
    // Break-glass and document keys are deliberately on few roles (PRD §8.4 non-negotiables:
    // verification.document.view is separate from verification.approve).
    'roles' => [
        'super_admin' => '*',
        'moderator' => [
            'dashboard.view', 'members.view', 'moderation.view', 'moderation.act',
            'safety.reports.view', 'safety.reports.act', 'chat.view_flagged', 'chat.moderate', 'chat.freeze',
            'support.view', 'masters.view',
        ],
        'verification_officer' => [
            'dashboard.view', 'members.view', 'verification.queue.view', 'verification.document.view', 'verification.approve',
        ],
        'support' => [
            'dashboard.view', 'members.view', 'members.edit', 'support.view', 'support.respond',
            'safety.reports.view', 'billing.view', 'brokers.view',
        ],
        'finance' => [
            'dashboard.view', 'billing.view', 'billing.plans.edit', 'billing.coupons.edit', 'billing.refund', 'billing.export',
            'brokers.view', 'brokers.payout', 'brokers.commission_bps', 'reports.view', 'reports.export',
        ],
        'content_editor' => [
            'dashboard.view', 'content.edit', 'content.publish', 'masters.view', 'masters.edit',
            'templates.edit', 'campaigns.view',
        ],
        // Every *.view key except the sensitive ones — never documents, never conversations.
        'read_only' => 'views',
    ],

    // Keys a read_only role must never get, even though they read data.
    'sensitive' => ['verification.document.view', 'chat.read_conversation'],

];
