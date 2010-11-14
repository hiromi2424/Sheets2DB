<h2>Index</h2>
<?php

echo $this->Form->create(false);
echo $this->Form->input('destination', array('lable' => 'enter name of spread sheet to import'));
echo $this->Form->end('submit');
