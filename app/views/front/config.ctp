<h2>Configuration</h2>
<?php

echo $this->Form->create(false);
echo $this->Form->input('email', array('lable' => 'enter your google acount', 'value' => $email));
echo $this->Form->input('password', array('lable' => 'enter your google password', 'value' => $password, 'type' => 'password'));
echo $this->Form->end('submit');
