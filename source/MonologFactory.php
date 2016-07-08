<?php
namespace Monolog\Configuration;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\Yaml\Yaml;

/**
 * Class LoggerFactory
 */
class MonologFactory implements LoggerFactoryInterface
{
    protected $vars;

    function __construct($vars){
        $this->vars = $vars;
    }

    protected $monologConfig;
    protected function loadMonologConfig()
    {
        
        $path = $this->vars['kernel.root_dir'] . '../monolog.yaml';
        if(!file_exists($path)){
            $path = $this->vars['kernel.root_dir'] . '../monolog.dist.yaml';
        }
        //Do not try catch parse erors because the system should
        // not continue to work until the configuration is fixed
        $this->monologConfig = Yaml::parse(file_get_contents($path));
    }
    /**
     * creates a logger object
     * this method should be called only once during a request
     * no internal caching is done because logger object can be stored in caller or registry
     *
     * this method is called during bootstrap be careful to not create cycle dependencies
     * @param $name string name of the Logger default is 'default'
     * the name will be used as channel and each channel can be configured in the monolog.yaml file
     * @return Logger
     *      
     */
    public function getLogger($name = 'default'){
        
        
        $this->loadMonologConfig();
        
        $channelConfig = $this->monologConfig['channels'][$name];
        
        if (array_key_exists('extends',$channelConfig)){
            // extend logger
            $log = $this->getLogger($channelConfig['extends']);
            $log = $log->withName($name);
        } else {
            // create logger
            $log = new Logger($name);
        }
        if (array_key_exists('use_microseconds',$channelConfig)){
            $log->useMicrosecondTimestamps($channelConfig['use_microseconds']);
        }
        if ($channelConfig['register_php_handlers']) {
            ErrorHandler::register($log);
        }

        
        $componentBuilder = function($component,$getter,$pusher){
            $components = $channelConfig["${component}s"];
            if(is_array($components)){
               foreach($components as $componentConfig){
                  if(!is_array($componentConfig)){
                      $componentConfig = $this->monologConfig["${component}s"][$componentConfig];
                  }
                  $component = $getter($componentConfig);
                  $pusher($component);
               }
            }
        }

        $componentBuilder('handler',
            [$this,'getHandler'],
            [$log,'pushHandler']
            );
        $componentBuilder('processor',
            [$this,'getProcessor'],
            [$log,'pushProcessor']
            );
        return $log;
    }

    /**
     * @return callable
     */
    public function getProcessor($processorConfig)
    {
       
    }

    public function getHandler($handlerConfig)
    {
        
        $type = $handlerConfig['type'];
        $levels = Logger::getLevels();
        $level =  $levels[strtoupper($handlerConfig['level'])];
        $bubble = $handlerConfig['bubble'];
        $args = [];
        if ($type) {            
            $class = '\\Monolog\\Handler\\' . $type . 'Handler';
            $type = strtolower($type);
            
            if ($handlerConfig['handler']) {
                $parentHandler = $this->getHandler($handlerConfig['handler']);
            }
            
            /**
            * adds constructor argument for the new handler
            * @return boolean true if the argument was configured and added
            **/
            $addParameter = function($name,$default) use (&$args,$handlerConfig){
                if (array_key_exists($name,$handlerConfig)) {
                    $args[] = $handlerConfig[$name];
                    return true;
                }
                if ($default !== null ){
                    $args[] = $default;
                }
                return false;
            };
            if ($type == 'buffer' ) {
                if ($parentHandler){
                    $args[] = $parentHandler;
                    $addParameter('bufferLimit',0);
                    $args[] = $level;
                    $args[] = $bubble;
                    $addParameter('flushOnOverflow');
                }
            }
            if ($type == 'couchdb' ) {
                $args[] = $handlerConfig;
            }
            if ($type == 'stream' ) {
                $addParameter('file');
            }
        } else {
            $class = $handlerConfig['class'];
            $args = $handlerConfig['arguments'];
        }
        $rc = new ReflectionClass($class);
        $handler = $rc->newInstanceArgs($args);
    }
