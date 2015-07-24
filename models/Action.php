<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\models;

use nineinchnick\audit\behaviors\TrackableBehavior;
use yii\base\Model;
use yii\data\SqlDataProvider;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Holds data for a single logged action.
 * @package nineinchnick\audit\models
 * @property string $user string representation of User found using user_id
 * @property ActiveRecord $model modelClass instance
 * @property string $actionTypeLabel translated label for action_type
 * @property array $rowData
 */
class Action extends Model
{
    /**
     * @var integer unique only together with $key_type
     */
    public $id;
    /**
     * @var string one of: c for changeset, t for transaction or a for a single action
     */
    public $key_type;


    /**
     * @var string date when the action has been executed, either request or action (single query) date
     */
    public $request_date;
    /**
     * @var string request url when the action has been executed
     */
    public $request_url;
    /**
     * @var string|integer user id
     */
    public $user_id;
    /**
     * @var string client ip address
     */
    public $request_addr;
    /**
     * @var string PHP session id
     */
    public $session_id;


    /**
     * @var string schema name
     */
    public $schema_name;
    /**
     * @var string table name
     */
    public $table_name;
    /**
     * @var string model class name matching the table name
     */
    public $modelClass;


    /**
     * @var string one of: INSERT, UPDATE, DELETE
     */
    public $action_type;
    /**
     * @var array data before the change, used to populate $model
     */
    public $row_data;
    /**
     * @var array
     */
    public $changed_fields;


