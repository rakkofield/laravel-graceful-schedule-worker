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
     * Uses a shell trap pattern so that SIGTERM sent to the /bin/sh wrapper
     * is forwarded to the actual child process.
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

        // Main command (ensureCorrectUser applies only here)
        $mainCmd = $this->ensureCorrectUser(
            $event,
            $event->command . $redirect . $output . ' 2>&1'
        );

        // schedule:finish command
        $finishCmd = $finished . ' "$EXIT_CODE" >> ' . $output . ' 2>&1';

        // trap handler (on SIGTERM)
        $trapHandler = 'kill $CHILD 2>/dev/null; wait $CHILD 2>/dev/null; EXIT_CODE=$?; '
            . $finishCmd . '; exit $EXIT_CODE';

        return $mainCmd . ' & CHILD=$!; '
            . "trap '" . $trapHandler . "' TERM; "
            . 'wait $CHILD; EXIT_CODE=$?; '
            . $finishCmd;
    }
}
