<?php
\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Custom\Component\UpdateMonitor\Administrator\Service\VersionJump;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$d = static fn ($v) => $v ? HTMLHelper::_('date', $v, Text::_('DATE_FORMAT_LC5')) : '–';
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=history'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (!$this->items) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_UPDATEMONITOR_NO_DATA'); ?></div>
<?php else : ?>
<table class="table table-striped table-sm">
    <thead><tr>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_NAME'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_TYPE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_INSTALLED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_AVAILABLE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_SEVERITY'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_STATUS'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_FIRST_DETECTED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_RESOLVED'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_NOTIFIED'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($this->items as $r) : ?>
        <tr>
            <td><?php echo $e($r->name); ?></td><td><?php echo $e($r->type_label); ?></td>
            <td><?php echo $e($r->installed_version); ?></td><td><?php echo $e($r->available_version); ?> <?php echo VersionJump::badge(VersionJump::type($r->installed_version, $r->available_version)); ?></td>
            <td><?php echo Severity::badge($r->severity); ?></td>
            <td><?php echo $r->status === 'resolved' ? '<span class="badge bg-success">' . Text::_('COM_UPDATEMONITOR_STATUS_RESOLVED') . '</span> <small class="text-muted">' . $e($r->resolve_reason) . '</small>' : ($r->ignored ? '<span class="badge bg-secondary">' . Text::_('COM_UPDATEMONITOR_STATUS_IGNORED') . '</span>' : '<span class="badge bg-warning text-dark">' . Text::_('COM_UPDATEMONITOR_STATUS_PENDING') . '</span>'); ?></td>
            <td><?php echo $d($r->first_detected); ?></td><td><?php echo $d($r->resolved_at); ?></td>
            <td><?php echo $d($r->notified_at); ?><?php echo $r->notify_count > 1 ? ' <small class="text-muted">(' . (int) $r->notify_count . '×)</small>' : ''; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</form>
