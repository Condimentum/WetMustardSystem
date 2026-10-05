<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared with Factory Performance Tracker. A day's downtime log entries. */
class FptDowntimeEvent extends Model
{
    protected $connection = 'fpt';

    protected $table = 'DowntimeEvents';

    protected $primaryKey = 'EventId';

    public $timestamps = false;

    protected $fillable = [
        'DepartmentId',
        'EventDate',
        'StartTime',
        'EndTime',
        'EventType',
        'ReasonId',
        'Description',
        'RecordedBy',
    ];
}
