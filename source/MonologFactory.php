<?php
namespace Monolog\Configuration;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\ErrorlogHandler;
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
        $this->logger= new Logger();
        $this->monologConfig = $this->loadMonologConfig($vars);
        $this->logger->pushHandler(new ErrorLogHandler());
    }

    protected $monologConfig;
    protected $channel;
    protected $channelConfig;
    protected $componentConfig;
    protected $componentConfigStack = [];

    //internal loggger
    protected $logger;

    protected $loggerRegistry = [];
    protected function loadMonologConfig($vars)
    {       
        $path = $vars['monolog_config_dir'] . '/monolog.yaml';
        if(!file_exists($path)){
            $path = $vars['monolog_config_dir'] . '/monolog.dist.yaml';
        }
        //Do not try catch parse erors because the system should
        // not continue to work until the configuration is fixed
        return Yaml::parse(file_get_contents($path));
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
        //Cycle detection 
        if (array_key_exists($name,$this->loggerRegistry)){
            if ($this->loggerRegistry[$name] === 'building'){
                 $this->throwError("cycle dependency of loggers: while trying to build '$name'");
            }
            
            return $this->loggerRegistry[$name];
        }
        $this->loggerRegistry[$name] = 'building';

        $this->channel=$name;
        if (! array_key_exists($name,$this->monologConfig['channels'])){
            if($name == 'default'){
                $this->throwError('default channel must be defined');
            }
            $this->monologConfig['channels'][$name] = ['extends'=> 'default'];
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
        $this->loggerRegistry[$name] = $log;
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
        $this->setActiveComponentConfig($processorConfig);
        $class = $processorConfig['class'];
        if (strpos('\\',$class)===false){
            $class = '\\Monolog\\Processor\\' . $class;
        }
        $args = array_key_exists('arguments', $processorConfig) ? $processorConfig['arguments'] : [];
        $rc = new \ReflectionClass($class);
        $p = $rc->newInstanceArgs($args);
        $this->popActiveComponentConfig();
        return $p;
    }
    
    protected function getParameter($name, $default=null){
        if (array_key_exists($name,$this->componentConfig)) {
            return $this->componentConfig[$name];        
        }
        return $default;
    }

    protected function getArg($name){
        $arg = $this->getParameter($name);
        if($name == 'handler'){
            $arg = $this->getHandler($arg);
        } elseif ($name == 'level' || $name == 'deduplicationLevel'){
            $levels = Logger::getLevels();
            $level = $arg ? $levels[strtoupper($arg)] : null;
        }
        return $arg;
    }

    protected function setComponentParameter($name,callable $c,$default=null){
        $value = $this->getParameter($name,$default);
        if($value!==null){
            $c($value);
            return true;
        }
        return false;
    }

    public function setActiveComponentConfig($config){
       $this->componentConfig = $config;
       array_push($this->componentConfigStack,$config);
    }

    public function popActiveComponentConfig(){
       $this->componentConfig = array_pop($this->componentConfigStack);
    }
    /**
     * @param $handlerConfig array
     * @return \Monolog\Handler
     */
    public function getHandler($handlerConfig)
    {
        $this->getNamedComponent('handlers',$handlerConfig); 
        $this->setActiveComponentConfig($handlerConfig);
        $type = array_key_exists('type',$handlerConfig) ? ucfirst(strtolower($handlerConfig['type'])) : false;
        $levels = Logger::getLevels();
        $level = $this->getParameter('level','info');       
        $level = $levels[strtoupper($level)];
        $bubble = (bool) $this->getParameter('bubble');
       
        if ($type) {            
            $class = '\\Monolog\\Handler\\' . $type . 'Handler';
        }
                
        $class = $this->getParameter('class', $class);      
        
        if ($class == null){
            $this->throwError('no type and no class given for handler');
        }
        
        $rc = new \ReflectionClass($class);
        print "building $class config:" . json_decode($handlerConfig,true);
        $args = $this->getParameter('arguments');
        if ($args==null) {         
            $args = [];  
            $type = strtolower($type);
                      
            $constructor = $rc->getConstructor();
            $parameters = $constructor->getParameters();
            
            foreach($parameters as $parameter){
                $arg = $this->getArg($parameter->name);
                if ($arg === null){
                    print $parameter->name . " is not definde";
                    break;
                }
                this->logger->debug($parameter->name . "-> $arg");
                print $parameter->name . "-> $arg";
                $args[] = $arg;
            }
            
            if ($type == 'couchdb' ) {
                $args[0] = $handlerConfig;
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
        $this->popActiveComponentConfig();
        return $handler;
    }
    
 }
