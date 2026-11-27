<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=servers'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>
<table class="table table-striped table-sm">
    <thead><tr><th><?php echo Text::_('COM_UPDATEMONITOR_COL_SERVER'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_EXTENSION'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_TYPE'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_LOCATION'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_ENABLED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_LAST_CHECKED'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($this->servers as $s) : ?>
        <tr>
            <td><?php echo $e($s->name); ?></td><td><?php echo $e($s->extension); ?></td><td><?php echo $e($s->type); ?></td>
            <td style="word-break:break-all"><?php echo $e($s->location); ?></td>
            <td><?php echo $s->enabled ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>'; ?></td>
            <td><?php echo $s->last_check_timestamp ? date('Y-m-d H:i', (int) $s->last_check_timestamp) : '–'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</form>
