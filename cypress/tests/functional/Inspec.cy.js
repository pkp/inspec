/**
 * @file cypress/tests/functional/Inspec.cy.js
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Functional tests for the Inspec plugin.
 */

describe('Inspec plugin tests', function () {

	it('Configures the plugin, shows export tabs, and exports a valid zip', function() {
		cy.login('admin', 'admin', 'publicknowledge');

		cy.get('nav').contains('Settings').click();
		cy.get('nav').contains('Website').click({force: true});
		cy.get('button[id="plugins-button"]').click();

		// Find and enable the plugin
		cy.get('input[id^="select-cell-inspecplugin-enabled"]').check();
		cy.get('input[id^="select-cell-inspecplugin-enabled"]').should('be.checked');
		cy.contains(/The plugin "Inspec Plugin" has been enabled\./i, {timeout: 20000});
		cy.reload();

		// Open import/export plugin page.
		cy.get('nav').contains('Tools').click();
		cy.contains(/Inspec Export Plugin/i, {timeout: 20000}).click();

		// The settings form has no required fields; the SFTP fields aren't
		// exercised by the export action (they're only used by the deposit flow).
		// The package name is taken from the journal's abbreviation (or URL
		// path when unset), not a setting.
		cy.waitJQuery({timeout: 20000});
		cy.get('form#inspecSettingsForm', {timeout: 20000}).should('be.visible');
		cy.get('form#inspecSettingsForm button:contains("Save")').click();
		cy.contains('Your changes have been saved.');
		cy.waitJQuery({timeout: 20000});

		// Verify the export tab is present.
		cy.get('a[href="#exportSubmissions-tab"]').should('exist');

		// Drive the export via cy.request to avoid Cypress hanging on the
		// streaming binary download.
		cy.getCsrfToken();
		cy.get('@csrfToken').then((csrfToken) => {
			cy.request({
				url: '/index.php/publicknowledge/en/management/importexport/plugin/InspecExportPlugin/exportSubmissions',
				method: 'POST',
				headers: {
					'X-Csrf-Token': csrfToken,
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: [
					`csrfToken=${encodeURIComponent(csrfToken)}`,
					'tab=exportSubmissions-tab',
					'selectedSubmissions%5B%5D=1',
					'validation=on',
					'export=1',
				].join('&'),
				timeout: 60000,
				encoding: 'binary',
				followRedirect: false,
			}).then((response) => {
				expect(response.status).to.eq(200);
				expect(response.headers['content-type']).to.eq('application/zip');
				expect(response.body).to.have.length.gt(0);
				// All zip files start with 'PK' (ASCII for the local file
				// header magic 0x50 0x4B).
				expect(response.body.slice(0, 2)).to.eq('PK');
			});
		});
	});
});
