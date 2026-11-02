<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
final class BranchFactory extends Factory
{
    use EditorialStates;

    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('BRA'),
            'city' => 'Thrissur',
            'role' => 'Branch',
            'address' => 'Round South, Thrissur',
            'phone' => '0487 244 1100',
            'tel' => '+914872441100',
            'hours' => 'Mon–Sat, 10am – 6pm',
        ];
    }
}
