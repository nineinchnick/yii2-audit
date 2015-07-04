<?php

/**
 * @author Patryk Radziszewski <pradziszewski@netis.pl>
 */
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use yii\helpers\Url;
?>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <td><?= $model['operation'] ?></td>
            <td style="min-width: 145px;"><?= $model['operation_date'] ?></td>
            <td colspan="7"><?= $arrayDiff[$model['audit_id']] ?></td>
        </tr>
    </thead>
    <tbody>
        <?php
        $i = 1;
        ?>
        <?php foreach ($model as $column => $value): ?>
            <?php
            if (in_array($column, Yii::$app->controller->hiddenColumns)) {
                continue;
            }
            $first = ($i % 3 === 1);
            $third = ($i % 3 === 0);
            $i++;
            ?>
            <?= ($first) ? "<tr>" : '' ?>
        <td><?= Yii::$app->controller->getColumnLabel($column, $table) ?></td>
        <td colspan="2"><?= $value ?></td>
        <?= ($third) ? "</tr>" : '' ?>
    <?php endforeach; ?>
</tbody>
<tfoot>
    <tr>
        <td colspan="9">
            <?php ActiveForm::begin(['action' => Url::toRoute('restore')]) ?>
            <?= Html::hiddenInput('audit_id', $model['audit_id']) ?>
            <?= Html::hiddenInput('table', $table) ?>
            <?= Html::submitButton(Yii::t('app', 'Restore'), ['class' => 'btn btn-success', 'data-confirm' => Yii::t('app', 'Are you sure to restore this record?')]); ?>
            <?php ActiveForm::end() ?>
        </td>
    </tr>
</tfoot>
</table>