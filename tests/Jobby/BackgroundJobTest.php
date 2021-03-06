<?php

namespace Jobby\Tests;

use Jobby\BackgroundJob;
use Jobby\Helper;

/**
 * @covers Jobby\BackgroundJob
 */
class BackgroundJobTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var string
     */
    private $logFile;

    /**
     * @return string
     */
    private function getLogContent()
    {
        return @file_get_contents($this->logFile);
    }

    /**
     *
     */
    public function setUp()
    {
        $this->logFile = __DIR__ . "/_files/BackgroundJobTest.log";
        @unlink($this->logFile);
    }

    /**
     *
     */
    public function tearDown()
    {
        @unlink($this->logFile);
    }

    /**
     * @param array $config
     * @param Helper $helper
     */
    private function runJob(array $config, Helper $helper = null)
    {
        $config = $this->getJobConfig($config);

        $job = new BackgroundJob("name", $config, $helper);
        $job->run();
    }

    /**
     * @param array $config
     * @return array
     */
    private function getJobConfig(array $config)
    {
        $helper = new Helper();

        if ($config['command'] instanceof \Closure) {
            $config['command'] = $helper->closureToString($config['command']);
        }

        return array_merge(
            array(
                "enabled" => 1,
                "runOnHost" => $helper->getHost(),
                "dateFormat" => "Y-m-d H:i:s",
                "schedule" => "* * * * *",
                "output" => $this->logFile,
                "maxRuntime" => null
            ),
            $config
        );
    }

    /**
     * @covers Jobby\BackgroundJob::run
     */
    public function testShouldNotRunIfNotEnabled()
    {
        $this->runJob(array(
            "command" => function() { echo "test"; return true; },
            "enabled" => false
        ));

        $this->assertEquals("", $this->getLogContent());
    }

    /**
     * @covers Jobby\BackgroundJob::run
     */
    public function testShouldNotRunIfNotDue()
    {
        $this->runJob(array(
            "command" => function() { echo "test"; return true; },
            "schedule" => "0 0 1 1 *"
        ));

        $this->assertEquals("", $this->getLogContent());
    }

    /**
     * @covers Jobby\BackgroundJob::run
     */
    public function testShouldNotRunOnWrongHost()
    {
        $this->runJob(array(
            "command" => function() { echo "test"; return true; },
            "runOnHost" => "something that does not match"
        ));

        $this->assertEquals("", $this->getLogContent());
    }

    /**
     * @covers Jobby\BackgroundJob::run
     */
    public function testShouldRunAsCurrentUser()
    {
        $this->runJob(array(
            "command" => function() { echo getmyuid(); return true; }
        ));

        $this->assertEquals(getmyuid(), $this->getLogContent());
    }

    /**
     * @covers Jobby\BackgroundJob::runFile
     */
    public function testInvalidCommand()
    {
        $this->runJob(array("command" => "invalid-command"));

        $this->assertContains("invalid-command", $this->getLogContent());
        $this->assertContains("not found", $this->getLogContent());
        $this->assertContains(
            "ERROR: Job exited with status '127'",
            $this->getLogContent()
        );
    }

    /**
     * @covers Jobby\BackgroundJob::runFunction
     */
    public function testClosureNotReturnTrue()
    {
        $this->runJob(array("command" => function() { return false; }));

        $this->assertContains(
            'ERROR: Closure did not return true! Returned:',
            $this->getLogContent()
        );
    }

    /**
     * @covers Jobby\BackgroundJob::getLogFile
     */
    public function testHideStdOutByDefault()
    {
        ob_start();
        $this->runJob(array(
            "command" => function() { echo "foo bar"; },
            "output" => null
        ));
        $content = ob_get_contents();
        ob_end_clean();

        $this->assertEquals("", $content);
    }

    /**
     * @covers Jobby\BackgroundJob::getLogFile
     */
    public function testShouldCreateLogFolder()
    {
        $logfile = dirname($this->logFile) . "/foo/bar.log";
        $this->runJob(array(
            "command" => function() { echo "foo bar"; },
            "output" => $logfile
        ));

        $dirExists = file_exists(dirname($logfile));
        $isDir = is_dir(dirname($logfile));

        unlink($logfile);
        rmdir(dirname($logfile));

        $this->assertTrue($dirExists);
        $this->assertTrue($isDir);
    }

    /**
     * @covers Jobby\BackgroundJob::mail
     */
    public function testNotSendMailOnMissingRecipients()
    {
        $helper = $this->getMock("Jobby\Helper", array("sendMail"));
        $helper->expects($this->never())
            ->method("sendMail");

        $this->runJob(
            array(
                "command" => function() { return false; },
                "recipients" => ""
            ),
            $helper
        );
    }

    /**
     * @covers Jobby\BackgroundJob::mail
     */
    public function testMailShoudTriggerHelper()
    {
        $helper = $this->getMock("Jobby\Helper", array("sendMail"));
        $helper->expects($this->once())
            ->method("sendMail");

        $this->runJob(
            array(
                "command" => function() { return false; },
                "recipients" => "test@example.com"
            ),
            $helper
        );
    }

    /**
     * @covers Jobby\BackgroundJob::checkMaxRuntime
     */
    public function testCheckMaxRuntime()
    {
        $helper = $this->getMock("Jobby\Helper", array("getLockLifetime"));
        $helper->expects($this->once())
            ->method("getLockLifetime")
            ->will($this->returnValue(0));

        $this->runJob(
            array(
                "command" => "true",
                "maxRuntime" => 1
            ),
            $helper
        );

        $this->assertEquals("", $this->getLogContent());
    }

    /**
     * @covers Jobby\BackgroundJob::checkMaxRuntime
     */
    public function testCheckMaxRuntimeShouldFailIsExceeded()
    {
        $helper = $this->getMock("Jobby\Helper", array("getLockLifetime"));
        $helper->expects($this->once())
            ->method("getLockLifetime")
            ->will($this->returnValue(2));

        $this->runJob(
            array(
                "command" => "true",
                "maxRuntime" => 1
            ),
            $helper
        );

        $this->assertContains(
            "MaxRuntime of 1 secs exceeded! Current runtime: 2 secs",
            $this->getLogContent()
        );
    }
}
