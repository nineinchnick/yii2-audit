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
     * Returns two queries, to create and destroy the audits schema.
     * @param string $schemaName
     * @return array with three keys: exists(boolean), up(string) and down(string)
     */
    public function schemaTemplate($schemaName)
    {
        return [
            'exists' => (new Query())
                ->select('nspname')
                ->from('pg_namespace')
                ->where('nspname = :value', [':value' => $schemaName])
                ->exists($this->db),
            'up' => "CREATE SCHEMA $schemaName",
            'down' => "DROP SCHEMA $schemaName",
        ];
    }

    /**
     * Returns two queries, to create and destroy the action db type.
     * @param string $schemaName if null, defaults to 'public'
     * @return array with three keys: exists(boolean), up(string) and down(string)
     */
    public function actionTypeTemplate($schemaName = null)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        return [
            'exists' => (new Query())
                ->select('typname')
                ->from('pg_type')
                ->where('typname=:value', [':value' => 'action_type'])->exists($this->db),
            'up' => "CREATE TYPE $schemaName.action_type AS ENUM ('INSERT', 'SELECT', 'UPDATE', 'DELETE', 'TRUNCATE')",
            'down' => "DROP TYPE $schemaName.action_type",
        ];
    }

    /**
     * Returns two queries, to create and destroy the db proc.
     * @param string $schemaName if null, defaults to 'public'
     * @param string $auditTableName
     * @return array with three keys: exists(boolean), up(array of strings) and down(array of strings)
     */
    public function procTemplate($schemaName, $auditTableName)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        $utilityKeys = <<<SQL
CREATE OR REPLACE FUNCTION json_object_delete_keys(_json json, VARIADIC _keys TEXT[]) RETURNS json AS \\\$BODY$
SELECT json_object_agg(key, value) AS json
FROM json_each(_json)
WHERE key != ALL (_keys)
\\\$BODY$
LANGUAGE sql
IMMUTABLE STRICT
SQL;

        $utilityValues = <<<SQL
CREATE OR REPLACE FUNCTION json_object_delete_values(_json json, _values json) RETURNS json AS \\\$BODY$
SELECT json_object_agg(a.key, a.value) AS json
FROM json_each_text(_json) a
JOIN json_each_text(_values) b ON a.key = b.key AND a.value != b.value
\\\$BODY$
LANGUAGE sql
IMMUTABLE STRICT
SQL;
        $functionTemplate = <<<SQL
CREATE OR REPLACE FUNCTION {$schemaName}.log_action() RETURNS trigger AS \\\$BODY$
DECLARE
    audit_row $schemaName.$auditTableName;
    include_values boolean;
    log_diffs boolean;
    h_old jsonb;
    h_new jsonb;
    excluded_cols text[] = ARRAY[]::text[];
BEGIN
    IF TG_WHEN <> 'AFTER' THEN
        RAISE EXCEPTION '$schemaName.log_action() may only run as an AFTER trigger';
    END IF;

    audit_row = ROW(
        nextval('$schemaName.{$auditTableName}_action_id_seq'), -- action_id
        TG_TABLE_SCHEMA::text,  -- schema_name
        TG_TABLE_NAME::text,    -- table_name
        TG_RELID,               -- relation OID for much quicker searches
        current_timestamp,      -- transaction_date
        statement_timestamp(),  -- statement_date
        clock_timestamp(),      -- action_date
        txid_current(),         -- transaction_id
        NULL::text,             -- session_user_name
        NULL::text,             -- application_name
        NULL::inet,             -- client_addr
        NULL::integer,          -- client_port
        current_query(),        -- top-level query or queries (if multistatement) from client
        TG_OP,                  -- action_type
        NULL::jsonb,            -- row_data
        NULL::jsonb,            -- changed_fields
        FALSE                   -- statement_only
    );

    IF TG_ARGV[0]::boolean IS NOT DISTINCT FROM FALSE THEN
        audit_row.query = NULL;
    END IF;

    IF TG_ARGV[1] IS NOT NULL THEN
        excluded_cols = TG_ARGV[1]::text[];
    END IF;

    IF TG_ARGV[2]::boolean IS NOT DISTINCT FROM FALSE THEN
        audit_row.session_user_name = session_user::text;
        audit_row.application_name = current_settings('application_name');
        audit_row.client_addr = inet_client_addr();
        audit_row.client_port = inet_client_port();
    END IF;

    IF (TG_OP = 'UPDATE' AND TG_LEVEL = 'ROW') THEN
        audit_row.row_data = row_to_json(OLD)::jsonb;
        audit_row.changed_fields = json_object_delete_keys(json_object_delete_values(row_to_json(NEW), audit_row.row_data::json), VARIADIC excluded_cols)::jsonb;
        IF audit_row.changed_fields IS NULL THEN
            -- All changed fields are ignored. Skip this update.
            RETURN NULL;
        END IF;
    ELSIF (TG_OP = 'DELETE' AND TG_LEVEL = 'ROW') THEN
        audit_row.row_data = json_object_delete_keys(row_to_json(OLD), VARIADIC excluded_cols)::jsonb;
    ELSIF (TG_OP = 'INSERT' AND TG_LEVEL = 'ROW') THEN
        audit_row.row_data = json_object_delete_keys(row_to_json(NEW), VARIADIC excluded_cols)::jsonb;
    ELSIF (TG_LEVEL = 'STATEMENT' AND TG_OP IN ('INSERT','UPDATE','DELETE','TRUNCATE')) THEN
        audit_row.statement_only = TRUE;
    ELSE
        RAISE EXCEPTION '[$schemaName.log_action] - Trigger func added as trigger for unhandled case: %, %', TG_OP, TG_LEVEL;
        RETURN NULL;
    END IF;
    INSERT INTO $schemaName.$auditTableName VALUES (audit_row.*);
    RETURN NULL;
