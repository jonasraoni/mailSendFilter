<script>
	$(function () {ldelim}
		$('#mostViewedSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});

	document.querySelectorAll('.checkNumbers').forEach(function (el) {ldelim}
		el.addEventListener("input", elem => el.value = (isNaN(el.value)) ? el.value.replace(elem.data, '') : el.value);
	{rdelim})
</script>

<form class="pkp_form" id="mostViewedSettings" method="POST" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	<p>{translate key="plugins.generic.mailSendFilter.description"}</p>
	{csrf}
	{fbvFormArea id="formArea"}
		{fbvFormSection title="plugins.generic.mailSendFilter.general" list="true"}
			{fbvElement type="checkbox" id="checkInactivity" checked=$checkInactivity label="plugins.generic.mailSendFilter.checkInactivity" translate="true"}
			{fbvElement type="checkbox" id="checkMxRecord" checked=$checkMxRecord label="plugins.generic.mailSendFilter.checkMxRecord" translate="true"}
			{fbvElement type="checkbox" id="checkDisposable" checked=$checkDisposable label="plugins.generic.mailSendFilter.checkDisposable" translate="true"}
			{fbvElement type="checkbox" id="checkNeverLoggedIn" checked=$checkNeverLoggedIn label="plugins.generic.mailSendFilter.checkNeverLoggedIn" translate="true"}
			{fbvElement type="checkbox" id="checkNotValidated" checked=$checkNotValidated label="plugins.generic.mailSendFilter.checkNotValidated" translate="true"}

			<p>
				{fbvElement type="text" id="disposableDomainsUrl" value=$disposableDomainsUrl label="plugins.generic.mailSendFilter.disposableDomainsUrl"}
				{fbvElement type="text" id="disposableDomainsExpiration" class="checkNumbers" value=$disposableDomainsExpiration label="plugins.generic.mailSendFilter.disposableDomainsExpiration"}
				{fbvElement type="keyword" id="passthroughMailKeys" current=$passthroughMailKeys label="plugins.generic.mailSendFilter.passthroughMailKeys"}
			</p>
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.mailSendFilter.inactivity.threshold.rules"}
			<p>{translate key="plugins.generic.mailSendFilter.inactivity.threshold.rules.help"}</p>
			<p>{fbvElement type="text" id="inactivityThresholdDays" class="checkNumbers" value=$inactivityThresholdDays label="plugins.generic.mailSendFilter.inactivity.threshold.days"}</p>
			{foreach from=$roles item="role"}
				{fbvElement type="text" id=$role.name class="checkNumbers" value=$role.value label=$role.label}
			{/foreach}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
