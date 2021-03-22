<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class FeeCommandTest extends KernelTestCase
{
    public function testExecute()
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $command = $application->find('app:fee');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                // pass arguments to the helper
                'csvFile' => 'input.csv',
            ]
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertEquals(
            '0.60,3.00,0.00,0.06,1.50,0.00,0.69,0.30,0.30,3.00,0.00,0.00,9000.00,',
            str_replace("\n", ',', $output)
        );

    }
}
