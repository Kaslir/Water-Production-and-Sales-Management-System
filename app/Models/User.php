<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone_number', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public const ROLES = ['administrator', 'manager', 'production_staff', 'inventory_staff', 'distribution_staff', 'sales_staff', 'auditor'];

    public const EMPLOYEE_ROLES = ['administrator', 'manager', 'production_staff', 'distribution_staff', 'sales_staff'];

    public const ASSIGNABLE_ROLES = ['administrator', 'manager', 'production_staff', 'distribution_staff', 'sales_staff', 'auditor'];

    public function employeeInvitations(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class, 'user_id', 'user_id');
    }

    public function latestInvitation(): HasOne
    {
        return $this->hasOne(EmployeeInvitation::class, 'user_id', 'user_id')->latestOfMany();
    }

    public function isAdminOrManager(): bool
    {
        return in_array($this->role, ['administrator', 'manager'], true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function appAlerts(): HasMany
    {
        return $this->hasMany(AppAlert::class, 'user_id', 'user_id');
    }

    public function securityCodes(): HasMany
    {
        return $this->hasMany(SecurityCode::class, 'user_id');
    }
}
