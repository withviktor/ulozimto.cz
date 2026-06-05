<?php

namespace App\Scheduler;

use App\Message\PurgeExpiredFilesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
class PurgeSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
        // Každou hodinu smazat expirované soubory
            RecurringMessage::every('1 hour', new PurgeExpiredFilesMessage())
        );
    }
}
