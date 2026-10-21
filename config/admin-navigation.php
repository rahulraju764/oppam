<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin sidebar (PRD §11.0)
|--------------------------------------------------------------------------
| section => [[route name, label, FA icon, permission|null]]. An item renders only when its route
| exists AND the admin holds the permission (@can hides the link; every page and action also
| authorizes on the server). Sections with no visible item are omitted. Modules add their items
| here as they are built (members P1.7, moderation P1.6, …).
*/

return [
    'Overview' => [
        ['admin.dashboard', 'Dashboard', 'fa-tachometer', null],
    ],
    'Members' => [
        ['admin.members.index', 'All members', 'fa-users', 'members.view'],
    ],
    'Moderation' => [
        ['admin.moderation.profiles', 'Profile queue', 'fa-check-square-o', 'moderation.view'],
        ['admin.moderation.photos', 'Photo queue', 'fa-picture-o', 'moderation.view'],
        ['admin.moderation.edits', 'Edited fields', 'fa-pencil-square-o', 'moderation.view'],
        ['admin.moderation.escalations', 'Escalations', 'fa-level-up', 'moderation.view'],
    ],
    'Chat safety' => [
        ['admin.chat.flags', 'Flagged messages', 'fa-flag', 'chat.view_flagged'],
        ['admin.chat.signals', 'Abuse signals', 'fa-bolt', 'chat.view_flagged'],
        ['admin.chat.overview', 'Conversation overview', 'fa-bar-chart', 'chat.view_flagged'],
        ['admin.chat.rules', 'Rules & word lists', 'fa-sliders', 'chat.view_flagged'],
        ['admin.chat.break-glass', 'Break-glass read', 'fa-unlock-alt', 'chat.read_conversation'],
    ],
    'Verification' => [
        ['admin.verification.index', 'ID verification', 'fa-id-card-o', 'verification.queue.view'],
    ],
    'Master data' => [
        ['admin.masters.index', 'Master data', 'fa-list', 'masters.view'],
    ],
    'System' => [
        ['admin.audit.index', 'Audit log', 'fa-history', 'audit.view'],
        ['horizon.index', 'Queues (Horizon)', 'fa-tasks', 'system.horizon'],
        ['pulse', 'Pulse', 'fa-heartbeat', 'system.pulse'],
    ],
    'Settings' => [
        ['admin.staff.index', 'Admin users', 'fa-user-secret', 'staff.view'],
        ['admin.roles.edit', 'Roles & permissions', 'fa-key', 'roles.view'],
        ['admin.sessions.index', 'Sessions', 'fa-desktop', null],
    ],
];
