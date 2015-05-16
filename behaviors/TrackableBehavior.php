<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\behaviors;

use Yii;
use yii\base\Event;
use yii\db\BaseActiveRecord;

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

    const STORE_RECORD = 1;
    const STORE_LOG = 2;
    const STORE_BOTH = 3;

    /**
     * @var string what method is used to record changes, either
     * TrackableBehavior::MODE_TRIGGER or TrackableBehavior::MODE_EVENT.
     */
    public $mode = self::MODE_TRIGGER;
    /**
     * @var integer how the change is stored, as a full record version
     * (TrackableBehavior::STORE_RECORD) or a attribute/value log entry
     * (TrackableBehavior::STORE_LOG). Can be both, since a full record
     * is easier to reconstruct a specific version and log entries
     * allow to search for specific changes faster.
     */
    public $store = self::STORE_BOTH;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return $this->mode !== self::MODE_EVENT ? [] : [
            ActiveRecord::EVENT_BEFORE_INSERT => 'recordInsert',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'recordUpdate',
            ActiveRecord::EVENT_BEFORE_DELETE => 'recordDelete',
        ];
    }

    /**
     * @param Event $event
     */
    public function recordInsert($event)
    {
        return $this->recordChange($event, ActiveRecord::EVENT_BEFORE_INSERT);
    }

    /**
     * @param Event $event
     */
    public function recordUpdate($event)
    {
        return $this->recordChange($event, ActiveRecord::EVENT_BEFORE_UPDATE);
    }

    /**
     * @param Event $event
     */
    public function recordDelete($event)
    {
        return $this->recordChange($event, ActiveRecord::EVENT_BEFORE_DELETE);
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
     */
    public function refreshVersion($version_id)
    {
        throw new Exception('Not implemented');
    }

    /**
     * Disables tracking, useful when using trigger mode.
     */
    public function disableTracking()
    {
        throw new Exception('Not implemented');
    }

    /**
     * Reenables tracking, useful when using trigger mode.
     */
    public function enableTracking()
    {
        throw new Exception('Not implemented');
    }

    /**
     * @param bool $enable if null, toggles the state, otherwise will either enable on true
     *                     or disable on false
     * @return bool true/false if enabled/disabled
     */
    public function toggleTracking($enable = null)
    {
        if ($enable === null) {
            if (($hasTrigger = $this->hasTrigger()) === null) {
                throw new \yii\base\Exception('Cannot toggle tracking for model '
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
