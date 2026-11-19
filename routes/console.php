<?php

use Illuminate\Support\Facades\Schedule;

// Safety nets for the asynchronous pipeline. Both commands are idempotent and safe to overlap.
Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping();
Schedule::command('gamevault:recover-stuck')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
