<?php
namespace Monolog\Configuration;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\ErrorLogHandler;
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
        $this->logger= new Logger('LoggerFactory');       
        $this->logger->pushHandler(new ErrorLogHandler());
        $this->monologConfig = $this->loadMonologConfig($vars);
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

    private function isList(array $arr)
    {
        $k = array_keys( $arr );
        return $k === array_keys( $k );
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
        if($name == null){
           $name = 'default';  
        }
        $this->logger->debug("getting logger $name");
        if (array_key_exists($name,$this->loggerRegistry)){
            //Cycle detection
            if ($this->loggerRegistry[$name] === 'building'){
                 $this->throwError("cycle dependency of loggers: while trying to build '$name' requested by $this->channel");
            }
            $this->logger->debug("getting logger $name from cache");
            return $this->loggerRegistry[$name];
        }
        $this->loggerRegistry[$name] = 'building';
        try{
            $this->channel=$name;
            if (! array_key_exists($name,$this->monologConfig['channels'])){
                if($name == 'default'){
                    $this->logger->warn('default channel should be defined');             
                } elseif($name != 'other'){
                    $implizitConfig = ['extends' => 'other'];
                } else {
                    $implizitConfig = [];
                }
                $this->monologConfig['channels'][$name] = $implizitConfig;                
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
                $this->logger->debug("registering error handlers to channel '$name'");
                ErrorHandler::register($log);
            }       

            $handlers = $this->componentBuilder(
                'handlers', [$this,'getHandler']
            );
            $this->logger->debug("setting handlers",$handlers);
            $log->setHandlers($handlers);

            $processors = $this->componentBuilder(
                'processors', [$this,'getProcessor']            
            );

            $this->logger->debug("setting processors",$processors);
            foreach (array_reverse($processors) as $processor){
                $log->pushProcessor($processor);
            }

        } finally {
           //In case of exceptions we want the internal 'building' state to be reseted
           //so the caller can try it agian
           unset($this->loggerRegistry[$name]);
        }
        $this->loggerRegistry[$name] = $log;
        $this->logger->debug("returning logger",$log);
        return $log;
    }
    /**
     * Gets a components (handler,processor) from the configuration
     */
    protected function componentBuilder($componentKey,callable $getter){        
        if (!array_key_exists($componentKey,$this->channelConfig)){
            return [];
        }
        $componentNames = $this->channelConfig[$componentKey];
        $this->logger->debug("building $componentKey",$componentNames);
        $components = [];
        if (!(is_array($componentNames) && $this->isList($componentNames))){  
           if($componentNames){
               $this->throwError("the value given in $componentKey must be a list");
           }
        }
   
        foreach($componentNames as $componentName){                               
           $component = $getter($componentName);
           if($component == null){
              $this->throwError("$componentKey was not created");
           }
           $this->logger->debug("$componentName created",[$component]);
           $components[] = $component;
        }
        $this->logger->debug("components created",$components);
        return $components;  
    }

    protected function getNamedComponent($componentType,$componentName){        
        
         $componentConfigSection = $this->monologConfig[$componentType];
         if (!array_key_exists($componentName,$componentConfigSection)){
             $this->throwError("$componentType - $componentName was refered in monolog configuration but was not defined");
         }
         return $componentConfigSection[$componentName];                      
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
    public function getProcessor($processorName)
    {
        $processorConfig = $this->getNamedComponent('processors', $processorName);
        $this->setActiveComponentConfig($processorConfig);
        $class = $processorConfig['class'];
        if (strpos('\\',$class)===false){
            $class = '\\Monolog\\Processor\\' . $class;
        }
        $args = array_key_exists('arguments', $processorConfig) ? $processorConfig['arguments'] : [];
        $rc = new \ReflectionClass($class);

        if ($args==null) {         
            $args = $this->createArguments($rc);                                                        
        }
   
        $p = $rc->newInstanceArgs($args);
        $this->popActiveComponentConfig();
        return $p;
    }
    
    protected function getParameter($name, $default=null){
        $this->logger->debug("get parameter $name",$this->componentConfig);
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
        $this->logger->debug("getArg $name -> $arg");
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

    protected function createArguments(\ReflectionClass $rc)
    {
        $args = []; 
        $constructor = $rc->getConstructor();
        if($constructor == null){
            return $args;
        }
        $class = $rc->getName();
        $parameters = $constructor->getParameters();
            
        foreach($parameters as $parameter){
            $arg = $this->getArg($parameter->name);
            if ($arg === null) {           
                if ($parameter->isDefaultValueAvailable()) {
                    $arg = $parameter->getDefaultValue();
                } elseif (!$parameter->allowsNull()) {      
                    $this->throwError("missing parameter '". $parameter->name ."' for $class");
                }
            }
            $this->logger->debug($parameter->name . "-> $arg");             
            $args[] = $arg;
        }
        return $args;
    }

    /**
     * @param $handlerConfig array
     * @return \Monolog\Handler
     */
    public function getHandler($handlerName)
    {
        $handlerConfig = $this->getNamedComponent('handlers',$handlerName); 
        $this->setActiveComponentConfig($handlerConfig);
        $type = array_key_exists('type',$handlerConfig) ? ucfirst($handlerConfig['type']) : false;
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
        $this->logger->debug("building $class config",$handlerConfig);
        $args = $this->getParameter('arguments');

        if ($args==null) {
            $type = strtolower($type);
            if ($type == 'couchdb') {
                $args[0] = $handlerConfig;
            } 
        }

        if ($args==null) {         
            $args = $this->createArguments($rc);                                                        
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
