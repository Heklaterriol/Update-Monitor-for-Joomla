<?php
\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ignoredView = $this->filter === 'ignored';
$tok = Session::getFormToken() . '=1';
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=updates'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>
<div class="btn-group mb-3">
    <a class="btn btn-sm btn-<?php echo $ignoredView ? 'outline-' : ''; ?>primary" href="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=updates&filter=pending'); ?>"><?php echo Text::_('COM_UPDATEMONITOR_STATUS_PENDING'); ?></a>
    <a class="btn btn-sm btn-<?php echo $ignoredView ? '' : 'outline-'; ?>primary" href="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=updates&filter=ignored'); ?>"><?php echo Text::_('COM_UPDATEMONITOR_STATUS_IGNORED'); ?></a>
</div>
<?php if (!$this->items) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_UPDATEMONITOR_NO_DATA'); ?></div>
<?php else : ?>
<table class="table table-striped">
    <thead><tr>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_NAME'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_TYPE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_INSTALLED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_AVAILABLE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_SEVERITY'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_FIRST_DETECTED'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_NOTIFIED'); ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($this->items as $r) : ?>
        <tr>
            <td><?php $link = Severity::safeUrl((string) $r->infourl); echo $link !== '' ? '<a href="' . $e($link) . '" target="_blank" rel="noopener noreferrer">' . $e($r->name) . '</a>' : $e($r->name); ?></td>
            <td><?php echo $e($r->type_label); ?></td>
            <td><?php echo $e($r->installed_version); ?></td>
            <td><?php echo $e($r->available_version); ?></td>
            <td><?php echo Severity::badge($r->severity); ?>
                <?php foreach (array_slice(json_decode((string) $r->security_info, true) ?: [], 0, 3) as $si) : ?>
                    <div class="small text-muted"><?php echo $e(mb_substr($si['text'], 0, 160)); ?></div>
                <?php endforeach; ?></td>
            <td><?php echo HTMLHelper::_('date', $r->first_detected, Text::_('DATE_FORMAT_LC5')); ?></td>
            <td><?php echo $r->notified_at ? HTMLHelper::_('date', $r->notified_at, Text::_('DATE_FORMAT_LC5')) : '–'; ?></td>
            <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_updatemonitor&task=action.' . ($ignoredView ? 'unignore' : 'ignore') . '&id=' . (int) $r->id . '&return=updates&' . $tok); ?>"><?php echo Text::_($ignoredView ? 'COM_UPDATEMONITOR_UNIGNORE' : 'COM_UPDATEMONITOR_IGNORE'); ?></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</form>
