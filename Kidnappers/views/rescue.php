<?php if (!defined('APPLICATION')) exit(); ?>

<h1><?php echo $this->Data('Title'); ?></h1>
<div class="P"><?php echo sprintf(T("Rescue <b>%s</b> by correctly answering the kidnapper's devious riddle"), $this->Data('Victim.Name')); ?></div>
<?php
echo $this->Form->Open();
echo $this->Form->Errors();
?>
<div class="P KidnappersHint"><?php echo $this->Data('Hint.Clue'); ?></div>
<div class="P"><?php 
   echo Wrap($this->Form->Label('Which forumer am I?', 'Guess'), 'b');
   echo Wrap($this->Form->TextBox('Guess'), 'div');
?></div>

<div class="Buttons Buttons-Confirm"><?php
   echo $this->Form->Button('OK', array('class' => 'Button Primary'));
   echo $this->Form->Button('Cancel', array('type' => 'button', 'class' => 'Button Close'));
?><div>
<?php echo $this->Form->Close(); ?>
