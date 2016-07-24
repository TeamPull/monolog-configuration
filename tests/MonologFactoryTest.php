<?php
namespace Monolog;
use Monolog\Configuration\MonologFactory;
class MonologFactoryTest extends \PHPUnit_Framework_TestCase
{
    /** creates a LoggerFactory (testsubject) that reads a test configuration file
    * @param $name channel/logger name that should be fetched
    */
    private function getLogger($name)
    {
        $vars = [
            'monolog_config_dir' => __DIR__,
            'kernel.root_dir'=>'.',
            'kernel.logs_dir'=>'.'
        ];
        $lf = new MonologFactory($vars);
        $log = $lf->getLogger($name);
        //even if the name is not configured it is guarenteed that we will
        //get a logger because it should possible that plugins in applications
        //log to their own channel but work even if configuration file is not adapted
        //they will then use the channel 'other' if that channel is configured
        //or if that is also not configured a logger with no handlers or a NullLogger will be returned.
        $this->assertNotNull($log);
        $this->assertInstanceOf('Psr\\Log\\LoggerInterface',$log);
        return $log;
    }

    public function testMailHandler()
    {
        $this->getLogger('testMail');
        //assert mailhandler
    }

    public function testUnkownChannel()
    {
        $this->getLogger('_NOTEXISTINGCHANNELNAME_');
        //assert otherChannel
    }
    
    public function testDefaultLogger()
    {
        $this->getLogger(null);
        //assertDefaultHandler
    }


    
}
