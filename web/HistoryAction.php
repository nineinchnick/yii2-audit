<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\web;

use nineinchnick\audit\behaviors\TrackableBehavior;
use yii\data\ActiveDataProvider;
use yii\data\SqlDataProvider;
use yii\db\ActiveRecord;
use yii\db\Query;

class HistoryAction extends \yii\rest\Action
{
    /**
     * @var callable a PHP callable that will be called to prepare a data provider that
     * should return a collection of the models. If not set, [[prepareDataProvider()]] will be used instead.
     * The signature of the callable should be:
     *
     * ```php
     * function ($action) {
     *     // $action is the action object currently running
     * }
     * ```
     *
     * The callable should return an instance of [[ActiveDataProvider]].
     */
    public $prepareDataProvider;
    /**
     * @var string view name to display model change history
     */
    public $viewName = 'history';

    /**
     * @return ActiveDataProvider
     */
    public function run($id = null)
    {
        $model = $id === null ? null : $this->findModel($id);
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }

        $dataProvider = $this->prepareDataProvider($model);

        if (\Yii::$app->response->format === \yii\web\Response::FORMAT_HTML) {
            return $this->controller->render($this->viewName, [
                'model' => $model,
                'dataProvider' => $dataProvider,
            ]);
        }
        return $dataProvider;
    }

    /**
     * @param ActiveRecord $model
     * @param array $relations
     * @return SqlDataProvider
     */
    private function getKeysDataprovider($model, $relations)
    {
        /** @var $modelClass \yii\db\BaseActiveRecord */
        $modelClass = $this->modelClass;
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
        $relations = implode(',', $relations);

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

    private function getChanges($keys, $relations, $tablesMap)
    {
        /** @var $modelClass \yii\db\ActiveRecord */
        $modelClass = $this->modelClass;
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
            $row['row_data'] = (array)json_decode($row['row_data']);
            $row['changed_fields'] = (array)json_decode($row['changed_fields']);
            if ($row['request_date'] === null) {
                $row['request_date'] = $row['action_date'];
            }
            if ($row['request_url'] === null) {
                $row['request_url'] = '<abbr title="Command-line Interpreter">CLI</abbr>';
            }
            if (isset($tablesMap[$row['schema_name'] . '.' . $row['table_name']])) {
                $row['modelClass'] = $tablesMap[$row['schema_name'] . '.' . $row['table_name']];
                /** @var ActiveRecord $model */
                $model = new $row['modelClass'];
                $model::populateRecord($model, $row['row_data']);
                $row['model'] = $model;
            }
            $row['user'] = $row['user_id'] === null ? \Yii::t('app', 'system') : (string)User::findOne($row['user_id']);

            $models[$row['key_type'] . $row['id']]['actions'][] = $row;
        }
        return $models;
    }

    /**
     * Prepares the data provider that should return the requested collection of the models.
     * @param ActiveRecord $model
     * @return ActiveDataProvider
     */
    protected function prepareDataProvider($model)
    {
        if ($this->prepareDataProvider !== null) {
            return call_user_func($this->prepareDataProvider, $this);
        }

        /** @var $modelClass \yii\db\ActiveRecord */
        $modelClass = $this->modelClass;
        /** @var \yii\db\ActiveRecord $staticModel */
        $staticModel = new $modelClass;

        $tablesMap = [];
        $relations = [];
        $relationNames = [];
        if ($staticModel instanceof \netis\utils\crud\ActiveRecord) {
            $relationNames = $staticModel->relations();
        }
        foreach ($relationNames as $relationName) {
            /** @var \yii\db\ActiveQuery $relation */
            $relation = $staticModel->getRelation($relationName);
            /** @var \yii\db\ActiveRecord $relationClass */
            $relationClass = $relation->modelClass;
            $tablesMap[$relationClass::getTableSchema()->fullName] = $relationClass;
            $relations[] = $relationClass::getTableSchema()->fullName;
        }

        $tablesMap[$modelClass::getTableSchema()->fullName] = $modelClass;
        $relations[] = $modelClass::getTableSchema()->fullName;

        $dataProvider = $this->getKeysDataprovider($model, array_keys($tablesMap));
        $dataProvider->setModels($this->getChanges($dataProvider->getModels(), array_keys($tablesMap), $tablesMap));

        return $dataProvider;
    }
}
