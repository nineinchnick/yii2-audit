<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\behaviors;

use Yii;
use yii\base\Behavior;
use yii\base\Event;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\db\Query;

/**
 * TrackableBehavior configures how change history of a model is being tracked.
 *
 * To use TrackableBehavior, insert the following code to your ActiveRecord class,
 * after Timestamp and Blameable behaviors:
 *
 * ```php
 * use yii\behaviors\BlameableBehavior;
 * use yii\behaviors\TimestampBehavior;
 * use nineinchnick\audit\behaviors\TrackableBehavior;
 *
 * public function behaviors()
 * {
 *     return [
 *         BlameableBehavior::className(),
 *         TimestampBehavior::className(),
 *         [
 *             'class' => TrackableBehavior::className(),
 *         ],
 *     ];
 * }
 * ```
 *
 * By default, TrackableBehavior will store changes as both full records and attribute/value pairs,
 * using database triggers. This requires installing the triggers and audit tables
 * by using the audit command:
 *
 * ```bash
 * yii audit/install --modelClass=app\models\SomeModel
 * ```
 *
 * @author Jan Waś <janek.jan@gmail.com>
 */
class TrackableBehavior extends Behavior
{
    const MODE_TRIGGER = 'trigger';
    const MODE_EVENT = 'event';

