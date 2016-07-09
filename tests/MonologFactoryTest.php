<?php
namespace Monolog;
use Monolog\Configuration\MonologFactory;
class MonologFactoryTest extends \PHPUnit_Framework_TestCase
{
    public function testGetLogger()
    {
        $vars = [
            'kernel.root_dir'=>'.',
            'kernel.logs_dir'=>'.'
        ];
        $lf = new MonologFactory($vars);
        $log = $lf->getLogger();
        $this->assertInstanceOf('Psr\Logger',$log);
    }
}
