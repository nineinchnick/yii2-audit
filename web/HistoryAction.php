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
use yii\db\Expression;
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
     * Prepares the data provider that should return the requested collection of the models.
     * @param ActiveRecord $model
     * @return ActiveDataProvider
     */
    protected function prepareDataProvider($model)
    {
        if ($this->prepareDataProvider !== null) {
            return call_user_func($this->prepareDataProvider, $this);
        }

        /** @var $modelClass \yii\db\BaseActiveRecord */
        $modelClass = $this->modelClass;
        /** @var \yii\db\ActiveRecord $staticModel */
        $staticModel = new $modelClass;
        /** @var TrackableBehavior $behavior */
        $behavior = $staticModel->getBehavior('trackable');

        $keyCondition = '';
        $params = [];
        $relations = ["'".$modelClass::getTableSchema()->fullName . "'::regclass::oid"];
        if ($model !== null) {
            $keyCondition = "AND a.row_data @> (:key)::jsonb";
            $params[':key'] = json_encode($model->getPrimaryKey(true));
            if ($model instanceof \netis\utils\crud\ActiveRecord) {
                $relations = $model->relations();
            }
        } else {
            if ($staticModel instanceof \netis\utils\crud\ActiveRecord) {
                $relations = $staticModel->relations();
            }
        }
        $relations = implode(', ', array_map(function ($relationName) use ($staticModel) {
            /** @var \yii\db\ActiveQuery $relation */
            $relation = $staticModel->getRelation($relationName);
            /** @var \yii\db\ActiveRecord $relationClass */
            $relationClass = $relation->modelClass;
            return "'".$relationClass::getTableSchema()->fullName . "'::regclass::oid";
        }, $relations));

        $subquery = (new Query())
            ->select([
                'key_type' => 'a.key_type',
                'id' => 'COALESCE(a.changeset_id, a.transaction_id, a.action_id)',
            ])
            ->from($behavior->auditTableName.' a')
            ->leftJoin($behavior->changesetTableName.' c', 'c.id = a.changeset_id')
            ->where("a.statement_only = FALSE AND a.relation_id IN ({$relations}) $keyCondition")
            ->groupBy(['a.key_type', 'COALESCE(a.changeset_id, a.transaction_id, a.action_id)']);
        $countQuery = clone $subquery;
        $countQuery->select(['COUNT(DISTINCT ROW(a.key_type, COALESCE(a.changeset_id, a.transaction_id, a.action_id)))']);
        $countQuery->groupBy([]);
        $dataProvider = new SqlDataProvider([
            'sql' => $subquery->createCommand($staticModel->getDb())->getSql(),
            'params' => $params,
            'totalCount' => $countQuery->scalar($staticModel->getDb()),
            'pagination' => [
                'pageSize' => 20,
            ],
            'key' => function ($model) {
                return $model['key_type'] . $model['id'];
            },
        ]);

        $rows = (new Query())
            ->select(['*', 'COALESCE(a.changeset_id, a.transaction_id, a.action_id) AS id'])
            ->from($behavior->auditTableName.' a')
            ->leftJoin($behavior->changesetTableName.' c', 'c.id = a.changeset_id')
            ->where([
                'AND',
                'a.statement_only = FALSE',
                "(a.key_type != 't' OR a.relation_id IN ({$relations}))",
                [
                    'IN',
                    ['a.key_type', 'COALESCE(a.changeset_id, a.transaction_id, a.action_id)'],
                    array_map(function ($model) {
                        return [
                            'a.key_type' => $model['key_type'],
                            'COALESCE(a.changeset_id, a.transaction_id, a.action_id)' => $model['id'],
                        ];
                    }, $dataProvider->getModels()),
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
            $models[$row['key_type'] . $row['id']]['actions'][] = $row;
        }
        $dataProvider->setModels($models);

        return $dataProvider;
    }
}
