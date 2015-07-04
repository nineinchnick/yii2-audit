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
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
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
                return $this->recordChange($event, ActiveRecord::EVENT_BEFORE_INSERT);
            },
            ActiveRecord::EVENT_BEFORE_UPDATE => function ($event) {
                return $this->recordChange($event, ActiveRecord::EVENT_BEFORE_UPDATE);
            },
            ActiveRecord::EVENT_BEFORE_DELETE => function ($event) {
                return $this->recordChange($event, ActiveRecord::EVENT_BEFORE_DELETE);
            },
        ];
    }

    /**
     * @param Event $event
     * @param string $type of on ActiveRecord::EVENT_BEFORE_* constants
     */
    public function recordChange($event, $type)
    {
        throw new Exception('Not implemented');
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
        return ActiveRecord::populateRecord(new $modelClass, (new Query())
            ->select($owner->attributes())
            ->from($this->auditTableName)
            ->where(array_fill_keys($owner->getDb()->getTableSchema($this->auditTableName)->primaryKey, $version_id))
            ->one($owner->getDb()));
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
