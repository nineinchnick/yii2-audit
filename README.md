# yii2-audit

Extensions to the Yii 2 PHP framework allowing tracking and viewing change history of a model.

Changes are tracked using triggers, but model events should also be available as a fallback
when using an unsupported database.

Provides:

* a model behavior:
    * loads older model versions
    * temporarily disabled tracking
* a command to manage and verify audit database objects
* a controller action with a view to view model change history

# Architecture

* a library with an interface for a schema generator, basically a set of sql templates
* a library to process existing db structures and generate migrations, that is sql scripts
* make a framework specific (behaviors) layer to support more features

# Installation

Install via composer:

~~~bash
composer require nineinchnick/yii2-audit
~~~

Attach the behavior to the model, after the Blameable and Timestamp behaviors:

~~~php
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use nineinchnick\audit\behaviors\TrackableBehavior;

public function behaviors()
{
    return [
        BlameableBehavior::className(),
        TimestampBehavior::className(),
        [
            'class' => TrackableBehavior::className(),
            'mode' => TrackableBehavior::MODE_TRIGGER,
            'store' => TrackableBehavior::STORE_RECORD | TrackableBehavior::STORE_LOG,
        ],
    ];
}

~~~

# References

* https://github.com/airblade/paper_trail
* http://en.wikipedia.org/wiki/Slowly_changing_dimension
