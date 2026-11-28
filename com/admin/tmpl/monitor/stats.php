<?php
\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Custom\Component\UpdateMonitor\Administrator\Service\Stats;
use Custom\Component\UpdateMonitor\Administrator\Service\VersionJump;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e      = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$d      = $this->statsData;
$groups = array_merge(Stats::GROUPS, ['all']);
$label  = static fn (string $g): string => $g === 'all' ? Text::_('COM_UPDATEMONITOR_GROUP_ALL') : ($g === 'other' ? Text::_('COM_UPDATEMONITOR_GROUP_OTHER') : Severity::label($g));
$periods = [30 => '30', 90 => '90', 365 => '365', 0 => 'ALL'];
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=stats'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>

<div class="btn-group mb-3">
    <?php foreach ($periods as $days => $key) : ?>
        <a class="btn btn-sm btn-<?php echo $this->period === $days ? '' : 'outline-'; ?>primary" href="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=stats&period=' . $days); ?>"><?php echo Text::_('COM_UPDATEMONITOR_PERIOD_' . $key); ?></a>
    <?php endforeach; ?>
</div>
<p class="text-muted small"><?php echo Text::_('COM_UPDATEMONITOR_STATS_NOTE'); ?></p>

<h3 class="h5 mt-4"><?php echo Text::_('COM_UPDATEMONITOR_STATS_FIXED_TITLE'); ?></h3>
<table class="table table-striped table-sm">
    <thead><tr>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_GROUP'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_FIXED'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_MEDIAN'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_AVERAGE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_LONGEST'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_WITHIN'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($groups as $g) : $f = $d['fixed'][$g]; if (!$f['n'] && $g !== 'all') { continue; } ?>
        <tr<?php echo $g === 'all' ? ' class="fw-bold"' : ''; ?>>
            <td><?php echo $e($label($g)); ?></td><td><?php echo (int) $f['n']; ?></td>
            <td><?php echo Stats::human($f['median']); ?></td><td><?php echo Stats::human($f['avg']); ?></td><td><?php echo Stats::human($f['max']); ?></td>
            <td><?php echo $f['targeted'] ? (int) round($f['within'] / $f['targeted'] * 100) . ' % (' . (int) $f['within'] . '/' . (int) $f['targeted'] . ')' : '–'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3 class="h5 mt-4"><?php echo Text::_('COM_UPDATEMONITOR_STATS_OPEN_TITLE'); ?></h3>
<table class="table table-striped table-sm">
    <thead><tr>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_GROUP'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_OPEN'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_AVERAGE'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_LONGEST'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_OVERDUE'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($groups as $g) : $o = $d['open'][$g]; if (!$o['n'] && $g !== 'all') { continue; } ?>
        <tr<?php echo $g === 'all' ? ' class="fw-bold"' : ''; ?>>
            <td><?php echo $e($label($g)); ?></td><td><?php echo (int) $o['n']; ?></td>
            <td><?php echo Stats::human($o['avg']); ?></td><td><?php echo Stats::human($o['max']); ?></td>
            <td><?php echo $o['overdue'] ? '<span class="badge bg-danger">' . (int) $o['overdue'] . '</span>' : '0'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($d['oldest']) : ?>
<h3 class="h5 mt-4"><?php echo Text::_('COM_UPDATEMONITOR_STATS_OLDEST_TITLE'); ?></h3>
<table class="table table-striped table-sm">
    <thead><tr>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_NAME'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_TYPE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_INSTALLED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_AVAILABLE'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_SEVERITY'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_AGE'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_TARGET'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($d['oldest'] as $o) : ?>
        <tr>
            <td><?php echo $e($o['name']); ?></td><td><?php echo $e($o['type']); ?></td><td><?php echo $e($o['installed']); ?></td>
            <td><?php echo $e($o['available']); ?> <?php echo VersionJump::badge(VersionJump::type($o['installed'], $o['available'])); ?></td>
            <td><?php echo Severity::badge($o['severity']); ?></td>
            <td><?php echo Stats::human($o['age']); ?> <?php echo $o['overdue'] ? '<span class="badge bg-danger">' . Text::_('COM_UPDATEMONITOR_OVERDUE') . '</span>' : ''; ?></td>
            <td><?php echo $o['target'] ? $e($o['target']) . ' d' : '–'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3 class="h5 mt-4"><?php echo Text::_('COM_UPDATEMONITOR_STATS_MONTHS_TITLE'); ?></h3>
<table class="table table-striped table-sm">
    <thead><tr>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_MONTH'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_DETECTED'); ?></th>
        <th><?php echo Text::_('COM_UPDATEMONITOR_COL_FIXED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_MEDIAN'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($d['months'] as $month => $m) : ?>
        <tr><td><?php echo $e($month); ?></td><td><?php echo (int) $m['detected']; ?></td><td><?php echo (int) $m['fixed']; ?></td><td><?php echo Stats::human($m['median']); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</form>
