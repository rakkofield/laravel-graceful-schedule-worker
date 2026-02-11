<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CommandBuilder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\ProcessUtils;

class ProcessCommandBuilder extends CommandBuilder
{
    /**
     * Build the command for running the event in the background.
     *
     * Removes the outer "> /dev/null 2>&1 &" that the parent adds,
     * because Process::start() handles async execution.
     * Redirects schedule:finish output to the same file as the main command.
     *
     * @param Event $event
     * @return string
     */
    protected function buildBackgroundCommand(Event $event)
    {
        $output = ProcessUtils::escapeArgument($event->output);
        $redirect = $event->shouldAppendOutput ? ' >> ' : ' > ';
        $finished = Application::formatCommandString('schedule:finish')
            . ' "' . $event->mutexName() . '"';

        if (windows_os()) {
            return 'cmd /c "(' . $event->command . ' & '
                . $finished . ' "%errorlevel%")' . $redirect . $output . ' 2>&1"';
        }

        return $this->ensureCorrectUser(
            $event,
            '(' . $event->command . $redirect . $output . ' 2>&1 ; '
            . $finished . ' "$?" >> ' . $output . ' 2>&1)'
        );
    }
}
