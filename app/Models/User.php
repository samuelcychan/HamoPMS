<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function hasPermission(string $permission, ?int $propertyId = null): bool
    {
        return $this->roleAssignments()
            ->when(
                $propertyId !== null,
                fn ($query) => $query->where(
                    fn ($scope) => $scope
                        ->whereNull('property_id')
                        ->orWhere('property_id', $propertyId),
                ),
                fn ($query) => $query->whereNull('property_id'),
            )
            ->whereHas('role.permissions', fn ($query) => $query->where('slug', $permission))
            ->exists();
    }
}
