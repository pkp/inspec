<?php

/**
 * @file tests/InspecSettingsFormTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the Inspec plugin settings form.
 */

namespace APP\plugins\generic\inspec\tests;

use APP\plugins\generic\inspec\classes\form\InspecSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(InspecSettingsForm::class)]
class InspecSettingsFormTest extends PKPTestCase
{
    /**
     * The setting types accepted by DAO::convertToDB(), which is what
     * Plugin::updateSetting() ultimately hands the declared type to.
     */
    private const SETTING_TYPES = [
        'bool', 'boolean', 'int', 'integer', 'float', 'number',
        'object', 'array', 'date', 'string',
    ];

    private function createForm(): InspecSettingsForm
    {
        return $this->getMockBuilder(InspecSettingsForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * The plugin has no required settings: the connection details are only
     * needed for depositing, which InspecExportPlugin::getExportActions()
     * gates separately. If any field became non-optional, the parent's
     * display() would raise EXPORT_CONFIG_ERROR_SETTINGS and hide the export
     * tab until it was filled in.
     */
    public function testEveryFormFieldIsOptional(): void
    {
        $form = $this->createForm();

        foreach (array_keys($form->getFormFields()) as $fieldName) {
            $this->assertTrue(
                $form->isOptional($fieldName),
                "Setting '{$fieldName}' should be optional"
            );
        }
    }

    public function testFormFieldsAreTheExpectedSettings(): void
    {
        $form = $this->createForm();

        $this->assertSame([
            'jatsImported' => 'bool',
            'automaticRegistration' => 'bool',
            'host' => 'string',
            'port' => 'string',
            'path' => 'string',
            'username' => 'string',
            'password' => 'string',
        ], $form->getFormFields());
    }

    /**
     * execute() passes each declared type straight to Plugin::updateSetting(). A
     * type DAO::convertToDB() does not recognise is stored as a serialised string
     * rather than failing, so a typo here would only surface as a corrupt setting.
     */
    public function testEveryFieldDeclaresAStorableType(): void
    {
        $form = $this->createForm();

        foreach ($form->getFormFields() as $fieldName => $fieldType) {
            $this->assertContains(
                $fieldType,
                self::SETTING_TYPES,
                "Setting '{$fieldName}' declares an unsupported type '{$fieldType}'"
            );
        }
    }
}
