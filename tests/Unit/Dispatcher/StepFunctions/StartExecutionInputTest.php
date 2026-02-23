<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use PHPUnit\Framework\TestCase;

/**
 * @testdox StartExecutionInput
 */
class StartExecutionInputTest extends TestCase
{
    /**
     * @testdox SEI.1 getName returns the name passed to constructor
     */
    public function testGetNameReturnsConstructorValue(): void
    {
        $input = new StartExecutionInput('my-execution', '{"command":"test"}');

        $this->assertSame('my-execution', $input->getName());
    }

    /**
     * @testdox SEI.2 getInput returns the input passed to constructor
     */
    public function testGetInputReturnsConstructorValue(): void
    {
        $input = new StartExecutionInput('my-execution', '{"command":"test"}');

        $this->assertSame('{"command":"test"}', $input->getInput());
    }

    /**
     * @testdox SEI.3 Stores empty strings without error
     */
    public function testStoresEmptyStrings(): void
    {
        $input = new StartExecutionInput('', '');

        $this->assertSame('', $input->getName());
        $this->assertSame('', $input->getInput());
    }
}
