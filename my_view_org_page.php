<?php
# Doctis — organisation chart view.

require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'html_api.php' );
require_api( 'layout_api.php' );
require_api( 'org_chart_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

auth_ensure_user_authenticated();
html_robots_noindex();

$t_chart = org_chart_build( user_get_enabled_rows() );
$t_rendered = array();

/**
 * Render a user and their direct reports.
 *
 * @param int   $p_user_id User identifier.
 * @param array $p_path    Current recursion path.
 * @return void
 */
function org_chart_render_user( $p_user_id, array $p_path = array() ) {
	global $t_chart, $t_rendered;
	if( isset( $p_path[$p_user_id] ) || !isset( $t_chart['nodes'][$p_user_id] ) ) {
		return;
	}
	$p_path[$p_user_id] = true;
	$t_rendered[$p_user_id] = true;
	$t_user = $t_chart['nodes'][$p_user_id];
	$t_name = $t_user['realname'] ?: $t_user['username'];
	?>
	<li>
		<div class="org-card">
			<div class="org-name"><?php echo string_html_specialchars( $t_name ) ?></div>
			<?php if( !is_blank( $t_user['position_title'] ?? '' ) ): ?>
			<div><?php echo string_html_specialchars( $t_user['position_title'] ) ?></div>
			<?php endif; ?>
			<?php if( !is_blank( $t_user['department'] ?? '' ) || !is_blank( $t_user['company'] ?? '' ) ): ?>
			<div class="org-meta"><?php echo string_html_specialchars( implode( ' · ', array_filter( array( $t_user['department'] ?? '', $t_user['company'] ?? '' ) ) ) ) ?></div>
			<?php endif; ?>
		</div>
		<?php if( !empty( $t_chart['children'][$p_user_id] ) ): ?>
		<ul>
			<?php foreach( $t_chart['children'][$p_user_id] as $t_child_id ) { org_chart_render_user( $t_child_id, $p_path ); } ?>
		</ul>
		<?php endif; ?>
	</li>
	<?php
}

layout_page_header( lang_get( 'org_chart_link' ) );
layout_page_begin( 'my_view_page.php', true );
print_my_view_menu( 'my_view_org_page.php' );
?>
<style>
.org-chart { max-width:100%; overflow-x:auto; padding:15px; text-align:center; }
.org-chart ul { align-items:flex-start; display:inline-flex; gap:18px; justify-content:center; list-style:none; margin:18px 0 0; padding:0; width:max-content; }
.org-chart > ul { min-width:100%; }
.org-chart li { flex:0 0 auto; min-width:180px; }
.org-card { background:#fff; border:1px solid #9eb6ce; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.12); display:inline-block; min-width:180px; padding:10px 14px; }
.org-name { font-size:14px; font-weight:bold; }
.org-meta { color:#777; font-size:11px; margin-top:3px; }
.org-external { background:#f6f6f6; border-style:dashed; }
</style>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-sitemap', 'ace-icon' ); ?> <?php echo lang_get( 'org_chart_title' ) ?></h4>
		</div>
		<div class="widget-body">
			<div class="widget-main">
				<p class="text-muted"><?php echo lang_get( 'org_chart_hint' ) ?></p>
				<div class="org-chart">
				<?php if( empty( $t_chart['nodes'] ) ): ?>
					<p><?php echo lang_get( 'org_chart_empty' ) ?></p>
				<?php else: ?>
					<ul>
					<?php foreach( $t_chart['roots'] as $t_root_id ) { org_chart_render_user( $t_root_id ); } ?>
					<?php foreach( $t_chart['external'] as $t_external_name => $t_user_ids ): ?>
					<li>
						<div class="org-card org-external"><div class="org-name"><?php echo string_html_specialchars( $t_external_name ) ?></div><div class="org-meta"><?php echo lang_get( 'org_chart_external' ) ?></div></div>
						<ul><?php foreach( $t_user_ids as $t_user_id ) { org_chart_render_user( $t_user_id ); } ?></ul>
					</li>
					<?php endforeach; ?>
					<?php
					# Render legacy cycles/dangling components without recursing forever.
					foreach( array_keys( $t_chart['nodes'] ) as $t_user_id ) {
						if( !isset( $t_rendered[$t_user_id] ) ) { org_chart_render_user( $t_user_id ); }
					}
					?>
					</ul>
				<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
<?php layout_page_end(); ?>
