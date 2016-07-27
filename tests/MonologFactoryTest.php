<?php
namespace Monolog;
use Monolog\Configuration\MonologFactory;
class MonologFactoryTest extends \PHPUnit_Framework_TestCase
{

    private $log;

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
        //or if that is also not configured a logger with no handlers will be returned.
        $this->assertNotNull($log);
        $this->assertInstanceOf('\Monolog\Logger',$log);
        $this->log = $log;
        return $log;
    }

    private function assertHandler($needed){
        $handlers = $this->log->getHandlers();
        $this->assertNotNull($handlers);
        print_r($handlers);
        $this->assertInstanceOf($needed,$handlers[0]);
    }

    public function testMailHandler()
    {
        $this->getLogger('testMail');
        $this->assertHandler('\Monolog\Handler\NativeMailerHandler');
    }

    public function testUnkownChannel()
    {
        $this->getLogger('_NOTEXISTINGCHANNELNAME_');
        //assert otherChannel
        //only the 'other' channel has the ErrorLogHandler configured first
        $this->assertHandler('\Monolog\Handler\ErrorLogHandler');
        //it not guaranteed that the loggername will have the requested name if there is no configuration
        //because guaranteeing that would make it unflexible in sense of perfomance tuning, anyway in moment of writing the channelname will be
        //_NOTEXISTINGCHANNELNAME_ but we only want to test that it is not one of the other configured channels
        $this->assertNotEquals('default',$this->log->getName())
    }
    
    public function testDefaultLogger()
    {
        $this->getLogger(null);
        //assertDefaultHandler
        //only the 'default' channel has the StreamHandler configured first
        $this->assertHandler('\Monolog\Handler\StreamHandler');
        $this->assertEquals('default',$this->log->getName())
    }


    
}
