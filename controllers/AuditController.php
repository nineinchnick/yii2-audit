<?php

/**
 * Class to manage audit tables
 * @author <pradziszewski@netis.pl>
 */

namespace nineinchnick\audit\controllers;

use yii\base\Controller;
use yii\filters\AccessControl;
use Yii;
use nineinchnick\audit\models\AuditForm;
use yii\base\InvalidConfigException;

class AuditController extends Controller
{

    /**
     * Instance of selected table Model from configuration file
     * 
     * @var Model
     */
    private $_currentModel;

    /**
     * List of columns that shouldn't be displayed
     * 
     * @var array
     */
    public $notDisplayedColumns;

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
        $modelForm    = $this->createAuditForm();
        $b            = new AuditForm;
        $b->addFormValidators($modelForm);
        $dataProvider = null;
        $arrayDiff    = [];
//        $modelForm->generateFilterFields();
        if ($modelForm->load(Yii::$app->request->get()) && $modelForm->validate()) {
            $this->setCurrentModel($modelForm->table);
            $this->notDisplayedColumns = array_merge($this->module->auditColumns, $this->module->tables[$modelForm->table]['notDisplayedColumns']);
            $dataProvider              = $this->createDataProvider($modelForm);
            $arrayDiff                 = [];
            $previousModel             = null;
            foreach ($dataProvider->getModels() as $model) {
                $auditId             = $model['audit_id'];
                $model               = $this->unsetNotDisplayedColumns($model);
                $arrayDiff[$auditId] = $this->createDiff($model, $modelForm, $previousModel, $auditId);
                $previousModel       = $model;
            }
        }
        return $this->render('index', [
                    'model'               => $modelForm,
                    'dataProvider'        => $dataProvider,
                    'arrayDiff'           => $arrayDiff,
                    'fields'              => $b->getFormFields($modelForm),
                    'notDisplayedColumns' => $this->notDisplayedColumns,
        ]);
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
        $this->_currentModel = new $this->module->tables[$table]['model'];
    }

    /**
     * Create dataProvider
     * 
     * @param yii\base\Model $modelForm
     * @return \yii\data\SqlDataProvider
     */
    public function createDataProvider($modelForm)
    {
        $query     = new \yii\db\Query;
        $this->prepareCondition($query, $modelForm);
        $count     = $query->from("audits.{$modelForm->table}")->count();
        $query     = new \yii\db\Query;
        $this->prepareCondition($query, $modelForm);
        $columns   = $this->getRelations($query, $modelForm->table);
        $columns[] = 'audit.*';

        $query->select(join(', ', $columns))->from("audits.{$modelForm->table} audit");
        $dataProvider = new \yii\data\ActiveDataProvider([
            'query'      => $query,
            'totalCount' => $count,
            'sort'       => [
                'attributes' => ['operation_date'],
            ],
            'pagination' => [
                'pageSize' => 10,
            ],
        ]);
        return $dataProvider;
    }

    public function getRelations(&$query, $table)
    {
        $relations = [];
        $columns   = [];
        if (isset($this->module->tables[$table]['relations'])) {
            $relations = $this->module->tables[$table]['relations'];
        }
        foreach ($relations as $relation => $relationParams) {
            $query->join($relationParams['type'], $relationParams['table'] . ' ' . $relationParams['alias'], $relationParams['on']);
            $columns[] = $relationParams['alias'] . '.' . $relationParams['representive_columns'] . ' ' . $relation;
        }

        return $columns;
    }

    /**
     * Get differences between current and previous dataProvider element
     * 
     * @param array $model
     * @param yii\base\Model $modelForm
     * @param array $previousModel
     * @param integer $auditId
     * @return type
     */
    public function createDiff($model, $modelForm, $previousModel, $auditId)
    {
        if ($previousModel) {
            $diff = array_diff_assoc($model, $previousModel);
        } else {
            //If dataProvider doesn't have previous element we should select it directly from table in condition if this is for example new page.
            $prev = $this->getPrevious($modelForm, $auditId);
            if (!empty($prev)) {
                $diff = array_diff_assoc($model, $prev);
            } else {
                $diff = $model;
            }
        }
        $arrayDiff = join('; ', array_map(function ($v, $k) {
                    return $k . '=' . $v;
                }, $diff, $this->getColumnsLabel(array_keys($diff), $modelForm->table)));

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
        $attributeLabels = $this->_currentModel->attributeLabels();
        $relations       = $this->module->tables[$table]['relations'];
        $columnsLabel    = [];
        foreach ($columns as $attribute) {
            if (isset($attributeLabels[$attribute])) {
                $columnsLabel[] = $attributeLabels[$attribute];
            } else if (isset($relations[$attribute]['label'])) {
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
        if (isset($this->_currentModel->attributeLabels()[$column])) {
            return $this->_currentModel->attributeLabels()[$column];
        } elseif ($this->module->tables[$table]['relations'][$column]['label']) {
            return $this->module->tables[$table]['relations'][$column]['label'];
        } else {
            return $column;
        }
    }

    /**
     * Prepare where condition
     * 
     * @param \yii\db\Query $query
     * @param yii\base\Model $modelForm
     * @return string
     */
    public function prepareCondition(&$query, $modelForm)
    {
        foreach ($modelForm->attributes() as $attribute) {
            if ($attribute === 'table' || is_null($modelForm[$attribute])) {
                continue;
            }
            $filterConfig = Yii::$app->controller->module->filters[$attribute];
            $query->andWhere("{$filterConfig['attribute']} {$filterConfig['criteria']['operator']} :{$attribute}", [$attribute => $modelForm[$attribute]]);
        }
    }

    /**
     * Gets previous element from database
     * 
     * @param yii\base\Model $modelForm
     * @param integer $auditId
     * @return array
     */
    public function getPrevious($modelForm, $auditId)
    {
        $query     = new \yii\db\Query;
        $this->prepareCondition($query, $modelForm);
        $query->andWhere('audit_id < :auditId', ['auditId' => $auditId]);
        $query->orderBy('audit_id desc');
        $columns   = $this->getRelations($query, $modelForm->table);
        $columns[] = 'audit.*';
        $query->select(join(', ', $columns))->from("audits.{$modelForm->table} audit");
        $row       = $query->one();
        return $this->unsetNotDisplayedColumns($row);
    }

    /**
     * Unset all columns from dataProvider element that shouldn't be displayed
     * 
     * @param array $model
     * @return array $model
     */
    public function unsetNotDisplayedColumns($model)
    {
        foreach ($this->notDisplayedColumns as $column) {
            if (isset($model[$column])) {
                unset($model[$column]);
            }
        }
        return $model;
    }

    public function createAuditForm()
    {
        return new \yii\base\DynamicModel(array_merge(['table'], array_keys(Yii::$app->controller->module->filters)));
    }

}
