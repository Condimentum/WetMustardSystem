<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared with Factory Performance Tracker. Downtime reason dropdown options. */
class FptDowntimeReason extends Model
{
    protected $connection = 'fpt';

    protected $table = 'DowntimeReasons';

    protected $primaryKey = 'ReasonId';

    public $timestamps = false;
}
