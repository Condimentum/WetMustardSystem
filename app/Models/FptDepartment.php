<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared with Factory Performance Tracker. */
class FptDepartment extends Model
{
    protected $connection = 'fpt';

    protected $table = 'Departments';

    protected $primaryKey = 'DepartmentId';

    public $timestamps = false;
}
