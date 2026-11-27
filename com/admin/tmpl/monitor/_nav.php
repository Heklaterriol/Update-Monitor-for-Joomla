<?php
\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$tabs = ['default' => 'DASHBOARD', 'updates' => 'UPDATES', 'history' => 'HISTORY', 'log' => 'LOG', 'servers' => 'SERVERS'];
$cur  = $this->getLayout();
?>
<ul class="nav nav-tabs mb-3">
<?php foreach ($tabs as $layout => $key) : ?>
    <li class="nav-item"><a class="nav-link<?php echo $cur === $layout ? ' active' : ''; ?>" href="<?php echo Route::_('index.php?option=com_updatemonitor&view=monitor&layout=' . $layout); ?>"><?php echo Text::_('COM_UPDATEMONITOR_TAB_' . $key); ?></a></li>
<?php endforeach; ?>
</ul>
<input type="hidden" name="task" value="">
<?php echo HTMLHelper::_('form.token'); ?>
