<?php
$prefix = $_MIDCOM->get_context_data(MIDCOM_CONTEXT_ANCHORPREFIX);
?>
<div class="sidebar">
  <div class="area org_openpsa_helper_box">
    <h3><?php echo $data['l10n']->get('groups'); ?></h3>
<?php
    midcom::get()->dynamic_load($prefix . 'groups/');
?>
  </div>
</div>

<div class="main">
<?php
$data['view']->display_view();
?>
</div>