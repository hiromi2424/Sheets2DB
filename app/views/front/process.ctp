<h2><?php __('Results') ?></h2>

<h3><?php __('Communication with Google') ?>:</h3>
<p><?php echo sprintf(__('%.1f seconds', true), $gdata_elapsed_time); ?></p>

<h3><?php __('Communication with database') ?>:</h3>
<p><?php echo sprintf(__('%.1f seconds', true), $database_elapsed_time); ?></p>

<dl>

<dt><?php __('Created Tables') ?>:</dt>
<dd><?php echo $countSuccessTables ?>/<?php echo $countTables ?></dd>

<dt><?php __('Executed Queries') ?>:</dt>
<dd><?php echo $countSuccessQueries ?>/<?php echo $countQueries ?></dd>
</dl>
