<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public const ROLES = ['administrator', 'manager', 'production_staff', 'inventory_staff', 'distribution_staff', 'sales_staff', 'auditor'];

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
