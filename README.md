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

# Installation

Install via composer:

~~~bash
composer require nineinchnick/yii2-audit
~~~

Warning! If the database is restored from a dump, it's necessary to reenumaret table oids in `audits.logged_actions.relation_id` column using the following query:

~~~sql
UPDATE audits.logged_actions SET relation_id = (schema_name || '.' || table_name)::regclass::oid;
~~~

## Recording changes

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
        ],
    ];
}

~~~

If using trigger mode, run a command that generates migrations,
which would create or update database objects like triggers and audit tables.

First, configure the controller in your console config:

~~~php
    'controllerMap' => [
        'audit' => [
            'class' => 'nineinchnick\audit\console\AuditController',
        ],
    ],
    // .... rest of configuration
~~~

Then run the command:

~~~bash
./yii audit/migration --modelName=AR_MODEL_CLASS
~~~

where `AR_MODEL_CLASS` is your model class name.

## Displaying changes

To view the change history, use the provided module in your app config:

~~~php
    'modules' => [
        'audit' => [
            'class' => 'nineinchnick\audit\Module',
        ],
        // .... other modules
    ],
~~~

# Sample configuration

~~~php
'audit'      => [
    'class'   => 'nineinchnick\audit\Module',
    'tables'  => [
        'orders' => [
            'model'         => 'netis\orders\models\Order',
            'hiddenColumns' => ['id', 'created_on', 'author_id'],
            'updateSkip'    => ['updated_on', 'editor_id'],
            'relations'     => [
                'editor' => [
                    'type'                 => 'LEFT JOIN',
                    'table'                => '{{%users}}',
                    'on'                   => 'editor_id = u.id',
                    'alias'                => 'u',
                    'representive_columns' => 'username',
                    'label'                => Yii::t('models', 'Editor'),
                ],
            ]
        ],
    ],
    'filters' => [
        'dateFrom' => [
            'format'      => 'date',
            'attribute'   => 'operation_date',
            'widgetClass' => 'omnilight\widgets\DatePicker',
            'options'     => ['class' => 'form-control'],
            'dateFormat'  => 'yyyy-MM-dd',
            'rules'       => [
                [
                    'validator' => 'date',
                    'options'   => ['format' => 'Y-m-d'],
                ]
            ],
            'criteria'    => [
                'operator' => '>=',
            ],
        ],
        'dateTo'   => [
            'format'      => 'date',
            'attribute'   => 'operation_date',
            'widgetClass' => 'omnilight\widgets\DatePicker',
            'options'     => ['class' => 'form-control'],
            'dateFormat'  => 'yyyy-MM-dd',
            'rules'       => [
                [
                    'validator' => 'date',
                    'options'   => ['format' => 'Y-m-d'],
                ]
            ],
            'criteria'    => [
                'operator' => '<=',
            ],
        ],
    ],
],
~~~

# References

* https://github.com/airblade/paper_trail
* https://github.com/2ndQuadrant/audit-trigger
* http://en.wikipedia.org/wiki/Slowly_changing_dimension
* http://en.wikipedia.org/wiki/Change_data_capture
