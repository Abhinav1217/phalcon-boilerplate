<?php

namespace Lib\Bootstrap;

use Phalcon\Config as Config,
    Phalcon\Loader as Loader,
    Phalcon\DI,
    Phalcon\DI\FactoryDefault,
    Phalcon\Mvc\Application,
    Phalcon\Mvc\Dispatcher,
    Phalcon\Mvc\View,
    Phalcon\Mvc\Url as UrlResolver,
    Phalcon\Db\Adapter\Pdo\Mysql as DbAdapter;

/**
 * This is the default Dependency Injection bootstrap class. It
 * contains service definitions for each of the default registered
 * services, loads the application config files, and sets up the
 * the application namespaces.
 *
 * Other classes in \Lib\Bootstrap extend this and are responsible
 * for creating the DI object and overwriting/instantiating any
 * dependencies.
 */
abstract class Base
{
    protected $di;
    protected $services;

    /**
     * Default constructor. Services contains a list of the
     * services to attach to the DI container.
     *
     * @param array $services services to run
     * @return factory default DI.
     */
    public function __construct( $services = array() )
    {
        $this->di = new FactoryDefault();
        $this->services = $services;
    }

    /**
     * Bootstraps the application.
     */
    public function run( $args = array() )
    {
        // initialize our required services
        $this->initConfig();
        $this->initLoader();

        foreach ( $this->services as $service )
        {
            $function = 'init'. ucfirst( $service );
            $this->$function();
        }

        \Phalcon\DI::setDefault( $this->di );
    }

    /**
     * Get the internal DI object
     */
    public function getDI()
    {
        return $this->di;
    }

    /**
     * Load configuration files
     */
    protected function initConfig()
    {
        // read in config arrays
        $defaultConfig = require( APP_PATH . '/etc/config.php' );
        $localConfig = require( APP_PATH . '/etc/config.local.php' );

        // instantiate them into the phalcon config
        $config = new Config( $defaultConfig );
        $config->merge( new Config($localConfig) );

        $this->di[ 'config' ] = $config;
    }

    /**
     * Register the namespaces, classes and directories
     */
    protected function initLoader()
    {
        $loader = new Loader();
        $loader->registerNamespaces([
            'Actions' => APP_PATH .'/actions/',
            'Base' => APP_PATH .'/base/',
            'Controllers' => APP_PATH .'/controllers/',
            'Db' => APP_PATH .'/models/',
            'Lib' => APP_PATH .'/library/'
        ]);
        $loader->registerClasses([
            '__' => VENDOR_PATH .'/Underscore.php'
        ]);
        $loader->register();

        // autoload vendor dependencies
        require_once VENDOR_PATH .'/autoload.php';

        $this->di[ 'loader' ] = $loader;
    }

    protected function initRouter()
    {
        $config = $this->di[ 'config' ];

        $this->di->set(
            'router',
            function () use ( $config ) {
                return require APP_PATH . '/etc/routes.php';
            },
            TRUE );
    }

    protected function initView()
    {
        $config = $this->di[ 'config' ];

        $this->di->set(
            'view',
            function () use ( $config ) {
                $view = new View();
                $view->setViewsDir( APP_PATH .'/views/' );
                return $view;
            },
            TRUE );
    }

    protected function initDispatcher()
    {
        $eventsManager = $this->di[ 'eventsManager' ];

        $this->di->set(
            'dispatcher',
            function () use ( $eventsManager ) {
                // create the default namespace
                $dispatcher = new Dispatcher();
                $dispatcher->setDefaultNamespace( 'Controllers' );

                // set up our error handler
                $eventsManager->attach(
                    'dispatch:beforeException',
                    function ( $event, $dispatcher, $exception ) {
                        switch ( $exception->getCode() )
                        {
                            case Dispatcher::EXCEPTION_HANDLER_NOT_FOUND:
                            case Dispatcher::EXCEPTION_ACTION_NOT_FOUND:
                                $dispatcher->forward([
                                    'namespace' => 'Controllers',
                                    'controller' => 'error',
                                    'action' => 'show404'
                                    ]);
                                return FALSE;
                            default:
                                $dispatcher->forward([
                                    'namespace' => 'Controllers',
                                    'controller' => 'error',
                                    'action' => 'show500',
                                    'params' => [
                                        NULL, // responseMode
                                        $exception ]
                                    ]);
                                return FALSE;
                        }
                    });

                $dispatcher->setEventsManager( $eventsManager );

                return $dispatcher;
            },
            TRUE );
    }

