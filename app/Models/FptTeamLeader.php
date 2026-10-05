<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared with Factory Performance Tracker. "Start By" / "End By" name dropdown options. */
class FptTeamLeader extends Model
{
    protected $connection = 'fpt';

    protected $table = 'TeamLeaders';

    protected $primaryKey = 'TeamLeaderId';

    public $timestamps = false;
}
