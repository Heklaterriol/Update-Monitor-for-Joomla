<?php
\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e      = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$groups = ['vel' => 'VEL', 'eol' => 'EOL', 'disabled' => 'DISABLED', 'nosrc' => 'NOSRC', 'srvoff' => 'SRVOFF', 'stale' => 'STALE'];
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=security'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>

<p class="text-muted"><?php echo Text::_('COM_UPDATEMONITOR_SECURITY_INTRO'); ?></p>
<div class="mb-3">
    <button type="button" class="btn btn-primary" onclick="this.form.task.value='action.check';this.form.submit();"><?php echo Text::_('COM_UPDATEMONITOR_RUN_CHECK'); ?></button>
    <span class="ms-3 text-muted"><?php echo Text::_('COM_UPDATEMONITOR_SECURITY_LAST'); ?>: <?php echo $this->findingsChecked ? HTMLHelper::_('date', $this->findingsChecked, Text::_('DATE_FORMAT_LC5')) : Text::_('COM_UPDATEMONITOR_NEVER'); ?></span>
</div>

<?php if (!$this->findings) : ?>
    <div class="alert alert-success"><?php echo Text::_('COM_UPDATEMONITOR_SECURITY_NONE'); ?></div>
<?php endif; ?>

<?php foreach ($groups as $kind => $key) :
    $rows = array_filter($this->findings, static fn ($f) => $f['kind'] === $kind);

    if (!$rows) {
        continue;
    }
    ?>
    <h3 class="h5 mt-4"><?php echo Text::_('COM_UPDATEMONITOR_KIND_' . $key); ?> <span class="badge bg-secondary"><?php echo \count($rows); ?></span></h3>
    <table class="table table-striped table-sm">
        <tbody>
        <?php foreach ($rows as $f) : $link = Severity::safeUrl((string) $f['url']); ?>
            <tr>
                <td class="text-nowrap" style="width:1%"><?php echo Severity::badge($f['severity']); ?></td>
                <td>
                    <strong><?php echo $link !== '' ? '<a href="' . $e($link) . '" target="_blank" rel="noopener noreferrer">' . $e($f['title']) . '</a>' : $e($f['title']); ?></strong>
                    <div class="small text-muted"><?php echo $e($f['detail']); ?></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
</form>
