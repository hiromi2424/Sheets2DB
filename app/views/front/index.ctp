<h2><?php __('Index') ?></h2>
<?php

echo $this->Form->create(false);
echo $this->Form->input('destination', array('label' => __('Enter name of spread sheet to import', true)));
echo $this->Form->end(__('submit', true));
