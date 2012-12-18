<h2><?php __('Index') ?></h2>
<?php

echo $this->Form->create(false);
echo $this->Form->input('destination', array('label' => __('Enter name of spread sheet to import', true)));
if (!empty($databases)) {
	echo $this->Html->script('//ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js');
	echo $this->Form->input('database', array('type' => 'select', 'options' => $databases, 'empty' => true, 'label' => __('Select database config to import', true)));
	echo $this->Html->scriptStart();
?>
var $target = $('#database');
var $input = $('#destination');
$target.change(function(){
	$input.val($('option:selected').text());
});
<?php
	echo $this->Html->scriptEnd();
}
echo $this->Form->end(__('submit', true));

echo $this->element('emptyZend');
