<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\components\pgsql;

use nineinchnick\audit\behaviors\TrackableBehavior;
use nineinchnick\audit\components\BackendAuditManagerInterface;
use yii\base\Exception;
use yii\base\Object;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\db\TableSchema;

class PgsqlAuditManager extends Object implements BackendAuditManagerInterface
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
    /**
     * @var string suffix appended to the audit table names to distinct them from tracked tables
     */
    public $auditSuffix = '_audit';
    /**
     * Returns two queries, to create and destroy the audits schema.
     * @param string $schemaName
     * @return array with three keys: exists(boolean), up(string) and down(string)
     */
    public function schemaTemplate($schemaName)
    {
        return [
            'exists' => (new Query())
                ->select('typname')
                ->from('pg_type')
                ->where('typname=:value', [':value' => 'operation'])
                ->exists($this->db),
            'up' => "CREATE SCHEMA $schemaName",
            'down' => "DROP SCHEMA $schemaName",
        ];
    }

    /**
     * Returns two queries, to create and destroy the operation db type.
     * @param string $schemaName if null, defaults to 'public'
     * @return array with three keys: exists(boolean), up(string) and down(string)
     */
    public function operationTemplate($schemaName = null)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        return [
            'exists' => (new Query())
                ->select('typname')
                ->from('pg_type')
                ->where('typname=:value', [':value' => 'operation'])->exists($this->db),
            'up' => "CREATE TYPE $schemaName.operation AS ENUM ('INSERT', 'SELECT', 'UPDATE', 'DELETE')",
            'down' => "DROP TYPE $schemaName.operation",
        ];
    }

    /**
     * Returns two queries, to create and destroy the db trigger.
     * @param string $tableName name of the tracked table
     * @param string $auditTableName name of the audit table to store row versions
     * @param string $schemaName if null, defaults to 'public'
     * @return array with three keys: exists(boolean), up(array of strings) and down(array of strings)
     */
    public function triggerTemplate($tableName, $auditTableName, $schemaName = null)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        $triggerName = $auditTableName;
        $procName = $auditTableName.'_process';
        $triggerTemplate = <<<SQL
CREATE TRIGGER {$triggerName} AFTER INSERT OR UPDATE OR DELETE ON {$tableName}
  FOR EACH ROW EXECUTE PROCEDURE {$schemaName}.{$procName}();
SQL;
        $functionTemplate = <<<SQL
CREATE OR REPLACE FUNCTION {$schemaName}.{$procName}()
  RETURNS trigger AS
\\\$BODY$
BEGIN
    IF (TG_OP = 'UPDATE') THEN
        INSERT INTO {$schemaName}.{$auditTableName}
        SELECT 'UPDATE', now(), nextval('{$schemaName}.{$auditTableName}_audit_id_seq'::regclass), NEW.*;
        RETURN NEW;
    ELSIF (TG_OP = 'DELETE') THEN
        INSERT INTO {$schemaName}.{$auditTableName}
        SELECT 'DELETE', now(), nextval('{$schemaName}.{$auditTableName}_audit_id_seq'::regclass), OLD.*;
        RETURN OLD;
    ELSIF (TG_OP = 'INSERT') THEN
        INSERT INTO {$schemaName}.{$auditTableName}
        SELECT 'INSERT', now(), nextval('{$schemaName}.{$auditTableName}_audit_id_seq'::regclass), NEW.*;
        RETURN NEW;
    ELSE
        RAISE WARNING '[{$schemaName}.{$auditTableName}] - Other action occurred: %, at %',TG_OP,now();
        RETURN NULL;
    END IF;
