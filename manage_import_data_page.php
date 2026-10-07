<?php
# Doctis — administrator upload of a one-time document repository snapshot.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

layout_page_header( lang_get( 'manage_import_link' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_import_data_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">Import document repository from ZIP</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main">
				<p>Upload a ZIP snapshot of an existing repository. Doctis creates a new project and its own Git repository, then registers matching documents. This is a one-time import; the source repository's commit history is not retained. Document responsibility can be assigned after import.</p>
				<form method="post" action="manage_import_data.php" enctype="multipart/form-data" class="form-horizontal">
					<?php echo form_security_field( 'manage_import_data' ); ?>
					<div class="form-group">
						<label class="col-sm-3 control-label" for="import-project-name">New project name</label>
						<div class="col-sm-7"><input class="form-control" id="import-project-name" name="project_name" maxlength="128" required placeholder="HCRQMS" /></div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label" for="import-archive">Repository ZIP</label>
						<div class="col-sm-7"><input id="import-archive" name="archive" type="file" accept=".zip,application/zip" required /><span class="help-block">ZIP must contain the selected directories, optionally inside one enclosing folder. Maximum upload: 64 MiB.</span></div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label" for="import-roots">Top-level directories</label>
						<div class="col-sm-7"><input class="form-control" id="import-roots" name="directories" value="content,system" required /><span class="help-block">Comma-separated. Only these directories are copied into the new Doctis repository.</span></div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label" for="import-patterns">Document patterns</label>
						<div class="col-sm-7"><input class="form-control" id="import-patterns" name="patterns" value="*.md" required /><span class="help-block">Comma-separated filename patterns to register; other files in the selected directories remain in Git.</span></div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label" for="import-visibility">Project visibility</label>
						<div class="col-sm-7"><select class="form-control" id="import-visibility" name="visibility"><option value="private">Private</option><option value="public">Public</option></select></div>
					</div>
					<div class="form-group">
						<div class="col-sm-offset-3 col-sm-7"><button class="btn btn-primary" type="submit">Import project and documents</button></div>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<?php
layout_page_end();