END;
\\\$BODY$
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SQL;
        return [
            'exists' => (new Query())
                    ->select('proname')->from('pg_proc')
                    ->where('proname=:value', [':value' => 'json_object_delete_keys'])
                    ->exists($this->db)
                && (new Query())
                    ->select('proname')->from('pg_proc')
                    ->where('proname=:value', [':value' => 'json_object_delete_values'])
                    ->exists($this->db)
                && (new Query())
                    ->select('proname')->from('pg_proc')
                    ->where('proname=:value', [':value' => 'log_action'])
                    ->exists($this->db),
            'up' => [
                $schemaName.'json_object_delete_keys()' => $utilityKeys,
                $schemaName.'json_object_delete_values()' => $utilityValues,
                $schemaName.'log_action()' => $functionTemplate,
            ],
            'down' => [
                $schemaName.'log_action()' => "DROP FUNCTION {$schemaName}.log_action()",
                $schemaName.'json_object_delete_values()' => "DROP FUNCTION {$schemaName}.json_object_delete_values()",
                $schemaName.'json_object_delete_keys()' => "DROP FUNCTION {$schemaName}.json_object_delete_keys()",
            ],
        ];
    }

    /**
     * Returns two queries, to create and destroy the db trigger.
     * @param string $tableName name of the tracked table
     * @param string $schemaName if null, defaults to 'public'
     * @return array with three keys: exists(boolean), up(array of strings) and down(array of strings)
     */
    public function triggerTemplate($tableName, $schemaName)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        $rowTriggerTemplate = <<<SQL
CREATE TRIGGER log_action_row_trigger AFTER INSERT OR UPDATE OR DELETE ON {$tableName}
  FOR EACH ROW EXECUTE PROCEDURE {$schemaName}.log_action();
SQL;
        $stmtTriggerTemplate = <<<SQL
CREATE TRIGGER log_action_stmt_trigger AFTER INSERT OR UPDATE OR DELETE ON {$tableName}
  FOR EACH STATEMENT EXECUTE PROCEDURE {$schemaName}.log_action();
