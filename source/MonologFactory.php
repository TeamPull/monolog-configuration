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

    }

    protected $monologConfig;
    protected function loadMonologConfig()
    {
        $config = Registry::get("oxConfigFile");
        $path = $config->getVar('sShopDir') . '../monolog.yaml';
        if(!file_exists($path)){
            $path = $config->getVar('sShopDir') . '../monolog.dist.yaml';
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
        $handlers = $channelConfig['handlers'];
        if(is_array($handlers)){
           foreach($handlers as $handlerConfig){
               $handler = $this->getHandler($handlerConfig);
               $log->pushHandler($handler);
           }
        }
        
        //Todo pushProcessors
        
        /*
         * every enhancing of the root logger that would create cycle dependencies during bootstrap
         * should be done in a separate class called after basic bootstrapping
         */
        return $log;
    }
    public function getHandler($handlerConfig)
    {
        if(!is_array($handlerConfig)){
             $handlerConfig = $this->monologConfig['handlers'][$handlerConfig];
        }
        
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
