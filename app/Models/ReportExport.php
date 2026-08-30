<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReportExport extends Model
{
    /** @use HasFactory<\Database\Factories\ReportExportFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'report_key' => ReportKey::class,
        'format' => ReportExportFormat::class,
        'status' => ReportExportStatus::class,
        'state' => 'array',
        'total_rows' => 'integer',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
