<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\components;

use hanneskod\classtools\Iterator\ClassIterator;
use nineinchnick\audit\behaviors\TrackableBehavior;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use yii\base\NotSupportedException;
use yii\base\Object;
use yii\db\Connection;
use yii\di\Instance;

class AuditManager extends Object
{
    /**
     * @var \yii\db\Connection|array|string the DB connection object, object configuration array
     * or the application component ID of the DB connection to use
     * when managing audits.
     */
    public $db = 'db';
    /**
     * @var string name of the audit schema, where all audit related objects are stored
     */
    public $auditSchema = 'audits';
    public $schemaMap = [
        'pgsql' => 'nineinchnick\audit\components\pgsql\PgsqlAuditManager',
    ];
    /**
     * @var BackendAuditManagerInterface an audit manager specific to the database type of $db
     */
    private $backendAuditManager;

    public function __construct($config = [])
    {
        parent::__construct($config);
    }

    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::className());
    }

    /**
     * @return BackendAuditManagerInterface
     * @throws NotSupportedException
     * @throws \yii\base\InvalidConfigException
     */
    public function getBackendAuditManager()
    {
        if ($this->backendAuditManager !== null) {
            return $this->backendAuditManager;
        }
        $driver = $this->db->getDriverName();
        if (!isset($this->schemaMap[$driver])) {
            throw new NotSupportedException("AuditManager does not support the '$driver' DBMS.");
        }
        $config = !is_array($this->schemaMap[$driver]) ? ['class' => $this->schemaMap[$driver]]
            : $this->schemaMap[$driver];
        $config['db'] = $this->db;
        $config['auditSchema'] = $this->auditSchema;

        return $this->backendAuditManager = \Yii::createObject($config);
    }

    /**
     * For every model found in the application and all modules checks if audit objects exist and are valid.
     * @param array $exclude as an array of model names
     * @return array
     */
    public function getReport($exclude = [])
    {
        $finder = new Finder();
        $iter = new ClassIterator($finder->in('.'));
        $iter->enableAutoloading();

        $result = $this->getBackendAuditManager()->checkGeneral();
        /** @var ReflectionClass $class */
        foreach ($iter->type('yii\db\ActiveRecord') as $name => $class) {
            if (in_array($name, $exclude)) {
                continue;
            }
            $result[$name] = $this->getBackendAuditManager()->checkModel(new $name);
        }

        return $result;
    }

    /**
     * Returns an array of commands required to either install or fix audit database objects.
     * @param string $modelName
     * @param string $direction
     * @return array
     * @throws NotSupportedException
     */
    public function getDbCommands($modelName, $direction = 'up')
    {
        return $this->getBackendAuditManager()->getDbCommands($modelName === null ? null : new $modelName, $direction);
    }
}
