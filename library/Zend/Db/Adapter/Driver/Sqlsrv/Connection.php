<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Db
 */

namespace Zend\Db\Adapter\Driver\Sqlsrv;

use Zend\Db\Adapter\Driver\Sqlsrv\Exception\ErrorException;
use Zend\Db\Adapter\Driver\ConnectionInterface;
use Zend\Db\Adapter\Exception;

/**
 * @category   Zend
 * @package    Zend_Db
 * @subpackage Adapter
 */
class Connection implements ConnectionInterface
{
    /**
     * @var Sqlsrv
     */
    protected $driver = null;

    /**
     * @var array
     */
    protected $connectionParameters = array();

    /**
     * @var resource
     */
    protected $resource = null;

    /**
     * @var bool
     */
    protected $inTransaction = false;

    /**
     * Constructor
     *
     * @param array|resource $connectionInfo
     * @throws \Zend\Db\Adapter\Exception\InvalidArgumentException
     */
    public function __construct($connectionInfo)
    {
        if (is_array($connectionInfo)) {
            $this->setConnectionParameters($connectionInfo);
        } elseif (is_resource($connectionInfo)) {
            $this->setResource($connectionInfo);
        } else {
            throw new Exception\InvalidArgumentException('$connection must be an array of parameters or a resource');
        }
    }

    /**
     * Set driver
     *
     * @param  Sqlsrv $driver
     * @return Connection
     */
    public function setDriver(Sqlsrv $driver)
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * Set connection parameters
     *
     * @param  array $connectionParameters
     * @return Connection
     */
    public function setConnectionParameters(array $connectionParameters)
    {
        $this->connectionParameters = $connectionParameters;
        return $this;
    }

    /**
     * Get connection parameters
     *
     * @return array
     */
    public function getConnectionParameters()
    {
        return $this->connectionParameters;
    }

    /**
     * Get current schema
     *
     * @return string
     */
    public function getCurrentSchema()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $result = sqlsrv_query($this->resource, 'SELECT SCHEMA_NAME()');
        $r = sqlsrv_fetch_array($result);
        return $r[0];
    }

    /**
     * Set resource
     *
     * @param  resource $resource
     * @return Connection
     */
    public function setResource($resource)
    {
        if (get_resource_type($resource) !== 'SQL Server Connection') {
            throw new Exception\InvalidArgumentException('Resource provided was not of type SQL Server Connection');
        }
        $this->resource = $resource;
        return $this;
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Connect
     *
     * @return null
     */
    public function connect()
    {
        if ($this->resource) {
            return;
        }

        $serverName = '.';
        $params = array(
            'ReturnDatesAsStrings' => true
        );
        foreach ($this->connectionParameters as $key => $value) {
            switch (strtolower($key)) {
                case 'hostname':
                case 'servername':
                    $serverName = (string) $value;
                    break;
                case 'username':
                case 'uid':
                    $params['UID'] = (string) $value;
                    break;
                case 'password':
                case 'pwd':
                    $params['PWD'] = (string) $value;
                    break;
                case 'database':
                case 'dbname':
                    $params['Database'] = (string) $value;
                    break;
                case 'driver_options':
                case 'options':
                    $params = array_merge($params, (array) $value);
                    break;

            }
        }

        $this->resource = sqlsrv_connect($serverName, $params);

        if (!$this->resource) {
            throw new Exception\RuntimeException(
                'Connect Error',
                null,
                new ErrorException(sqlsrv_errors())
            );
        }

    }

    /**
     * Is connected
     * @return boolean
     */
    public function isConnected()
    {
        return (is_resource($this->resource));
    }

    /**
     * Disconnect
     */
    public function disconnect()
    {
        sqlsrv_close($this->resource);
        $this->resource = null;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        // http://msdn.microsoft.com/en-us/library/cc296151.aspx
        /*
        $this->resource->autocommit(false);
        $this->inTransaction = true;
        */
    }

    /**
     * Commit
     */
    public function commit()
    {
        // http://msdn.microsoft.com/en-us/library/cc296194.aspx
        /*
        if (!$this->resource) {
            $this->connect();
        }

        $this->resource->commit();

        $this->inTransaction = false;
        */
    }

    /**
     * Rollback
     */
    public function rollback()
    {
        // http://msdn.microsoft.com/en-us/library/cc296176.aspx
        /*
        if (!$this->resource) {
            throw new \Exception('Must be connected before you can rollback.');
        }

        if (!$this->_inCommit) {
            throw new \Exception('Must call commit() before you can rollback.');
        }

        $this->resource->rollback();
        return $this;
        */
    }

    /**
     * Execute
     *
     * @param  string $sql
     * @return mixed
     */
    public function execute($sql)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $returnValue = sqlsrv_query($this->resource, $sql);

        // if the returnValue is something other than a Sqlsrv_result, bypass wrapping it
        if ($returnValue === false) {
            $errors = sqlsrv_errors();
            // ignore general warnings
            if ($errors[0]['SQLSTATE'] != '01000') {
                throw new Exception\RuntimeException(
                    'An exception occured while trying to execute the provided $sql',
                    null,
                    new ErrorException($errors)
                );
            }
        }

        $result = $this->driver->createResult($returnValue);
        return $result;
    }

    /**
     * Prepare
     *
     * @param  string $sql
     * @return string
     */
    public function prepare($sql)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $statement = $this->driver->createStatement($sql);
        return $statement;
    }

    /**
     * Get last generated id
     *
     * @return mixed
     */
    public function getLastGeneratedValue($name = null)
    {
        $sql = 'SELECT @@IDENTITY as Current_Identity';
        $result = sqlsrv_query($this->resource, $sql);
        $row = sqlsrv_fetch_array($result);
        return $row['Current_Identity'];
    }

}
