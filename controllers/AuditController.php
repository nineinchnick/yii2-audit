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
        $modelForm    = new AuditForm;
        $dataProvider = null;
        $arrayDiff    = [];
        if ($modelForm->load(Yii::$app->request->get()) && $modelForm->validate()) {
            $dataProvider  = $this->createDataProvider($modelForm);
            $arrayDiff     = [];
            $previousModel = null;
            foreach ($dataProvider->getModels() as $model) {
                $auditId             = $model['audit_id'];
                $model               = $this->unsetNotDisplayedColumns($model, $modelForm->table);
                $arrayDiff[$auditId] = $this->createDiff($model, $modelForm, $previousModel, $auditId);
                $previousModel       = $model;
            }
        }
        return $this->render('index', [
                    'model'               => $modelForm,
                    'dataProvider'        => $dataProvider,
                    'arrayDiff'           => $arrayDiff,
                    'notDisplayedColumns' => []//$this->module->auditColumns,
        ]);
    }

    /**
     * Create dataProvider
     * 
     * @param yii\base\Model $modelForm
     * @return \yii\data\SqlDataProvider
     */
    public function createDataProvider($modelForm)
    {
        $params       = [];
        $condition    = $this->prepareCondition($modelForm);
        $countSql     = "SELECT COUNT(*) FROM audits.{$modelForm->table}" . $condition;
        $count        = Yii::$app->db->createCommand($countSql, $params)->queryScalar();
        $dataSql      = "SELECT * FROM audits.{$modelForm->table}" . $condition;
        $dataProvider = new \yii\data\SqlDataProvider([
            'sql'        => $dataSql,
            'params'     => $params,
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
            $diff = array_diff($model, $previousModel);
        } else {
            //If dataProvider doesn't have previous element we should select it directly from table in condition if this is for example new page.
            $prev = $this->getPrevious($modelForm, $auditId);
            if (!empty($prev)) {
                $diff = array_diff($model, $prev);
            } else {
                $diff = $model;
            }
        }
        $arrayDiff = join('; ', array_map(function ($v, $k) {
                    return $k . '=' . $v;
                }, $diff, $this->getColumnsLabel(array_keys($diff), $modelForm->table)));

        return $arrayDiff;
    }

    public function getColumnsLabel($columns, $table)
    {
        if (!isset($this->module->tables[$table]['model'])) {
            throw new InvalidConfigException(Yii::t('app', 'Table model has to be set in config file'));
        }
        $model           = new $this->module->tables[$table]['model'];
        $attributeLabels = $model->attributeLabels();
        $columnsLabel    = [];
        foreach ($columns as $attribute) {
            $columnsLabel[] = $attributeLabels[$attribute];
        }

        return $columnsLabel;
    }

    /**
     * Prepare where condition
     * 
     * @param yii\base\Model $modelForm
     * @return string
     */
    public function prepareCondition($modelForm)
    {
        return ' WHERE 1=1';
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
        $condition = $this->prepareCondition($modelForm);
        $sql       = "SELECT * FROM audits.{$modelForm->table}" . $condition . " AND audit_id < :auditId ORDER BY audit_id desc";
        $row       = Yii::$app->db->createCommand($sql, ['auditId' => $auditId])->queryOne();
        return $this->unsetNotDisplayedColumns($row, $modelForm->table);
    }

    /**
     * Unset all columns from dataProvider element that shouldn't be displayed
     * 
     * @param array $model
     * @return array $model
     */
    public function unsetNotDisplayedColumns($model, $table)
    {
        $notDisplayedColumns = array_merge($this->module->auditColumns, $this->module->tables[$table]['notDisplayedColumns']);
        foreach ($notDisplayedColumns as $column) {
            if (isset($model[$column])) {
                unset($model[$column]);
            }
        }
        return $model;
    }

}
