<?php

declare(strict_types=1);

namespace App\Data\Audit;

/**
 * The A12 audit viewer's filters (P9.2a), as typed by the admin and trimmed. Every value is
 * matched against allow-lists or exact columns in AuditLogQuery — none reaches SQL as-is.
 *
 *  - actor: an admin's email, a member's profile code, or "system";
 *  - action: an action key or an area prefix ("billing" or "billing.refund_approved");
 *  - subjectType: a model name from AuditLogQuery::subjectTypes();
 *  - subject: the label stored with the row — usually a code (OPM12370, BRK1042, ORD100004);
 *  - from / to: IST dates (Y-m-d);
 *  - search: free text over action, actor, subject and reason (FULLTEXT);
 *  - entity: "member:OPM12370" / "broker:BRK1042" / "order:ORD100004" — exactly the rows of that
 *    entity's timeline (TimelineSubjects), the link from the member / bureau / order pages.
 */
final readonly class AuditFilterData
{
    public function __construct(
        public string $actor = '',
        public string $action = '',
        public string $subjectType = '',
        public string $subject = '',
        public string $from = '',
        public string $to = '',
        public string $search = '',
        public string $entity = '',
    ) {}

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values): self
    {
        $text = static fn (string $key, int $max): string => mb_substr(trim(is_string($values[$key] ?? null) ? $values[$key] : ''), 0, $max);

        return new self(
            actor: $text('actor', 150),
            action: $text('action', 80),
            subjectType: $text('subjectType', 80),
            subject: $text('subject', 150),
            from: $text('from', 10),
            to: $text('to', 10),
            search: $text('search', 100),
            entity: $text('entity', 40),
        );
    }

    /** @return array{actor: string, action: string, subjectType: string, subject: string, from: string, to: string, search: string, entity: string} */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor, 'action' => $this->action, 'subjectType' => $this->subjectType,
            'subject' => $this->subject, 'from' => $this->from, 'to' => $this->to, 'search' => $this->search,
            'entity' => $this->entity,
        ];
    }
}
