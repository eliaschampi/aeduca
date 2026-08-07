<?php

namespace App\Support\EmployeeAttendance;

enum EmployeeAttendanceMethod: string
{
    case Scan = 'scan';
    case Manual = 'manual';
}
