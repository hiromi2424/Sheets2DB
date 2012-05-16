<h2><?php __('Configuration') ?></h2>
<?php

echo $this->Form->create(false);
echo $this->Form->input('email', array('label' => __('Enter your google acount', true), 'value' => $email));
echo $this->Form->input('password', array('label' => __('Enter your google password', true), 'value' => $password, 'type' => 'password'));
echo $this->Form->end(__('submit', true));

echo $this->element('emptyZend');