END
\\\$BODY$
  LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SQL;
        /*
EXCEPTION
    WHEN data_exception THEN
        RAISE WARNING '[{$schemaName}.{$auditTableName}] - ERROR [DATA EXCEPTION] - SQLSTATE: %, SQLERRM: %',SQLSTATE,SQLERRM;
        RETURN NULL;
    WHEN unique_violation THEN
        RAISE WARNING '[{$schemaName}.{$auditTableName}] - ERROR [UNIQUE] - SQLSTATE: %, SQLERRM: %',SQLSTATE,SQLERRM;
        RETURN NULL;
    WHEN OTHERS THEN
        RAISE WARNING '[{$schemaName}.{$auditTableName}] - ERROR [OTHER] - SQLSTATE: %, SQLERRM: %',SQLSTATE,SQLERRM;
        RETURN NULL;
         */
        return [
            'exists' => (new Query())
                    ->select('tgname')
                    ->from('pg_trigger')
                    ->where('tgname=:value', [':value' => $triggerName])
                    ->exists($this->db)
                && (new Query())
                    ->select('proname')
                    ->from('pg_proc')
                    ->where('proname=:value', [':value' => $procName])
                    ->exists($this->db),
            'up' => [
                $schemaName.$procName.'()' => $functionTemplate,
                $triggerName => $triggerTemplate,
            ],
            'down' => [
                $schemaName.$procName.'()' => "DROP FUNCTION {$schemaName}.{$procName}()",
                $triggerName => "DROP TRIGGER {$triggerName} ON {$tableName}",
            ],
        ];
    }

    /**
     * Returns an array with columns list for the audit table that stores row version.
     * If the table already exists, also returns current columns for comparison.
     * @param TableSchema $table     the tracked table
     * @param string $auditTableName name of the audit table to store row versions
     * @param string $schemaName     if null, defaults to 'public'
     * @return array with three keys: exists(boolean), columns(array of strings) and currentColumns(array of strings)
     * @throws Exception
     */
    public function tableTemplate(TableSchema $table, $auditTableName, $schemaName = null)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        $columns = [
            'operation' => "$schemaName.operation NOT NULL",
            'operation_date' => 'timestamp NOT NULL',
            'audit_id'=>'serial NOT NULL PRIMARY KEY',
        ];
        foreach ($table->columns as $name => $column) {
            if (isset($columns[$name])) {
                throw new Exception("Cannot create audit table template: duplicate column name $name.");
            }
            $columns[$name] = $column->dbType;
        }
        $auditTable = $this->db->schema->getTableSchema("$schemaName.$auditTableName");
        return [
            'exists' => $auditTable !== null,
            'columns' => $columns,
            'currentColumns' => $auditTable !== null ? $auditTable->columns : [],
        ];
    }

    /**
     * Compares the main and audit table columns and produces a list of columns to add, alter or drop.
     * @param array $columns
     * @param array $currentColumns
     * @return array up/down keys as arrays with three keys: add(array of strings),
     * alter(array of strings) and drop(array of strings)
     */
    public function tablePatch($columns, $currentColumns)
    {
        $result = [
            'up' => ['add' => [], 'alter' => [], 'drop' => []],
            'down' => ['add' => [], 'alter' => [], 'drop' => []],
        ];
        foreach ($columns as $name => $columnType) {
            if (isset($currentColumns[$name])) {
                if ($name != 'operation' && $name != 'operation_date' && $name != 'audit_id'
                    && $columnType != $currentColumns[$name]->dbType
                ) {
                    $result['up']['alter'][$name] = $columnType;
                    $result['down']['alter'][$name] = $currentColumns[$name]->dbType;
                }
                unset($currentColumns[$name]);
            } else {
                $result['up']['add'][$name] = $columnType;
                $result['down']['add'][$name] = false;
            }
        }
        foreach ($currentColumns as $name => $column) {
            $result['up']['drop'][$name] = true;
            $result['down']['drop'][$name] = $column->dbType;
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function checkGeneral()
    {
        $result = [];
        $schemaTemplate = $this->schemaTemplate('audits');
        if (!$schemaTemplate['exists']) {
            $result[] = 'Missing db schema: audits\n';
        }
        $operationTemplate = $this->operationTemplate('audits');
        if (!$operationTemplate['exists']) {
            $result[] = 'Missing db operator: operator\n';
        }
        return $result;
    }

    /**
     * Checks if audit objects for specified model exist and are valid.
     * @param ActiveRecord $model
     * @return array contains boolean keys: 'enabled' and 'valid'
     * @throws Exception
     */
    public function checkModel(ActiveRecord $model)
    {
        /** @var TrackableBehavior $behavior */
        if (($behavior = $model->getBehavior('audit')) === null) {
            return null;
        }

        $auditTableName = $behavior->auditTableName;
        if ($this->db->getTableSchema($auditTableName) === null) {
            return [
                'enabled' => false,
                'valid' => null,
            ];
        }

        if (($pos=strpos($auditTableName, '.')) !== false) {
            $auditSchema = substr($auditTableName, 0, $pos);
            $auditTableName = substr($auditTableName, $pos + 1);
        } else {
            $auditSchema = null;
        }
        $tableTemplate = $this->tableTemplate($model->getTableSchema(), $auditTableName, $auditSchema);
        $tablePatch = !$tableTemplate['exists']
            ? null : $this->tablePatch($tableTemplate['columns'], $tableTemplate['currentColumns']);
        $tableValid = $tableTemplate['exists'] && empty($tablePatch['up']['add'])
            && empty($tablePatch['up']['alter']) && empty($tablePatch['up']['drop']);
        $triggerTemplate = $this->triggerTemplate($model->getTableSchema()->name, $auditTableName, $auditSchema);

        return [
            'enabled' => true,
            'valid' => $tableValid && $triggerTemplate['exists'],
        ];
    }

    /**
     * Returns an array of commands required to either install or fix audit database objects.
     * @param ActiveRecord $model
     * @param string $direction
     * @return array
     */
    public function getDbCommands(ActiveRecord $model, $direction = 'up')
    {
        $commands = [];

        if ($direction == 'up') {
            // don't drop schema and operator, since other objects could be left there
            $schemaTemplate = $this->schemaTemplate('audits');
            if (!$schemaTemplate['exists']) {
                $commands[] = $this->db->createCommand($schemaTemplate[$direction]);
            }
            $operationTemplate = $this->operationTemplate('audits');
            if (!$operationTemplate['exists']) {
                $commands[] = $this->db->createCommand($operationTemplate[$direction]);
            }
        }

        $auditTableName = $model->getBehavior('trackable')->auditTableName;
        if (($pos=strpos($auditTableName, '.')) !== false) {
            $auditSchema = substr($auditTableName, 0, $pos);
            $auditTableName = substr($auditTableName, $pos + 1);
        } else {
            $auditSchema = null;
        }

        $tableTemplate = $this->tableTemplate($model->getTableSchema(), $auditTableName, $auditSchema);
        $queryBuilder = $this->db->getQueryBuilder();
        if ($tableTemplate['exists']) {
            $tablePatch = $this->tablePatch($tableTemplate['columns'], $tableTemplate['currentColumns']);
            foreach ($tablePatch[$direction]['add'] as $name => $type) {
                if ($type === false) {
                    $query = $queryBuilder->dropColumn($auditTableName, $name);
                } else {
                    $query = $queryBuilder->addColumn($auditTableName, $name, $type);
                }
                $commands[] = $this->db->createCommand($query);
            }
            foreach ($tablePatch[$direction]['alter'] as $name => $type) {
                $query = $queryBuilder->alterColumn($auditTableName, $name, $type);
                $commands[] = $this->db->createCommand($query);
            }
            foreach ($tablePatch[$direction]['drop'] as $name => $type) {
                if ($type === true) {
                    $query = $queryBuilder->dropColumn($auditTableName, $name);
                } else {
                    $query = $queryBuilder->addColumn($auditTableName, $name, $type);
                }
                $commands[] = $this->db->createCommand($query);
            }
        } else {
            if ($direction == 'up') {
                $query = $queryBuilder->createTable("$auditSchema.$auditTableName", $tableTemplate['columns']);
            } else {
                $query = $queryBuilder->dropTable("$auditSchema.$auditTableName");
            }
            $commands[] = $this->db->createCommand($query);
        }

        $triggerTemplate = $this->triggerTemplate($model->getTableSchema()->fullName, $auditTableName, $auditSchema);
        if (!$triggerTemplate['exists']) {
            foreach ($triggerTemplate[$direction] as $query) {
                $commands[] = $this->db->createCommand($query);
            }
        }
        return $direction == 'down' ? array_reverse($commands) : $commands;
    }
}
