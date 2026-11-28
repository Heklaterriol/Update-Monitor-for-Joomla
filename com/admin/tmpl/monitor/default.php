<?php
\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Custom\Component\UpdateMonitor\Administrator\Service\VersionJump;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$d = static fn ($v) => $v ? HTMLHelper::_('date', $v, Text::_('DATE_FORMAT_LC5')) : Text::_('COM_UPDATEMONITOR_NEVER');
$s = $this->stats;
$card = static fn (string $label, $value, string $cls = '') => '<div class="col-6 col-md-3"><div class="card text-center h-100"><div class="card-body"><div class="display-6 ' . $cls . '">' . $value . '</div><div class="text-muted">' . $label . '</div></div></div></div>';
?>
<form action="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor'); ?>" method="post" name="adminForm" id="adminForm">
<?php include __DIR__ . '/_nav.php'; ?>

<div class="mb-3">
    <button type="button" class="btn btn-primary" onclick="this.form.task.value='action.check';this.form.submit();"><?php echo Text::_('COM_UPDATEMONITOR_RUN_CHECK'); ?></button>
    <button type="button" class="btn btn-outline-secondary" onclick="this.form.task.value='action.testmail';this.form.submit();"><?php echo Text::_('COM_UPDATEMONITOR_TEST_MAIL'); ?></button>
    <button type="button" class="btn btn-outline-secondary" onclick="this.form.task.value='action.testchannels';this.form.submit();"><?php echo Text::_('COM_UPDATEMONITOR_TEST_CHANNELS'); ?></button>
    <span class="ms-3 text-muted"><?php echo Text::_('COM_UPDATEMONITOR_LAST_CHECK'); ?>: <?php echo $d($s['last_check']); ?> &middot; <?php echo Text::_('COM_UPDATEMONITOR_LAST_SUCCESS'); ?>: <?php echo $d($s['last_success']); ?></span>
</div>

<?php if (!$s['last_check']) : ?>
    <div class="alert alert-warning"><?php echo Text::_('COM_UPDATEMONITOR_NO_CHECK_YET'); ?></div>
<?php elseif ($s['installer_list'] > 0 && $s['pending'] + $s['ignored'] === 0) : ?>
    <div class="alert alert-danger"><?php echo Text::sprintf('COM_UPDATEMONITOR_MISMATCH', (int) $s['installer_list']); ?></div>
<?php endif; ?>

<?php if ($this->health) : $h = $this->health; ?>
    <div class="alert alert-<?php echo $e($h['level']); ?> mb-4">
        <h4 class="alert-heading h6"><?php echo $e($h['title']); ?></h4>
        <p class="mb-2"><?php echo $e($h['text']); ?></p>
        <?php if ($h['reasons']) : ?>
            <ul class="mb-2">
            <?php foreach ($h['reasons'] as $reason) : ?>
                <li><?php echo $e($reason); ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_scheduler&view=tasks'); ?>"><?php echo Text::_('COM_UPDATEMONITOR_HEALTH_OPEN_TASKS'); ?></a>
    </div>
<?php endif; ?>

<?php if ($this->core) : $c = $this->core; ?>
    <div class="card border-<?php echo in_array($c->severity, ['critical', 'high'], true) ? 'danger' : 'warning'; ?> mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="fs-2 text-warning"><span class="icon-joomla" aria-hidden="true"></span></div>
            <div class="flex-grow-1">
                <div class="text-muted small"><?php echo Text::_('COM_UPDATEMONITOR_CORE_TITLE'); ?></div>
                <div class="fs-4"><?php echo $e($c->installed_version); ?> → <strong><?php echo $e($c->available_version); ?></strong> <?php echo VersionJump::badge(VersionJump::type($c->installed_version, $c->available_version)); ?>
                    <?php echo $c->severity !== '' ? Severity::badge($c->severity) : ''; ?></div>
                <div class="small text-muted"><?php echo Text::_('COM_UPDATEMONITOR_COL_FIRST_DETECTED'); ?>: <?php echo $d($c->first_detected); ?></div>
            </div>
            <div>
                <?php if (Severity::safeUrl((string) $c->infourl) !== '') : ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo $e(Severity::safeUrl((string) $c->infourl)); ?>" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_UPDATEMONITOR_CORE_RELEASE_NOTES'); ?></a><?php endif; ?>
                <a class="btn btn-primary btn-sm" href="<?php echo Route::_('index.php?option=com_joomlaupdate'); ?>"><?php echo Text::_('COM_UPDATEMONITOR_CORE_OPEN_UPDATE'); ?></a>
            </div>
        </div>
    </div>
