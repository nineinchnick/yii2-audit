<?php
/**
 * @author Patryk Radziszewski <pradziszewski@netis.pl>
 */
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
            if (in_array($column, Yii::$app->controller->notDisplayedColumns)) {
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
</table>


