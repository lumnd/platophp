<?php
return array(
    /* core error */
    1000 => 'System Error [%s]',
    1001 => 'App path[%s] is not readable.',
    1002 => 'Config file[%s] is not exists.',
    1003 => 'Autoload file[%s] is fails',
    1004 => 'Autoload registry handler is fail',
    1005 => 'Permission Error: AutoLoad File[%s] can not write',
    1006 => 'APP has no property [%s]',
    1007 => 'Log [%s] has no permission to write',

    /* controller error */
    2001 => 'Controller[%s] is not exists.',
    2002 => 'Method [%s] not exists in [%s]',
    2003 => 'Param Key [%s] checkType Error, %s given',
    2004 => 'Access error',
    2005 => 'Template [%s] is not exists',
    2006 => 'Shell[%s] is not exists.',
    2007 => 'Route [%s] cannot be resolved',
    2008 => 'Method [%s] is not allowed for [%s]',
    2009 => 'Action [%s] is not routable in [%s]',
    2010 => 'Middleware [%s] cannot be run',
    2011 => 'Action [%s] in [%s] has a malformed declaration',
    2012 => 'Controller [%s] declares $actions, but it is not a public static array',
    2013 => 'Route [%s] authenticates, but check_purview_handle is not configured',
    2014 => 'Authentication must return an identity object, null, or a reply for route [%s]; %s given',

    /* DAO error */
    3001 => 'Connect mysql[%s] is fail',
    3002 => 'DoubleDAO load Error: %s',
    3003 => 'Filter DAO must be DAO or DoubleDAO; %s given',
    3004 => 'Param Filter Error; %s given',
    3005 => 'Filter / Merge must be the same DAO',
    3006 => 'First Filter must be Array; %s given',
    3007 => 'Filter cannot be empty',
    3008 => 'Slave DataBase[%s] must be same to the master DataBase[%s]',
    3009 => 'Method [%s] not exists in [%s]',
    3010 => 'Group field can not be empty in [%s]',
    3011 => 'Addition Key[%s] invalid',
    3012 => 'Filter(not in) must be Array, %s given',

    /* Connect error */
    4001 => 'Socket Create Error: [%s]',
    4002 => 'Socket Connect[%s:%s] Error',
    4003 => 'Socket Len Error',
    4004 => 'Memcache Connect Error [%s:%s]',
    4005 => 'Redis Connect Error [%s:%s]',
    4006 => 'MQTT Connect Error [%s:%s]',
    4007 => 'Kafka Connect Error [%s:%s]',

    /* lib error */
    5001 => '[%s] not in form[%s] values',
    5002 => 'Method[%s] not exists in form[%s]',
    5003 => 'Event handler [%s] is not callable',

    /* web error */
    6000 => 'Cannot read request uri',
    6001 => 'Privilege [%s] is not access [%s]',
    6002 => 'Request body is not valid for its declared content type',

    /* model error */
    7000 => 'Model[%s] can not be callable',

    /* custom error */
    8000 => 'Custom Error',
);