<?php elseif ($s['last_check']) : ?>
    <div class="alert alert-success d-flex align-items-center mb-4"><?php echo Text::sprintf('COM_UPDATEMONITOR_CORE_UP_TO_DATE', $e(JVERSION)); ?></div>
<?php endif; ?>

<?php if (!empty($s['vel'])) : ?>
    <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-4">
        <span><?php echo Text::sprintf('COM_UPDATEMONITOR_VEL_BANNER', (int) $s['vel']); ?></span>
        <a class="btn btn-danger btn-sm ms-auto" href="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=security'); ?>"><?php echo Text::_('COM_UPDATEMONITOR_TAB_SECURITY'); ?></a>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php echo $card(Text::_('COM_UPDATEMONITOR_PENDING'), $s['pending']); ?>
    <?php echo $card(Text::_('COM_UPDATEMONITOR_SECURITY_UPDATES'), $s['security'], $s['security'] ? 'text-danger' : ''); ?>
    <?php echo $card(Text::_('COM_UPDATEMONITOR_FINDINGS'), $s['findings'], $s['findings'] ? 'text-warning' : ''); ?>
    <?php echo $card(Text::_('COM_UPDATEMONITOR_IGNORED'), $s['ignored']); ?>
    <?php echo $card(Text::_('COM_UPDATEMONITOR_RESOLVED_30D'), $s['resolved30'], 'text-success'); ?>
    <?php echo $card(Text::_('COM_UPDATEMONITOR_CARD_MEDIAN'), $e($s['fix_median'])); ?>
</div>

<?php if ($s['security']) : ?>
<p>
    <?php foreach (['critical', 'high', 'medium', 'low', 'unknown'] as $lvl) : ?>
        <?php if (!empty($s[$lvl])) : ?><?php echo Severity::badge($lvl); ?> <strong><?php echo (int) $s[$lvl]; ?></strong>&nbsp;&nbsp;<?php endif; ?>
    <?php endforeach; ?>
</p>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h3 class="h5 mb-0"><?php echo Text::_('COM_UPDATEMONITOR_EXTENSION_UPDATES'); ?></h3>
            <?php if ($this->extCount) : ?>
                <a class="btn btn-warning btn-sm" href="<?php echo Route::_('index.php?option=com_installer&view=update'); ?>"><?php echo Text::sprintf('COM_UPDATEMONITOR_OPEN_EXT_UPDATES', (int) $this->extCount); ?></a>
            <?php endif; ?>
        </div>
        <?php if (!$this->items && $s['last_check']) : ?>
            <div class="alert alert-success"><?php echo Text::_('COM_UPDATEMONITOR_EXTENSIONS_UP_TO_DATE'); ?></div>
        <?php elseif ($this->items) : ?>
        <table class="table table-sm">
            <thead><tr><th><?php echo Text::_('COM_UPDATEMONITOR_COL_NAME'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_TYPE'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_INSTALLED'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_AVAILABLE'); ?></th><th><?php echo Text::_('COM_UPDATEMONITOR_COL_SEVERITY'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($this->items as $r) : ?>
                <tr><td><?php echo $e($r->name); ?></td><td><?php echo $e($r->type_label); ?></td><td><?php echo $e($r->installed_version); ?></td><td><?php echo $e($r->available_version); ?> <?php echo VersionJump::badge(VersionJump::type($r->installed_version, $r->available_version)); ?></td><td><?php echo Severity::badge($r->severity); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <h3 class="h5"><?php echo Text::_('COM_UPDATEMONITOR_RECENT_ACTIVITY'); ?></h3>
        <ul class="list-group list-group-flush small">
        <?php foreach ($this->log as $l) : ?>
            <li class="list-group-item px-0"><span class="text-muted"><?php echo $d($l->created); ?></span><br><?php echo $e($l->message); ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
</div>
</form>
