<?php

if ($_POST['editing']) {
    if (Auth::user()->hasGlobalAdmin()) {
        $api_url = trim((string) ($_POST['mist_api_url'] ?? ''));
        $api_key = trim((string) ($_POST['mist_api_key'] ?? ''));
        $org_id = trim((string) ($_POST['mist_org_id'] ?? ''));
        $site_ids = trim((string) ($_POST['mist_site_ids'] ?? ''));

        if ($api_url !== '') {
            set_dev_attrib($device, 'mist.api_url', $api_url);
        } else {
            del_dev_attrib($device, 'mist.api_url');
        }

        if ($api_key !== '') {
            set_dev_attrib($device, 'mist.api_key', $api_key);
        } else {
            del_dev_attrib($device, 'mist.api_key');
        }

        if ($org_id !== '') {
            set_dev_attrib($device, 'mist.org_id', $org_id);
        } else {
            del_dev_attrib($device, 'mist.org_id');
        }

        if ($site_ids !== '') {
            // store raw string; MistApi will split by commas/whitespace
            set_dev_attrib($device, 'mist.site_ids', $site_ids);
        } else {
            del_dev_attrib($device, 'mist.site_ids');
        }

        $update_message = 'Mist Cloud settings updated for this device.';
        $updated = 1;
    } else {
        include 'includes/html/error-no-perm.inc.php';
    }
}

if ($updated && $update_message) {
    print_message($update_message);
} elseif ($update_message) {
    print_error($update_message);
}

?>

<form id="edit-mist" name="edit-mist" method="post" action="" role="form" class="form-horizontal">
<?php echo csrf_field(); ?>
<input type="hidden" name="editing" value="yes">

  <div class="form-group">
    <label for="mist_api_url" class="col-sm-2 control-label">API URL</label>
    <div class="col-sm-8">
      <input id="mist_api_url" name="mist_api_url" class="form-control"
             placeholder="https://api.mist.com or regional endpoint"
             value="<?php echo htmlentities((string) get_dev_attrib($device, 'mist.api_url')); ?>" />
    </div>
  </div>

  <div class="form-group">
    <label for="mist_api_key" class="col-sm-2 control-label">API Token</label>
    <div class="col-sm-8">
      <input id="mist_api_key" name="mist_api_key" type="password" class="form-control"
             value="<?php echo htmlentities((string) get_dev_attrib($device, 'mist.api_key')); ?>" />
    </div>
  </div>

  <div class="form-group">
    <label for="mist_org_id" class="col-sm-2 control-label">Organization ID</label>
    <div class="col-sm-8">
      <input id="mist_org_id" name="mist_org_id" class="form-control"
             placeholder="Mist Org UUID"
             value="<?php echo htmlentities((string) get_dev_attrib($device, 'mist.org_id')); ?>" />
    </div>
  </div>

  <div class="form-group">
    <label for="mist_site_ids" class="col-sm-2 control-label">Site IDs (optional)</label>
    <div class="col-sm-8">
      <textarea id="mist_site_ids" name="mist_site_ids" rows="3" class="form-control"
                placeholder="Comma or whitespace separated list of site UUIDs; leave empty to include all sites."><?php
          echo htmlentities((string) get_dev_attrib($device, 'mist.site_ids'));
      ?></textarea>
    </div>
  </div>

  <div class="row">
    <div class="col-md-2 col-md-offset-2">
        <button type="submit" name="Submit" class="btn btn-default">
            <i class="fa fa-check"></i> Save
        </button>
    </div>
  </div>

  <br><br>
  <div class="alert alert-info" role="alert">
    <p>
      These settings apply only to this device. To use the Mist integration, set this device OS to
      <b>mist</b> and enable the global <code>mist.enabled</code> option in the Web UI
      (External &rarr; Mist Cloud Integration).
    </p>
  </div>
</form>

