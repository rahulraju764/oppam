<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The four plans and the PRD §7.3 entitlement matrix. Prices in paise, monthly only for now
 * (3/6/12-month discounts are an open business decision, PRD §19 #7). FREE is a real row so its
 * limits are editable in A06 like any other plan; it is never purchasable.
 * INSERT-ONLY: plans, prices and entitlements are created when missing and never updated, so a
 * re-seed can't overwrite an A06 price or limit. After the first run they are edited only in A06.
 */
final class PlansSeeder extends Seeder
{
    /** Quota value meaning "unlimited" in the matrix below (stored as limit_value = null). */
    private const UNLIMITED = null;

    public function run(): void
    {
        foreach ($this->plans() as $order => $definition) {
            $plan = Plan::query()->firstWhere('code', $definition['code']->value)
                ?? $this->createPlan($definition, $order);

            if ($definition['monthly_paise'] !== null) {
                $plan->prices()->firstOrCreate(['duration_months' => 1], ['price_paise' => $definition['monthly_paise'], 'is_active' => true]);
            }

            foreach ($definition['features'] as $entitlement => $value) {
                $entitlementCase = Entitlement::from($entitlement);
                $plan->features()->firstOrCreate(['entitlement' => $entitlementCase->value], $entitlementCase->isFlag()
                    ? ['limit_value' => null, 'is_enabled' => (bool) $value]
                    : ['limit_value' => $value, 'is_enabled' => $value !== 0]);
            }
        }
    }

    /** @param array{code: PlanCode, name: string, badge?: string, featured?: bool, display: list<string>} $definition */
    private function createPlan(array $definition, int $order): Plan
    {
        $plan = new Plan;
        $plan->forceFill([
            'code' => $definition['code']->value,
            'name' => $definition['name'],
            'badge' => $definition['badge'] ?? null,
            'is_featured' => $definition['featured'] ?? false,
            'is_purchasable' => $definition['code'] !== PlanCode::Free,
            'is_active' => true,
            'display_features' => $definition['display'],
            'sort_order' => $order,
        ])->save();

        return $plan;
    }

    /**
     * PRD §7.3. Quotas: an int limit, or UNLIMITED. Flags: true / false.
     *
     * @return list<array{code: PlanCode, name: string, badge?: string, featured?: bool, monthly_paise: int|null, display: list<string>, features: array<string, int|bool|null>}>
     */
    private function plans(): array
    {
        return [
            [
                'code' => PlanCode::Free,
                'name' => 'Free',
                'monthly_paise' => null,
                'display' => ['Search and view profiles', 'Daily match recommendations', 'Send 3 interests a month'],
                'features' => [
                    'likes_per_day' => 10,
                    'favorites_total' => 25,
                    'interests_per_month' => 3,
                    'contact_views_per_month' => 0,
                    'free_replies_per_conversation' => 1,     // R-M07-3: one free reply per accepted conversation
                    'chat_send' => false,
                    'see_who_liked_me' => false,               // count + blurred list only
                    'see_who_viewed_me' => false,              // count only
                    'search_highlight' => false,
                    'top_placement' => false,
                ],
            ],
            [
                'code' => PlanCode::Silver,
                'name' => 'Silver',
                'monthly_paise' => 49_900,
                'display' => ['Send 25 interests a month', 'View 10 verified mobile numbers', 'Chat with accepted matches', 'Daily match recommendations'],
                'features' => [
                    'likes_per_day' => 50,
                    'favorites_total' => 200,
                    'interests_per_month' => 25,
                    'contact_views_per_month' => 10,
                    'free_replies_per_conversation' => self::UNLIMITED,
                    'chat_send' => true,
                    'see_who_liked_me' => true,
                    'see_who_viewed_me' => false,
                    'search_highlight' => false,
                    'top_placement' => false,
                ],
            ],
            [
                'code' => PlanCode::Gold,
                'name' => 'Gold',
                'badge' => 'Most Popular',
                'featured' => true,
                'monthly_paise' => 99_900,
                'display' => ['Send 100 interests a month', 'View 50 verified mobile numbers', 'Unlimited chat and messages', 'Profile highlighted in search'],
                'features' => [
                    'likes_per_day' => self::UNLIMITED,
                    'favorites_total' => self::UNLIMITED,
                    'interests_per_month' => 100,
                    'contact_views_per_month' => 50,
                    'free_replies_per_conversation' => self::UNLIMITED,
                    'chat_send' => true,
                    'see_who_liked_me' => true,
                    'see_who_viewed_me' => true,
                    'search_highlight' => true,
                    'top_placement' => false,
                ],
            ],
            [
                'code' => PlanCode::Diamond,
                'name' => 'Diamond',
                'monthly_paise' => 199_900,
                // The template listed "Dedicated relationship manager" — a v2 feature (PRD §7.3), so it
                // is not advertised on a v1 card; top placement is Diamond's real v1 extra.
                'display' => ['Unlimited interests', 'Unlimited verified mobile numbers', 'Unlimited chat and messages', 'Top placement in search results'],
                'features' => [
                    'likes_per_day' => self::UNLIMITED,
                    'favorites_total' => self::UNLIMITED,
                    'interests_per_month' => self::UNLIMITED,
                    'contact_views_per_month' => self::UNLIMITED,
                    'free_replies_per_conversation' => self::UNLIMITED,
                    'chat_send' => true,
                    'see_who_liked_me' => true,
                    'see_who_viewed_me' => true,
                    'search_highlight' => true,
                    'top_placement' => true,
                ],
            ],
        ];
    }
}
