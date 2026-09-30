<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdminStatus;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts for tests. States: withTwoFactor(secret), suspended(), locked(), role(name).
 * The default admin has NOT enrolled 2FA (like a freshly invited one).
 *
 * @extends Factory<AdminUser>
 */
final class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->userName().'@oppam.test',
            'password' => self::$password ??= Hash::make('Password-1234'),
            'status' => AdminStatus::Active,
        ];
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('recover-code1'), Hash::make('recover-code2')],
        ]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => AdminStatus::Suspended]);
    }

    public function locked(): static
    {
        return $this->state(['locked_until' => now()->addMinutes(30)]);
    }

    public function role(string $role): static
    {
        return $this->afterCreating(fn (AdminUser $admin) => $admin->assignRole($role));
    }
}
