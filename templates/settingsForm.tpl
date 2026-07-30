{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Inspec plugin settings.
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#inspecSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim})
</script>
<div class="legacyDefaults">
	<form class="pkp_form" method="post" id="inspecSettingsForm" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="InspecExportPlugin" category="importexport" verb="save"}">
		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="inspecSettingsFormNotification"}
		{fbvFormArea id="inspecSettingsFormArea"}
			<p class="pkp_help">
				{translate key="plugins.importexport.inspec.description"}
			</p>
			<br/>
			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="jatsImported" label="plugins.importexport.inspec.settings.form.jatsImportedOnly" checked=$jatsImported|compare:true}
			{/fbvFormSection}

			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.inspec.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
			{/fbvFormSection}

			{fbvFormSection}
				<span class="instruct">{translate key="plugins.importexport.inspec.settings.form.journalAbbreviation.description"}</span>
				<br/>
				{fbvElement type="text" required=true id="nlmTitle" value=$nlmTitle label="plugins.importexport.inspec.settings.form.journalAbbreviation" maxlength="100" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{capture assign="namingTypeTitle"}{translate key="plugins.importexport.inspec.settings.form.namingType"}{/capture}
			{fbvFormSection list=true title=$namingTypeTitle translate=false}
				{fbvElement type="radio" id="namingType-volumeIssue" name="namingType" value="volumeIssue" checked=$namingType|compare:"volumeIssue" label="plugins.importexport.inspec.settings.form.namingType.volumeIssue"}
				{fbvElement type="radio" id="namingType-articleNumber" name="namingType" value="articleNumber" checked=$namingType|compare:"articleNumber" label="plugins.importexport.inspec.settings.form.namingType.articleNumber"}
			{/fbvFormSection}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.inspec.endpoint"}{/capture}
			{fbvFormSection id="formSection" title=$sectionTitle translate=false class="endpointContainer"}
				{fbvElement type="text" id="host" value=$host label="plugins.importexport.inspec.host" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="port" value=$port label="plugins.importexport.inspec.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="path" value=$path label="plugins.importexport.inspec.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="username" value=$username label="plugins.importexport.inspec.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" password=true id="password" value=$password label="plugins.importexport.inspec.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}
		{/fbvFormArea}
		{fbvFormButtons submitText="common.save" hideCancel="true"}
		<p>
			<span class="formRequired">{translate key="common.requiredField"}</span>
		</p>
	</form>
</div>
