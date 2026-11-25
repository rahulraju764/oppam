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
        ['admin.dashboard', 'Dashboard', 'fa-solid fa-gauge-high', null],
    ],
    'Members' => [
        ['admin.members.index', 'All members', 'fa-solid fa-users', 'members.view'],
    ],
    'Moderation' => [
        ['admin.moderation.profiles', 'Profile queue', 'fa-regular fa-square-check', 'moderation.view'],
        ['admin.moderation.photos', 'Photo queue', 'fa-regular fa-image', 'moderation.view'],
        ['admin.moderation.edits', 'Edited fields', 'fa-regular fa-pen-to-square', 'moderation.view'],
        ['admin.moderation.escalations', 'Escalations', 'fa-solid fa-turn-up', 'moderation.view'],
    ],
    'Safety & support' => [
        ['admin.safety.cases', 'Safety cases', 'fa-solid fa-shield', 'safety.reports.view'],
        ['admin.support.tickets', 'Support tickets', 'fa-solid fa-life-ring', 'support.view'],
    ],
    'Chat safety' => [
        ['admin.chat.flags', 'Flagged messages', 'fa-solid fa-flag', 'chat.view_flagged'],
        ['admin.chat.signals', 'Abuse signals', 'fa-solid fa-bolt', 'chat.view_flagged'],
        ['admin.chat.overview', 'Conversation overview', 'fa-solid fa-chart-column', 'chat.view_flagged'],
        ['admin.chat.rules', 'Rules & word lists', 'fa-solid fa-sliders', 'chat.view_flagged'],
        ['admin.chat.break-glass', 'Break-glass read', 'fa-solid fa-unlock', 'chat.read_conversation'],
    ],
    'Verification' => [
        ['admin.verification.index', 'ID verification', 'fa-regular fa-id-card', 'verification.queue.view'],
    ],
    'Brokers' => [
        ['admin.brokers.index', 'Broker directory', 'fa-solid fa-briefcase', 'brokers.view'],
        ['admin.brokers.kyc', 'Applications & KYC', 'fa-regular fa-building', 'brokers.kyc'],
        ['admin.brokers.unverified', 'Unverified codes', 'fa-regular fa-circle-question', 'brokers.edit'],
        ['admin.brokers.payouts', 'Payout runs', 'fa-solid fa-building-columns', 'brokers.payout'],
        ['admin.brokers.imports', 'Bulk imports', 'fa-solid fa-upload', 'brokers.imports.view'],
        ['admin.brokers.exports', 'Export audit', 'fa-solid fa-download', 'brokers.exports.view'],
        ['admin.brokers.disputes', 'Consent disputes', 'fa-solid fa-scale-balanced', 'brokers.view'],
    ],
    'Content' => [
        ['admin.content.home-blocks', 'Home blocks', 'fa-solid fa-table-cells-large', 'content.view'],
        ['admin.content.banners', 'Hero banners', 'fa-regular fa-image', 'content.view'],
        ['admin.content.stories', 'Success stories', 'fa-regular fa-heart', 'content.view'],
        ['admin.content.testimonials', 'Testimonials', 'fa-solid fa-quote-left', 'content.view'],
        ['admin.content.branches', 'Branches', 'fa-solid fa-location-dot', 'content.view'],
        ['admin.content.faq', 'FAQ', 'fa-solid fa-circle-question', 'content.view'],
        ['admin.content.legal', 'Legal pages', 'fa-solid fa-gavel', 'content.view'],
        ['admin.content.ads', 'Ads', 'fa-solid fa-bullhorn', 'content.view'],
        ['admin.content.communities', 'Community pages', 'fa-solid fa-users', 'content.view'],
        ['admin.contact.index', 'Contact inbox', 'fa-regular fa-envelope', 'support.view'],
    ],
    'Communication' => [
        ['admin.comms.campaigns.index', 'Campaigns', 'fa-regular fa-paper-plane', 'campaigns.view'],
        ['admin.comms.announcements', 'Announcements', 'fa-solid fa-bullhorn', 'campaigns.view'],
        ['admin.comms.templates', 'Templates', 'fa-regular fa-file-code', 'templates.edit'],
        ['admin.comms.journeys', 'Automated journeys', 'fa-solid fa-road', 'campaigns.view'],
        ['admin.comms.delivery-log', 'Delivery log', 'fa-regular fa-envelope-open', 'campaigns.view'],
    ],
    'Billing' => [
        ['admin.billing.orders', 'Orders', 'fa-regular fa-file-lines', 'billing.view'],
        ['admin.billing.stuck', 'Stuck orders', 'fa-solid fa-triangle-exclamation', 'billing.view'],
        ['admin.billing.refunds', 'Refunds', 'fa-solid fa-arrow-rotate-left', 'billing.view'],
        ['admin.billing.investigations', 'Reconciliation', 'fa-solid fa-magnifying-glass', 'billing.view'],
        ['admin.billing.subscriptions', 'Subscriptions', 'fa-regular fa-credit-card', 'billing.view'],
        ['admin.billing.plans', 'Plans', 'fa-solid fa-tags', 'billing.view'],
        ['admin.billing.coupons', 'Coupons', 'fa-solid fa-ticket', 'billing.view'],
    ],
    'Master data' => [
        ['admin.masters.index', 'Master data', 'fa-solid fa-list', 'masters.view'],
    ],
    'Reports' => [
        ['admin.reports.index', 'Reports', 'fa-solid fa-chart-column', 'reports.view'],
        ['admin.reports.exports', 'My exports', 'fa-solid fa-download', 'reports.export'],
    ],
    'System' => [
        ['admin.audit.index', 'Audit log', 'fa-solid fa-clock-rotate-left', 'audit.view'],
        ['admin.system.health', 'System health', 'fa-solid fa-stethoscope', 'system.health.view'],
        ['horizon.index', 'Queues (Horizon)', 'fa-solid fa-bars-progress', 'system.horizon'],
        ['pulse', 'Pulse', 'fa-solid fa-heart-pulse', 'system.pulse'],
    ],
    'Settings' => [
        ['admin.settings.site', 'Site settings', 'fa-solid fa-earth-americas', 'settings.view'],
        ['admin.settings.rules', 'Business rules', 'fa-solid fa-sliders', 'settings.view'],
        ['admin.settings.features', 'Feature flags', 'fa-solid fa-toggle-on', 'settings.view'],
        ['admin.settings.entitlements', 'Entitlements matrix', 'fa-solid fa-table-list', 'settings.view'],
        ['admin.settings.integrations', 'Integrations', 'fa-solid fa-plug', 'settings.view'],
        ['admin.staff.index', 'Admin users', 'fa-solid fa-user-secret', 'staff.view'],
        ['admin.roles.edit', 'Roles & permissions', 'fa-solid fa-key', 'roles.view'],
        ['admin.sessions.index', 'Sessions', 'fa-solid fa-desktop', null],
    ],
];