    /**
     * @var array full row data
     */
    private $data;
    /**
     * @var ActiveRecord modelClass instance
     */
    private $cachedModel;
    /**
     * @var array action type labels
     */
    private $actionTypeMap;

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->actionTypeMap = [
            'INSERT' => \Yii::t('app', 'Inserted'),
            'UPDATE' => \Yii::t('app', 'Updated'),
            'DELETE' => \Yii::t('app', 'Deleted'),
        ];
    }

    public function getUser()
    {
        if ($this->user_id === null) {
            return \Yii::t('app', 'system');
        }
        if (($user = \app\models\User::findOne($this->user_id)) === null) {
            return \Yii::t('app', 'Deleted user');
        }

        return method_exists($user, '__toString')
            ? (string)$user
            : \Yii::t('app', 'User') . ' ' . implode('-', $user->getPrimaryKey(true));
    }

    public function getModel()
    {
        if ($this->cachedModel !== null) {
            return $this->cachedModel;
        }
        /** @var ActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        $this->cachedModel = new $modelClass;
        $modelClass::populateRecord($this->cachedModel, $this->row_data);
        return $this->cachedModel;
    }

    public function getActionTypeLabel()
    {
        return $this->actionTypeMap[$this->action_type];
    }

    /**
     * @param array $data row data
     * @param array $tablesMap map of fully qualified table names to model class names
     */
    public function setRowData($data, $tablesMap)
    {
        $this->data = $data;

        $data['row_data'] = (array)json_decode($data['row_data']);
        $data['changed_fields'] = (array)json_decode($data['changed_fields']);
        if ($data['request_date'] === null) {
            $data['request_date'] = $data['action_date'];
        }
        if ($data['request_url'] === null) {
            $data['request_url'] = '<abbr title="Command-line Interpreter">CLI</abbr>';
        }
        if (isset($tablesMap[$data['schema_name'] . '.' . $data['table_name']])) {
            $data['modelClass'] = $tablesMap[$data['schema_name'] . '.' . $data['table_name']];
        }

        $this->setAttributes($data, false);
    }

    public function getRowData()
    {
        return $this->data;
    }

    /**
     * @param string $modelClass
     * @param ActiveRecord $model
     * @param array $relations
     * @return SqlDataProvider
     */
    private static function getKeysDataprovider($modelClass, $model, $relations)
    {
        /** @var \yii\db\ActiveRecord $staticModel */
        $staticModel = new $modelClass;
        /** @var TrackableBehavior $behavior */
        $behavior = $staticModel->getBehavior('trackable');
        $conditions = ['AND', 'a.statement_only = FALSE', ['IN', '(a.relation_id::regclass::varchar)', $relations]];
        $params = [];
        if ($model !== null) {
            $conditions[] = "a.row_data @> (:key)::jsonb";
            $params[':key'] = json_encode($model->getPrimaryKey(true));
        }

        $subquery = (new Query())
            ->select([
                'key_type' => 'a.key_type',
                'id' => 'COALESCE(a.changeset_id, a.transaction_id, a.action_id)',
            ])
            ->from($behavior->auditTableName.' a')
            ->leftJoin($behavior->changesetTableName.' c', 'c.id = a.changeset_id')
            ->where($conditions)
            ->groupBy(['a.key_type', 'COALESCE(a.changeset_id, a.transaction_id, a.action_id)']);
        $subquery->addParams($params);
        $countQuery = clone $subquery;
        $countQuery->select(['COUNT(DISTINCT ROW(a.key_type, COALESCE(a.changeset_id, a.transaction_id, a.action_id)))']);
        $countQuery->groupBy([]);
        $command = $subquery->createCommand($staticModel->getDb());
        return new SqlDataProvider([
            'sql' => $command->getSql(),
            'params' => $command->params,
            'totalCount' => $countQuery->scalar($staticModel->getDb()),
            'pagination' => [
                'pageSize' => 20,
            ],
            'key' => function ($model) {
                return $model['key_type'] . $model['id'];
            },
        ]);
    }

    /**
     * @param string $modelClass
     * @param array $keys each item has two keys: key_type and id
     * @param array $relations
     * @param array $tablesMap maps fully qualified table names to model classes
     * @return Action[] indexed by concatenated key_type and id from keys
     */
    private static function getChanges($modelClass, $keys, $relations, $tablesMap)
    {
        /** @var \yii\db\ActiveRecord $staticModel */
        $staticModel = new $modelClass;
        /** @var TrackableBehavior $behavior */
        $behavior = $staticModel->getBehavior('trackable');

        $rows = (new Query())
            ->select(['*', 'COALESCE(a.changeset_id, a.transaction_id, a.action_id) AS id'])
            ->from($behavior->auditTableName.' a')
            ->leftJoin($behavior->changesetTableName.' c', 'c.id = a.changeset_id')
            ->where([
                'AND',
                'a.statement_only = FALSE',
                ['OR', "a.key_type != 't'", ['IN', '(a.relation_id::regclass::varchar)', $relations]],
                [
                    'IN',
                    ['a.key_type', 'COALESCE(a.changeset_id, a.transaction_id, a.action_id)'],
                    array_map(function ($model) {
                        return [
                            'a.key_type' => $model['key_type'],
                            'COALESCE(a.changeset_id, a.transaction_id, a.action_id)' => $model['id'],
                        ];
                    }, $keys),
                ],
            ])
            ->orderBy('a.action_date')
            ->all($staticModel->getDb());
        $models = [];
        foreach ($rows as $row) {
            if (!isset($models[$row['key_type'] . $row['id']])) {
                $models[$row['key_type'] . $row['id']] = [
                    'key_type' => $row['key_type'],
                    'id' => $row['id'],
                    'actions' => [],
                ];
            }

            $action = new Action;
            $action->setRowData($row, $tablesMap);
            $models[$row['key_type'] . $row['id']]['actions'][] = $action;
        }
        return $models;
    }

    /**
     * @param string $modelClass
     * @param array $tablesMap
     * @param ActiveRecord$model
     * @return SqlDataProvider
     */
    public static function getDataProvider($modelClass, $tablesMap, $model = null)
    {
        $dataProvider = self::getKeysDataprovider($modelClass, $model, array_keys($tablesMap));
        $changes = self::getChanges($modelClass, $dataProvider->getModels(), array_keys($tablesMap), $tablesMap);
        $dataProvider->setModels($changes);
        return $dataProvider;
    }
}
