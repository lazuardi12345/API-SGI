<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    const ROLE_ADMIN   = 'admin';
    const ROLE_HM      = 'hm';
    const ROLE_CHECKER = 'checker';
    const ROLE_PETUGAS = 'petugas';
    const ROLE_GUDANG  = 'gudang';
    const ROLE_KASIR   = 'kasir';

    protected $fillable = ['name', 'email', 'password', 'role'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    protected $appends = ['role_name', 'application_type'];

    public function getApplicationTypeAttribute(): string
{
    return 'pawn-apps';
}

    public function getRoleNameAttribute(): string
    {
        $map = [
            self::ROLE_HM      => 'Head Marketing',
            self::ROLE_ADMIN   => 'Administrator',
            self::ROLE_CHECKER => 'Kepala Toko',
            self::ROLE_PETUGAS => 'Petugas Lapangan',
            self::ROLE_GUDANG  => 'Staff Gudang',
            self::ROLE_KASIR   => 'Kasir',
        ];

        return $map[$this->role] ?? $this->role;
    }

    public function getJWTIdentifier() { return $this->getKey(); }

    public function getJWTCustomClaims(): array
    {
        return [
            'applicationType' => 'pawn-apps',
            'role'      => $this->role,
            'role_name' => $this->role_name,
            'name'      => $this->name,
        ];
    }

    public function hasRole(string $role): bool { return $this->role === $role; }

    public function hasAnyRole(array $roles): bool { return in_array($this->role, $roles); }
}