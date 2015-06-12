<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
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
            if (in_array($column, $notDisplayedColumns)) {
                continue;
            }
            $first  = ($i % 3 === 1);
            $second = ($i % 3 === 2);
            $third  = ($i % 3 === 0);
            $i++;
            ?>
            <?= ($first) ? "<tr>" : '' ?>
                <td><?= $column ?></td>
                <td colspan="2"><?= $value ?></td>
            <?= ($third) ? "</tr>" : '' ?>
        <?php endforeach; ?>
    </tbody>
</table>


