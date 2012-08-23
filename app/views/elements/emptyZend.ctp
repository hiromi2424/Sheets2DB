<?php if (Configure::read('emptyZend')) : ?>
<p class="notice">
	<?php echo __('Could not find ZendFramework files', true); ?><br />
	<?php echo $this->Html->link(__('Download here', true), 'http://framework.zend.com/download/archives', array('target' => '_blank')); ?>
	<?php echo __('Please put them like <b>app/vendors/zend_framework</b>', true); ?><br />
</p>
<?php endif; ?>
