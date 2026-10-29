<?php

namespace App\Domains\SecurityAndAudit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'staff_id',
        'module',
        'action',
        'severity',
        'resource_type',
        'resource_id',
        'description',
        'diffs',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'diffs' => 'array',
        'created_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_id');
    }
}
