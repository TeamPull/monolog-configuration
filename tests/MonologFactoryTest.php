<?php
namespace Monolog;
use Monolog\Configuration\LoggerFactory;
class MonologFactoryTest extends \PHPUnit_Framework_TestCase
{
    public function testGetLogger()
    {
        $lf = new LoggerFactory($vars);
        $log = $lf->getLogger();
        $this->assertInstanceOf('Psr\Logger',$log);
    }
}
