<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();
