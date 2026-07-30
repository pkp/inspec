<?php

/**
 * @file classes/form/InspecSettingsForm.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class InspecSettingsForm
 *
 * @brief Form for journal managers to modify Inspec plugin settings.
 */

namespace APP\plugins\generic\inspec\classes\form;

use APP\plugins\generic\inspec\InspecExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use Exception;

class InspecSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private readonly InspecExportPlugin $plugin, private readonly int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));
    }

    //
    // Implement template methods from Form.
    //
    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $contextId = $this->contextId;
        $plugin = $this->plugin;
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
        // Default to volume/issue naming when no value has been saved.
        if (!$this->getData('namingType')) {
            $this->setData('namingType', 'volumeIssue');
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydata Form::fetch()
     *
     * @param null|mixed $template
     *
     * @throws Exception
     */
    public function fetch($request, $template = null, $display = false): ?string
    {
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;
        parent::execute(...$functionArgs);
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
    }

    public function getFormFields(): array
    {
        return [
            'jatsImported' => 'bool',
            'automaticRegistration' => 'bool',
            'nlmTitle' => 'string',
            'namingType' => 'string',
            'host' => 'string',
            'port' => 'string',
            'path' => 'string',
            'username' => 'string',
            'password' => 'string'
        ];
    }

    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, [
            'jatsImported',
            'automaticRegistration',
            'namingType',
            'host',
            'port',
            'path',
            'username',
            'password'
        ]);
    }
}
