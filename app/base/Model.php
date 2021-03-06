<?php

namespace Base;

use Phalcon\DI as DI,
    Phalcon\Mvc\Model\Behavior as Behavior;

class Model extends \Phalcon\Mvc\Model
{
    protected $behaviors = array();

    function getService( $service )
    {
        return $this->getDI()->get( $service );
    }

    static function getStaticService( $service )
    {
        return DI::getDefault()->get( $service );
    }

    static function getStaticDI()
    {
        return DI::getDefault();
    }
}