    /**
     * @var string what method is used to record changes, either
     * TrackableBehavior::MODE_TRIGGER or TrackableBehavior::MODE_EVENT.
     */
    public $mode = self::MODE_TRIGGER;
    /**
     * @var string Change log table name, may contain schema name.
     */
    public $auditTableName = 'audits.logged_actions';
    /**
     * @var string Changeset table name, may contain schema name but must be the same as in $auditTableName.
     */
    public $changesetTableName = 'audits.changesets';


    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        if ($this->mode === self::MODE_EVENT) {
            throw new Exception('TrackableBehavior does not yet support events.');
        }
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return $this->mode !== self::MODE_EVENT ? [] : [
            ActiveRecord::EVENT_BEFORE_INSERT => function ($event) {
                $this->logAction($event, ActiveRecord::EVENT_BEFORE_INSERT);
            },
            ActiveRecord::EVENT_BEFORE_UPDATE => function ($event) {
                $this->logAction($event, ActiveRecord::EVENT_BEFORE_UPDATE);
            },
            ActiveRecord::EVENT_BEFORE_DELETE => function ($event) {
                $this->logAction($event, ActiveRecord::EVENT_BEFORE_DELETE);
            },
        ];
    }

    /**
     * @param Event $event
     * @param string $type of on ActiveRecord::EVENT_BEFORE_* constants
     */
    public function logAction($event, $type)
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;
        $actionTypeMap = [
            ActiveRecord::EVENT_BEFORE_INSERT => 'INSERT',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'UPDATE',
            ActiveRecord::EVENT_BEFORE_DELETE => 'DELETE',
        ];
        $audit_row = [
            'schema_name' => $model->getTableSchema()->schemaName,
            'table_name' => $model->getTableSchema()->name,
            'action_date' => date('Y-m-d H:i:s'),
            'action_type' => $actionTypeMap[$type],
            'row_data' => json_encode($model->getAttributes()),
            'changed_fields' => null,
        ];

        if ($type === ActiveRecord::EVENT_BEFORE_UPDATE) {
            $audit_row['row_data'] = json_encode($model->getAttributes());
            $audit_row['changed_fields'] = json_encode($model->getDirtyAttributes());
            if ($audit_row['changed_fields'] === json_encode([])) {
                return;
            }
        } elseif ($type === ActiveRecord::EVENT_BEFORE_DELETE) {
            $audit_row['row_data'] = $model->getOldAttributes();
        } elseif ($type === ActiveRecord::EVENT_BEFORE_INSERT) {
            $audit_row['row_data'] = $model->getAttributes();
        }

        $model->getDb()->createCommand()->insert($this->auditTableName, $audit_row);
    }

    /**
     * Creates a new db audit changeset, which contains metadata
     * like current user id, request url and client ip address.
     * @throws \yii\db\Exception
     */
    public function beginChangeset()
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;
        $id = $model->getDb()->getSchema()->insert($this->changesetTableName, [
            'transaction_id' => new Expression('txid_current()'),
            'user_id'        => Yii::$app->user->getId(),
            'session_id'     => Yii::$app->has('session') ? Yii::$app->session->getId() : null,
            'request_date'   => date('Y-m-d H:i:s'),
            'request_url'    => Yii::$app->request instanceof \yii\web\Request ? Yii::$app->request->getUrl() : null,
            'request_addr'   => Yii::$app->request instanceof \yii\web\Request ? Yii::$app->request->getUserIP() : null,
        ]);
        $model->getDb()->createCommand('SET LOCAL audit.changeset_id = ' . reset($id))->execute();
    }

    /**
     * Ends the current db audit changeset, so following queries wont be included in it.
     * @throws \yii\db\Exception
     */
    public function endChangeset()
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;
        $model->getDb()->createCommand("SET LOCAL audit.changeset_id = ''")->execute();
    }

    /**
     * @param integer $version_id
     * @return ActiveRecord
     */
    public function loadVersion($version_id)
    {
        /** @var ActiveRecord $owner */
        $owner = $this->owner;
        $modelClass = get_class($owner);
        $row = (new Query())
            ->select('row_data')
            ->from($this->auditTableName)
            ->where(array_fill_keys($owner->getDb()->getTableSchema($this->auditTableName)->primaryKey, $version_id))
            ->one($owner->getDb());
        return ActiveRecord::populateRecord(new $modelClass, json_decode($row));
    }

    /**
     * Returns all recorded values for specified attribute.
     * @param string $attribute attribute name
     * @return array attribute values indexed by version id
     */
    public function getAttributeVersions($attribute)
    {
        /** @var ActiveRecord $owner */
        $owner = $this->owner;
        return (new Query)
            ->select([
                'value' => "CASE action_type "
                    . "WHEN 'INSERT' THEN jsonb_object_field_text(row_data, :attribute::text) "
                    . "ELSE jsonb_object_field_text(changed_fields, :attribute::text) END",
                'action_id',
            ])
            ->from($this->auditTableName)
            ->where(['AND',
                'relation_id = \''.$owner->tableName().'\'::regclass',
                'statement_only = false',
                ['OR',
                    ['AND', 'action_type = \'INSERT\'', "jsonb_exists(row_data, :attribute)"],
                    ['AND', 'action_type = \'UPDATE\'', "jsonb_exists(changed_fields, :attribute)"],
                ],
                'row_data @> :primaryKey',
            ], [
                //':tableName' => $owner->tableName(),
                ':attribute' => $attribute,
                ':primaryKey' => json_encode($owner->getPrimaryKey(true)),
            ])
            ->orderBy('action_id')
            ->indexBy('action_id')
            ->column($owner->getDb());
    }

    /**
     * Returns ids of all recorded versions.
     * @return array
     */
    public function getRecordVersions()
    {
        /** @var ActiveRecord $owner */
        $owner = $this->owner;
        return (new Query)
            ->select('action_id')
            ->from($this->auditTableName)
            ->where(['AND',
                'relation_id = (:tableName)::regclass',
                'statement_only = false',
                ['OR', 'action_type = \'INSERT\'', 'action_type = \'UPDATE\''],
                ':primaryKey' => json_encode($owner->getPrimaryKey(true)),
            ], [':tableName' => $owner->tableName()])
            ->orderBy('action_id')
            ->column($owner->getDb());
    }

    /**
     * Disables tracking, useful when using trigger mode.
     * @return bool
     */
    public function disableTracking()
    {
        return $this->toggleTracking(false);
    }

    /**
     * Reenables tracking, useful when using trigger mode.
     * @return bool
     */
    public function enableTracking()
    {
        return $this->toggleTracking(true);
    }

    /**
     * @param bool $enable if null, toggles the state, otherwise will either enable on true
     *                     or disable on false
     * @return bool true/false if enabled/disabled
     * @throws Exception
     */
    public function toggleTracking($enable = null)
    {
        if ($enable === null) {
            if (($hasTrigger = $this->hasTrigger()) === null) {
                throw new Exception('Cannot toggle tracking for model '
                    .get_class($this->owner).', audit trigger doesn\'t exist');
            }
            $enable = !$hasTrigger;
        }
        if ($enable) {
            $this->enableTracking();
        } else {
            $this->disableTracking();
        }
        return $enable;
    }

    /**
     * @return boolean null if trigger doesn't exist or true/false if enabled/disabled
     */
    public function hasTrigger()
    {
        throw new Exception('Not implemented');
    }
}
