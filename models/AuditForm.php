<?php

/**
 * Active form to manage display audits
 * 
 * @author Patryk Radziszewski <pradziszewski@netis.pl>
 */

namespace nineinchnick\audit\models;

use yii\base\Model;
use Yii;

class AuditForm extends Model
{

    /**
     * Creates validation rules for $model property
     * 
     * @param \yii\base\DynamicModel $model
     */
    public function addFormValidators(&$model)
    {
        //add validators for basic table filter
        $model->addRule(['table'], 'required');
        $model->addRule(['table'], function() use ($model) {
            return $this->checkTableExists($model);
        });
        //add validators declared in config file
        foreach (Yii::$app->controller->module->filters as $property => $params) {
            $model->addRule($property, 'default');
            if (!isset($params['rules'])) {
                continue;
            }
            foreach ($params['rules'] as $rule) {
                $options = isset($rule['options']) ? $rule['options'] : [];
                $validator = $rule['validator'];
                if (is_string($validator)) {
                    $model->addRule($property, $validator, $options);
                } else {
                    $model->addRule($property, function() use ($model, $validator, $options) {
                        return call_user_func([$validator['class'], $validator['method']], $model, $options);
                    });
                }
            }
        }
    }

    /**
     * Selects all table from 'audits' schema
     * 
     * @return array $map
     */
    public static function getAuditTables()
    {
        $connection = Yii::$app->db;
        $schema = $connection->schema;
        $tables = $schema->getTableNames('audits');
        $map = [];
        $map[] = Yii::t('app', 'Choose');
        foreach ($tables as $table) {
            $map[$table] = $table;
        }
        return $map;
    }

    /**
     * Check if given table exists in audits schema
     * 
     * @return boolean
     */
    public function checkTableExists($model)
    {
        if (!in_array($model->table, $this->getAuditTables())) {
            $model->addError('table', Yii::t('app', "You've entered an invalid table name."));
            return false;
        }
        return true;
    }

    /**
     * Renders form field
     * 
     * @param \yii\db\ActiveRecord $model
     * @param \yii\widgets\ActiveForm $form
     * @param string $name
     * @param array $data
     */
    public static function renderControlGroup($model, $form, $name, $data)
    {
        if (isset($data['model'])) {
            $model = $data['model'];
        }
        $field = $form->field($model, $name);
        if (isset($data['formMethod'])) {
            if (is_string($data['formMethod'])) {
                echo call_user_func_array([$field, $data['formMethod']], $data['arguments']);
            } else {
                echo call_user_func($data['formMethod'], $field, $data['arguments']);
            }
            return;
        }
        if (isset($data['options']['label'])) {
            $label = $data['options']['label'];
            unset($data['options']['label']);
        } else {
            $label = $model->getAttributeLabel($name);
        }
        echo $field
                ->label($label, ['class' => 'control-label'])
                ->error(['class' => 'help-block'])
                ->widget($data['widgetClass'], $data['options']);
        return;
    }

    /**
     * @param \yii\web\View $view
     * @param \yii\db\ActiveRecord $model
     * @param \yii\widgets\ActiveForm $form
     * @param array $fields
     * @param int $topColumnWidth
     */
    public static function renderRow($view, $model, $form, $fields, $topColumnWidth = 12)
    {
        if (empty($fields)) {
            return;
        }
        $oneColumn = count($fields) == 1;
        echo $oneColumn ? '' : '<div class="row">';
        $columnWidth = ceil($topColumnWidth / count($fields));
        foreach ($fields as $name => $column) {
            echo $oneColumn ? '' : '<div class="col-lg-' . $columnWidth . '">';
            if (is_string($column)) {
                echo $column;
            } elseif (!is_numeric($name) && isset($column['attribute'])) {
                static::renderControlGroup($model, $form, $name, $column);
            } else {
                foreach ($column as $name2 => $row) {
                    if (is_string($row)) {
                        echo $row;
                    } elseif (!is_numeric($name2) && isset($row['attribute'])) {
                        static::renderControlGroup($model, $form, $name2, $row);
                    } else {
                        static::renderRow($view, $model, $form, $row);
                    }
                }
            }
            echo $oneColumn ? '' : '</div>';
        }
        echo $oneColumn ? '' : '</div>';
    }

    /**
     * Retrieves form fields configuration.
     * 
     * @param \yii\base\Model $model
     * @param bool $multiple true for multiple values inputs, usually used for search forms
     * @return array form fields
     */
    public function getFormFields($model, $multiple = false)
    {
        $formFields[] = [
            'table' => [
                'attribute' => 'table',
                'formMethod' => 'dropDownList',
                'arguments' => [
                    'items' => call_user_func(['nineinchnick\audit\models\AuditForm', 'getAuditTables'])
                ],
            ]
        ];
        foreach (Yii::$app->controller->module->filters as $propety => $params) {
            $formFields[] = static::addFormField($model, $propety, $params);
        }
        return $formFields;
    }

    /**
     * @param \yii\db\ActiveRecord $model
     * @param string $propety
     * @param array $params
     * @return array
     */
    protected static function addFormField($model, $propety, $params)
    {
        $field = [
            'attribute' => $params['attribute'],
            'arguments' => [],
        ];

        switch ($params['format']) {
            case 'datetime':
            case 'date':
                $field['widgetClass'] = $params['widgetClass'];
                $field['options'] = [
                    'model' => $model,
                    'attribute' => $params['attribute'],
                    'options' => $params['options'],
                    'dateFormat' => $params['dateFormat'],
                ];
                break;
            case 'list':
                $field['formMethod'] = 'dropDownList';
                $items = self::prepareItems($params['items']);
                $field['arguments'] = ['items' => $items];
                break;
            default:
            case 'text':
                $field['formMethod'] = 'textInput';
                break;
        }
        $formFields[$propety] = $field;
        return $formFields;
    }

    /**
     * Prepares items list to 'dropDownList' field. 
     * $param could be an array of items or an array of class and method name which generates list
     * 
     * @param array $items
     * @return array
     * @throws InvalidConfigException
     */
    public static function prepareItems($items)
    {
        if (!is_array($items)) {
            throw new InvalidConfigException(Yii::t('app', 'List items should be an array.'));
        }
        if (isset($items['class']) && isset($items['method'])) {
            return call_user_func([$items['class'], $items['method']]);
        } else {
            return $items;
        }
    }

}