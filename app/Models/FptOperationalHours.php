<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with Factory Performance Tracker (external app, same SQL Server host,
 * its own database). One row per DepartmentId + OperationDate.
 */
class FptOperationalHours extends Model
{
    protected $connection = 'fpt';

    protected $table = 'OperationalHours';

    protected $primaryKey = 'RecordId';

    public $timestamps = false;

    protected $fillable = [
        'DepartmentId',
        'OperationDate',
        'IsWorkingDay',
        'ShiftStartTime',
        'ProductionStartTime',
        'ShiftFinishTime',
        'ProductionFinishTime',
        'RecordedBy',
        'ShiftEndRecordedBy',
        'PackingCompleted',
        'PackingHours',
        'IsSubmitted',
    ];

    protected function casts(): array
    {
        return [
            'IsWorkingDay' => 'boolean',
            'PackingCompleted' => 'boolean',
            'IsSubmitted' => 'boolean',
            'PackingHours' => 'float',
        ];
    }
}
