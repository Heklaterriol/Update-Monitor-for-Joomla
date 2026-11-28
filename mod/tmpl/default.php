<?php

\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Custom\Component\UpdateMonitor\Administrator\Service\VersionJump;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array|null $summary */
if (empty($summary)) {
    return;
}

$e   = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$s   = $summary;
$sec = array_sum($s['security']);
?>
<div class="p-3">
    <?php if ($s['health']) : ?>
        <div class="alert alert-<?php echo $e($s['health']['level']); ?> py-2 small mb-3"><?php echo $e($s['health']['title']); ?></div>
    <?php endif; ?>

    <?php if (!empty($s['vel'])) : ?>
        <div class="alert alert-danger py-2 small mb-3"><?php echo Text::sprintf('COM_UPDATEMONITOR_VEL_BANNER', (int) $s['vel']); ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <div class="text-muted small"><?php echo Text::_('COM_UPDATEMONITOR_CORE_TITLE'); ?></div>
        <?php if ($s['core']) : $c = $s['core']; ?>
            <div class="fs-5">
                <?php echo $e($c->installed_version); ?> → <strong><?php echo $e($c->available_version); ?></strong>
                <?php echo VersionJump::badge(VersionJump::type($c->installed_version, $c->available_version)); ?>
                <?php echo $c->severity !== '' ? Severity::badge($c->severity) : ''; ?>
            </div>
        <?php elseif ($s['last_check']) : ?>
            <div class="text-success"><?php echo Text::sprintf('COM_UPDATEMONITOR_CORE_UP_TO_DATE', $e(JVERSION)); ?></div>
        <?php else : ?>
            <div class="text-muted">–</div>
        <?php endif; ?>
    </div>

    <div class="row text-center g-2 mb-3">
        <div class="col-4"><div class="fs-3<?php echo $s['pending'] ? ' text-warning' : ''; ?>"><?php echo (int) $s['pending']; ?></div><div class="small text-muted"><?php echo Text::_('COM_UPDATEMONITOR_EXTENSION_UPDATES'); ?></div></div>
        <div class="col-4"><div class="fs-3<?php echo $sec ? ' text-danger' : ''; ?>"><?php echo (int) $sec; ?></div><div class="small text-muted"><?php echo Text::_('COM_UPDATEMONITOR_SECURITY_UPDATES'); ?></div></div>
        <div class="col-4"><div class="fs-3<?php echo $s['findings'] ? ' text-warning' : ''; ?>"><?php echo (int) $s['findings']; ?></div><div class="small text-muted"><?php echo Text::_('COM_UPDATEMONITOR_FINDINGS'); ?></div></div>
    </div>

    <?php if ($sec) : ?>
        <p class="mb-3">
            <?php foreach (['critical', 'high', 'medium', 'low', 'unknown'] as $lvl) : ?>
                <?php if (!empty($s['security'][$lvl])) : ?><?php echo Severity::badge($lvl); ?> <strong><?php echo (int) $s['security'][$lvl]; ?></strong>&nbsp;&nbsp;<?php endif; ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="small text-muted"><?php echo Text::_('COM_UPDATEMONITOR_LAST_CHECK'); ?>: <?php echo $s['last_check'] ? HTMLHelper::_('date', $s['last_check'], Text::_('DATE_FORMAT_LC5')) : Text::_('COM_UPDATEMONITOR_NEVER'); ?></span>
        <a class="btn btn-sm btn-primary" href="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor'); ?>"><?php echo Text::_('MOD_UPDATEMONITOR_OPEN'); ?></a>
    </div>
</div>
