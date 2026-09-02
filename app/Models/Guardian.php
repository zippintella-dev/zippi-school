<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * A parent. Authenticates on the `guardian` guard by phone + OTP — there is no
 * password column and there never should be (PART P1).
 *
 * ⚠ Guardians are NOT school-scoped: one phone number is legitimately a parent
 * at two schools. Anything that scopes a guardian must go through their
 * children, never through a school_id on this table.
 */
class Guardian extends Model implements AuthenticatableContract
{
    use Authorizable;
    use \Illuminate\Auth\Authenticatable;
    use \Laravel\Sanctum\HasApiTokens;   // bearer tokens for the Flutter app

    protected $guarded = [];

    protected $hidden = ['fcm_token', 'remember_token'];

    protected $casts = [
        'app_access'         => 'boolean',
        'sms_fallback'       => 'boolean',
        'notification_prefs' => 'array',
        'invited_at'         => 'datetime',
        'last_login_at'      => 'datetime',
    ];

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'child_guardian_links')
            ->withPivot(['relationship', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * There is no password column and Auth::attempt() is never used for
     * guardians — they authenticate through OtpService and are then logged in
     * directly. Returning an empty string keeps the contract satisfied without
     * implying a credential exists.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /** PART K9 — masked in every ops-facing payload. */
    public function maskedPhone(): string
    {
        return $this->phone ? '•••••' . substr($this->phone, -4) : '—';
    }

    /**
     * PART L10 — an FCM token belongs to at most ONE guardian. Strip it from
     * every other row before claiming it, or one family's boarding push lands
     * under the wrong guardian's identity and the journey trail becomes wrong.
     */
    public static function claimFcmToken(string $token, int $guardianId): void
    {
        static::where('fcm_token', $token)
            ->where('id', '!=', $guardianId)
            ->update(['fcm_token' => null]);

        static::where('id', $guardianId)->update(['fcm_token' => $token]);
    }
}
