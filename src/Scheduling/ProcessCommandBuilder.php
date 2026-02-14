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
     * Uses exec to replace the shell process with the command,
     * so that SIGTERM is delivered directly to the command process.
     * schedule:finish is handled separately via buildFinishCommand().
     *
     * @param Event $event
     * @return string
     */
    protected function buildBackgroundCommand(Event $event)
    {
        $output = ProcessUtils::escapeArgument($event->output);
        $redirect = $event->shouldAppendOutput ? ' >> ' : ' > ';

        return 'exec ' . $this->ensureCorrectUser(
            $event,
            $event->command . $redirect . $output . ' 2>&1'
        );
    }

    /**
     * Build the finish command template for schedule:finish.
     *
     * Returns a FinishCommandTemplate that builds the final command via string
     * concatenation, avoiding sprintf % character conflicts in output paths.
     * Always uses append redirect (>>) to avoid overwriting the main command output.
     *
     * @param Event $event
     * @return FinishCommandTemplate
     */
    public function buildFinishCommand(Event $event): FinishCommandTemplate
    {
        $output = ProcessUtils::escapeArgument($event->output);
        $finished = Application::formatCommandString('schedule:finish')
            . ' "' . $event->mutexName() . '"';

        return new FinishCommandTemplate($finished, '>> ' . $output . ' 2>&1');
    }

    /**
     * Ensure the command is run as the correct user.
     *
     * When a user is set, wraps the command with sudo and adds exec
     * inside the sh -c to avoid an extra shell process layer.
     *
     * @param Event $event
     * @param string $command
     * @return string
     */
    protected function ensureCorrectUser(Event $event, $command)
    {
        return $event->user
            ? 'sudo -u ' . $event->user . ' -- sh -c \'exec ' . $command . '\''
            : $command;
    }
}