SQL;
        return [
            'exists' => (new Query())
                    ->select('tgname')
                    ->from('pg_trigger t')
                    ->innerJoin('pg_class c', 'c.oid = t.tgrelid')
                    ->innerJoin('pg_namespace n', 'n.oid = c.relnamespace')
                    ->where('n.nspname || \'.\' || c.relname = :table AND tgname=:value', [
                        ':table' => $tableName,
                        ':value' => 'log_action_row_trigger'
                    ])
                    ->exists($this->db)
                && (new Query())
                    ->select('tgname')
                    ->from('pg_trigger t')
                    ->innerJoin('pg_class c', 'c.oid = t.tgrelid')
                    ->innerJoin('pg_namespace n', 'n.oid = c.relnamespace')
                    ->where('n.nspname || \'.\' || c.relname = :table AND tgname=:value', [
                        ':table' => $tableName,
                        ':value' => 'log_action_stmt_trigger'
                    ])
                    ->exists($this->db),
            'up' => [
                'log_action_row_trigger' => $rowTriggerTemplate,
                'log_action_stmt_trigger' => $stmtTriggerTemplate,
            ],
            'down' => [
                'log_action_stmt_trigger' => "DROP TRIGGER log_action_stmt_trigger ON {$tableName}",
                'log_action_row_trigger' => "DROP TRIGGER log_action_row_trigger ON {$tableName}",
            ],
        ];
    }

    /**
     * Returns an array with columns list for the audit table that stores row version.
     * If the table already exists, also returns current columns for comparison.
     * @param string $auditTableName name of the audit table to store row versions
     * @param string $schemaName     if null, defaults to 'public'
     * @return array with three keys: exists(boolean), columns(array of strings) and currentColumns(array of strings)
     * @throws Exception
     */
    public function tableTemplate($auditTableName, $schemaName = null)
    {
        if ($schemaName === null) {
            $schemaName = 'public';
        }
        $columns = [
            'action_id'         => 'bigserial NOT NULL PRIMARY KEY',
            'schema_name'       => 'text NOT NULL',
            'table_name'        => 'text NOT NULL',
            'relation_id'       => 'oid NOT NULL',
            'transaction_date'  => 'timestamp with time zone NOT NULL',
            'statement_date'    => 'timestamp with time zone NOT NULL',
            'action_date'       => 'timestamp with time zone NOT NULL',
            'transaction_id'    => 'bigint',
            'session_user_name' => 'text',
            'application_name'  => 'text',
            'client_addr'       => 'inet',
            'client_port'       => 'integer',
            'query'             => 'text',
            'action_type'       => "$schemaName.action_type NOT NULL",
            'row_data'          => 'jsonb',
            'changed_fields'    => 'jsonb',
            'statement_only'    => 'boolean NOT NULL DEFAULT FALSE',
        ];
        return [
            'exists' => $this->db->schema->getTableSchema("$schemaName.$auditTableName") !== null,
            'columns' => $columns,
            'indexes' => [
                'schema_name, table_name', 'relation_id', 'statement_date', 'action_type',
                'row_data' => 'USING GIN (row_data jsonb_path_ops)',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function checkGeneral()
    {
        $result = [];
        $schemaTemplate = $this->schemaTemplate($this->auditSchema);
        if (!$schemaTemplate['exists']) {
            $result[] = "Missing db schema: {$this->auditSchema}\n";
        }
        $actionTypeTemplate = $this->actionTypeTemplate($this->auditSchema);
        if (!$actionTypeTemplate['exists']) {
            $result[] = 'Missing db type: action_type\n';
        }
        $procTemplate = $this->procTemplate($this->auditSchema, 'logged_actions');
        if (!$procTemplate['exists']) {
            $result[] = 'Missing db proc: log_action\n';
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
        if (($behavior = $this->getBehavior($model)) === null) {
            return null;
        }

        $auditTableName = $behavior->auditTableName;

        if (($pos=strpos($auditTableName, '.')) !== false) {
            $auditSchema = substr($auditTableName, 0, $pos);
        } else {
            $auditSchema = null;
        }
        $triggerTemplate = $this->triggerTemplate($model->getTableSchema()->name, $auditSchema);

        return [
            'enabled' => true,
            'valid' => $triggerTemplate['exists'],
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
        $auditTableName = $this->getBehavior($model)->auditTableName;
        if (($pos=strpos($auditTableName, '.')) !== false) {
            $auditSchema = substr($auditTableName, 0, $pos);
            $auditTableName = substr($auditTableName, $pos + 1);
        } else {
            $auditSchema = 'public';
        }

        $commands = [];

        if ($direction == 'up') {
            // don't drop schema and operator, since other objects could be left there
            $schemaTemplate = $this->schemaTemplate($auditSchema);
            if (!$schemaTemplate['exists']) {
                $commands[] = $this->db->createCommand($schemaTemplate[$direction]);
            }
            $actionTypeTemplate = $this->actionTypeTemplate($auditSchema);
            if (!$actionTypeTemplate['exists']) {
                $commands[] = $this->db->createCommand($actionTypeTemplate[$direction]);
            }
        }

        $tableTemplate = $this->tableTemplate($auditTableName, $auditSchema);
        $queryBuilder = $this->db->getQueryBuilder();
        if (!$tableTemplate['exists']) {
            if ($direction == 'up') {
                $query = $queryBuilder->createTable("$auditSchema.$auditTableName", $tableTemplate['columns']);
            } else {
                $query = $queryBuilder->dropTable("$auditSchema.$auditTableName");
            }
            $commands[] = $this->db->createCommand($query);
            foreach ($tableTemplate['indexes'] as $columns => $type) {
                if (is_numeric($columns)) {
                    $columns = $type;
                    $type = null;
                }
                if ($type === null) {
                    $query = "CREATE INDEX ON $auditSchema.$auditTableName ($columns)";
                } else {
                    $query = "CREATE INDEX ON $auditSchema.$auditTableName $type";
                }
                $commands[] = $this->db->createCommand($query);
            }
        }

        $procTemplate = $this->procTemplate($auditSchema, $auditTableName);
        if (!$procTemplate['exists']) {
            foreach ($procTemplate[$direction] as $query) {
                $commands[] = $this->db->createCommand($query);
            }
        }

        $triggerTemplate = $this->triggerTemplate($model->getTableSchema()->fullName, $auditSchema);
        if (!$triggerTemplate['exists']) {
            foreach ($triggerTemplate[$direction] as $query) {
                $commands[] = $this->db->createCommand($query);
            }
        }
        return $direction == 'down' ? array_reverse($commands) : $commands;
    }

    /**
     * @param ActiveRecord $model
     * @return TrackableBehavior
     */
    private function getBehavior(ActiveRecord $model)
    {
        foreach ($model->getBehaviors() as $behavior) {
            if ($behavior instanceof TrackableBehavior) {
                return $behavior;
            }
        }
        return null;
    }
}