    protected function initUrl()
    {
        $config = $this->di[ 'config' ];

        $this->di->set(
            'url',
            function () use ( $config ) {
                $url = new UrlResolver();
                $url->setBaseUri( $config->paths->baseUri );
                $url->setStaticBaseUri( $config->paths->assetUri );
                return $url;
            },
            TRUE );
    }

    protected function initCookies()
    {
        $this->di->set(
            'cookies',
            function() {
                $cookies = new \Phalcon\Http\Response\Cookies();
                $cookies->useEncryption( FALSE );
                return $cookies;
            },
            TRUE );
    }

    protected function initSession()
    {
        $config = $this->di[ 'config' ];

        $this->di->set(
            'session',
            function () use ( $config ) {
                if ( $config->session->adapter === 'redis' ):
                    $session = new \Phalcon\Session\Adapter\Redis([
                        'path' => sprintf(
                            'tcp://%s:%s?weight=1&prefix=%s',
                            $config->redis->session->host,
                            $config->redis->session->port,
                            $config->redis->session->prefix ),
                        'name' => $config->session->name,
                        'lifetime' => $config->session->lifetime,
                        'cookie_lifetime' => $config->session->cookieLifetime
                    ]);
                else:
                    $session = new \Phalcon\Session\Adapter\Files();
                endif;

                $session->start();
                return $session;
            },
            TRUE );
    }

    protected function initProfiler()
    {
        $this->di->set(
            'profiler',
            function () {
                return new \Phalcon\Db\Profiler();
            },
            TRUE );
    }

    protected function initDb()
    {
        $config = $this->di[ 'config' ];
        $profiler = $this->di[ 'profiler' ];

        $this->di->set(
            'db',
            function () use ( $config, $profiler ) {
                // set up the database adapter
                $adapter = new DbAdapter([
                    'host' => $config->database->host,
                    'username' => $config->database->username,
                    'password' => $config->database->password,
                    'dbname' => $config->database->dbname,
                    'persistent' => $config->database->persistent
                ]);

                if ( $config->profiling->query ):
                    $eventsManager = new \Phalcon\Events\Manager();

                    // listen to all the database events
                    $eventsManager->attach(
                        'db',
                        function ( $event, $connection ) use ( $profiler ) {
                            if ( $event->getType() == 'beforeQuery' ):
                                $profiler->startProfile( $connection->getSQLStatement() );
                            endif;

                            if ( $event->getType() == 'afterQuery' ):
                                $profiler->stopProfile();
                            endif;
                        });
                    $adapter->setEventsManager( $eventsManager );
                endif;

                return $adapter;
            },
            TRUE );
    }

    protected function initMongo()
    {
        $config = $this->di[ 'config' ];

        $this->di->set(
            'mongo',
            function () use ( $config ) {
                $mongo = new Mongo();
                return $mongo->selectDb(
                    $config->mongodb->dbname );
            },
            TRUE );
    }

    protected function initCollectionManager()
    {
        $this->di->set(
            'collectionManager',
            function(){
                return new \Phalcon\Mvc\Collection\Manager();
            },
            TRUE );
    }

    protected function initBehaviors()
    {
        $this->di->set(
            'behavior_timestamp',
            function () {
                return new \Db\Behaviors\Timestamp();
            });
    }

    protected function initDataCache()
    {
        $config = $this->di[ 'config' ];

        $this->di->set(
            'dataCache',
            function () use ( $config ) {
                // create a Data frontend and set a default lifetime to 1 hour
                $frontend = new \Phalcon\Cache\Frontend\Data([
                    'lifetime' => 3600
                ]);

                if ( $config->session->adapter === 'redis' ):
                    // connect to redis
                    $redis = new Redis();
                    $redis->connect(
                        $config->redis->cache->host,
                        $config->redis->cache->port );

                    // create the cache passing the connection
                    return new \Phalcon\Cache\Backend\Redis(
                        $frontend, [
                            'redis' => $redis
                        ]);
                else:
                    return new \Phalcon\Cache\Backend\File(
                        $frontend, [
                            'cacheDir' => $config->cache->dir,
                            'prefix' => $config->cache->prefix
                        ]);
                endif;
            },
            TRUE );
    }

    protected function initUtil()
    {
        $this->di->set(
            'util', [
                'className' => '\Lib\Services\Util' ],
            TRUE );
    }

    protected function initAuth()
    {
        $this->di->set(
            'auth', [
                'className' => '\Lib\Services\Auth' ],
            TRUE );
    }

    protected function initCache()
    {
        $this->di->set(
            'cache', [
                'className' => '\Lib\Services\Cache' ],
            TRUE );
    }

    protected function initValidate()
    {
        $this->di->set(
            'validate', [
                'className' => '\Lib\Services\Validate'
            ]);
    }
}