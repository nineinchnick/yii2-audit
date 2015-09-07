<?php

/**
 * Class to manage audit tables
 * @author Patryk Radziszewski <pradziszewski@netis.pl>
 */

namespace nineinchnick\audit\controllers;

use nineinchnick\audit\Module;
use yii\web\Controller;
use yii\filters\AccessControl;
use Yii;
use nineinchnick\audit\models\AuditForm;
use yii\base\InvalidConfigException;
use yii\db\Query;
use yii\web\Response;

class AuditController extends Controller
{

    /**
     * Instance of selected table Model from configuration file
     *
     * @var \yii\db\ActiveRecord
     */
    private $_currentModel;

    /**
     * List of columns that shouldn't be displayed
     *
     * @var array
     */
    public $hiddenColumns;

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Displays list of choosen audit table records
     *
     * @return string the rendering result
     */
    public function actionIndex()
    {
        $dynamicModel = $this->createAuditForm();
        $auditForm = new AuditForm;
        $auditForm->addFormValidators($dynamicModel);
        $dataProvider = null;
        $arrayDiff = [];
        /** @var Module $module */
        $module = $this->module;
        if ($dynamicModel->load(Yii::$app->request->get()) && $dynamicModel->validate()) {
            $this->setCurrentModel($dynamicModel->table);
            $this->hiddenColumns = array_merge(
                $module->auditColumns,
                $module->tables[$dynamicModel->table]['hiddenColumns']
            );
            $dataProvider = $this->createDataProvider($dynamicModel);
            $arrayDiff = [];
            $previousModel = null;
            foreach ($dataProvider->getModels() as $model) {
                $auditId = $model['audit_id'];
                $model = $this->unsetHiddenColumns($model);
                $arrayDiff[$auditId] = $this->createDiff($model, $dynamicModel, $previousModel, $auditId);
                $previousModel = $model;
            }
        }
        return $this->render('index', [
            'model' => $dynamicModel,
            'dataProvider' => $dataProvider,
            'arrayDiff' => $arrayDiff,
            'fields' => $auditForm->getFormFields($dynamicModel),
            'hiddenColumns' => $this->hiddenColumns,
        ]);
    }

    /**
     * Restores record from audit
     *
     * @return Response last visited page
     */
    public function actionRestore()
    {
        $post = Yii::$app->request->post();
        $query = new Query;
        $audit = $query
            ->from("audits.{$post['table']}")
            ->where('audit_id = :audit_id', ['audit_id' => $post['audit_id']])
            ->one();
        $this->setCurrentModel($post['table']);
        $criteria = [];
        /** @var \yii\db\ActiveRecord $model */
        $model = $this->_currentModel;
        foreach ($model->primaryKey() as $primaryKey) {
            $criteria[$primaryKey] = $audit[$primaryKey];
        }
        foreach ($this->module->tables[$post['table']]['updateSkip'] as $column) {
            if (isset($audit[$column])) {
                unset($audit[$column]);
            }
        }
        /** @var \yii\db\ActiveRecord $record */
        $record = $model->findOne($criteria);
        $record->setAttributes($audit, false);
        if ($record->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Record has been restored.'));
        } else {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Failed to restore record.'));
        }
        return $this->redirect(Yii::$app->request->referrer);
    }

    /**
     * Sets to _currentModel property current model instance from configuration file
     *
     * @param string $table
     * @throws InvalidConfigException
     */
    public function setCurrentModel($table)
    {
        if (!isset($this->module->tables[$table]['model'])) {
            throw new InvalidConfigException(Yii::t('app', 'Table model has to be set in config file'));
        }
        $modelClass = $this->module->tables[$table]['model'];
        $this->_currentModel = new $modelClass;
    }

    /**
     * Creates dataProvider
     *
     * @param \yii\base\Model $modelForm
     * @return \yii\data\SqlDataProvider
     */
    public function createDataProvider($modelForm)
    {
        $query = new Query;
        $this->prepareCondition($query, $modelForm);
        $count = $query->from("audits.{$modelForm->table}")->count();
        $query = new Query;
        $this->prepareCondition($query, $modelForm);
        $columns = $this->getRelations($query, $modelForm->table);
        $columns[] = 'audit.*';

        $query->select(join(', ', $columns))->from("audits.{$modelForm->table} audit");
        $dataProvider = new \yii\data\ActiveDataProvider([
            'query' => $query,
            'totalCount' => $count,
            'sort' => [
                'attributes' => ['operation_date'],
            ],
            'pagination' => [
                'pageSize' => 10,
            ],
        ]);
        return $dataProvider;
    }

