<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\models;

use Yii;
use yii\base\Model;

/**
 * Holds action search form data.
 * @package nineinchnick\audit\models
 */
class ActionSearch extends Model
{
    /**
     * @var string date when the action has been executed, either request or action (single query) date
     */
    public $request_date_from;
    /**
     * @var string date when the action has been executed, either request or action (single query) date
     */
    public $request_date_to;
    /**
     * @var string part of the request url when the action has been executed
     */
    public $request_url;
    /**
     * @var string[] user ids
     */
    public $user_ids;
    /**
     * @var string[] client ip addresses
     */
    public $request_addr;
    /**
     * @var string model class names
     */
    public $model_classes;
    /**
     * @var string[] one of: INSERT, UPDATE, DELETE
     */
    public $action_types;
    /**
     * @var array attributes present in changed_fields
     */
    public $attributes;
    /**
     * @var array values present in either row_data or changed_fields
     */
    public $values;

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'request_date_from' => Yii::t('models', 'Request date from'),
            'request_date_to' => Yii::t('models', 'Request date to'),
            'request_url' => Yii::t('models', 'Request URL'),
            'user_ids' => Yii::t('models', 'Users'),
            'request_addr' => Yii::t('models', 'Client IP addresses'),
            'model_classes' => Yii::t('models', 'Models'),
            'action_types' => Yii::t('models', 'Action types'),
            'attributes' => Yii::t('models', 'Attributes'),
            'values' => Yii::t('models', 'Values'),
        ];
    }

    public function rules()
    {
        return [
            [['request_date_from', 'request_date_to', 'request_url', 'user_ids', 'request_addr', 'model_classes', 'action_types', 'attributes', 'values'], 'trim'],
            [['request_date_from', 'request_date_to', 'request_url', 'user_ids', 'request_addr', 'model_classes', 'action_types', 'attributes', 'values'], 'default'],
            [['request_date_from', 'request_date_to'], 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
        ];
    }

    public function getConditions($conditions = [], $params = [], $tablesMap = [])
    {
        if ($this->request_date_from !== null) {
            $conditions[] = "a.action_date > :from";
            $params[':from'] = $this->request_date_from;
        }
        if ($this->request_date_to !== null) {
            $conditions[] = "a.action_date > :to";
            $params[':to'] = $this->request_date_to;
        }
        if ($this->request_url !== null) {
            $conditions[] = "(c.request_url IS NULL OR c.request_url ILIKE '%' || :url || '%')";
            $params[':url'] = $this->request_url;
        }
        if ($this->user_ids !== null) {
            $ids = array_filter(array_map('intval', explode(',', $this->user_ids)));
            $conditions[] = ['OR', "c.user_id IS NULL", ['IN', 'c.user_id', $ids]];
        }
        if ($this->request_addr !== null) {
            $ids = array_filter(array_map('trim', explode(',', $this->request_addr)));
            $conditions[] = ['OR', "c.request_addr IS NULL", ['IN', 'c.request_addr', $ids]];
        }
        if ($this->model_classes !== null) {
            $ids = array_filter(array_map('trim', explode(',', $this->model_classes)));
            $conditions = [
                'IN',
                '(a.relation_id::regclass)',
                array_keys(array_intersect($tablesMap, $ids)),
            ];
        }
        if ($this->action_types !== null) {
            $ids = array_filter(array_map('trim', explode(',', $this->action_types)));
            $conditions[] = ['IN', 'a.action_type', $ids];
        }
        if ($this->attributes !== null) {
            $ids = array_filter(array_map('trim', explode(',', $this->attributes)));
            $conditions[] = ['?', 'a.changed_fields', $ids];
        }
        if ($this->values !== null) {
            $conditions[] = [
                'OR',
                "EXISTS (SELECT key FROM jsonb_each_text(a.row_data) WHERE value ILIKE '%' || :value || '%')",
                "EXISTS (SELECT key FROM jsonb_each_text(a.changed_fields) WHERE value ILIKE '%' || :value || '%')",
            ];
            $params[':value'] = $this->values;
        }
        return [$conditions, $params];
    }
}
