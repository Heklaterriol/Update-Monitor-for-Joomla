<?php
\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$lvl = ['error' => 'bg-danger', 'warning' => 'bg-warning text-dark', 'info' => 'bg-secondary'];
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=log'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>
<div class="mb-3">
    <button type="button" class="btn btn-outline-danger" onclick="if(confirm('<?php echo $e(Text::_('COM_UPDATEMONITOR_CONFIRM_CLEAR')); ?>')){this.form.task.value='action.clearlog';this.form.submit();}"><?php echo Text::_('COM_UPDATEMONITOR_CLEAR_LOG'); ?></button>
</div>
<?php if (!$this->log) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_UPDATEMONITOR_NO_DATA'); ?></div>
<?php else : ?>
<table class="table table-striped table-sm">
    <thead><tr><th><?php echo Text::_('COM_UPDATEMONITOR_COL_DATE'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_LEVEL'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_EVENT'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_MESSAGE'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($this->log as $l) : ?>
        <tr><td class="text-nowrap"><?php echo HTMLHelper::_('date', $l->created, Text::_('DATE_FORMAT_LC5')); ?></td>
            <td><span class="badge <?php echo $lvl[$l->level] ?? 'bg-secondary'; ?>"><?php echo $e($l->level); ?></span></td>
            <td><?php echo $e($l->event); ?></td><td><?php echo $e($l->message); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</form>