    /**
     * Adds join statement to basic query from relations declared in config files
     *
     * @param \yii\db\Query $query
     * @param string $table
     * @return array $columns to select statement
     */
    public function getRelations(&$query, $table)
    {
        $relations = [];
        $columns = [];
        /** @var Module $module */
        $module = $this->module;
        if (isset($module->tables[$table]['relations'])) {
            $relations = $module->tables[$table]['relations'];
        }
        foreach ($relations as $relation => $relationParams) {
            $query->join(
                $relationParams['type'],
                $relationParams['table'] . ' ' . $relationParams['alias'],
                $relationParams['on']
            );
            $columns[] = $relationParams['alias'] . '.' . $relationParams['representive_columns'] . ' ' . $relation;
        }

        return $columns;
    }

    /**
     * Gets differences between current and previous dataProvider element
     *
     * @param array $model
     * @param \yii\base\Model $modelForm
     * @param array $previousModel
     * @param integer $auditId
     * @return string
     */
    public function createDiff($model, $modelForm, $previousModel, $auditId)
    {
        if ($previousModel) {
            $diff = array_diff_assoc($model, $previousModel);
        } else {
            // if dataProvider doesn't have previous element we should select it directly from table
            // in condition if this is for example new page.
            $prev = $this->getPrevious($modelForm, $auditId);
            if (!empty($prev)) {
                $diff = array_diff_assoc($model, $prev);
            } else {
                $diff = $model;
            }
        }
        $arrayDiff = implode('; ', array_map(
            function ($v, $k) {
                return $k . '=' . $v;
            },
            $diff,
            $this->getColumnsLabel(array_keys($diff), $modelForm->table)
        ));
        return $arrayDiff;
    }

    /**
     * Gets attributes label from current model
     *
     * @param array $columns
     * @return array
     */
    public function getColumnsLabel($columns, $table)
    {
        /** @var \yii\db\ActiveRecord $model */
        $model = $this->_currentModel;
        /** @var Module $module */
        $module = $this->module;
        $attributeLabels = $model->attributeLabels();
        $relations = $module->tables[$table]['relations'];
        $columnsLabel = [];
        foreach ($columns as $attribute) {
            if (isset($attributeLabels[$attribute])) {
                $columnsLabel[] = $attributeLabels[$attribute];
            } elseif (isset($relations[$attribute]['label'])) {
                $columnsLabel[] = $relations[$attribute]['label'];
            } else {
                $columnsLabel[] = $attribute;
            }
        }

        return $columnsLabel;
    }

    /**
     * Gets attribute label from current model
     *
     * @param string $column
     * @return string
     */
    public function getColumnLabel($column, $table)
    {
        /** @var \yii\db\ActiveRecord $model */
        $model = $this->_currentModel;
        /** @var Module $module */
        $module = $this->module;
        if (isset($model->attributeLabels()[$column])) {
            return $model->attributeLabels()[$column];
        } elseif ($module->tables[$table]['relations'][$column]['label']) {
            return $module->tables[$table]['relations'][$column]['label'];
        } else {
            return $column;
        }
    }

    /**
     * Prepares where condition
     *
     * @param Query $query
     * @param \yii\base\Model $modelForm
     * @return string
     */
    public function prepareCondition(&$query, $modelForm)
    {
        foreach ($modelForm->attributes() as $attribute) {
            if ($attribute === 'table' || is_null($modelForm[$attribute])) {
                continue;
            }
            $filterConfig = Yii::$app->controller->module->filters[$attribute];
            $query->andWhere("{$filterConfig['attribute']} {$filterConfig['criteria']['operator']} :{$attribute}", [
                $attribute => $modelForm[$attribute],
            ]);
        }
    }

    /**
     * Gets previous element from database
     *
     * @param \yii\base\Model $modelForm
     * @param integer $auditId
     * @return array
     */
    public function getPrevious($modelForm, $auditId)
    {
        $query = new Query;
        $this->prepareCondition($query, $modelForm);
        $query->andWhere('audit_id < :auditId', ['auditId' => $auditId]);
        $query->orderBy('audit_id desc');
        $columns = $this->getRelations($query, $modelForm->table);
        $columns[] = 'audit.*';
        $query->select(join(', ', $columns))->from("audits.{$modelForm->table} audit");
        $row = $query->one();
        return $this->unsetHiddenColumns($row);
    }

    /**
     * Unset all columns from dataProvider element that shouldn't be displayed
     * @param array $model
     * @return array $model
     */
    public function unsetHiddenColumns($model)
    {
        foreach ($this->hiddenColumns as $column) {
            if (isset($model[$column])) {
                unset($model[$column]);
            }
        }
        return $model;
    }

    /**
     * Creates dynamic audit form
     * @return \yii\base\DynamicModel
     */
    public function createAuditForm()
    {
        return new \yii\base\DynamicModel(array_merge(['table'], array_keys(Yii::$app->controller->module->filters)));
    }

}