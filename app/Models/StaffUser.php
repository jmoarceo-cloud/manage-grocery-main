<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

abstract class StaffUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = ['id', 'role', 'is_active', 'force_logout'];

    protected $hidden = ['password', 'two_factor_backup_codes', 'two_factor_email', 'procurement_signature_reference', 'specimen_signature_file'];

    public function getRememberTokenName(): string
    {
        return '';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'force_logout' => 'boolean', 'two_factor_locked_until' => 'datetime', 'locked_until' => 'datetime'];
    }
}
