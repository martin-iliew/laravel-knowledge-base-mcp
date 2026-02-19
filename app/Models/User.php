<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

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
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Knowledge items authored by this account.
     */
    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class, 'created_by');
    }

    /**
     * Account-level access grants this user owns.
     */
    public function knowledgeAccessGrants(): HasMany
    {
        return $this->hasMany(KnowledgeAccountAccess::class, 'owner_user_id');
    }

    /**
     * Account-level access grants this user has received.
     */
    public function knowledgeAccessReceived(): HasMany
    {
        return $this->hasMany(KnowledgeAccountAccess::class, 'grantee_user_id');
    }
}
