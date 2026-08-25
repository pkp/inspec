{**
 * templates/index.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * List of operations this plugin can perform.
 *
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>

	{if !empty($configurationErrors)}
		{assign var="allowExport" value=false}
	{else}
		{assign var="allowExport" value=true}
	{/if}

	{if !$currentContext->getData('publisherInstitution') || !($currentContext->getData('onlineIssn') || $currentContext->getData('printIssn'))}
		<p>
		{capture assign="contextSettingsUrl"}{url page="management" op="settings" path="context"}{/capture}
		{translate key="plugins.importexport.inspec.missingFields" url=$contextSettingsUrl}
		</p>
	{else}

		<script type="text/javascript">
			// Attach the JS file tab handler.
			$(function() {ldelim}
				$('#importExportTabs').pkpHandler('$.pkp.controllers.TabHandler');
				{rdelim});
		</script>

		{capture assign="sftpWarning"}
			{if $sftpLibraryMissing}
				{translate key="plugins.importexport.inspec.sftpLibraryMissing"}
			{/if}
		{/capture}

		<div id="importExportTabs">
			<ul>
				<li>
					<a href="#settings-tab">{translate key="plugins.importexport.common.settings"}</a>
				</li>
				{* Inspec only indexes the first published version of an article, so
				   articles are always listed and deposited at the submission level,
				   even when the journal versions its DOIs. *}
				{if $allowExport}
					<li><a href="#exportSubmissions-tab">{translate key="plugins.importexport.common.export.articles"}</a></li>
				{/if}
			</ul>
			<div id="settings-tab">
				{$sftpWarning}
				{if !$allowExport}
					<div class="pkp_notification" id="inspecConfigurationErrors">
						{foreach from=$configurationErrors item=configurationError}
							{if $configurationError == APP\plugins\PubObjectsExportPlugin::EXPORT_CONFIG_ERROR_SETTINGS}
								{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=inspecConfigurationErrors notificationStyleClass="notifyWarning" notificationTitle="plugins.importexport.common.missingRequirements"|translate notificationContents="plugins.importexport.common.error.pluginNotConfigured"|translate}
							{/if}
						{/foreach}
					</div>
				{/if}
				{capture assign=inspecSettingsGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="InspecExportPlugin" category="importexport" verb="index" escape=false}{/capture}
				{load_url_in_div id="inspecSettingsGridContainer" url=$inspecSettingsGridUrl}
			</div>

			{if $allowExport}
				<div id="exportSubmissions-tab">
					<script type="text/javascript">
						$(function() {ldelim}
							// Attach the form handler.
							var form = $('#exportSubmissionXmlForm').pkpHandler('$.pkp.controllers.form.FormHandler');
							// Selecting rows in the grid marks the form as changed; don't warn
							// the user about unsaved changes when they actually submit it.
							form.find('button[type=submit]').click(function () {ldelim}
								form.trigger('unregisterAllForms');
							{rdelim});
						{rdelim});
					</script>
					<form id="exportSubmissionXmlForm" class="pkp_form" action="{plugin_url path="exportSubmissions"}" method="post">
						{csrf}
						<input type="hidden" name="tab" value="exportSubmissions-tab" />
						{fbvFormArea id="submissionsXmlForm"}
						{capture assign=submissionsListGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.submissions.ExportPublishedSubmissionsListGridHandler" op="fetchGrid" plugin="InspecExportPlugin" category="importexport" escape=false}{/capture}
						{load_url_in_div id="submissionsListGridContainer" url=$submissionsListGridUrl}
						{fbvFormSection list="true"}
							{fbvElement type="checkbox" id="validation" label="plugins.importexport.common.validation" checked=$validation|default:true}
						{/fbvFormSection}
						{if !empty($actionNames)}
							{fbvFormSection}
								<ul class="export_actions">
									{foreach from=$actionNames key=action item=actionName}
										<li class="export_action">
											{fbvElement type="submit" label="$actionName" id="$action" name="$action" value="1" class="$action" translate=false inline=true}
										</li>
									{/foreach}
								</ul>
							{/fbvFormSection}
							{fbvFormSection}
								{translate key="plugins.importexport.inspec.downloadWarning"}
							{/fbvFormSection}
						{/if}
						{/fbvFormArea}
					</form>
				</div>
			{/if}
		</div>
	{/if}
{/block}
