<?php
namespace Monolog\Configuration;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\Yaml\Yaml;

/**
 * Class MonologFactory
 * responsible for creating a monlog instance
 * it reads the configuration file monolog.yaml
 * and configures the instance accordingly.
 */
class MonologFactory
{
    protected $vars;
    
    function __construct($vars){
        $this->vars = $vars;
        $this->loadMonologConfig();
    }

    protected $monologConfig;
    protected $channel;
    protected $channelConfig;
    protected $componentConfig;

    protected function loadMonologConfig()
    {       
        $path = $this->vars['monolog_config_dir'] . '/monolog.yaml';
        if(!file_exists($path)){
            $path = $this->vars['monolog_config_dir'] . '/monolog.dist.yaml';
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
        $this->channel=$name;
        if (!array_key_exists($name,$this->monologConfig)){
            return new \Psr\Log\NullLogger();
        }
        $this->channelConfig = $this->monologConfig['channels'][$name];
        
        if (array_key_exists('extends',$this->channelConfig)){
            // extend logger
            $log = $this->getLogger($this->channelConfig['extends']);
            $this->channel=$name;
            $this->channelConfig = $this->monologConfig['channels'][$name];
            $log = $log->withName($name);
        } else {
            // create logger
            $log = new Logger($name);
        }
        if (array_key_exists('use_microseconds',$this->channelConfig)){
            $log->useMicrosecondTimestamps($this->channelConfig['use_microseconds']);
        }
        if (array_key_exists('register_php_handlers',$this->channelConfig) && $this->channelConfig['register_php_handlers']) {
            ErrorHandler::register($log);
        }

        

        $this->componentBuilder(
            'handlers',
            [$this,'getHandler'],
            [$log,'pushHandler']
            );
        $this->componentBuilder(
            'processors',
            [$this,'getProcessor'],
            [$log,'pushProcessor']
            );
        return $log;
    }
    /**
     * Gets a components (handler,processor) from the configuration
     */
    protected function componentBuilder($componentKey,callable $getter,callable $pusher){
        $components = $this->channelConfig[$componentKey];
        if(is_array($components)){          
           foreach($components as $componentConfig){
              $this->componentConfig = $componentConfig;                 
              $component = $getter($componentConfig);
              if($component == null){$this->throwError("$componentKey was not created");}
              $pusher($component);
           }
        }
    }

    protected function getNamedComponent($componentType,&$componentConfig){        
        if(!is_array($componentConfig)){
             $componentName = $componentConfig;
             $componentConfigSection = $this->monologConfig[$componentType];
             if (!array_key_exists($componentName,$componentConfigSection)){
                 $this->throwError("$componentType - $componentName was refered in monolog configuration but was not defined");
             }
             $componentConfig = $componentConfigSection[$componentName];
        }               
    }

    protected function throwError($message){
        throw new MonologConfigurationError(
            $this->channel . ': '. $message
            . ' config:'
            . print_r($this->monologConfig,true)
        );
    }


    /**
     * @return callable
     */
    public function getProcessor($processorConfig)
    {
        $this->getNamedComponent('processors', $processorConfig);
        $class = $processorConfig['class'];
        if (strpos('\\',$class)===false){
            $class = '\\Monolog\\Processor\\' . $class;
        }
        $args = array_key_exists('arguments', $processorConfig) ? $processorConfig['arguments'] : [];
        $rc = new \ReflectionClass($class);
        $p = $rc->newInstanceArgs($args);
        return $p;
    }
    
    protected function getParameter($name, $default=null){
        if (array_key_exists($name,$this->componentConfig)) {
            return $this->componentConfig[$name];        
        }
        return $default;
    }

    protected function setComponentParameter($name,callable $c,$default=null){
        $value = $this->getParameter($name,$default);
        if($value!==null){
            $c($value);
            return true;
        }
        return false;
    }
    /**
     * @param $handlerConfig array
     * @return \Monolog\Handler
     */
    public function getHandler($handlerConfig)
    {
        $this->getNamedComponent('handlers',$handlerConfig); 
        $type = array_key_exists('type',$handlerConfig) ? ucfirst(strtolower($handlerConfig['type'])) : false;
        $levels = Logger::getLevels();
        $level =  array_key_exists('level',$handlerConfig) ? $levels[strtoupper($handlerConfig['level'])] : Logger::INFO;
        $bubble = array_key_exists('bubble',$handlerConfig) ? $handlerConfig['bubble'] : true;
       
        if ($type) {            
            $class = '\\Monolog\\Handler\\' . $type . 'Handler';
        }
                
        $class = $this->getParameter('class', $class);      
        
        if ($class==null){
            $this->throwError('no type and no class given for handler');
        }
        
        $rc = new \ReflectionClass($class);

        $args = [];
        $args = $this->getParameter('arguments');
        if ($args==null) { 
            $type = strtolower($type);
            $parentHandler = $this->getParameter('handler');
            if ($parentHandler) {
                $parentHandler = $this->getHandler($parentHandler);
            }
            
            /**
            * adds constructor argument for the new handler
            * @return boolean true if the argument was configured and added
            **/
            $addParameter = function($name,$default = null) use (&$args,$handlerConfig){
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
            


            if ($type == 'stream' || type == 'RotatingFile') {
                $addParameter('file');
            }
        } 
                            
        $handler = $rc->newInstanceArgs($args);
        $handler->setBubble($bubble);
        $handler->setLevel($level);

        if ($handler instanceof \Monolog\Handler\RotatingFileHandler) {
            $dateFormat = $this->getParameter('dateFormat','Y-m-d');
            $filenameFormat = $this->getParameter('FilenameFormat');
            if($fileFormat != null){
                $handler->setFilenameFormat($filenameFormat);
            }
        }
        return $handler;
    }
 }
